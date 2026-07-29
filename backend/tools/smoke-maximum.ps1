param(
    [string]$BaseUrl = 'http://127.0.0.1:8788'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0

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
        $params.Body = $Body | ConvertTo-Json -Depth 20 -Compress
    }
    try {
        $response = Invoke-RestMethod @params
    } catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) {
        throw "$Method $Path returned code=$($response.code): $($response.msg)"
    }
    $script:Checks++
    return $response.data
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function Assert-ApiFailure {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers,
        [int]$ExpectedCode
    )
    try {
        Invoke-RestMethod -Method $Method -Uri "$BaseUrl$Path" -Headers $Headers -UseBasicParsing | Out-Null
    } catch {
        $actualCode = 0
        $message = $_.Exception.Message
        if (-not [string]::IsNullOrWhiteSpace($_.ErrorDetails.Message)) {
            $payload = $_.ErrorDetails.Message | ConvertFrom-Json
            $actualCode = [int]$payload.code
            $message = [string]$payload.msg
        } elseif ($null -ne $_.Exception.Response) {
            $actualCode = [int]$_.Exception.Response.StatusCode
        }
        if ($actualCode -ne $ExpectedCode) {
            throw "Expected code $ExpectedCode from $Method $Path, got $actualCode`: $message"
        }
        $script:Checks++
        return
    }
    throw "Expected $Method $Path to fail with code $ExpectedCode"
}

function Get-Signature {
    param([hashtable]$Payload, [string]$Secret)
    $pairs = @()
    foreach ($key in ($Payload.Keys | Where-Object { $_ -ne 'sign' } | Sort-Object)) {
        $pairs += "$key=$($Payload[$key])"
    }
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    try {
        $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($Secret)
        $hash = $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes(($pairs -join '&')))
        return ([System.BitConverter]::ToString($hash)).Replace('-', '').ToLowerInvariant()
    } finally {
        $hmac.Dispose()
    }
}

function Invoke-Upload {
    param([string]$Path, [hashtable]$Headers, [string]$FilePath)
    Add-Type -AssemblyName System.Net.Http
    $client = New-Object System.Net.Http.HttpClient
    $multipart = New-Object System.Net.Http.MultipartFormDataContent
    try {
        $client.DefaultRequestHeaders.Authorization = New-Object System.Net.Http.Headers.AuthenticationHeaderValue('Bearer', ($Headers.Authorization -replace '^Bearer\s+', ''))
        $client.DefaultRequestHeaders.Add('X-App-Key', $Headers['X-App-Key'])
        $bytes = [System.IO.File]::ReadAllBytes($FilePath)
        $fileContent = New-Object System.Net.Http.ByteArrayContent -ArgumentList @(,$bytes)
        $fileContent.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue('text/plain')
        $multipart.Add($fileContent, 'file', [System.IO.Path]::GetFileName($FilePath))
        $multipart.Add((New-Object System.Net.Http.StringContent('smoke')), 'scene')
        $httpResponse = $client.PostAsync("$BaseUrl$Path", $multipart).Result
        $json = $httpResponse.Content.ReadAsStringAsync().Result | ConvertFrom-Json
        if (-not $httpResponse.IsSuccessStatusCode -or $json.code -ne 1) {
            throw "POST $Path upload failed: $($json.msg)"
        }
        $script:Checks++
        return $json.data
    } finally {
        $multipart.Dispose()
        $client.Dispose()
    }
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$health = Invoke-Api GET '/api/health'
Assert-True ($health.database -eq 'connected') 'database health'

$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'; password = '123456'; device = 'maximum-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

# A previously interrupted run must not consume the next run's app quota.
$staleApps = Invoke-Api GET '/api/admin/apps?keyword=Maximum%20Smoke&limit=100' $adminHeaders
foreach ($staleApp in @($staleApps.items)) {
    if ([string]$staleApp.name -like 'Maximum Smoke *') {
        Invoke-Api DELETE "/api/admin/apps/$([int]$staleApp.id)" $adminHeaders @{ confirm = 'DELETE' } | Out-Null
    }
}

$appResult = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
    name = "Maximum Smoke $suffix"; description = 'Maximum-loop automated verification'
}
$appId = [int]$appResult.app.id
$appKey = [string]$appResult.app.app_key
Assert-True ($appId -gt 0 -and $appKey.Length -gt 5) 'application creation'

Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
    settings = @{
        initial_document_credit = 50
        resource_submit_audit = $true
        resource_user_submit_enabled = $true
        forum_post_audit = $false
        wallet_transfer_enabled = $true
        wallet_transfer_max = 50000
        registration_phone_enabled = $true
        registration_phone_required = $false
    }
} | Out-Null
$features = Invoke-Api GET "/api/admin/apps/$appId/features" $adminHeaders
Assert-True (@($features.features.PSObject.Properties).Count -ge 20) 'default feature flags'

