param([string]$BaseUrl = 'http://127.0.0.1:8787')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0
$operatorId = 0
$rootHeaders = @{}
$systemBadge = [string]([char]0x7CFB) + [char]0x7EDF
$systemMessageName = $systemBadge + [char]0x6D88 + [char]0x606F
$ownerBadge = [string]([char]0x7FA4) + [char]0x4E3B
$moderatorBadge = [string]([char]0x7248) + [char]0x4E3B

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 50 -Compress
    }
    try { $response = Invoke-RestMethod @params }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $stream = $_.Exception.Response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = [IO.StreamReader]::new($stream, [Text.Encoding]::UTF8)
                    try { $detail = $reader.ReadToEnd() } finally { $reader.Dispose() }
                }
            } catch { }
        }
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

function Assert-HttpFailure {
    param([string]$Method, [string]$Path, [hashtable]$Headers, [int]$Status, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    try { Invoke-RestMethod @params | Out-Null }
    catch {
        if ([int]$_.Exception.Response.StatusCode -ne $Status) {
            throw "Expected HTTP $Status from $Method $Path, got $([int]$_.Exception.Response.StatusCode)"
        }
        $script:Checks++
        return
    }
    throw "Expected $Method $Path to fail with HTTP $Status"
}

function Find-Message([object[]]$Items, [int]$MessageId) {
    return @($Items | Where-Object { [int]$_.id -eq $MessageId })[0]
}

try {
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operatorAccount = "takeover_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Takeover Level 2'
        membership_days = 30; admin_quota = 3; balance = 20; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "takeover_l3_$suffix"
    $createdAdmin = Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Takeover Level 3'
        vip_days = 30; app_quota = 2; remote_document_quota = 10; balance = 20
    }
    $adminId = [int]$createdAdmin.admin.id
    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Takeover Test $suffix"; app_key = "takeover_$suffix"; description = 'Temporary smoke application'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        private_message_enabled = $true
        group_chat_enabled = $true
        chat_room_enabled = $true
        customer_service_enabled = $true
    } } | Out-Null

    $createdA = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = "takeover_a_$suffix"; password = '123456'; nickname = 'Takeover Alpha'
    }
    $createdB = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = "takeover_b_$suffix"; password = '123456'; nickname = 'Takeover Beta'
    }
    $createdC = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = "takeover_c_$suffix"; password = '123456'; nickname = 'Takeover Gamma'
    }
    $userA = [int]$createdA.user.id
    $userB = [int]$createdB.user.id
    $userC = [int]$createdC.user.id
    $loginA = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = "takeover_a_$suffix"; password = '123456' }
    $loginB = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = "takeover_b_$suffix"; password = '123456' }
    $headersA = @{ Authorization = "Bearer $($loginA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($loginB.access_token)"; 'X-App-Key' = $appKey }

    $friendRequest = Invoke-Api POST '/api/user/friends/requests' $headersA @{
        to_user_id = $userB; message = 'Communication takeover smoke friend request'
    }
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersB @{} | Out-Null

    $fileNeedle = "managed_attachment_$suffix.pdf"
    $tagNeedle = "tag_$suffix"
    $snapshotNeedle = "snapshot_body_$suffix"
    $private = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = "Private body $snapshotNeedle"; tags = @($tagNeedle, 'important')
        attachments = @(@{
            media_type = 'file'; url = "https://example.com/$fileNeedle"; file_name = $fileNeedle
            mime_type = 'application/pdf'; size_bytes = 4096
        })
    }
    $conversationId = [int]$private.conversation_id

    $group = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "Managed Group $suffix"; join_mode = 'open'; max_members = 20
    }
    $groupId = [int]$group.room.id
    Invoke-Api POST "/api/user/chat-rooms/$groupId/join" $headersB @{} | Out-Null
    Invoke-Api PUT "/api/user/chat-rooms/$groupId/members/$userB" $headersA @{ role = 'admin' } | Out-Null
    $ownerMessage = Invoke-Api POST "/api/user/chat-rooms/$groupId/messages" $headersA @{ content = 'Owner message' }
    $moderatorMessage = Invoke-Api POST "/api/user/chat-rooms/$groupId/messages" $headersB @{ content = 'Moderator message' }
    $membersBefore = Invoke-Api GET "/api/user/chat-rooms/$groupId/members?limit=100" $headersA
    Assert-True ([int]$membersBefore.pagination.total -eq 2) 'group starts with exactly two real members'

    $chatroom = Invoke-Api POST "/api/admin/apps/$appId/chat-rooms" $adminHeaders @{
        name = "Managed Chatroom $suffix"; join_mode = 'open'; max_members = 50; announcement = 'Chatroom takeover smoke'
    }
    $chatroomId = [int]$chatroom.room.id
    Invoke-Api POST "/api/user/chat-rooms/$chatroomId/join" $headersA @{} | Out-Null
    Invoke-Api POST "/api/user/chat-rooms/$chatroomId/join" $headersB @{} | Out-Null
    Invoke-Api POST "/api/user/chat-rooms/$chatroomId/messages" $headersA @{ content = 'Chatroom user message' } | Out-Null

    $service = Invoke-Api POST '/api/user/service/messages' $headersA @{
        subject = 'Takeover service'; content = 'User service request'
    }
    $serviceSessionId = [int]$service.session_id

    $platformPolicy = Invoke-Api GET "/api/platform/apps/$appId/communication-takeover-policy" $operatorHeaders
    Assert-True ([bool]$platformPolicy.editable -and -not [bool]$platformPolicy.unlimited) 'level 2 owns an editable branch policy'
    Invoke-Api PUT "/api/platform/apps/$appId/communication-takeover-policy" $operatorHeaders @{
        platform_view_enabled = $true; platform_send_enabled = $true
        platform_private_enabled = $true; platform_group_enabled = $true; platform_service_enabled = $true
        admin_view_enabled = $true; admin_send_enabled = $true
        admin_private_enabled = $true; admin_group_enabled = $true; admin_service_enabled = $true
        force_descendants = $false
    } | Out-Null
    $adminPolicy = Invoke-Api PUT "/api/admin/apps/$appId/communication-takeover-policy" $adminHeaders @{
        admin_view_enabled = $true; admin_send_enabled = $true
        admin_private_enabled = $true; admin_group_enabled = $true; admin_service_enabled = $true
    }
    Assert-True ([bool]$adminPolicy.editable) 'level 3 can configure its policy while the parent does not force it'

    $privateQuery = "channel_type=private&channel_id=$conversationId&limit=100"
    $groupQuery = "channel_type=group&channel_id=$groupId&limit=100"
    $chatroomQuery = "channel_type=chat_room&channel_id=$chatroomId&limit=100"
    $serviceQuery = "channel_type=service&channel_id=$serviceSessionId&limit=100"
    Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?$privateQuery" $operatorHeaders | Out-Null
    Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?$groupQuery" $operatorHeaders | Out-Null
    Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?$chatroomQuery" $operatorHeaders | Out-Null
    Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?$serviceQuery" $operatorHeaders | Out-Null
    $adminOverview = Invoke-Api GET "/api/admin/apps/$appId/users/$userA" $adminHeaders
    $overviewPrivate = @(
        $adminOverview.sections.PSObject.Properties |
            ForEach-Object { $_.Value.PSObject.Properties } |
            ForEach-Object { $_.Value } |
            Where-Object { $null -ne $_.peer_user_id -and [int]$_.id -eq $conversationId }
    )[0]
    Assert-True ($null -ne $overviewPrivate -and [int]$overviewPrivate.peer_user_id -eq $userB) 'user overview lets a manager select the exact private counterpart'
    $adminPrivateContext = Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?$privateQuery" $adminHeaders
    Assert-True ([int]$adminPrivateContext.view_context.subject_user.id -eq $userA) 'private manager view is anchored to the selected subject user'
    Assert-True ([int]$adminPrivateContext.view_context.counterpart_user.id -eq $userB) 'private manager view identifies the exact counterpart user'
    Assert-True ([string]$adminPrivateContext.view_context.channel_kind -eq 'private') 'private manager view declares its exact channel kind'
    Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?$groupQuery" $adminHeaders | Out-Null
    Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?$chatroomQuery" $adminHeaders | Out-Null
    Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?$serviceQuery" $adminHeaders | Out-Null
    Assert-HttpFailure GET "/api/admin/apps/$appId/users/$userC/communications?$privateQuery" $adminHeaders 404
    Assert-HttpFailure GET "/api/admin/apps/$appId/users/$userC/communications?$groupQuery" $adminHeaders 404

    $l2Private = Invoke-Api POST "/api/platform/apps/$appId/users/$userA/communications/send" $operatorHeaders @{
        channel_type = 'private'; channel_id = $conversationId; content = 'Level 2 private system takeover'
    }
    $l3Private = Invoke-Api POST "/api/admin/apps/$appId/users/$userA/communications/send" $adminHeaders @{
        channel_type = 'private'; channel_id = $conversationId; content = 'Level 3 private system takeover'
    }
    $l2Group = Invoke-Api POST "/api/platform/apps/$appId/users/$userA/communications/send" $operatorHeaders @{
        channel_type = 'group'; channel_id = $groupId; content = 'Level 2 group system takeover'
    }
    $l3Group = Invoke-Api POST "/api/admin/apps/$appId/users/$userA/communications/send" $adminHeaders @{
        channel_type = 'group'; channel_id = $groupId; content = 'Level 3 group system takeover'
    }
    $l2Chatroom = Invoke-Api POST "/api/platform/apps/$appId/users/$userA/communications/send" $operatorHeaders @{
        channel_type = 'chat_room'; channel_id = $chatroomId; content = 'Level 2 chatroom system takeover'
    }
    $l3Chatroom = Invoke-Api POST "/api/admin/apps/$appId/users/$userA/communications/send" $adminHeaders @{
        channel_type = 'chat_room'; channel_id = $chatroomId; content = 'Level 3 chatroom system takeover'
    }
    Invoke-Api POST "/api/platform/apps/$appId/users/$userA/communications/send" $operatorHeaders @{
        channel_type = 'service'; channel_id = $serviceSessionId; content = 'Level 2 service system takeover'
    } | Out-Null
    Invoke-Api POST "/api/admin/apps/$appId/users/$userA/communications/send" $adminHeaders @{
        channel_type = 'service'; channel_id = $serviceSessionId; content = 'Level 3 service system takeover'
    } | Out-Null
    Assert-True ([bool]$l2Private.actor_hidden_from_members -and $l2Private.public_sender.badge -eq $systemBadge) 'level 2 public identity is system and actor is hidden'
    Assert-True ([bool]$l3Private.actor_hidden_from_members -and $l3Private.public_sender.name -eq $systemMessageName) 'level 3 public identity is system message'

    $privateRead = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersB
    foreach ($messageId in @([int]$l2Private.message_id, [int]$l3Private.message_id)) {
        $item = Find-Message @($privateRead.items) $messageId
        Assert-True ($null -ne $item -and $item.sender_type -eq 'system') 'private takeover message is visible as system'
        Assert-True ($item.sender_display_name -eq $systemMessageName -and $item.sender_badge -eq $systemBadge) 'private takeover has explicit system badge'
    }

    $anonymousManagedForward = Invoke-Api POST '/api/user/message-forwards' $headersA @{
        source_type = 'private'; source_id = $conversationId
        message_ids = @([int]$private.message_id, [int]$l2Private.message_id, [int]$l3Private.message_id)
        target_type = 'private'; target_id = $userB; anonymity_mode = 'full'
        tags = @('user_anonymous', 'manager_real')
    }
    $anonymousUserSnapshot = Invoke-Api GET "/api/user/message-forwards/$($anonymousManagedForward.forward_bundle_id)" $headersB
    $anonymousOrdinary = @($anonymousUserSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$private.message_id })[0]
    Assert-True ([bool]$anonymousOrdinary.anonymous -and [int]$anonymousOrdinary.sender_id -eq 0) 'full anonymity hides the ordinary level 4 sender'
    foreach ($messageId in @([int]$l2Private.message_id, [int]$l3Private.message_id)) {
        $systemItem = @($anonymousUserSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq $messageId })[0]
        Assert-True ($null -ne $systemItem -and [string]$systemItem.sender_type -eq 'system' -and -not [bool]$systemItem.anonymous) 'level 2 or 3 system message never becomes anonymous to users'
    }
    $anonymousAdminSnapshot = Invoke-Api GET "/api/admin/apps/$appId/message-forwards/$($anonymousManagedForward.forward_bundle_id)" $adminHeaders
    $auditL2 = @($anonymousAdminSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$l2Private.message_id })[0]
    $auditL3 = @($anonymousAdminSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$l3Private.message_id })[0]
    Assert-True ([string]$auditL2.audit_actor.actor_type -eq 'platform' -and [int]$auditL2.audit_actor.actor_level -eq 2) 'level 3 audit view recovers the real level 2 sender'
    Assert-True ([string]$auditL3.audit_actor.actor_type -eq 'admin' -and [int]$auditL3.audit_actor.actor_level -eq 3) 'level 3 audit view recovers the real level 3 sender'
    Assert-HttpFailure POST '/api/user/message-forwards' $headersA 422 @{
        source_type = 'service'; source_id = $serviceSessionId; message_ids = @([int]$service.message_id)
        target_type = 'private'; target_id = $userB; anonymity_mode = 'full'
    }

    $groupRead = Invoke-Api GET "/api/user/chat-rooms/$groupId/messages?limit=100" $headersB
    $ownerItem = Find-Message @($groupRead.items) ([int]$ownerMessage.message_id)
    $moderatorItem = Find-Message @($groupRead.items) ([int]$moderatorMessage.message_id)
    Assert-True ($ownerItem.sender_badge -eq $ownerBadge) 'group owner message has an explicit owner badge'
    Assert-True ($moderatorItem.sender_badge -eq $moderatorBadge) 'group administrator message has an explicit moderator badge'
    foreach ($messageId in @([int]$l2Group.message_id, [int]$l3Group.message_id)) {
        $item = Find-Message @($groupRead.items) $messageId
        Assert-True ($null -ne $item -and $item.sender_badge -eq $systemBadge -and $null -eq $item.user_id) 'group takeover is system-only and has no member user id'
    }
    $membersAfter = Invoke-Api GET "/api/user/chat-rooms/$groupId/members?limit=100" $headersA
    Assert-True ([int]$membersAfter.pagination.total -eq 2) 'takeover actors never enter the group member list'

    $chatroomRead = Invoke-Api GET "/api/user/chat-rooms/$chatroomId/messages?limit=100" $headersB
    Assert-True ((Find-Message @($chatroomRead.items) ([int]$l2Chatroom.message_id)).sender_badge -eq $systemBadge) 'level 2 can take over a chatroom'
    Assert-True ((Find-Message @($chatroomRead.items) ([int]$l3Chatroom.message_id)).sender_badge -eq $systemBadge) 'level 3 can take over a chatroom'

    $escapedFile = [Uri]::EscapeDataString($fileNeedle)
    $fileSearch = Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?channel_type=private&channel_id=$conversationId&content_filter=file&keyword=$escapedFile&limit=100" $adminHeaders
    Assert-True (@($fileSearch.items | Where-Object { [int]$_.id -eq [int]$private.message_id }).Count -eq 1) 'manager search finds a private message by file name'
    $escapedTag = [Uri]::EscapeDataString($tagNeedle)
    $tagSearch = Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?channel_type=private&channel_id=$conversationId&content_filter=tag&keyword=$escapedTag&limit=100" $operatorHeaders
    Assert-True (@($tagSearch.items | Where-Object { [int]$_.id -eq [int]$private.message_id }).Count -eq 1) 'platform search finds a private message by tag'

    $forward = Invoke-Api POST '/api/user/message-forwards' $headersA @{
        source_type = 'private'; source_id = $conversationId; message_ids = @([int]$private.message_id)
        target_type = 'private'; target_id = $userB; tags = @('managed_snapshot')
    }
    $escapedSnapshot = [Uri]::EscapeDataString($snapshotNeedle)
    $snapshotSearch = Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?channel_type=private&channel_id=$conversationId&content_filter=snapshot&keyword=$escapedSnapshot&limit=100" $adminHeaders
    $forwardItem = @($snapshotSearch.items | Where-Object { [int]$_.id -eq [int]$forward.message_id })[0]
    Assert-True ($null -ne $forwardItem -and [int]$forwardItem.forward_bundle_id -eq [int]$forward.forward_bundle_id) 'manager search returns the structured forwarded snapshot card'
    $snapshotDetail = Invoke-Api GET "/api/admin/apps/$appId/message-forwards/$($forward.forward_bundle_id)" $adminHeaders
    Assert-True ([bool]$snapshotDetail.forward.read_only -and @($snapshotDetail.forward.items).Count -eq 1) 'manager loads the complete read-only snapshot'
    Assert-True ($snapshotDetail.forward.items[0].sender_display_name -eq 'Takeover Alpha') 'snapshot keeps the original sender display name'
    Assert-True (@($snapshotDetail.forward.items[0].attachments).Count -eq 1) 'snapshot keeps the original file attachment'
    $platformSnapshot = Invoke-Api GET "/api/platform/apps/$appId/message-forwards/$($forward.forward_bundle_id)" $operatorHeaders
    Assert-True ([int]$platformSnapshot.forward.id -eq [int]$forward.forward_bundle_id) 'level 2 can open the same scoped snapshot'

    Invoke-Api PUT "/api/platform/apps/$appId/communication-takeover-policy" $operatorHeaders @{
        platform_view_enabled = $true; platform_send_enabled = $true
        platform_private_enabled = $true; platform_group_enabled = $true; platform_service_enabled = $true
        admin_view_enabled = $true; admin_send_enabled = $true
        admin_private_enabled = $true; admin_group_enabled = $true; admin_service_enabled = $true
        force_descendants = $true
    } | Out-Null
    $lockedPolicy = Invoke-Api GET "/api/admin/apps/$appId/communication-takeover-policy" $adminHeaders
    Assert-True (-not [bool]$lockedPolicy.editable -and [int]$lockedPolicy.policy.policy_locked_for_level -eq 3) 'level 2 can force-lock its level 3 policy'
    Assert-HttpFailure PUT "/api/admin/apps/$appId/communication-takeover-policy" $adminHeaders 403 @{
        admin_send_enabled = $false
    }

    $audits = Invoke-Api GET "/api/platform/apps/$appId/communication-takeover-audits?action=send&limit=100" $operatorHeaders
    Assert-True (@($audits.items | Where-Object { $_.actor_type -eq 'platform' -and [int]$_.actor_id -eq $operatorId }).Count -ge 4) 'audit stores the real level 2 actor'
    Assert-True (@($audits.items | Where-Object { $_.actor_type -eq 'admin' -and [int]$_.actor_id -eq $adminId }).Count -ge 4) 'audit stores the real level 3 actor'
    Assert-True (@($audits.items | Where-Object { -not [bool]$_.detail.actor_visible_to_users }).Count -ge 8) 'audit confirms real actors are hidden from ordinary users'

    Write-Host 'Communication takeover smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks operator_id=$operatorId admin_id=$adminId app_id=$appId"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary communication takeover branch cleanup failed: $($_.Exception.Message)" }
    }
}
