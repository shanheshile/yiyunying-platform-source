param(
    [string]$BaseUrl = 'http://127.0.0.1:8788'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )

    $params = @{
        Method = $Method
        Uri = "$BaseUrl$Path"
        Headers = $Headers
        UseBasicParsing = $true
    }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 10 -Compress
    }
    try {
        $response = Invoke-RestMethod @params
    } catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) {
        throw "$Method $Path failed: $($response.msg)"
    }
    return $response.data
}

$health = Invoke-Api -Method GET -Path '/api/health'
$adminLogin = Invoke-Api -Method POST -Path '/api/admin/login' -Body @{
    account = 'admin'
    password = '123456'
    device = 'smoke-test'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$appResult = Invoke-Api -Method POST -Path '/api/admin/apps' -Headers $adminHeaders -Body @{
    name = "Smoke Test $suffix"
    description = 'Created by tools/smoke.ps1'
}
$appId = $appResult.app.id
$appKey = $appResult.app.app_key

$account = "test_$suffix"
$register = Invoke-Api -Method POST -Path '/api/user/register' -Body @{
    app_key = $appKey
    account = $account
    password = '123456'
    password_confirmation = '123456'
    nickname = 'Smoke Test User'
    device = 'smoke-test'
}
$userHeaders = @{
    Authorization = "Bearer $($register.access_token)"
    'X-App-Key' = $appKey
}

$login = Invoke-Api -Method POST -Path '/api/user/login' -Body @{
    app_key = $appKey
    account = $account
    password = '123456'
}
$userHeaders.Authorization = "Bearer $($login.access_token)"

$document = Invoke-Api -Method POST -Path '/api/user/notes' -Headers $userHeaders -Body @{
    title = 'First Document'
    content = 'Yiyunying document CRUD smoke test.'
    content_type = 'text'
}
$documentId = $document.document.id
Invoke-Api -Method GET -Path "/api/user/notes/$documentId" -Headers $userHeaders | Out-Null
Invoke-Api -Method PUT -Path "/api/user/notes/$documentId" -Headers $userHeaders -Body @{
    title = 'First Document Updated'
    content = 'Second revision.'
} | Out-Null

$batch = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/card-batches" -Headers $adminHeaders -Body @{
    name = "Smoke Cards $suffix"
    total_count = 1
    max_use = 1
    value_json = @{
        balance = 100
        document_credit = 5
        vip_days = 7
    }
}
Invoke-Api -Method POST -Path '/api/user/cards/redeem' -Headers $userHeaders -Body @{
    card_code = $batch.codes[0]
} | Out-Null

Invoke-Api -Method POST -Path "/api/admin/apps/$appId/notices" -Headers $adminHeaders -Body @{
    title = 'Smoke Test Notice'
    content = 'Notice endpoint is working.'
    is_popup = $true
} | Out-Null
Invoke-Api -Method PUT -Path "/api/admin/apps/$appId/versions" -Headers $adminHeaders -Body @{
    version_name = '1.0.1'
    version_code = [int]($suffix % 2000000000)
    apk_url = 'https://example.com/demo.apk'
    package_name = 'xyz.jjmxg.yiyunying.user.debug'
    size_bytes = 1024
    sha256 = ('a' * 64)
    update_content = 'Version endpoint is working.'
    force_update = $false
} | Out-Null

Invoke-Api -Method GET -Path "/api/public/bootstrap?app_key=$appKey" | Out-Null
Invoke-Api -Method GET -Path "/api/admin/apps/$appId/statistics" -Headers $adminHeaders | Out-Null
Invoke-Api -Method DELETE -Path "/api/user/notes/$documentId" -Headers $userHeaders | Out-Null
Invoke-Api -Method DELETE -Path "/api/admin/apps/$appId" -Headers $adminHeaders -Body @{ confirm = 'DELETE' } | Out-Null

Write-Host 'Yiyunying core-loop smoke test passed.'
Write-Host "app_id=$appId"
Write-Host "app_key=$appKey"
Write-Host "user=$account"