$accountA = "alice_$suffix"
$accountB = "bob_$suffix"
$phoneB = '139' + ([string](10000000 + ([long]$suffix % 90000000)))
$registerA = Invoke-Api POST '/api/user/register' @{} @{
    app_key = $appKey; account = $accountA; password = '123456'; password_confirmation = '123456'; nickname = 'Alice'; device = 'maximum-smoke'
}
$headersA = @{ Authorization = "Bearer $($registerA.access_token)"; 'X-App-Key' = $appKey }
$meA = Invoke-Api GET '/api/user/me' $headersA
$userA = [int]$meA.user.id

$registerB = Invoke-Api POST '/api/user/register' @{} @{
    app_key = $appKey; account = $accountB; password = '123456'; password_confirmation = '123456'; nickname = 'Bob'; phone = $phoneB; device = 'maximum-smoke'
}
$headersB = @{ Authorization = "Bearer $($registerB.access_token)"; 'X-App-Key' = $appKey }
$meB = Invoke-Api GET '/api/user/me' $headersB
$userB = [int]$meB.user.id

$refreshA = Invoke-Api POST '/api/user/token/refresh' @{ 'X-App-Key' = $appKey } @{
    refresh_token = $registerA.refresh_token
}
$headersA.Authorization = "Bearer $($refreshA.access_token)"
Invoke-Api PUT '/api/user/profile' $headersA @{ nickname = 'Alice Updated'; qq = '10001'; signature = 'maximum loop' } | Out-Null
Invoke-Api POST '/api/user/sign' $headersA @{} | Out-Null
Invoke-Api PUT "/api/admin/apps/$appId/users/$userA/wallet" $adminHeaders @{ asset_type = 'balance'; change_value = 2000; remark = 'smoke seed' } | Out-Null
Invoke-Api PUT "/api/admin/apps/$appId/users/$userB/wallet" $adminHeaders @{ asset_type = 'balance'; change_value = 1000; remark = 'smoke seed' } | Out-Null

$tag = Invoke-Api POST "/api/admin/apps/$appId/user-tags" $adminHeaders @{ name = "VIP-$suffix"; color = '#d97706' }
Invoke-Api POST "/api/admin/apps/$appId/users/$userA/tags" $adminHeaders @{ tag_ids = @([int]$tag.tag_id) } | Out-Null
$tagged = Invoke-Api GET "/api/admin/apps/$appId/users?tag_id=$($tag.tag_id)" $adminHeaders
Assert-True ($tagged.pagination.total -ge 1) 'tagged user query'

