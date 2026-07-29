param([string]$BaseUrl = 'http://127.0.0.1:8789')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 40 -Compress
    }
    try { $response = Invoke-RestMethod @params }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            $reader = [IO.StreamReader]::new($_.Exception.Response.GetResponseStream(), [Text.Encoding]::UTF8)
            try { $detail = $reader.ReadToEnd() } finally { $reader.Dispose() }
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

function Expand-SnapshotItems([object[]]$Items) {
    foreach ($item in @($Items)) {
        if ($null -eq $item) { continue }
        Write-Output $item
        if ($null -ne $item.forward_bundle -and $null -ne $item.forward_bundle.items) {
            Expand-SnapshotItems @($item.forward_bundle.items)
        }
    }
}

function Invoke-MultipartUpload {
    param([string]$Token, [string]$AppKey, [string]$FilePath)
    $arguments = @(
        '-sS', '-X', 'POST', "$BaseUrl/api/user/uploads",
        '-H', "Authorization: Bearer $Token",
        '-H', "X-App-Key: $AppKey",
        '-F', "scene=chat",
        '-F', "file=@$FilePath;type=image/png"
    )
    $raw = & curl.exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "curl upload failed with exit code $LASTEXITCODE" }
    try { return ($raw | ConvertFrom-Json) }
    catch { throw "multipart upload did not return JSON: $raw" }
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$fixture = Join-Path $env:TEMP "yyy-chat-media-$suffix.png"
$quotaRaised = $false

$root = Invoke-Api POST '/api/platform/login' @{} @{
    account = 'root'; password = '123456'; device = 'chat-media-forward-search-smoke'
}
$rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
$admin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'; password = '123456'; device = 'chat-media-forward-search-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
$adminId = [int]$admin.admin.id
$originalQuota = [int]$admin.admin.app_quota

