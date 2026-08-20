param(
    [string]$BaseUrl = 'http://127.0.0.1:8794'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$TestPassword = [Environment]::GetEnvironmentVariable('YY_SMOKE_TEST_PASSWORD', 'Process')
if ([string]::IsNullOrWhiteSpace($TestPassword) -or [Text.Encoding]::UTF8.GetByteCount($TestPassword) -lt 12) {
    throw 'YY_SMOKE_TEST_PASSWORD must be set to an isolated password of at least 12 UTF-8 bytes.'
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )
    $request = @{
        Method = $Method
        Uri = "$BaseUrl$Path"
        Headers = $Headers
        UseBasicParsing = $true
    }
    if ($null -ne $Body) {
        $request.ContentType = 'application/json; charset=utf-8'
        $request.Body = $Body | ConvertTo-Json -Depth 12 -Compress
    }
    try { $response = Invoke-RestMethod @request }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $detail = $reader.ReadToEnd()
                $reader.Dispose()
            } catch { }
        }
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) { throw "$Method $Path failed: $($response.msg)" }
    return $response.data
}

function User-Headers([object]$login, [string]$appKey) {
    return @{
        Authorization = "Bearer $($login.access_token)"
        'X-App-Key' = $appKey
    }
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$adminLogin = Invoke-Api POST '/api/admin/login' -Body @{
    platform_key = 'yiyunying-root'; app_key = 'yiyunying-demo'
    account = 'admin'; password = $TestPassword; device = 'notification-test-admin'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
$appId = 0
$chatItemCount = 0
$notificationGroupCount = 0

try {
    $app = Invoke-Api POST '/api/admin/apps' -Headers $adminHeaders -Body @{
        name = "Notification Center Smoke $suffix"; description = 'Chat list and business notification separation test'
    }
    $appId = [int]$app.app.id
    $appKey = [string]$app.app.app_key
    $plate = Invoke-Api POST "/api/admin/apps/$appId/forum-plates" -Headers $adminHeaders -Body @{
        name = 'Notification Test Plate'; description = 'Comment, like, favorite and system notification test'
    }
    $ownerLogin = Invoke-Api POST '/api/user/register' -Body @{
        app_key = $appKey; account = "notify_owner_$suffix"; password = $TestPassword; password_confirmation = $TestPassword
        nickname = 'Notification Owner'; device = 'notification-test-owner'
    }
    $actorLogin = Invoke-Api POST '/api/user/register' -Body @{
        app_key = $appKey; account = "notify_actor_$suffix"; password = $TestPassword; password_confirmation = $TestPassword
        nickname = 'Notification Actor'; device = 'notification-test-actor'
    }
    $ownerHeaders = User-Headers $ownerLogin $appKey
    $actorHeaders = User-Headers $actorLogin $appKey
    $ownerId = [int]$ownerLogin.user.id
    $ownerUid = [string]$ownerLogin.user.uid
    $post = Invoke-Api POST '/api/user/forum-posts' -Headers $ownerHeaders -Body @{
        plate_id = [int]$plate.plate_id; title = "Notification Center $suffix"; content = 'Comment, like and favorite notification test.'
    }

    Invoke-Api POST "/api/user/profiles/$ownerId/likes" -Headers $actorHeaders -Body @{ count = 2 } | Out-Null
    Invoke-Api POST "/api/user/profiles/$ownerId/follow" -Headers $actorHeaders -Body @{} | Out-Null
    Invoke-Api POST '/api/user/friends/requests' -Headers $actorHeaders -Body @{ to_uid = $ownerUid; message = 'Notification center friend request test.' } | Out-Null
    Invoke-Api POST "/api/user/forum-posts/$($post.post_id)/comments" -Headers $actorHeaders -Body @{ content = 'Notification center comment test.' } | Out-Null
    Invoke-Api POST "/api/user/forum-posts/$($post.post_id)/like" -Headers $actorHeaders -Body @{} | Out-Null
    Invoke-Api POST "/api/user/forum-posts/$($post.post_id)/favorite" -Headers $actorHeaders -Body @{} | Out-Null
    Invoke-Api POST "/api/admin/apps/$appId/system-messages" -Headers $adminHeaders -Body @{
        target_type = 'users'; target_user_ids = @($ownerId); title = 'System notification test'; content = 'This belongs to notifications, not chat conversations.'
    } | Out-Null

    $messageCenter = Invoke-Api GET '/api/user/message-center?limit=200' -Headers $ownerHeaders
    $invalidChatTypes = @($messageCenter.items | Where-Object { $_.type -notin @('private', 'group', 'service', 'bot') })
    if ($messageCenter.content_scope -ne 'chat_only' -or $invalidChatTypes.Count -gt 0) {
        throw 'Message center contains non-chat records.'
    }
    if (-not (@($messageCenter.items.type) -contains 'service') -or -not (@($messageCenter.items.type) -contains 'bot')) {
        throw 'Message center must contain stable service and bot conversations.'
    }

    $center = Invoke-Api GET '/api/user/notifications?limit=200' -Headers $ownerHeaders
    $groupMap = @{}
    foreach ($group in $center.groups) { $groupMap[[string]$group.key] = $group }
    foreach ($required in @('likes', 'comments', 'social', 'system')) {
        if (-not $groupMap.ContainsKey($required)) { throw "Missing notification group: $required" }
        if ([int]$groupMap[$required].unread_count -le 0) { throw "Notification group has no unread record: $required" }
    }
    if ($center.content_scope -ne 'notification_only') { throw 'Notification center scope is incorrect.' }
    if (@($center.items | Where-Object { $_.source_type -eq 'system' }).Count -eq 0) { throw 'System messages were not merged into notification center.' }

    Invoke-Api POST '/api/user/notifications/groups/likes/read' -Headers $ownerHeaders -Body @{} | Out-Null
    $afterGroupRead = Invoke-Api GET '/api/user/notifications?limit=200' -Headers $ownerHeaders
    $likes = @($afterGroupRead.groups | Where-Object { $_.key -eq 'likes' })[0]
    $comments = @($afterGroupRead.groups | Where-Object { $_.key -eq 'comments' })[0]
    if ([int]$likes.unread_count -ne 0) { throw 'Group read did not clear likes.' }
    if ([int]$comments.unread_count -le 0) { throw 'Group read incorrectly cleared another group.' }

    Invoke-Api POST '/api/user/notifications/read-all' -Headers $ownerHeaders -Body @{} | Out-Null
    $afterReadAll = Invoke-Api GET '/api/user/notifications?limit=200' -Headers $ownerHeaders
    if ([int]$afterReadAll.unread_count -ne 0) { throw 'Read all did not clear notification center.' }
    $unread = Invoke-Api GET '/api/user/messages/unread' -Headers $ownerHeaders
    if ([int]$unread.notification_total -ne 0) { throw 'Unread summary still reports notification unread records.' }
    $chatItemCount = @($messageCenter.items).Count
    $notificationGroupCount = @($center.groups).Count
} finally {
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" -Headers $adminHeaders -Body @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary app cleanup failed: $($_.Exception.Message)" }
    }
}

Write-Host 'Notification center smoke: passed'
Write-Host "Chat items: $chatItemCount, notification groups: $notificationGroupCount"
Write-Host 'Verified: chat-only message center, service/bot entries, grouped likes/comments/social/system, group read, read all.'