$folder = Invoke-Api POST '/api/user/note-folders' $headersA @{ name = 'Smoke Folder' }
$document = Invoke-Api POST '/api/user/notes' $headersA @{
    folder_id = [int]$folder.folder_id; title = 'Maximum Document'; content = 'first revision'; content_type = 'markdown'
}
$documentId = [int]$document.document.id
Invoke-Api PUT "/api/user/notes/$documentId" $headersA @{ title = 'Maximum Document Updated'; content = 'second revision' } | Out-Null
$share = Invoke-Api POST "/api/user/notes/$documentId/share" $headersA @{ password = 'share123' }
$shared = Invoke-Api GET "/api/public/note-shares/$($share.share.share_code)?password=share123"
Assert-True ($shared.document.title -eq 'Maximum Document Updated') 'public document share'

$adminDocument = Invoke-Api POST "/api/admin/apps/$appId/documents" $adminHeaders @{
    title = 'Administrator Document'; content = 'admin first revision'; content_type = 'markdown'; is_public = $false
}
$adminDocumentId = [int]$adminDocument.document.id
Assert-True ($adminDocument.document.owner_type -eq 'admin' -and $null -eq $adminDocument.document.user_id) 'admin document ownership'
Invoke-Api GET "/api/admin/apps/$appId/documents/$adminDocumentId" $adminHeaders | Out-Null
$adminDocumentUpdated = Invoke-Api PUT "/api/admin/apps/$appId/documents/$adminDocumentId" $adminHeaders @{
    title = 'Administrator Document Updated'; content = 'admin second revision'
}
Assert-True ($adminDocumentUpdated.document.version_no -eq 2) 'admin document version update'
$adminDocuments = Invoke-Api GET "/api/admin/apps/$appId/documents?owner_type=admin&keyword=Administrator" $adminHeaders
Assert-True ($adminDocuments.pagination.total -ge 1) 'admin document list and search'
Invoke-Api DELETE "/api/admin/apps/$appId/documents/$adminDocumentId" $adminHeaders @{ reason = 'recycle test' } | Out-Null
Invoke-Api POST "/api/admin/apps/$appId/documents/$adminDocumentId/restore" $adminHeaders @{} | Out-Null

Invoke-Api POST "/api/admin/apps/$appId/notices" $adminHeaders @{ title = 'Maximum Notice'; content = 'notice body'; type = 'notice'; is_popup = $true } | Out-Null
Invoke-Api POST "/api/admin/apps/$appId/banners" $adminHeaders @{ title = 'Home Banner'; image_url = 'https://example.com/banner.png'; position = 'home'; sort_order = 10 } | Out-Null
Invoke-Api PUT "/api/admin/apps/$appId/remote-configs" $adminHeaders @{ config_key = 'smoke.enabled'; config_value = $true; value_type = 'bool' } | Out-Null
Invoke-Api PUT "/api/admin/apps/$appId/versions" $adminHeaders @{
    version_name = '2.0.0'; version_code = [int]($suffix % 2000000000); apk_url = 'https://example.com/app.apk'; update_content = 'maximum loop'; force_update = $false
} | Out-Null
$bootstrap = Invoke-Api GET "/api/public/bootstrap?app_key=$appKey"
Assert-True (@($bootstrap.banners).Count -ge 1 -and @($bootstrap.features.PSObject.Properties).Count -ge 20) 'public bootstrap aggregation'

