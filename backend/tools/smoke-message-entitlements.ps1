param([string]$BaseUrl = 'http://127.0.0.1:8792')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 20 -Compress
    }
    try {
        $response = Invoke-RestMethod @params
    }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $detail = $reader.ReadToEnd()
                $reader.Dispose()
            }
            catch { }
        }
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        Write-Host "$Method $Path failed: $detail" -ForegroundColor Red
        throw
    }
    if ($response.code -ne 1) { throw "$Method $Path returned code=$($response.code): $($response.msg)" }
    $script:Checks++
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function Assert-Failure {
    param([string]$Method, [string]$Path, [hashtable]$Headers, [object]$Body, [int]$Code)
    try { Invoke-Api $Method $Path $Headers $Body | Out-Null }
    catch {
        $payload = $null
        if (-not [string]::IsNullOrWhiteSpace($_.ErrorDetails.Message)) {
            try { $payload = $_.ErrorDetails.Message | ConvertFrom-Json } catch { }
        }
        $actual = if ($null -ne $payload) { [int]$payload.code } elseif ($null -ne $_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
        if ($actual -ne $Code) { throw "Expected code $Code, got $actual from $Method $Path" }
        $script:Checks++
        return
    }
    throw "Expected $Method $Path to fail"
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$operatorId = 0
$root = Invoke-Api POST '/api/platform/login' @{} @{ account = 'root'; password = '123456'; device = 'message-entitlement-smoke' }
$rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
Assert-True ([bool]$root.platform.unlimited) 'L1 must advertise unlimited entitlement'
Assert-True ([bool]$root.platform.membership_unlimited) 'L1 membership must be unlimited'
$rootSettings = Invoke-Api GET '/api/platform/settings' $rootHeaders
$originalRecallSettings = @{
    default_message_recall_seconds = [int]$rootSettings.settings.default_message_recall_seconds
    force_message_recall_seconds = [bool]$rootSettings.settings.force_message_recall_seconds
    allow_child_message_recall_override = [bool]$rootSettings.settings.allow_child_message_recall_override
    message_recall_inherit = [bool]$rootSettings.settings.message_recall_inherit
}

try {
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = "message_operator_$suffix"; password = '123456'; nickname = '消息权益测试平台'
        platform_key = "message-operator-$suffix"; membership_days = 3; admin_quota = 3; balance = 10
        admin_free_trial_days = 2; admin_free_app_quota = 1; admin_free_remote_document_quota = 3; admin_free_balance = 15
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key

    $operatorVip = Invoke-Api PUT "/api/platform/operators/$operatorId/entitlement" $rootHeaders @{
        entitlement_type = 'vip'; operation = 'increase'; amount = 1; duration_unit = 'year'; membership_level = 'vip'
    }
    Assert-True (([datetime]$operatorVip.operator.membership_expired_at) -gt (Get-Date).AddMonths(11)) 'operator VIP year adjustment'
    $operatorBalance = Invoke-Api PUT "/api/platform/operators/$operatorId/entitlement" $rootHeaders @{
        entitlement_type = 'balance'; operation = 'set'; amount = 40
    }
    Assert-True ([int]$operatorBalance.operator.balance -eq 40) 'operator balance set'
    Invoke-Api PUT '/api/platform/operators/batch-entitlement' $rootHeaders @{
        target_ids = @($operatorId); entitlement_type = 'gift_document_quota'; operation = 'set'; amount = 7
    } | Out-Null
    $operatorSettings = Invoke-Api GET "/api/platform/operators/$operatorId/settings" $rootHeaders
    Assert-True ([int]$operatorSettings.settings.admin_free_remote_document_quota -eq 7) 'operator downstream document gift'

    $adminAccount = "message_admin_$suffix"
    $adminRegistration = Invoke-Api POST '/api/admin/register' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'; password_confirmation = '123456'; nickname = '消息权益测试管理员'
    }
    $adminId = [int]$adminRegistration.admin.id
    Assert-True ([int]$adminRegistration.registration_gift.remote_document_quota -eq 7) 'configured registration gift applied'
    $adminVip = Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
        entitlement_type = 'vip'; operation = 'increase'; amount = 1; duration_unit = 'month'; membership_level = 'vip'
    }
    Assert-True (([datetime]$adminVip.admin.membership_expired_at) -gt (Get-Date).AddDays(25)) 'admin VIP month adjustment'
    Invoke-Api PUT '/api/platform/admins/batch-entitlement' $rootHeaders @{
        target_ids = @($adminId); entitlement_type = 'balance'; operation = 'increase'; amount = 9
    } | Out-Null

    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'; device = 'message-entitlement-smoke'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $app = Invoke-Api POST '/api/admin/apps' $adminHeaders @{ name = "消息权益测试应用 $suffix" }
    $appId = [int]$app.app.id; $appKey = [string]$app.app.app_key

    Invoke-Api PUT '/api/platform/settings' $rootHeaders @{ settings = @{
        default_message_recall_seconds = 180; force_message_recall_seconds = $false
        allow_child_message_recall_override = $true; message_recall_inherit = $true
    } } | Out-Null
    $inheritedPolicy = Invoke-Api GET "/api/admin/apps/$appId/settings" $adminHeaders
    Assert-True ([int]$inheritedPolicy.message_recall_policy.effective_seconds -eq 180) 'L3 inherits L1 recall default through L2'

    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        account = "message_operator_$suffix"; password = '123456'; device = 'message-recall-policy-smoke'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }
    Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{ settings = @{
        default_message_recall_seconds = 300; force_message_recall_seconds = $false
        allow_child_message_recall_override = $true; message_recall_inherit = $false
    } } | Out-Null
    $operatorPolicy = Invoke-Api GET "/api/admin/apps/$appId/settings" $adminHeaders
    Assert-True ([int]$operatorPolicy.message_recall_policy.effective_seconds -eq 300) 'L2 custom recall default reaches L3'
    Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{ settings = @{ force_message_recall_seconds = $true } } | Out-Null
    $forcedByOperator = Invoke-Api GET "/api/admin/apps/$appId/settings" $adminHeaders
    Assert-True ([bool]$forcedByOperator.message_recall_policy.locked -and [int]$forcedByOperator.message_recall_policy.forced_by_level -eq 2) 'L2 force locks L3'
    Assert-Failure PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
        settings = @{ message_recall_seconds = 45; message_recall_inherit = $false }
    } 403
    Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{ settings = @{ force_message_recall_seconds = $false } } | Out-Null

    $userA = Invoke-Api POST '/api/user/register' @{} @{ app_key = $appKey; account = "sender_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '发送者' }
    $userB = Invoke-Api POST '/api/user/register' @{} @{ app_key = $appKey; account = "receiver_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '接收者' }
    $userAId = [int]$userA.user.id; $userBId = [int]$userB.user.id
    $headersA = @{ Authorization = "Bearer $($userA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($userB.access_token)"; 'X-App-Key' = $appKey }

    Invoke-Api PUT "/api/admin/apps/$appId/users/batch-entitlement" $adminHeaders @{
        target_ids = @($userAId, $userBId); entitlement_type = 'balance'; operation = 'increase'; amount = 25
    } | Out-Null
    Invoke-Api PUT "/api/admin/apps/$appId/users/batch-entitlement" $adminHeaders @{
        target_ids = @($userAId, $userBId); entitlement_type = 'vip'; operation = 'increase'; amount = 2; duration_unit = 'hour'
    } | Out-Null

    $settingsOff = Invoke-Api PUT '/api/user/message-settings' $headersB @{
        accept_stranger_messages = $false; system_notification_enabled = $true
        private_notification_enabled = $true; group_notification_enabled = $true
    }
    Assert-True (-not [bool]$settingsOff.settings.accept_stranger_messages) 'stranger switch off'
    Assert-Failure POST '/api/user/messages/private' $headersA @{ to_user_id = $userBId; content = '应被拒绝' } 403
    Invoke-Api PUT '/api/user/message-settings' $headersB @{
        accept_stranger_messages = $true; system_notification_enabled = $true
        private_notification_enabled = $true; group_notification_enabled = $true
    } | Out-Null
    $privateSent = Invoke-Api POST '/api/user/messages/private' $headersA @{ to_user_id = $userBId; content = '陌生人消息已允许' }
    $centerB = Invoke-Api GET '/api/user/message-center?limit=200' $headersB
    $private = @($centerB.items | Where-Object { $_.type -eq 'private' -and [int]$_.peer_user_id -eq $userAId })
    Assert-True ($private.Count -eq 1 -and [bool]$private[0].is_stranger) 'stranger conversation in message center'
    Invoke-Api POST "/api/user/messages/$([int]$privateSent.message_id)/recall" $headersA @{} | Out-Null
    $privateRecall = Invoke-Api GET "/api/user/conversations/$([int]$privateSent.conversation_id)/messages?since_id=$([int]$privateSent.message_id)&limit=100" $headersB
    $privateRecallEvents = @($privateRecall.items | Where-Object { $_.content_type -eq 'recall' -and [int]$_.recalled_message_id -eq [int]$privateSent.message_id })
    Assert-True ($privateRecallEvents.Count -eq 1) 'private recall event reaches incremental polling'

    Invoke-Api POST '/api/user/service/messages' $headersA @{ subject = '撤回规则测试'; content = '客服消息只能复制' } | Out-Null
    $serviceMessages = Invoke-Api GET '/api/user/service/messages?limit=100' $headersA
    Assert-True (-not [bool]$serviceMessages.message_recall_allowed) 'service messages never support recall'
    Assert-True (@($serviceMessages.items | Where-Object { [bool]$_.can_recall }).Count -eq 0) 'service message items cannot recall'

    $room = Invoke-Api POST '/api/user/chat-rooms' $headersA @{ name = "消息测试群 $suffix"; join_mode = 'open'; max_members = 20 }
    $roomId = [int]$room.room.id
    Invoke-Api POST "/api/user/chat-rooms/$roomId/join" $headersB @{} | Out-Null
    $groupSent = Invoke-Api POST "/api/user/chat-rooms/$roomId/messages" $headersA @{ content = '群聊新消息' }
    $centerGroup = Invoke-Api GET '/api/user/message-center?limit=200' $headersB
    $group = @($centerGroup.items | Where-Object { $_.type -eq 'group' -and [int]$_.target_id -eq $roomId })
    Assert-True ($group.Count -eq 1 -and [int]$group[0].unread_count -ge 1) 'group conversation and unread count'
    Invoke-Api DELETE "/api/user/chat-rooms/$roomId/messages/$([int]$groupSent.message_id)" $headersA @{} | Out-Null
    $groupRecall = Invoke-Api GET "/api/user/chat-rooms/$roomId/messages?since_id=$([int]$groupSent.message_id)&limit=100" $headersB
    $groupRecallEvents = @($groupRecall.items | Where-Object { $_.content_type -eq 'recall' -and [int]$_.recalled_message_id -eq [int]$groupSent.message_id })
    Assert-True ($groupRecallEvents.Count -eq 1) 'group recall event reaches incremental polling'

    Invoke-Api PUT '/api/platform/settings' $rootHeaders @{ settings = @{
        default_message_recall_seconds = 60; force_message_recall_seconds = $true
        allow_child_message_recall_override = $true; message_recall_inherit = $true
    } } | Out-Null
    Assert-Failure PUT '/api/platform/settings' $operatorHeaders @{
        settings = @{ default_message_recall_seconds = 90; message_recall_inherit = $false }
    } 403
    $forcedByRoot = Invoke-Api GET "/api/admin/apps/$appId/settings" $adminHeaders
    Assert-True ([int]$forcedByRoot.message_recall_policy.effective_seconds -eq 60 -and [int]$forcedByRoot.message_recall_policy.forced_by_level -eq 1) 'L1 force overrides L2 and L3'

    Write-Host "Message/entitlement smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    try { Invoke-Api PUT '/api/platform/settings' $rootHeaders @{ settings = $originalRecallSettings } | Out-Null }
    catch { Write-Warning "Root recall settings cleanup failed: $($_.Exception.Message)" }
    if ($operatorId -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for operator $operatorId`: $($_.Exception.Message)" }
    }
}
