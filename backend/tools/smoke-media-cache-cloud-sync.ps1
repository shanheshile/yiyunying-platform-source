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

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$quotaRaised = $false
$root = Invoke-Api POST '/api/platform/login' @{} @{ account = 'root'; password = '123456'; device = 'cloud-sync-smoke' }
$rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
$admin = Invoke-Api POST '/api/admin/login' @{} @{ account = 'admin'; password = '123456'; device = 'cloud-sync-smoke' }
$adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
$adminId = [int]$admin.admin.id
$originalQuota = [int]$admin.admin.app_quota

try {
    Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
        entitlement_type = 'app_quota'; operation = 'set'; amount = ($originalQuota + 1); remark = 'test quota'
    } | Out-Null
    $quotaRaised = $true
    $created = Invoke-Api POST '/api/admin/apps' $adminHeaders @{ name = "Cloud sync test $suffix"; description = 'temporary' }
    $appId = [int]$created.app.id
    $appKey = [string]$created.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        upload_image_max_bytes = 104857600
        upload_video_max_bytes = 524288000
        upload_audio_max_bytes = 104857600
        upload_file_max_bytes = 209715200
        media_optimize_by_default = $true
        media_original_upload_enabled = $true
        sticker_optimize_enabled = $true
        cloud_chat_backup_enabled = $true
        cloud_chat_backup_vip_required = $false
        cloud_chat_backup_price = 0
        cloud_sticker_sync_enabled = $true
        cloud_sticker_sync_price = 0
        cloud_favorite_sync_enabled = $true
        cloud_favorite_sync_price = 0
        cloud_backup_max_items = 100
        auto_download_cache_enabled = $true
        auto_cache_allowed_categories = @('chat_record', 'profile', 'image', 'video', 'voice', 'audio', 'document', 'file', 'sticker')
        auto_cache_default_max_bytes = 536870912
        auto_cache_max_bytes_limit = 2147483648
        auto_cache_retention_days = 90
        auto_cache_network = 'wifi_mobile'
        auto_cache_force_wifi_only = $false
        video_autoplay_enabled = $true
        video_autoplay_network = 'wifi_mobile'
        video_autoplay_default_network = 'wifi'
    } } | Out-Null

    $a = Invoke-Api POST '/api/user/register' @{} @{ app_key = $appKey; account = "sync_a_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'A' }
    $b = Invoke-Api POST '/api/user/register' @{} @{ app_key = $appKey; account = "sync_b_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'B' }
    $headersA = @{ Authorization = "Bearer $($a.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($b.access_token)"; 'X-App-Key' = $appKey }
    $request = Invoke-Api POST '/api/user/friends/requests' $headersA @{ to_user_id = [int]$b.user.id; message = 'test' }
    Invoke-Api POST "/api/user/friends/requests/$($request.request_id)/accept" $headersB @{} | Out-Null

    $text = Invoke-Api POST '/api/user/messages/private' $headersA @{ to_user_id = [int]$b.user.id; content = "context_$suffix https://example.com/item" }
    $media = Invoke-Api POST '/api/user/messages/private' $headersB @{
        to_user_id = [int]$a.user.id; content = ''
        attachments = @(
            @{ media_type = 'image'; url = 'https://example.com/a.gif'; mime_type = 'image/gif'; size_bytes = 50000000 },
            @{ media_type = 'video'; url = 'https://example.com/a.mp4'; mime_type = 'video/mp4'; size_bytes = 100000000 }
        )
    }
    $conversationId = [int]$media.conversation_id

    $mediaSearch = Invoke-Api GET "/api/user/chat-search?scope_type=private&target_id=$conversationId&content_filter=media&limit=30" $headersA
    Assert-True ([int]$mediaSearch.match_count -eq 1) 'media filter finds the media-only message'
    Assert-True ([int]$mediaSearch.items[0].attachment_count -eq 2) 'media filter keeps both attachments'
    $linkSearch = Invoke-Api GET "/api/user/chat-search?scope_type=private&target_id=$conversationId&content_filter=link&limit=30" $headersA
    Assert-True ([int]$linkSearch.match_count -eq 1) 'link filter finds URL content'
    $history = Invoke-Api GET "/api/user/chat-search/history?scope_type=private&target_id=$conversationId" $headersA
    Assert-True (@($history.items | Where-Object { $_.content_filter -eq 'media' }).Count -eq 1) 'category search is stored with readable filter metadata'

    $pack = Invoke-Api POST '/api/user/sticker-packs' $headersA @{ name = "Pack $suffix" }
    $batch = Invoke-Api POST "/api/user/sticker-packs/$($pack.pack_id)/stickers/batch" $headersA @{ items = @(
        @{ name = 'one'; image_url = 'https://example.com/sticker-one.gif' },
        @{ name = 'two'; image_url = 'https://example.com/sticker-two.webp' },
        @{ name = 'duplicate'; image_url = 'https://example.com/sticker-one.gif' }
    ) }
    Assert-True ([int]$batch.created_count -eq 2 -and [int]$batch.skipped_count -eq 1) 'batch sticker add creates unique items and skips duplicates'
    $packs = Invoke-Api GET '/api/user/sticker-packs' $headersA
    $savedPack = @($packs.items | Where-Object { [int]$_.id -eq [int]$pack.pack_id })[0]
    Assert-True ([int]$savedPack.sticker_count -eq 2) 'sticker count is recalculated'
    $deleteId = [int]$savedPack.stickers[0].id
    $deleted = Invoke-Api DELETE "/api/user/sticker-packs/$($pack.pack_id)/stickers/batch" $headersA @{ sticker_ids = @($deleteId) }
    Assert-True ([int]$deleted.deleted_count -eq 1) 'batch sticker delete removes selected item'

    Invoke-Api POST "/api/user/messages/$($text.message_id)/state" $headersA @{ action = 'favorite' } | Out-Null
    $policy = Invoke-Api GET '/api/user/cloud-sync/policy' $headersA
    Assert-True ([int]$policy.media_cache_max_bytes -eq 536870912) 'cloud policy exposes local media cache limit'
    Assert-True ([bool]$policy.auto_cache_policy.enabled) 'automatic cache policy is enabled'
    Assert-True ([string]$policy.auto_cache_policy.network -eq 'wifi_mobile') 'automatic cache network policy is returned'
    Assert-True ([int]$policy.auto_cache_policy.default_max_bytes -eq 536870912) 'automatic cache default capacity is returned'
    Assert-True (@($policy.auto_cache_policy.allowed_categories).Count -eq 9) 'automatic cache category allow-list is returned'
    Assert-True ([bool]$policy.video_autoplay_policy.enabled) 'video autoplay policy is enabled'
    Assert-True ([string]$policy.video_autoplay_policy.network -eq 'wifi_mobile') 'video autoplay network policy is returned'
    Assert-True ([bool]$policy.items.chat.available) 'chat backup policy is available'

    $chatBackup = Invoke-Api POST '/api/user/cloud-sync/snapshots' $headersA @{
        data_type = 'chat'; scope_type = 'private'; target_id = $conversationId; title = 'chat test'
        filters = @{ message_ids = @([int]$text.message_id, [int]$media.message_id) }
    }
    Assert-True ([int]$chatBackup.item_count -eq 2 -and [bool]$chatBackup.read_only) 'chat snapshot stores two immutable records'
    $chatPull = Invoke-Api GET "/api/user/cloud-sync/snapshots/$($chatBackup.snapshot_id)" $headersA
    Assert-True ([bool]$chatPull.read_only -and @($chatPull.snapshot.items).Count -eq 2) 'chat snapshot can be pulled on another device'
    $chatRestore = Invoke-Api POST "/api/user/cloud-sync/snapshots/$($chatBackup.snapshot_id)/restore" $headersA @{}
    Assert-True ([string]$chatRestore.mode -ne '') 'chat restore returns read-only pull mode'

    $stickerBackup = Invoke-Api POST '/api/user/cloud-sync/snapshots' $headersA @{ data_type = 'stickers'; title = 'sticker test' }
    Assert-True ([int]$stickerBackup.item_count -eq 1) 'sticker snapshot stores remaining sticker'
    $favoriteBackup = Invoke-Api POST '/api/user/cloud-sync/snapshots' $headersA @{ data_type = 'favorites'; title = 'favorite test' }
    Assert-True ([int]$favoriteBackup.item_count -ge 1) 'favorite snapshot stores message favorite'
    $snapshots = Invoke-Api GET '/api/user/cloud-sync/snapshots' $headersA
    Assert-True (@($snapshots.items).Count -eq 3) 'snapshot list returns all data types'

    $cleanup = Invoke-Api POST '/api/user/chat-records/cleanup' $headersA @{
        scope_type = 'private'; target_id = $conversationId; filters = @{ message_ids = @([int]$text.message_id) }
    }
    Assert-True ([int]$cleanup.hidden_count -eq 1) 'selected chat record is hidden only for current account'
    $afterCleanup = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersA
    Assert-True (@($afterCleanup.items | Where-Object { [int]$_.id -eq [int]$text.message_id }).Count -eq 0) 'locally cleared record is absent for current account'
    $otherView = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersB
    Assert-True (@($otherView.items | Where-Object { [int]$_.id -eq [int]$text.message_id }).Count -eq 1) 'local cleanup does not delete the other account record'

    Invoke-Api DELETE "/api/user/cloud-sync/snapshots/$($chatBackup.snapshot_id)" $headersA @{} | Out-Null
    $afterDelete = Invoke-Api GET '/api/user/cloud-sync/snapshots?data_type=chat' $headersA
    Assert-True (@($afterDelete.items).Count -eq 0) 'deleted cloud snapshot is no longer listed'
    Write-Host "Media/cache/cloud-sync smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for app $appId" }
    }
    if ($quotaRaised) {
        try { Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{ entitlement_type = 'app_quota'; operation = 'set'; amount = $originalQuota; remark = 'restore quota' } | Out-Null }
        catch { Write-Warning 'Failed to restore quota' }
    }
}