$resourceCategory = Invoke-Api POST "/api/admin/apps/$appId/resource-categories" $adminHeaders @{ name = "Resources-$suffix" }
$resourcePolicy = Invoke-Api GET '/api/user/resource-submission-policy' $headersA
Assert-True ($resourcePolicy.enabled -eq $true -and $resourcePolicy.audit_required -eq $true) 'resource submission policy enabled'
Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
    settings = @{ resource_user_submit_enabled = $false }
} | Out-Null
Assert-ApiFailure POST '/api/user/resources' $headersA 403
$resourcePolicyDisabled = Invoke-Api GET '/api/user/resource-submission-policy' $headersA
Assert-True ($resourcePolicyDisabled.enabled -eq $false) 'resource submission policy disabled'
Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
    settings = @{ resource_user_submit_enabled = $true }
} | Out-Null
$resource = Invoke-Api POST '/api/user/resources' $headersA @{
    category_id = [int]$resourceCategory.category_id; title = 'Paid Resource'; description = 'resource body'; download_url = 'https://example.com/resource.zip'; price_balance = 100
}
$resourceId = [int]$resource.resource_id
Invoke-Api PUT "/api/admin/apps/$appId/resources/$resourceId/audit" $adminHeaders @{ audit_status = 'approved' } | Out-Null
$resourceBuy = Invoke-Api POST "/api/user/resources/$resourceId/buy" $headersB @{}
Assert-True ($resourceBuy.download_url -eq 'https://example.com/resource.zip') 'paid resource delivery'
Invoke-Api POST "/api/user/resources/$resourceId/comments" $headersB @{ content = 'resource comment' } | Out-Null
Invoke-Api POST "/api/user/resources/$resourceId/rating" $headersB @{ score = 5 } | Out-Null

$storeCategory = Invoke-Api POST "/api/admin/apps/$appId/store-categories" $adminHeaders @{ name = "Apps-$suffix" }
$storeApp = Invoke-Api POST "/api/admin/apps/$appId/store-apps" $adminHeaders @{
    category_id = [int]$storeCategory.category_id; name = 'Smoke App'; package_name = "com.yiyunying.smoke$suffix";
    version_name = '1.0.0'; version_code = 1; apk_url = 'https://example.com/smoke.apk'; images = @('https://example.com/1.png')
}
Invoke-Api GET "/api/user/store-apps/$($storeApp.store_app_id)" $headersA | Out-Null

$plate = Invoke-Api POST "/api/admin/apps/$appId/forum-plates" $adminHeaders @{ name = "Forum-$suffix"; description = 'forum plate' }
$post = Invoke-Api POST '/api/user/forum-posts' $headersA @{ plate_id = [int]$plate.plate_id; title = 'Maximum Post'; content = 'forum body'; images = @() }
$postId = [int]$post.post_id
Invoke-Api POST "/api/user/forum-posts/$postId/comments" $headersB @{ content = 'forum comment' } | Out-Null
Invoke-Api POST "/api/user/forum-posts/$postId/like" $headersB @{} | Out-Null
Invoke-Api POST "/api/user/forum-posts/$postId/favorite" $headersB @{} | Out-Null
Invoke-Api PUT "/api/admin/apps/$appId/forum-posts/$postId/top" $adminHeaders @{ enabled = $true } | Out-Null

