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
        $params.Body = $Body | ConvertTo-Json -Depth 12 -Compress
    }
    try {
        $response = Invoke-RestMethod @params
    } catch {
        $raw = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($raw) -and $_.Exception.Message -match 'failed:\s*(\{.+\})$') {
            $raw = $Matches[1]
        }
        if ([string]::IsNullOrWhiteSpace($raw) -and $null -ne $_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $raw = $reader.ReadToEnd()
        }
        throw "$Method $Path failed: $raw"
    }
    if ($response.code -ne 1) {
        throw "$Method $Path failed: $($response.msg)"
    }
    return $response.data
}

function Assert-ApiFailure {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [int]$ExpectedCode
    )

    try {
        Invoke-Api -Method $Method -Path $Path -Headers $Headers -Body $Body | Out-Null
    } catch {
        $raw = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($raw) -and $_.Exception.Message -match 'failed:\s*(\{.+\})$') {
            $raw = $Matches[1]
        }
        if ([string]::IsNullOrWhiteSpace($raw) -and $null -ne $_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $raw = $reader.ReadToEnd()
        }
        if (-not [string]::IsNullOrWhiteSpace($raw)) {
            $failure = $raw | ConvertFrom-Json
            if ([int]$failure.code -eq $ExpectedCode) {
                return $failure
            }
            throw "$Method $Path returned code $($failure.code), expected ${ExpectedCode}: $($failure.msg)"
        }
        throw
    }
    throw "$Method $Path unexpectedly succeeded"
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw "Assertion failed: $Message"
    }
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$operatorAccount = "operator_$suffix"
$operatorPlatformKey = "operator-$suffix"
$adminAccount = "tenant_admin_$suffix"

$rootLogin = Invoke-Api -Method POST -Path '/api/platform/login' -Body @{
    account = 'root'
    password = '123456'
    device = 'platform-smoke'
}
$rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }
Assert-True ([int]$rootLogin.platform.level -eq 1) 'root must be level 1'

$operatorResult = Invoke-Api -Method POST -Path '/api/platform/operators' -Headers $rootHeaders -Body @{
    account = $operatorAccount
    password = '123456'
    nickname = 'Platform Smoke Operator'
    platform_key = $operatorPlatformKey
    membership_days = 30
    admin_quota = 10
}
$operatorId = [int]$operatorResult.operator.id
$platformKey = [string]$operatorResult.operator.platform_key
Assert-True ($platformKey -eq $operatorPlatformKey) 'operator must keep the platform_key selected by level 1'

$operatorLogin = Invoke-Api -Method POST -Path '/api/platform/login' -Body @{
    account = $operatorAccount
    password = '123456'
    device = 'platform-smoke'
}
$operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }
Assert-True ([int]$operatorLogin.platform.level -eq 2) 'operator must be level 2'

$registration = Invoke-Api -Method POST -Path '/api/admin/register' -Body @{
    platform_key = $platformKey
    account = $adminAccount
    password = '123456'
    password_confirmation = '123456'
    nickname = 'Platform Smoke Admin'
}
Assert-True ([int]$registration.admin.platform_id -eq $operatorId) 'admin must belong to level 2 operator'
Assert-True ([int]$registration.registration_gift.app_quota -eq 1) 'new admin app gift'
Assert-True ([int]$registration.registration_gift.remote_document_quota -eq 3) 'new admin document gift'
Assert-True ([int]$registration.registration_gift.balance -eq 15) 'new admin balance gift'

