param([string]$BaseUrl = 'http://127.0.0.1:8788')

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
    try { $response = Invoke-RestMethod @params } catch {
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

function Assert-HttpFailure([string]$Method, [string]$Path, [hashtable]$Headers, [int]$Status) {
    try { Invoke-RestMethod -Method $Method -Uri "$BaseUrl$Path" -Headers $Headers -UseBasicParsing | Out-Null }
    catch {
        if ([int]$_.Exception.Response.StatusCode -ne $Status) { throw "Expected HTTP $Status from $Method $Path" }
        $script:Checks++
        return
    }
    throw "Expected $Method $Path to fail"
}

try {
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{ platform_key = 'yiyunying-root'; account = 'root'; password = '123456' }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = "layer2_$suffix"; password = '123456'; nickname = 'Hierarchy Test Platform'
        membership_days = 30; admin_quota = 5; balance = 50; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $operatorKey = [string]$operator.operator.platform_key
    Assert-True ([int]$operator.operator.balance -eq 50) 'level 2 receives configured balance'

    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{ platform_key = $operatorKey; account = "layer2_$suffix"; password = '123456' }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }
    Assert-True ([int]$operatorLogin.platform.level -eq 2) 'authorized platform logs in as level 2'

    $admin = Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = "layer3_$suffix"; password = '123456'; nickname = 'Hierarchy Test Admin'
        app_key = "hierarchy_bootstrap_$suffix"; app_name = 'Hierarchy Bootstrap App'
        vip_days = 30; app_quota = 2; remote_document_quota = 3; balance = 20
    }
    $adminId = [int]$admin.admin.id
    Assert-True ([int]$admin.admin.balance -eq 20) 'level 3 receives configured balance'

    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{ platform_key = $operatorKey; app_key = "hierarchy_bootstrap_$suffix"; account = "layer3_$suffix"; password = '123456' }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $app = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = 'Hierarchy Test App'; app_key = "hierarchy_$suffix"; description = 'temporary smoke app'
    }
    $appId = [int]$app.app.id
    $appKey = [string]$app.app.app_key
    $user = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = "layer4_$suffix"; password = '123456'; nickname = 'Hierarchy Test User'
    }
    $userId = [int]$user.user.id
    $userLogin = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = "layer4_$suffix"; password = '123456' }
    $userHeaders = @{ Authorization = "Bearer $($userLogin.access_token)"; 'X-App-Key' = $appKey }

    $red = Invoke-Api POST '/api/platform/activities' $operatorHeaders @{
        activity_type = 'red_packet'; funding_mode = 'balance'; title = 'Level 2 to Level 3 red packet'
        total_balance = 10; total_count = 2; packet_mode = 'equal'; targets = @(@{ type = 'level'; level = 3 })
    }
    $redId = [int]$red.activity.id
    $rootView = Invoke-Api GET "/api/platform/activities/$redId" $rootHeaders
    Assert-True ([bool]$rootView.activity.can_manage) 'level 1 can audit every lower-level activity in its root'
    Assert-HttpFailure GET "/api/user/activities/$redId" $userHeaders 404
    Assert-HttpFailure POST "/api/user/activities/$redId/claim" $userHeaders 404
    $claim = Invoke-Api POST "/api/admin/activities/$redId/claim" $adminHeaders @{}
    Assert-True ([int]$claim.reward_balance -eq 5) 'level 3 claims level 2 red packet'

    $separate = Invoke-Api POST '/api/platform/activities' $operatorHeaders @{
        activity_type = 'red_packet'; funding_mode = 'balance'; title = 'Separate visibility and participation'
        total_balance = 2; total_count = 2; packet_mode = 'equal'; audience_sync = $false
        visibility_targets = @(@{ type = 'level'; level = 4 })
        participation_targets = @(@{ type = 'level'; level = 3 })
    }
    $separateId = [int]$separate.activity.id
    Assert-True (-not [bool]$separate.activity.audience_sync) 'separate audience mode is stored'
    $userVisible = Invoke-Api GET "/api/user/activities/$separateId" $userHeaders
    Assert-True (-not [bool]$userVisible.activity.participation_allowed) 'level 4 can be view-only'
    Assert-HttpFailure POST "/api/user/activities/$separateId/claim" $userHeaders 403
    $adminVisible = Invoke-Api GET "/api/admin/activities/$separateId" $adminHeaders
    Assert-True ([bool]$adminVisible.activity.participation_allowed) 'level 3 participation target is also visible'
    $separateClaim = Invoke-Api POST "/api/admin/activities/$separateId/claim" $adminHeaders @{}
    Assert-True ([int]$separateClaim.reward_balance -eq 1) 'level 3 can claim in separate audience mode'
    $separateCancel = Invoke-Api POST "/api/platform/activities/$separateId/cancel" $operatorHeaders @{}
    Assert-True ([int]$separateCancel.refunded_balance -eq 1) 'separate audience activity refunds unused escrow'

    $directAdminLogin = Invoke-Api POST '/api/admin/login' @{} @{ platform_key = 'yiyunying-root'; app_key = 'yiyunying-demo'; account = 'admin'; password = '123456' }
    $directAdminHeaders = @{ Authorization = "Bearer $($directAdminLogin.access_token)" }
    Assert-HttpFailure GET "/api/admin/activities/$redId" $directAdminHeaders 404

    $lottery = Invoke-Api POST '/api/platform/activities' $operatorHeaders @{
        activity_type = 'lottery'; funding_mode = 'balance'; title = 'Level 2 to Level 4 lottery'
        targets = @(@{ type = 'app'; id = $appId }); per_actor_limit = 1
        prizes = @(@{ name = 'Fixed test prize'; reward_balance = 6; weight = 1; stock = 1 })
    }
    $draw = Invoke-Api POST "/api/user/activities/$([int]$lottery.activity.id)/draw" $userHeaders @{}
    Assert-True ([int]$draw.prize.reward_balance -eq 6) 'level 4 receives lottery balance'

    $bounty = Invoke-Api POST '/api/admin/activities' $adminHeaders @{
        activity_type = 'bounty'; funding_mode = 'balance'; title = 'Level 3 to Level 4 bounty'
        reward_balance = 5; targets = @(@{ type = 'app'; id = $appId })
    }
    $submission = Invoke-Api POST "/api/user/activities/$([int]$bounty.activity.id)/submit" $userHeaders @{ content = 'Hierarchy bounty submission' }
    $award = Invoke-Api POST "/api/admin/activities/$([int]$bounty.activity.id)/award" $adminHeaders @{ submission_id = [int]$submission.submission_id }
    Assert-True ([int]$award.reward_balance -eq 5) 'bounty is awarded atomically'
    Assert-True ([int]$award.winner.actor_id -eq $userId) 'bounty winner is scoped user'

    $before = Invoke-Api GET '/api/admin/activities/balance' $adminHeaders
    $cancelled = Invoke-Api POST '/api/admin/activities' $adminHeaders @{
        activity_type = 'red_packet'; funding_mode = 'balance'; title = 'Refund test'
        total_balance = 4; total_count = 2; packet_mode = 'equal'; targets = @(@{ type = 'app'; id = $appId })
    }
    $afterEscrow = Invoke-Api GET '/api/admin/activities/balance' $adminHeaders
    $refund = Invoke-Api POST "/api/admin/activities/$([int]$cancelled.activity.id)/cancel" $adminHeaders @{}
    $afterRefund = Invoke-Api GET '/api/admin/activities/balance' $adminHeaders
    Assert-True (([int]$before.balance - [int]$afterEscrow.balance) -eq 4) 'activity budget is escrowed'
    Assert-True ([int]$refund.refunded_balance -eq 4) 'unused budget is refunded'
    Assert-True ([int]$afterRefund.balance -eq [int]$before.balance) 'refund restores exact balance'

    Write-Host 'Yiyunying hierarchy-loop smoke test passed.'
    Write-Host "checks=$script:Checks"
    Write-Host "operator_id=$operatorId admin_id=$adminId app_id=$appId user_id=$userId"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary hierarchy branch cleanup failed: $($_.Exception.Message)" }
    }
}