$bountyCategory = Invoke-Api POST "/api/admin/apps/$appId/bounty-categories" $adminHeaders @{
    name = "Bounty-$suffix"; description = 'maximum-loop bounty category'; sort_order = 20
}
$bountyCategoryId = [int]$bountyCategory.category_id
$visibleBountyCategories = Invoke-Api GET '/api/user/bounty-categories' $headersA
Assert-True (@($visibleBountyCategories.items | Where-Object { [int]$_.id -eq $bountyCategoryId }).Count -eq 1) 'user can browse bounty categories'
$bountyCategoryRequest = Invoke-Api POST '/api/user/bounty-category-requests' $headersA @{
    name = "Requested-Bounty-$suffix"; description = 'requested from the visual bounty publisher'; reason = 'smoke review'
}
$bountyCategoryRequestId = [int]$bountyCategoryRequest.request_id
$pendingBountyCategoryRequests = Invoke-Api GET "/api/admin/apps/$appId/bounty-category-requests?status=pending" $adminHeaders
Assert-True (@($pendingBountyCategoryRequests.items | Where-Object { [int]$_.id -eq $bountyCategoryRequestId }).Count -eq 1) 'admin can list bounty category requests'
$reviewedBountyCategory = Invoke-Api POST "/api/admin/apps/$appId/bounty-category-requests/$bountyCategoryRequestId/review" $adminHeaders @{
    decision = 'approve'; review_comment = 'maximum-loop review approved'
}
Assert-True ([int]$reviewedBountyCategory.category_id -gt 0 -and $reviewedBountyCategory.status -eq 'approved') 'admin can approve bounty category request'
$bountyAttachments = @()
$bountyAttachments += @{
    type = 'image'
    url = 'https://example.com/bounty.png'
    name = 'bounty.png'
    mime_type = 'image/png'
    size = 1024
}
$bounty = Invoke-Api POST '/api/user/bounties' $headersA @{
    category_id = $bountyCategoryId
    title = 'Maximum Bounty'
    description = 'bounty body with visual attachment'
    reward_balance = 25
    requirements = @('submit a verifiable result')
    attachments = $bountyAttachments
    deadline_at = (Get-Date).AddDays(7).ToString('yyyy-MM-dd HH:mm:ss')
}
$bountyId = [int]$bounty.bounty_id
Invoke-Api PUT "/api/admin/apps/$appId/bounties/$bountyId/audit" $adminHeaders @{ audit_status = 'approved'; reason = 'content verified' } | Out-Null
$userBountyDetail = Invoke-Api GET "/api/user/bounties/$bountyId" $headersB
Assert-True ($userBountyDetail.bounty.category_name -eq "Bounty-$suffix" -and @($userBountyDetail.bounty.attachments).Count -eq 1) 'user bounty detail contains category and visual attachments'
Assert-True (-not ($userBountyDetail.bounty.PSObject.Properties.Name -contains 'attachments_json')) 'user bounty detail hides raw attachment JSON'
$adminBountyDetail = Invoke-Api GET "/api/admin/apps/$appId/bounties/$bountyId" $adminHeaders
Assert-True (@($adminBountyDetail.bounty.attachments).Count -eq 1 -and -not ($adminBountyDetail.bounty.PSObject.Properties.Name -contains 'requirements_json')) 'admin bounty detail is visual data instead of raw JSON'
$bountySubmissionAttachments = @()
$bountySubmissionAttachments += @{
    type = 'file'
    url = 'https://example.com/result.pdf'
    name = 'result.pdf'
    mime_type = 'application/pdf'
    size = 2048
}
$bountySubmission = Invoke-Api POST "/api/user/bounties/$bountyId/submissions" $headersB @{
    content = 'completed bounty work'
    attachments = $bountySubmissionAttachments
}
$bountyAward = Invoke-Api POST "/api/user/bounties/$bountyId/award" $headersA @{ submission_id = [int]$bountySubmission.submission_id }
Assert-True ([int]$bountyAward.winner_user_id -eq $userB -and [int]$bountyAward.reward_balance -eq 25) 'bounty submission can be reviewed and awarded'

$privateMessage = Invoke-Api POST '/api/user/messages/private' $headersA @{ to_user_id = $userB; content = 'hello Bob' }
Invoke-Api GET "/api/user/conversations/$($privateMessage.conversation_id)/messages" $headersB | Out-Null
$friendRequest = Invoke-Api POST '/api/user/friends/requests' $headersA @{ to_user_id = $userB; message = 'add friend' }
Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersB @{} | Out-Null
$friendsB = Invoke-Api GET '/api/user/friends' $headersB
Assert-True ($friendsB.items.Count -ge 1) 'friend acceptance'

