param(
    [string]$BaseUrl = 'http://127.0.0.1:8788',
    [string]$Php = 'php',
    [string]$PhpExtensionDir = '',
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'appht',
    [string]$DbUser = 'appht',
    [string]$DbPassword = $env:YIYUNYING_TEST_DB_PASSWORD
)

$ErrorActionPreference = 'Stop'
$DbPassword = if ($null -eq $DbPassword) { '' } else { $DbPassword.Trim() }
if ([string]::IsNullOrWhiteSpace($DbPassword)) {
    throw 'Set YIYUNYING_TEST_DB_PASSWORD or pass -DbPassword explicitly before running this smoke test.'
}
$BaseUrl = $BaseUrl.TrimEnd('/')
$root = Split-Path -Parent $PSScriptRoot
$worker = Join-Path $PSScriptRoot 'exchange-concurrency-worker.php'
$phpCommand = Get-Command $Php -ErrorAction Stop
$Checks = 0
$phpRuntimeArgs = @()
if (-not [string]::IsNullOrWhiteSpace($PhpExtensionDir)) {
    $resolvedExtensionDir = (Resolve-Path -LiteralPath $PhpExtensionDir).Path
    $phpRuntimeArgs = @(
        '-d', "extension_dir=$resolvedExtensionDir",
        '-d', 'extension=php_mbstring.dll',
        '-d', 'extension=php_pdo_mysql.dll'
    )
}

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 12 -Compress
    }
    $response = Invoke-RestMethod @params
    if ($response.code -ne 1) { throw "$Method $Path failed: $($response.msg)" }
    return $response.data
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function Invoke-WorkerPair {
    param([int]$AdminId, [int]$ProductId, [string]$KeyOne, [string]$KeyTwo, [string]$Tag)
    $dir = Join-Path ([System.IO.Path]::GetTempPath()) "yiyunying-$Tag-$([Guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $dir | Out-Null
    try {
        $jobs = @()
        foreach ($item in @(@('one', $KeyOne), @('two', $KeyTwo))) {
            $out = Join-Path $dir "$($item[0]).json"
            $err = Join-Path $dir "$($item[0]).err"
            $arguments = @($script:phpRuntimeArgs) + @($worker, $AdminId, $ProductId, 1, $item[1])
            $start = @{
                FilePath = $phpCommand.Source
                ArgumentList = $arguments
                WorkingDirectory = $root
                RedirectStandardOutput = $out
                RedirectStandardError = $err
                PassThru = $true
                WindowStyle = 'Hidden'
            }
            $jobs += Start-Process @start
        }
        $jobs | Wait-Process -Timeout 30
        $results = @()
        foreach ($name in @('one', 'two')) {
            $errorText = Get-Content -LiteralPath (Join-Path $dir "$name.err") -Raw -Encoding UTF8 -ErrorAction SilentlyContinue
            if (-not [string]::IsNullOrWhiteSpace($errorText)) { throw "Worker stderr: $errorText" }
            $results += (Get-Content -LiteralPath (Join-Path $dir "$name.json") -Raw -Encoding UTF8 | ConvertFrom-Json)
        }
        return $results
    } finally {
        $resolved = (Resolve-Path -LiteralPath $dir).Path
        $temp = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
        if (-not $resolved.StartsWith($temp, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Unsafe temporary path: $resolved"
        }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}

$env:DB_HOST = $DbHost
$env:DB_PORT = [string]$DbPort
$env:DB_NAME = $DbName
$env:DB_USER = $DbUser
$env:DB_PASSWORD = $DbPassword

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$rootLogin = Invoke-Api POST '/api/platform/login' @{} @{ account = 'root'; password = '123456' }
$rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }
$operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
    account = "concurrency_operator_$suffix"; password = '123456'; membership_days = 30; admin_quota = 5
}
$operatorId = [int]$operator.operator.id
$operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
    account = "concurrency_operator_$suffix"; password = '123456'
}
$operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }
$admin = Invoke-Api POST '/api/admin/register' @{} @{
    platform_key = $operator.operator.platform_key; account = "concurrency_admin_$suffix"; password = '123456'; password_confirmation = '123456'
}
$adminId = [int]$admin.admin.id
Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $operatorHeaders @{
    balance_change = 100; remark = 'Concurrency test grant'
} | Out-Null
$product = Invoke-Api POST '/api/platform/exchange-products' $operatorHeaders @{
    product_code = "concurrency_$suffix"
    name = 'Concurrency stock product'
    product_type = 'remote_document_quota'
    grant = @{ remote_document_quota = 1 }
    price_balance = 1
    stock = 1
}
$productId = [int]$product.product.id

$differentKeys = Invoke-WorkerPair $adminId $productId "race-a:$suffix" "race-b:$suffix" 'different'
Write-Host ('different-key race results=' + ($differentKeys | ConvertTo-Json -Depth 12 -Compress))
$successes = @($differentKeys | Where-Object { [int]$_.code -eq 1 })
$failures = @($differentKeys | Where-Object { [int]$_.code -eq 0 })
Assert-True ($successes.Count -eq 1) 'one-stock race has exactly one success'
Assert-True ($failures.Count -eq 1) 'one-stock race has exactly one rejected request'
$winnerOrderId = [int]$successes[0].data.order.id
Invoke-Api POST "/api/platform/exchanges/$winnerOrderId/refund" $operatorHeaders @{
    refund_reason = 'Restore stock after different-key race'
} | Out-Null

$sameKey = "same-race:$suffix"
$sameResults = Invoke-WorkerPair $adminId $productId $sameKey $sameKey 'same'
Write-Host ('same-key race results=' + ($sameResults | ConvertTo-Json -Depth 12 -Compress))
Assert-True (@($sameResults | Where-Object { [int]$_.code -eq 1 }).Count -eq 2) 'same-key race returns success to both callers'
$orderIds = @($sameResults | ForEach-Object { [int]$_.data.order.id } | Select-Object -Unique)
Assert-True ($orderIds.Count -eq 1) 'same-key race creates one order'
Assert-True (@($sameResults | Where-Object { [bool]$_.data.idempotent }).Count -eq 1) 'same-key race marks one response as replay'
Assert-True (@($sameResults | Where-Object { -not [bool]$_.data.idempotent }).Count -eq 1) 'same-key race performs one actual exchange'

$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    platform_key = $operator.operator.platform_key; account = "concurrency_admin_$suffix"; password = '123456'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
$entitlement = Invoke-Api GET '/api/admin/entitlement' $adminHeaders
Assert-True ([int]$entitlement.quotas.balance -eq 114) 'refunded first race plus same-key race leaves exactly one balance deduction'
Assert-True ([int]$entitlement.quotas.remote_documents.limit -eq 4) 'refunded first race plus same-key race leaves exactly one document slot grant'

Invoke-Api POST "/api/platform/exchanges/$([int]$orderIds[0])/refund" $operatorHeaders @{
    refund_reason = 'Concurrency smoke cleanup'
} | Out-Null
Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null

Write-Host 'Yiyunying exchange concurrency smoke test passed.'
Write-Host "checks=$Checks"
Write-Host 'Validated: stock contention and same-key idempotency across independent PHP processes.'