try {
    Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
        entitlement_type = 'app_quota'; operation = 'set'; amount = ($originalQuota + 1)
        remark = '自动化测试临时增加一个应用名额'
    } | Out-Null
    $quotaRaised = $true

    $created = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "聊天媒体转发测试 $suffix"; description = '上下文搜索与只读转发快照临时应用'
    }
    $appId = [int]$created.app.id
    $appKey = [string]$created.app.app_key

    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        private_message_enabled = $true
        message_recall_seconds = 600
        upload_image_max_bytes = 1048576
        upload_video_max_bytes = 209715200
        upload_audio_max_bytes = 52428800
        upload_file_max_bytes = 104857600
    } } | Out-Null

    $bootstrap = Invoke-Api GET "/api/public/bootstrap?app_key=$appKey"
    Assert-True ([int]$bootstrap.upload_limits.image_max_bytes -eq 1048576) 'public bootstrap exposes the configured image limit'
    Assert-True ([int]$bootstrap.upload_limits.video_max_bytes -eq 209715200) 'public bootstrap exposes the configured video limit'
    Assert-True ([int]$bootstrap.upload_limits.audio_max_bytes -eq 52428800) 'public bootstrap exposes the configured audio limit'
    Assert-True ([int]$bootstrap.upload_limits.file_max_bytes -eq 104857600) 'public bootstrap exposes the configured file limit'
    Assert-True ([string]$bootstrap.upload_limits.unit -eq '字节') 'upload limit unit is explicit and Chinese'

    $a = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "chat_a_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '搜索甲'
    }
    $b = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "chat_b_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '搜索乙'
    }
    $userA = [int]$a.user.id
    $userB = [int]$b.user.id
    $headersA = @{ Authorization = "Bearer $($a.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($b.access_token)"; 'X-App-Key' = $appKey }

    $request = Invoke-Api POST '/api/user/friends/requests' $headersA @{
        to_user_id = $userB; message = '上下文搜索测试好友申请'
    }
    Invoke-Api POST "/api/user/friends/requests/$($request.request_id)/accept" $headersB @{} | Out-Null

    $before = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = '这是搜索命中消息之前的上下文'
    }
    $needle = "YYSNAPSHOT_$suffix"
    $match = Invoke-Api POST '/api/user/messages/private' $headersB @{
        to_user_id = $userA; content = "需要定位的关键消息 $needle"
        attachments = @(
            @{ media_type = 'image'; url = 'https://example.com/chat/one.png'; mime_type = 'image/png'; width = 1200; height = 900 },
            @{ media_type = 'image'; url = 'https://example.com/chat/two.png'; mime_type = 'image/png'; width = 900; height = 1200 },
            @{ media_type = 'video'; url = 'https://example.com/chat/demo.mp4'; mime_type = 'video/mp4'; duration_ms = 12000 },
            @{ media_type = 'audio'; url = 'https://example.com/chat/voice.m4a'; mime_type = 'audio/mp4'; duration_ms = 3200 }
        )
    }
    $after = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = '这是搜索命中消息之后的上下文'
    }
    $conversationId = [int]$match.conversation_id
    Assert-True ([int]$before.conversation_id -eq $conversationId -and [int]$after.conversation_id -eq $conversationId) 'private messages stay in one conversation'

    $location = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = ''
        attachments = @(
            @{
                media_type = 'location'; url = '/api/user/me'; file_name = '测试地点'
                metadata = @{
                    location_name = '测试地点'; address = '测试地址'
                    latitude = 35.550001; longitude = 116.800001
                }
            }
        )
    }
    Assert-True ([string]$location.content_type -eq 'location') 'a location-only message keeps the location content type'
    Assert-True (@($location.attachments).Count -eq 1) 'a location-only message returns one structured attachment'
    Assert-True ([string]$location.attachments[0].metadata.location_name -eq '测试地点') 'location name survives message normalization'
    Assert-True ([string]$location.attachments[0].metadata.address -eq '测试地址') 'location address survives message normalization'

    $locationSearch = Invoke-Api GET "/api/user/chat-search?scope_type=private&target_id=$conversationId&content_filter=location&context_size=0&limit=30" $headersB
    $locationResult = @($locationSearch.items | Where-Object { [int]$_.id -eq [int]$location.message_id })[0]
    Assert-True ($null -ne $locationResult -and [bool]$locationResult.is_search_match) 'location filter finds the structured location message'
    Assert-True ([string]$locationResult.attachments[0].metadata.location_name -eq '测试地点') 'location search preserves display metadata'
    Assert-True ([math]::Abs([double]$locationResult.attachments[0].metadata.latitude - 35.550001) -lt 0.000001) 'location search preserves latitude'
    Assert-True ([math]::Abs([double]$locationResult.attachments[0].metadata.longitude - 116.800001) -lt 0.000001) 'location search preserves longitude'

    $escapedNeedle = [Uri]::EscapeDataString($needle)
    $search = Invoke-Api GET "/api/user/chat-search?scope_type=private&target_id=$conversationId&keyword=$escapedNeedle&context_size=1&limit=30" $headersA
    Assert-True ([int]$search.match_count -eq 1) 'context search returns one exact match'
    Assert-True (@($search.items).Count -eq 3) 'context search returns previous, match and next message'
    Assert-True (@($search.items | Where-Object { [bool]$_.is_search_match }).Count -eq 1) 'only the matched message is highlighted'
    Assert-True ([int]@($search.items | Where-Object { [bool]$_.is_search_match })[0].id -eq [int]$match.message_id) 'highlighted result is the expected message'
    Assert-True ([bool]$search.read_only) 'search result is explicitly read-only'

    $history = Invoke-Api GET "/api/user/chat-search/history?scope_type=private&target_id=$conversationId" $headersA
    Assert-True (@($history.items | Where-Object { $_.keyword -eq $needle }).Count -eq 1) 'search keyword is remembered in unified history'
    $cleared = Invoke-Api DELETE '/api/user/chat-search/history' $headersA @{
        scope_type = 'private'; target_id = $conversationId; keyword = $needle
    }
    Assert-True ([int]$cleared.deleted_count -eq 1) 'one scoped search history item is cleared'
    $historyAfterClear = Invoke-Api GET "/api/user/chat-search/history?scope_type=private&target_id=$conversationId" $headersA
    Assert-True (@($historyAfterClear.items | Where-Object { $_.keyword -eq $needle }).Count -eq 0) 'cleared history no longer appears'

    $forward = Invoke-Api POST '/api/user/message-forwards' $headersA @{
        source_type = 'private'; source_id = $conversationId
        message_ids = @([int]$before.message_id, [int]$match.message_id, [int]$after.message_id)
        target_type = 'private'; target_id = $userB; tags = @('聊天记录', '只读快照')
    }
    Assert-True ([int]$forward.forwarded_count -eq 3) 'three selected messages are forwarded as one bundle'
    Assert-True ([int]$forward.forward_bundle_id -gt 0) 'forward operation returns a bundle id'

    $targetMessages = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersB
    $forwardMessage = @($targetMessages.items | Where-Object { [int]$_.id -eq [int]$forward.message_id })[0]
    Assert-True ($null -ne $forwardMessage) 'recipient conversation contains the forwarded message'
    Assert-True ([int]$forwardMessage.forward_bundle_id -eq [int]$forward.forward_bundle_id) 'message list hydrates structured forward metadata'
    Assert-True ([bool]$forwardMessage.forward_bundle.read_only) 'forward card is marked read-only'

    $snapshot = Invoke-Api GET "/api/user/message-forwards/$($forward.forward_bundle_id)" $headersB
    Assert-True ([bool]$snapshot.forward.read_only) 'recipient sees a read-only snapshot'
    Assert-True ([bool]$snapshot.forward.permissions.search -and [bool]$snapshot.forward.permissions.copy) 'snapshot permits search and copy'
    Assert-True (-not [bool]$snapshot.forward.permissions.create -and -not [bool]$snapshot.forward.permissions.update -and -not [bool]$snapshot.forward.permissions.delete) 'snapshot forbids create, update and delete'
    Assert-True ([int]$snapshot.forward.item_count -eq 3 -and @($snapshot.forward.items).Count -eq 3) 'snapshot preserves all selected messages'
    Assert-True (@($snapshot.forward.items | Where-Object { $_.PSObject.Properties.Name -contains 'snapshot_mine' }).Count -eq 3) 'snapshot preserves sender perspective for every item'
    Assert-True (@($snapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$match.message_id })[0].attachments.Count -eq 4) 'snapshot preserves stacked image, video and voice attachments'

    $selectiveForward = Invoke-Api POST '/api/user/message-forwards' $headersA @{
        source_type = 'private'; source_id = $conversationId
        message_ids = @([int]$before.message_id, [int]$match.message_id, [int]$after.message_id)
        target_type = 'private'; target_id = $userB
        anonymity_mode = 'selected'; anonymous_sender_keys = @("user:$userB")
        tags = @('聊天记录', '部分匿名')
    }
    $selectiveSnapshot = Invoke-Api GET "/api/user/message-forwards/$($selectiveForward.forward_bundle_id)" $headersB
    $selectiveAnonymous = @($selectiveSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$match.message_id })[0]
    $selectiveVisible = @($selectiveSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$before.message_id })[0]
    Assert-True ([bool]$selectiveAnonymous.anonymous -and [string]$selectiveAnonymous.sender_name -eq '默认用户1') 'selective anonymity hides only the chosen sender with a stable default alias'
    Assert-True ([int]$selectiveAnonymous.sender_id -eq 0 -and [string]$selectiveAnonymous.sender_avatar -eq '') 'selective anonymity removes the chosen sender id and avatar'
    Assert-True (-not [bool]$selectiveVisible.anonymous -and [string]$selectiveVisible.sender_name -eq '搜索甲') 'selective anonymity keeps an unselected sender visible'

    $nestedFullForward = Invoke-Api POST '/api/user/message-forwards' $headersA @{
        source_type = 'private'; source_id = $conversationId
        message_ids = @([int]$forward.message_id, [int]$before.message_id, [int]$match.message_id)
        target_type = 'private'; target_id = $userB
        anonymity_mode = 'full'; anonymous_sender_keys = @()
        tags = @('聊天记录', '嵌套快照', '全部匿名')
    }
    $nestedSnapshot = Invoke-Api GET "/api/user/message-forwards/$($nestedFullForward.forward_bundle_id)" $headersB
    $nestedCard = @($nestedSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$forward.message_id })[0]
    Assert-True (@($nestedCard.forward_bundle.items).Count -eq 3) 'a forwarded snapshot recursively embeds the original snapshot items'
    $outerHumanItems = @($nestedSnapshot.forward.items | Where-Object { [string]$_.sender_type -ne 'system' })
    Assert-True (@($outerHumanItems | Where-Object { -not [bool]$_.anonymous }).Count -eq 0) 'full anonymity covers every human sender selected in the current forwarding layer'
    Assert-True (@($outerHumanItems | Where-Object { [int]$_.sender_id -ne 0 -or -not [string]::IsNullOrEmpty([string]$_.sender_avatar) }).Count -eq 0) 'current-layer anonymity removes ids and avatars from current-layer items'
    $senderAOuter = @($nestedSnapshot.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$before.message_id })[0]
    $senderANested = @($nestedCard.forward_bundle.items | Where-Object { [int]$_.source_message_id -eq [int]$before.message_id })[0]
    $senderBNested = @($nestedCard.forward_bundle.items | Where-Object { [int]$_.source_message_id -eq [int]$match.message_id })[0]
    Assert-True ([bool]$senderAOuter.anonymous -and [string]$senderAOuter.sender_name -like '默认用户*') 'the current-layer sender is anonymous'
    Assert-True (-not [bool]$senderANested.anonymous -and [string]$senderANested.sender_name -eq '搜索甲') 'nested snapshots keep their independently stored sender identity state'
    Assert-True (-not [bool]$senderBNested.anonymous -and [string]$senderBNested.sender_name -eq '搜索乙') 'outer anonymity does not overwrite another sender inside a nested snapshot'
    Assert-True ([int]$senderANested.sender_id -gt 0 -and [int]$senderBNested.sender_id -gt 0) 'nested sender ids remain available when the nested snapshot was created without anonymity'
    Assert-True (@($senderBNested.attachments).Count -eq 4) 'nested snapshots retain image, video and voice media while the outer layer is anonymous'
    Assert-True (@($senderBNested.attachments | Where-Object { [string]$_.media_type -eq 'audio' }).Count -eq 1) 'nested voice content remains playable after outer-layer anonymization'
    $nestedJson = $nestedSnapshot.forward | ConvertTo-Json -Depth 60 -Compress
    Assert-True ($nestedJson -like '*搜索甲*' -and $nestedJson -like '*搜索乙*') 'nested snapshot identity is preserved instead of being recursively anonymized'

    Invoke-Api POST "/api/user/messages/$($match.message_id)/recall" $headersB @{} | Out-Null
    $snapshotAfterRecall = Invoke-Api GET "/api/user/message-forwards/$($forward.forward_bundle_id)" $headersA
    $savedMatch = @($snapshotAfterRecall.forward.items | Where-Object { [int]$_.source_message_id -eq [int]$match.message_id })[0]
    Assert-True ([string]$savedMatch.content -like "*$needle*") 'immutable snapshot keeps original text after source recall'
    Assert-True (@($savedMatch.attachments).Count -eq 4) 'immutable snapshot keeps original media after source recall'

    $stream = [IO.File]::Open($fixture, [IO.FileMode]::Create, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try { $stream.SetLength(1572864) } finally { $stream.Dispose() }
    $tooLarge = Invoke-MultipartUpload ([string]$a.access_token) $appKey $fixture
    Assert-True ([int]$tooLarge.code -ne 1) 'image larger than the configured limit is rejected'
    Assert-True ([string]$tooLarge.msg -like '*图片大小超出*') 'oversized image returns a clear Chinese explanation'
    Assert-True ([int]$tooLarge.data.max_bytes -eq 1048576) 'oversized response states the applied byte limit'

    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        upload_image_max_bytes = 2097152
    } } | Out-Null
    $accepted = Invoke-MultipartUpload ([string]$a.access_token) $appKey $fixture
    Assert-True ([int]$accepted.code -eq 1) 'the same image succeeds after the administrator raises the limit'
    Assert-True ([int]$accepted.data.size_bytes -eq 1572864) 'successful upload records the real file size'

    Write-Host "Chat/media/forward/search smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Force }
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for app $appId`: $($_.Exception.Message)" }
    }
    if ($quotaRaised) {
        try {
            Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
                entitlement_type = 'app_quota'; operation = 'set'; amount = $originalQuota
                remark = '自动化测试结束，恢复原应用名额'
            } | Out-Null
        }
        catch { Write-Warning "Failed to restore admin app quota: $($_.Exception.Message)" }
    }
}