$adminRoomResult = Invoke-Api POST "/api/admin/apps/$appId/chat-rooms" $adminHeaders @{
    name = "Admin-Room-$suffix"; join_mode = 'open'; max_members = 50; announcement = 'admin managed group'
}
$adminRoomId = [int]$adminRoomResult.room.id
Invoke-Api POST "/api/user/chat-rooms/$adminRoomId/join" $headersA @{} | Out-Null
Invoke-Api POST "/api/user/chat-rooms/$adminRoomId/join" $headersB @{} | Out-Null
$roomMessageA = Invoke-Api POST "/api/user/chat-rooms/$adminRoomId/messages" $headersA @{ content = 'first user room message' }
$roomMessageB = Invoke-Api POST "/api/user/chat-rooms/$adminRoomId/messages" $headersB @{ content = 'second user room message' }
$adminRoomMessages = Invoke-Api GET "/api/admin/apps/$appId/chat-rooms/$adminRoomId/messages" $adminHeaders
Assert-True ($adminRoomMessages.items.Count -ge 2) 'admin can audit user group messages'
Invoke-Api DELETE "/api/admin/apps/$appId/chat-rooms/$adminRoomId/messages/$($roomMessageB.message_id)" $adminHeaders @{} | Out-Null
$roomMessages = Invoke-Api GET "/api/user/chat-rooms/$adminRoomId/messages" $headersB
Assert-True ($roomMessages.items.Count -ge 1) 'admin can moderate user-only group messages'

$userRoomResult = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
    name = "User-Room-$suffix"; join_mode = 'approval'; max_members = 20; announcement = 'user managed group'
}
$userRoomId = [int]$userRoomResult.room.id
$joinResult = Invoke-Api POST "/api/user/chat-rooms/$userRoomId/join" $headersB @{ message = 'please approve' }
Assert-True ($joinResult.pending -eq $true) 'approval join request'
$joinRequests = Invoke-Api GET "/api/user/chat-rooms/$userRoomId/join-requests" $headersA
$joinRequestId = [int]$joinRequests.items[0].id
Invoke-Api POST "/api/user/chat-rooms/$userRoomId/join-requests/$joinRequestId/approve" $headersA @{} | Out-Null
Invoke-Api POST "/api/user/chat-rooms/$userRoomId/messages" $headersB @{ content = 'approved member message' } | Out-Null
Invoke-Api PUT "/api/user/chat-rooms/$userRoomId/members/$userB" $headersA @{ role = 'admin' } | Out-Null
Invoke-Api POST "/api/user/chat-rooms/$userRoomId/transfer" $headersA @{ new_owner_user_id = $userB } | Out-Null
Invoke-Api POST "/api/user/chat-rooms/$userRoomId/leave" $headersA @{} | Out-Null
Invoke-Api DELETE "/api/user/chat-rooms/$userRoomId" $headersB @{} | Out-Null

$service = Invoke-Api POST '/api/user/service/messages' $headersB @{ subject = 'Need help'; content = 'service question' }
Invoke-Api POST "/api/admin/apps/$appId/service-sessions/$($service.session_id)/reply" $adminHeaders @{ content = 'service answer' } | Out-Null
$serviceMessages = Invoke-Api GET '/api/user/service/messages' $headersB
Assert-True ($serviceMessages.items.Count -ge 2) 'customer service conversation'

Invoke-Api POST "/api/admin/apps/$appId/system-messages" $adminHeaders @{ target_type = 'all'; title = 'System'; content = 'system message' } | Out-Null
$unread = Invoke-Api GET '/api/user/messages/unread' $headersB
Assert-True ($unread.system_notification -ge 1 -and $unread.notification_total -ge 1) 'system notification unread count'
Assert-True ($unread.total -eq ($unread.private + $unread.group + $unread.service)) 'chat unread excludes business notifications'

$batch = Invoke-Api POST "/api/admin/apps/$appId/card-batches" $adminHeaders @{
    name = "Cards-$suffix"; total_count = 1; max_use = 1; value_json = @{ balance = 100; document_credit = 5; vip_days = 1 }
}
Invoke-Api POST '/api/user/cards/redeem' $headersB @{ card_code = $batch.codes[0] } | Out-Null
Invoke-Api GET "/api/admin/apps/$appId/card-redeem-logs" $adminHeaders | Out-Null

