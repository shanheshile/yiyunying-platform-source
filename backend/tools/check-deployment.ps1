[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$BaseUrl,
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$PlatformAccount,
    [Parameter(Mandatory = $true)][ValidateNotNull()][System.Security.SecureString]$PlatformPassword,
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$PlatformKey,
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$AdminAccount,
    [Parameter(Mandatory = $true)][ValidateNotNull()][System.Security.SecureString]$AdminPassword,
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$AppKey,
    [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$UserAccount,
    [Parameter(Mandatory = $true)][ValidateNotNull()][System.Security.SecureString]$UserPassword
)

$ErrorActionPreference = 'Stop'
$script:Checks = 0

function Assert-ExplicitText {
    param([string]$Name, [string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) {
        throw "$Name 必须通过命令行显式传入，不能使用空值。"
    }
}

function ConvertTo-PlainText {
    param([System.Security.SecureString]$Value, [string]$Name)
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
        if ([string]::IsNullOrWhiteSpace($plain)) { throw "$Name 不能为空。" }
        return $plain
    } finally {
        if ($pointer -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        }
    }
}

foreach ($entry in ([ordered]@{
    BaseUrl = $BaseUrl
    PlatformAccount = $PlatformAccount
    PlatformKey = $PlatformKey
    AdminAccount = $AdminAccount
    AppKey = $AppKey
    UserAccount = $UserAccount
}).GetEnumerator()) {
    Assert-ExplicitText -Name $entry.Key -Value ([string]$entry.Value)
}

$baseUri = $null
if ((-not [Uri]::TryCreate($BaseUrl.Trim(), [UriKind]::Absolute, [ref]$baseUri)) -or
    ($baseUri.Scheme -notin @('http', 'https')) -or
    (-not [string]::IsNullOrEmpty($baseUri.UserInfo)) -or
    (-not [string]::IsNullOrEmpty($baseUri.Query)) -or
    (-not [string]::IsNullOrEmpty($baseUri.Fragment))) {
    throw 'BaseUrl 必须是显式的 http/https 绝对地址，且不能包含账号、密码、查询参数或片段。'
}
$BaseUrl = $baseUri.AbsoluteUri.TrimEnd('/')
$platformPasswordPlain = ConvertTo-PlainText -Value $PlatformPassword -Name 'PlatformPassword'
$adminPasswordPlain = ConvertTo-PlainText -Value $AdminPassword -Name 'AdminPassword'
$userPasswordPlain = ConvertTo-PlainText -Value $UserPassword -Name 'UserPassword'

function Invoke-JsonCheck {
    param([string]$Method, [string]$Path, [object]$Body = $null, [hashtable]$Headers = @{})

    $request = [System.Net.HttpWebRequest]::Create("$BaseUrl$Path")
    $request.Method = $Method
    $request.Accept = 'application/json'
    $request.AllowAutoRedirect = $false
    $request.Timeout = 15000
    foreach ($entry in $Headers.GetEnumerator()) { $request.Headers[$entry.Key] = [string]$entry.Value }
    if ($null -ne $Body) {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 20 -Compress))
        $request.ContentType = 'application/json; charset=utf-8'
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Dispose() }
    }

    $response = $null
    try { $response = $request.GetResponse() } catch [System.Net.WebException] { $response = $_.Exception.Response }
    if ($null -eq $response) { throw "$Method $Path：服务器没有返回 HTTP 响应" }
    try {
        $status = [int]$response.StatusCode
        $contentType = [string]$response.ContentType
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream(), [System.Text.Encoding]::UTF8)
        try { $raw = $reader.ReadToEnd() } finally { $reader.Dispose() }
        try { $json = $raw | ConvertFrom-Json } catch {
            $preview = if ($raw.Length -gt 180) { $raw.Substring(0, 180) } else { $raw }
            throw ("{0} {1} 返回的不是 JSON：HTTP {2}，Content-Type={3}，内容={4}" -f $Method, $Path, $status, $contentType, $preview)
        }
        if ($status -lt 200 -or $status -ge 300 -or [int]$json.code -ne 1) {
            throw ("{0} {1} 失败：HTTP {2}，code={3}，msg={4}" -f $Method, $Path, $status, $json.code, $json.msg)
        }
        $script:Checks++
        Write-Output ("[OK] {0} {1} -> HTTP {2}, JSON" -f $Method, $Path, $status)
        return $json.data
    } finally {
        $response.Dispose()
    }
}

try {
    Invoke-JsonCheck GET '/api/health' | Out-Null
    Invoke-JsonCheck POST '/api/platform/login' @{
        account = $PlatformAccount; password = $platformPasswordPlain
        platform_key = $PlatformKey; device = 'deployment-check'
    } | Out-Null
    Invoke-JsonCheck POST '/api/admin/login' @{
        platform_key = $PlatformKey; app_key = $AppKey; account = $AdminAccount
        password = $adminPasswordPlain; device = 'deployment-check'
    } | Out-Null
    Invoke-JsonCheck POST '/api/user/login' @{
        app_key = $AppKey; account = $UserAccount; password = $userPasswordPlain; device = 'deployment-check'
    } @{ 'X-App-Key' = $AppKey } | Out-Null

    Write-Output "部署 JSON 检查通过：$script:Checks/4"
} finally {
    $platformPasswordPlain = $null
    $adminPasswordPlain = $null
    $userPasswordPlain = $null
}
