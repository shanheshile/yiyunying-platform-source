param([string]$BaseUrl = 'http://127.0.0.1:8789')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0
$operatorId = 0
$rootHeaders = @{}

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

function Invoke-MultipartUpload {
    param(
        [hashtable]$Headers,
        [string]$FileName,
        [string]$MimeType,
        [byte[]]$Bytes,
        [string]$Scene = 'chat_file',
        [bool]$OriginalUpload = $true
    )
    $client = [System.Net.Http.HttpClient]::new()
    $content = [System.Net.Http.MultipartFormDataContent]::new()
    try {
        foreach ($key in $Headers.Keys) {
            [void]$client.DefaultRequestHeaders.TryAddWithoutValidation([string]$key, [string]$Headers[$key])
        }
        $fileContent = [System.Net.Http.ByteArrayContent]::new($Bytes)
        $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse($MimeType)
        $content.Add($fileContent, 'file', $FileName)
        $content.Add([System.Net.Http.StringContent]::new($Scene), 'scene')
        $content.Add([System.Net.Http.StringContent]::new($(if ($OriginalUpload) { '1' } else { '0' })), 'original_upload')
        $response = $client.PostAsync("$BaseUrl/api/user/uploads", $content).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if (-not $response.IsSuccessStatusCode) {
            throw "POST /api/user/uploads failed: HTTP $([int]$response.StatusCode) $body"
        }
        $json = $body | ConvertFrom-Json
        if ($json.code -ne 1) { throw "POST /api/user/uploads returned code=$($json.code): $($json.msg)" }
        $script:Checks++
        return $json.data
    }
    finally {
        $content.Dispose()
        $client.Dispose()
    }
}

try {
    Add-Type -AssemblyName System.Net.Http
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $health = Invoke-Api GET '/api/health'
    Assert-True ($health.database -eq 'connected') 'database health'

    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operatorAccount = "upload_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Upload Level 2'
        membership_days = 30; admin_quota = 1; balance = 10; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "upload_l3_$suffix"
    Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Upload Level 3'
        vip_days = 30; app_quota = 1; remote_document_quota = 3; balance = 10
    } | Out-Null
    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Upload Types $suffix"; app_key = "upload_$suffix"; description = 'Upload type smoke test'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key

    $account = "upload_user_$suffix"
    Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $account; password = '123456'; nickname = 'Upload User'
    } | Out-Null
    $login = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $account; password = '123456' }
    $userHeaders = @{ Authorization = "Bearer $($login.access_token)"; 'X-App-Key' = $appKey }

    $plainBytes = [System.Text.Encoding]::UTF8.GetBytes("upload-smoke-$suffix")
    $gifBytes = [Convert]::FromBase64String('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')
    $pngBytes = [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    $cases = @(
        @{ Name = 'document.docx'; Mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; Bytes = $plainBytes },
        @{ Name = 'sheet.xlsx'; Mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; Bytes = $plainBytes },
        @{ Name = 'slides.pptx'; Mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation'; Bytes = $plainBytes },
        @{ Name = 'manual.pdf'; Mime = 'application/pdf'; Bytes = $plainBytes },
        @{ Name = 'application.apk'; Mime = 'application/vnd.android.package-archive'; Bytes = $plainBytes },
        @{ Name = 'photo.jpg'; Mime = 'image/jpeg'; Bytes = $plainBytes },
        @{ Name = 'photo.png'; Mime = 'image/png'; Bytes = $pngBytes },
        @{ Name = 'animation.gif'; Mime = 'image/gif'; Bytes = $gifBytes; Scene = 'sticker' },
        @{ Name = 'clip.mp4'; Mime = 'video/mp4'; Bytes = $plainBytes },
        @{ Name = 'clip.mov'; Mime = 'video/quicktime'; Bytes = $plainBytes },
        @{ Name = 'voice.mp3'; Mime = 'audio/mpeg'; Bytes = $plainBytes },
        @{ Name = 'archive.zip'; Mime = 'application/zip'; Bytes = $plainBytes },
        @{ Name = 'archive.7z'; Mime = 'application/x-7z-compressed'; Bytes = $plainBytes },
        @{ Name = 'archive.rar'; Mime = 'application/vnd.rar'; Bytes = $plainBytes }
    )

    $uploadedIds = @()
    foreach ($case in $cases) {
        $scene = if ($case.Scene) { [string]$case.Scene } else { 'chat_file' }
        $result = Invoke-MultipartUpload $userHeaders $case.Name $case.Mime ([byte[]]$case.Bytes) $scene $true
        Assert-True ([int]$result.upload_id -gt 0) "$($case.Name) receives upload id"
        Assert-True (-not [string]::IsNullOrWhiteSpace([string]$result.file_url)) "$($case.Name) receives file URL"
        Assert-True ([string]$result.upload_mode -eq 'original') "$($case.Name) keeps requested original mode"
        $uploadedIds += [int]$result.upload_id
        if ($case.Name -eq 'animation.gif') {
            Assert-True ([bool]$result.is_animated) 'GIF is recognized as animated media'
        }
    }

    $first = $cases[0]
    $duplicate = Invoke-MultipartUpload $userHeaders $first.Name $first.Mime ([byte[]]$first.Bytes) 'chat_file' $true
    Assert-True ([bool]$duplicate.reused) 'identical upload is reused'
    Assert-True ([int]$duplicate.upload_id -eq [int]$uploadedIds[0]) 'same owner receives the existing logical upload'

    $library = Invoke-Api GET '/api/user/uploads?limit=100' $userHeaders
    Assert-True (@($library.items).Count -ge $cases.Count) 'upload library lists every tested file type'
    foreach ($id in $uploadedIds) {
        Assert-True (@($library.items | Where-Object { [int]$_.id -eq $id }).Count -eq 1) "upload $id is queryable"
    }

    $deleteId = [int]$uploadedIds[-1]
    Invoke-Api DELETE "/api/user/uploads/$deleteId" $userHeaders @{ confirm = 'DELETE' } | Out-Null
    $afterDelete = Invoke-Api GET '/api/user/uploads?limit=100' $userHeaders
    Assert-True (@($afterDelete.items | Where-Object { [int]$_.id -eq $deleteId }).Count -eq 0) 'deleted upload disappears from the library'

    Write-Host 'Upload type smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks operator_id=$operatorId app_id=$appId file_types=$($cases.Count)"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary upload branch cleanup failed: $($_.Exception.Message)" }
    }
}