$goods = Invoke-Api POST "/api/admin/apps/$appId/shop-goods" $adminHeaders @{
    name = 'Balance Goods'; description = 'shop test'; price_balance = 50; price_money = 0; stock = 20; status = $true
}
$shopBuy = Invoke-Api POST "/api/user/shop-goods/$($goods.goods_id)/buy" $headersB @{ quantity = 2 }
Assert-True ($shopBuy.cost_balance -eq 100) 'balance shop order'
$orderList = Invoke-Api GET '/api/user/orders?limit=100' $headersB
$listedOrder = $orderList.items | Where-Object {
    $_.order_source -eq 'shop' -and [int]$_.id -eq [int]$shopBuy.order_id
} | Select-Object -First 1
Assert-True ($null -ne $listedOrder) 'shop order appears in my orders'
Assert-True (-not [string]::IsNullOrWhiteSpace([string]$listedOrder.status_text)) 'shop order has readable status'
$orderDetail = Invoke-Api GET "/api/user/orders/shop/$($shopBuy.order_id)" $headersB
Assert-True ([int]$orderDetail.item.id -eq [int]$shopBuy.order_id) 'shop order detail'
Assert-True ($orderDetail.item.events.Count -ge 2) 'shop order tracking events'

$packet = Invoke-Api POST '/api/user/red-packets' $headersA @{
    packet_type = 'random'; distribution_mode = 'count_split'; eligibility_mode = 'selected'
    delivery_scope = 'private'; context_user_id = $userB; to_user_ids = @($userB)
    include_sender = $true; total_amount = 100; total_count = 2; message = 'smoke packet'
}
$claim = Invoke-Api POST "/api/user/red-packets/$($packet.packet_id)/claim" $headersB @{}
Assert-True ($claim.amount -gt 0) 'red packet claim'

Invoke-Api POST "/api/admin/apps/$appId/lottery-prizes" $adminHeaders @{
    name = 'Lottery Balance'; prize_type = 'balance'; value_json = @{ balance = 10 }; weight = 1; stock = 100; status = $true
} | Out-Null
$draw = Invoke-Api POST '/api/user/lottery/draw' $headersB @{}
Assert-True ($draw.rewards.balance -eq 10) 'lottery reward'

$vote = Invoke-Api POST "/api/admin/apps/$appId/votes" $adminHeaders @{
    title = 'Smoke Vote'; options = @('A', 'B'); multi_select = $false
}
$voteList = Invoke-Api GET '/api/user/votes' $headersB
$voteItem = $voteList.items | Where-Object { $_.id -eq $vote.vote_id } | Select-Object -First 1
Invoke-Api POST "/api/user/votes/$($vote.vote_id)/submit" $headersB @{ option_ids = @([int]$voteItem.options[0].id) } | Out-Null

$secret = "smoke-secret-$suffix"
Invoke-Api PUT "/api/admin/apps/$appId/payment-channels" $adminHeaders @{
    channel_code = 'demo'; name = 'Demo Payment'; enabled = $true; config_json = @{ secret = $secret; gateway_url = '' }
} | Out-Null
$cashGoods = Invoke-Api POST "/api/admin/apps/$appId/shop-goods" $adminHeaders @{
    name = 'Cash Goods'; description = 'cash order test'; price_balance = 0; price_money = 5.25; stock = 20; status = $true
}
$order = Invoke-Api POST "/api/user/shop-goods/$($cashGoods.goods_id)/buy" $headersB @{
    quantity = 1; pay_channel = 'demo'
}
Assert-True ($order.payment_required -eq $true) 'cash purchase creates order automatically'
$callback = @{
    app_key = $appKey
    order_no = [string]$order.order_no
    amount = '5.25'
    status = 'paid'
    timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    trade_no = "TRADE-$suffix"
}
$callback.sign = Get-Signature $callback $secret
$paid = Invoke-Api POST '/api/public/payment/callback/demo' @{} $callback
Assert-True (-not $paid.idempotent) 'first payment callback'
$paidAgain = Invoke-Api POST '/api/public/payment/callback/demo' @{} $callback
Assert-True ($paidAgain.idempotent) 'idempotent payment callback'