$adminLogin = Invoke-Api -Method POST -Path '/api/admin/login' -Body @{
    platform_key = $platformKey
    account = $adminAccount
    password = '123456'
    device = 'platform-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

$appResult = Invoke-Api -Method POST -Path '/api/admin/apps' -Headers $adminHeaders -Body @{
    name = "Platform Smoke App $suffix"
}
$appId = [int]$appResult.app.id
$appKey = [string]$appResult.app.app_key

Assert-ApiFailure -Method POST -Path '/api/admin/apps' -Headers $adminHeaders -Body @{
    name = "Quota Overflow $suffix"
} -ExpectedCode 0 | Out-Null

$adminSettings = Invoke-Api -Method PUT -Path "/api/admin/apps/$appId/settings" -Headers $adminHeaders -Body @{
    settings = @{ chat_poll_interval_ms = 1000 }
}
Assert-True ([int]$adminSettings.settings.chat_poll_interval_ms -eq 1000) 'admin can use 1 second when unlocked'
Assert-True (-not [bool]$adminSettings.chat_polling_policy.locked) 'polling must initially be unlocked'

$operatorSettings = Invoke-Api -Method PUT -Path '/api/platform/settings' -Headers $operatorHeaders -Body @{
    settings = @{
        default_chat_poll_interval_ms = 2000
        force_chat_poll_interval = $true
    }
}
Assert-True ([bool]$operatorSettings.chat_polling_policy.locked) 'level 2 force switch must lock descendants'

$lockedFailure = Assert-ApiFailure -Method PUT -Path "/api/admin/apps/$appId/settings" -Headers $adminHeaders -Body @{
    settings = @{ chat_poll_interval_ms = 1500 }
} -ExpectedCode 403
Assert-True ([int]$lockedFailure.data.chat_polling_policy.forced_by_level -eq 2) 'lock source must be level 2'

$bootstrap = Invoke-Api -Method GET -Path "/api/public/bootstrap?app_key=$appKey"
Assert-True ([int]$bootstrap.settings.chat_poll_interval_ms -eq 2000) 'public runtime must receive forced interval'
Assert-True ([bool]$bootstrap.chat_polling_policy.locked) 'public runtime must receive lock state'

Invoke-Api -Method PUT -Path '/api/platform/settings' -Headers $rootHeaders -Body @{
    settings = @{
        default_chat_poll_interval_ms = 3000
        force_chat_poll_interval = $true
    }
} | Out-Null
$rootForcedBootstrap = Invoke-Api -Method GET -Path "/api/public/bootstrap?app_key=$appKey"
Assert-True ([int]$rootForcedBootstrap.settings.chat_poll_interval_ms -eq 3000) 'level 1 force must override level 2 force'
Assert-True ([int]$rootForcedBootstrap.chat_polling_policy.forced_by_level -eq 1) 'highest force source must be level 1'
Invoke-Api -Method PUT -Path '/api/platform/settings' -Headers $rootHeaders -Body @{
    settings = @{
        default_chat_poll_interval_ms = 5000
        force_chat_poll_interval = $false
    }
} | Out-Null
$levelTwoResumed = Invoke-Api -Method GET -Path "/api/public/bootstrap?app_key=$appKey"
Assert-True ([int]$levelTwoResumed.settings.chat_poll_interval_ms -eq 2000) 'level 2 force resumes after level 1 releases force'

$feedback = Invoke-Api -Method POST -Path '/api/admin/platform-feedbacks' -Headers $adminHeaders -Body @{
    type = 'policy'
    title = 'Polling policy feedback'
    content = 'Please review the forced polling interval.'
}
$feedbackId = [int]$feedback.feedback_id
$feedbackList = Invoke-Api -Method GET -Path '/api/platform/admin-feedbacks?status=pending' -Headers $operatorHeaders
Assert-True (@($feedbackList.items | Where-Object { [int]$_.id -eq $feedbackId }).Count -eq 1) 'level 2 can see own admin feedback'
Invoke-Api -Method POST -Path "/api/platform/admin-feedbacks/$feedbackId/reply" -Headers $operatorHeaders -Body @{
    reply_content = 'Policy received.'
    status = 'closed'
} | Out-Null

$purchase = Invoke-Api -Method POST -Path '/api/admin/purchase-orders' -Headers $adminHeaders -Body @{
    purchase_type = 'app_quota'
    quantity = 2
    note = 'Platform smoke quota order'
}
$orderId = [int]$purchase.order.id
$fulfilled = Invoke-Api -Method POST -Path "/api/platform/purchase-orders/$orderId/fulfill" -Headers $operatorHeaders -Body @{
    platform_note = 'Smoke fulfillment'
}
Assert-True ([int]$fulfilled.entitlement.app_quota -eq 3) 'fulfilled order must increase app quota'

Invoke-Api -Method POST -Path "/api/platform/operators/$operatorId/ban" -Headers $rootHeaders -Body @{
    reason = 'Platform smoke chain test'
} | Out-Null
Assert-ApiFailure -Method GET -Path "/api/public/bootstrap?app_key=$appKey" -ExpectedCode 403 | Out-Null
Invoke-Api -Method POST -Path "/api/platform/operators/$operatorId/unban" -Headers $rootHeaders | Out-Null

Invoke-Api -Method DELETE -Path "/api/platform/operators/$operatorId" -Headers $rootHeaders -Body @{
    confirm = 'DELETE'
} | Out-Null

Write-Host 'Yiyunying four-level platform smoke test passed.'
Write-Host 'Validated: L1 > L2 > admin > user/app chain, gifts, quotas, polling force, feedback, purchase fulfillment, cascade blocking.'
