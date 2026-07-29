param(
    [string]$BaseUrl = 'http://127.0.0.1:8789',
    [int]$PayloadMiB = 64
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0
$operatorId = 0
$rootHeaders = @{}
$tempFile = $null
$uploadId = 0
$userHeaders = @{}

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    try { $response = Invoke-RestMethod @params }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) { throw "$Method $Path returned code=$($response.code): $($response.msg)" }
    $script:Checks++
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function New-LargeZip([string]$Path, [int]$SizeMiB) {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $file = [System.IO.File]::Open($Path, [System.IO.FileMode]::Create, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new($file, [System.IO.Compression.ZipArchiveMode]::Create, $true)
        try {
            $entry = $archive.CreateEntry('payload.bin', [System.IO.Compression.CompressionLevel]::NoCompression)
            $entryStream = $entry.Open()
            try {
                $buffer = [byte[]]::new(1MB)
                for ($index = 0; $index -lt $SizeMiB; $index++) {
                    $buffer[0] = [byte]($index % 251)
                    $entryStream.Write($buffer, 0, $buffer.Length)
                }
            }
            finally { $entryStream.Dispose() }
        }
        finally { $archive.Dispose() }
    }
    finally { $file.Dispose() }
}

function Invoke-StreamingUpload {
    param([hashtable]$Headers, [string]$Path, [string]$FileName)

    $handler = [System.Net.Http.HttpClientHandler]::new()
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromMinutes(15)
    $content = [System.Net.Http.MultipartFormDataContent]::new()
    $stream = $null
    try {
        foreach ($key in $Headers.Keys) {
            [void]$client.DefaultRequestHeaders.TryAddWithoutValidation([string]$key, [string]$Headers[$key])
        }
        $stream = [System.IO.File]::OpenRead($Path)
        $fileContent = [System.Net.Http.StreamContent]::new($stream, 1MB)
        $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/zip')
        $content.Add($fileContent, 'file', $FileName)
        $content.Add([System.Net.Http.StringContent]::new('chat_file'), 'scene')
        $content.Add([System.Net.Http.StringContent]::new('1'), 'original_upload')

        $response = $client.PostAsync("$BaseUrl/api/user/uploads", $content).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        try { $json = $body | ConvertFrom-Json }
        catch {
            $preview = if ($body.Length -gt 300) { $body.Substring(0, 300) } else { $body }
            throw "Large upload returned non-JSON: HTTP $([int]$response.StatusCode) $preview"
        }
        if (-not $response.IsSuccessStatusCode -or $json.code -ne 1) {
            throw "Large upload failed: HTTP $([int]$response.StatusCode) code=$($json.code) msg=$($json.msg)"
        }
        $script:Checks++
        return $json.data
    }
    finally {
        $content.Dispose()
        if ($null -ne $stream) { $stream.Dispose() }
        $client.Dispose()
        $handler.Dispose()
    }
}

try {
    if ($PayloadMiB -lt 51) { throw 'PayloadMiB must be at least 51 to cover the reported 50 MB boundary.' }
    Add-Type -AssemblyName System.Net.Http
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $tempFile = Join-Path ([System.IO.Path]::GetTempPath()) "yiyunying-large-$suffix.zip"
    New-LargeZip $tempFile $PayloadMiB
    $expectedBytes = (Get-Item -LiteralPath $tempFile).Length
    Assert-True ($expectedBytes -gt 50MB) 'generated ZIP exceeds 50 MB'

    $health = Invoke-Api GET '/api/health'
    Assert-True ($health.database -eq 'connected') 'database health'

    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operatorAccount = "upload_size_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Large Upload Level 2'
        membership_days = 30; admin_quota = 1; balance = 10; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "upload_size_l3_$suffix"
    Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Large Upload Level 3'
        vip_days = 30; app_quota = 1; remote_document_quota = 3; balance = 10
    } | Out-Null
    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Large Upload $suffix"; app_key = "upload_size_$suffix"; description = 'Large upload smoke test'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key

    $account = "upload_size_user_$suffix"
    Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $account; password = '123456'; nickname = 'Large Upload User'
    } | Out-Null
    $login = Invoke-Api POST '/api/user/login' @{} @{
        app_key = $appKey; account = $account; password = '123456'
    }
    $userHeaders = @{ Authorization = "Bearer $($login.access_token)"; 'X-App-Key' = $appKey }

    $uploaded = Invoke-StreamingUpload $userHeaders $tempFile "large-$PayloadMiB-mib.zip"
    $uploadId = [int]$uploaded.upload_id
    Assert-True ($uploadId -gt 0) 'large upload receives an id'
    Assert-True ([long]$uploaded.size_bytes -eq $expectedBytes) 'server reports the complete streamed byte count'
    Assert-True ([long]$uploaded.original_size_bytes -eq $expectedBytes) 'server retains the original byte count'
    Assert-True ([string]$uploaded.upload_mode -eq 'original') 'large ZIP preserves original mode'

    $library = Invoke-Api GET '/api/user/uploads?limit=20' $userHeaders
    $listed = @($library.items | Where-Object { [int]$_.id -eq $uploadId })
    Assert-True ($listed.Count -eq 1) 'large upload is queryable in the user library'
    Assert-True ([long]$listed[0].size_bytes -eq $expectedBytes) 'library retains the complete large file size'

    Invoke-Api DELETE "/api/user/uploads/$uploadId" $userHeaders @{ confirm = 'DELETE' } | Out-Null
    $uploadId = 0
    $afterDelete = Invoke-Api GET '/api/user/uploads?limit=20' $userHeaders
    Assert-True (@($afterDelete.items | Where-Object { [int]$_.id -eq $uploadId }).Count -eq 0) 'large upload can be deleted'

    Write-Host 'Large upload smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks payload_mib=$PayloadMiB bytes=$expectedBytes operator_id=$operatorId app_id=$appId"
}
finally {
    if ($uploadId -gt 0 -and $userHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/user/uploads/$uploadId" $userHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary large upload cleanup failed: $($_.Exception.Message)" }
    }
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary large-upload branch cleanup failed: $($_.Exception.Message)" }
    }
    if (-not [string]::IsNullOrWhiteSpace($tempFile) -and (Test-Path -LiteralPath $tempFile)) {
        Remove-Item -LiteralPath $tempFile -Force
    }
}