$remoteFile = Invoke-Api POST "/api/admin/apps/$appId/remote-files" $adminHeaders @{
    name = 'public.txt'; content = 'remote content'; file_type = 'file'; is_public = $true; mime_type = 'text/plain'
}
$publicFile = Invoke-Api GET "/api/public/remote-files/$($remoteFile.file_id)?app_key=$appKey"
Assert-True ($publicFile.file.content -eq 'remote content') 'public remote file'

$uploadPath = Join-Path $env:TEMP "yiyunying-upload-$suffix.txt"
[System.IO.File]::WriteAllText($uploadPath, 'upload smoke', [System.Text.UTF8Encoding]::new($false))
try {
    $upload = Invoke-Upload '/api/user/uploads' $headersB $uploadPath
    Assert-True ($upload.upload_id -gt 0) 'multipart upload'
} finally {
    Remove-Item -LiteralPath $uploadPath -Force -ErrorAction SilentlyContinue
}

$feedback = Invoke-Api POST '/api/user/feedbacks' $headersB @{ type = 'bug'; title = 'Smoke feedback'; content = 'feedback body'; images = @() }
Invoke-Api POST "/api/admin/apps/$appId/feedbacks/$($feedback.feedback_id)/reply" $adminHeaders @{ reply_content = 'resolved'; status = 'resolved' } | Out-Null
$feedbackList = Invoke-Api GET '/api/user/feedbacks' $headersB
Assert-True (($feedbackList.items | Where-Object { $_.id -eq $feedback.feedback_id }).status -eq 'resolved') 'feedback reply'

Invoke-Api POST "/api/admin/apps/$appId/bot-qa" $adminHeaders @{ question = 'smoke question'; answer = 'smoke answer'; keywords = 'smoke'; status = $true } | Out-Null
$bot = Invoke-Api POST '/api/user/bot/ask' $headersB @{ question = 'smoke question' }
Assert-True ($bot.answer -eq 'smoke answer') 'bot answer'

Invoke-Api POST '/api/user/wallet/transfer' $headersA @{ to_user_id = $userB; amount = 10 } | Out-Null
Invoke-Api GET "/api/admin/apps/$appId/statistics" $adminHeaders | Out-Null
Invoke-Api GET "/api/admin/apps/$appId/api-logs?actor_type=user" $adminHeaders | Out-Null
Invoke-Api GET "/api/admin/apps/$appId/operation-logs" $adminHeaders | Out-Null

Assert-ApiFailure GET '/api/user/me' @{ Authorization = $headersB.Authorization; 'X-App-Key' = 'yiyunying-demo' } 403

$captcha = Invoke-Api POST '/api/public/captcha' @{} @{ app_key = $appKey; scene = 'password_reset'; account = $accountB }
if ($captcha.question -notmatch '^(\d+)\s*([+-])\s*(\d+)\s*=') {
    throw "Unexpected captcha question: $($captcha.question)"
}
$captchaAnswer = if ($Matches[2] -eq '+') { [int]$Matches[1] + [int]$Matches[3] } else { [int]$Matches[1] - [int]$Matches[3] }
Invoke-Api POST '/api/user/password/reset' @{} @{
    app_key = $appKey; account = $accountB; email_or_phone = $phoneB; code = [string]$captchaAnswer; new_password = '654321'
} | Out-Null
$reloginB = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountB; password = '654321' }
Assert-True ($reloginB.access_token.Length -gt 20) 'captcha password reset and relogin'

Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null

Write-Host 'Yiyunying maximum-loop smoke test passed.'
Write-Host "checks=$script:Checks"
Write-Host "app_id=$appId"
Write-Host "app_key=$appKey"
Write-Host "users=$accountA,$accountB"
