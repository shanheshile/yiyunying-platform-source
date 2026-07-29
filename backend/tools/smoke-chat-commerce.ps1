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
        $params.Body = $Body | ConvertTo-Json -Depth 40 -Compress
    }
    try { $response = Invoke-RestMethod @params }
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
    if ($response.code -ne 1) { throw "$Method $Path returned code=$($response.code): $($response.msg)" }
    $script:Checks++
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function Assert-Close([decimal]$Actual, [decimal]$Expected, [string]$Message) {
    if ([math]::Abs([double]($Actual - $Expected)) -gt 0.001) {
        throw "Assertion failed: $Message (actual=$Actual expected=$Expected)"
    }
    $script:Checks++
}

function Assert-HttpFailure {
    param([string]$Method, [string]$Path, [hashtable]$Headers, [int[]]$Statuses, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    try { Invoke-RestMethod @params | Out-Null }
    catch {
        $status = [int]$_.Exception.Response.StatusCode
        if ($status -notin $Statuses) { throw "Expected HTTP $($Statuses -join '/') from $Method $Path, got $status" }
        $script:Checks++
        return
    }
    throw "Expected $Method $Path to fail"
}

function Get-Balance([hashtable]$Headers) {
    $value = Invoke-Api GET '/api/user/wallet' $Headers
    return [decimal]$value.wallet.balance
}

function Find-Message([object[]]$Items, [int]$MessageId) {
    return @($Items | Where-Object { [int]$_.id -eq $MessageId })[0]
}

try {
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $health = Invoke-Api GET '/api/health'
    Assert-True ($health.database -eq 'connected') 'database health'

    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operatorAccount = "commerce_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Commerce Level 2'
        membership_days = 30; admin_quota = 2; balance = 20; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "commerce_l3_$suffix"
    Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Commerce Level 3'
        vip_days = 30; app_quota = 1; remote_document_quota = 3; balance = 20
    } | Out-Null
    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Chat Commerce $suffix"; app_key = "commerce_$suffix"; description = 'Chat commerce smoke test'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        private_message_enabled = $true; group_chat_enabled = $true
        wallet_transfer_enabled = $true; wallet_transfer_max = 100000
        balance_activity_enabled = $true
    } } | Out-Null

    $accountA = "commerce_a_$suffix"
    $accountB = "commerce_b_$suffix"
    $accountC = "commerce_c_$suffix"
    $createdA = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $accountA; password = '123456'; nickname = 'Commerce Alpha'
    }
    $createdB = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $accountB; password = '123456'; nickname = 'Commerce Beta'
    }
    $createdC = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $accountC; password = '123456'; nickname = 'Commerce Gamma'
    }
    $userA = [int]$createdA.user.id
    $userB = [int]$createdB.user.id
    $userC = [int]$createdC.user.id
    Invoke-Api PUT "/api/admin/apps/$appId/users/$userA/wallet" $adminHeaders @{
        asset_type = 'balance'; change_value = 1000; remark = 'commerce smoke seed'
    } | Out-Null
    Invoke-Api PUT "/api/admin/apps/$appId/users/$userB/wallet" $adminHeaders @{
        asset_type = 'balance'; change_value = 100; remark = 'commerce smoke seed'
    } | Out-Null
    Invoke-Api PUT "/api/admin/apps/$appId/users/$userC/wallet" $adminHeaders @{
        asset_type = 'balance'; change_value = 100; remark = 'commerce smoke seed'
    } | Out-Null

    $loginA = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountA; password = '123456' }
    $loginB = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountB; password = '123456' }
    $loginC = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountC; password = '123456' }
    $headersA = @{ Authorization = "Bearer $($loginA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($loginB.access_token)"; 'X-App-Key' = $appKey }
    $headersC = @{ Authorization = "Bearer $($loginC.access_token)"; 'X-App-Key' = $appKey }

    $friendRequest = Invoke-Api POST '/api/user/friends/requests' $headersA @{
        to_user_id = $userB; message = 'commerce test friend'
    }
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersB @{} | Out-Null
    $friends = Invoke-Api GET '/api/user/friends' $headersA
    Assert-True (@($friends.items | Where-Object { [int]$_.user_id -eq $userB }).Count -eq 1) 'recipient appears in visual friend picker data'

    $createdGroup = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "Commerce Group $suffix"; join_mode = 'open'; max_members = 20
    }
    $groupContextId = [int]$createdGroup.room.id
    Invoke-Api POST "/api/user/chat-rooms/$groupContextId/join" $headersB @{} | Out-Null
    Invoke-Api POST "/api/user/chat-rooms/$groupContextId/join" $headersC @{} | Out-Null

    $startA = Get-Balance $headersA
    $startB = Get-Balance $headersB
    $startC = Get-Balance $headersC

    $packet = Invoke-Api POST '/api/user/red-packets' $headersA @{
        packet_type = 'equal'; total_amount = 30; to_user_ids = @($userA, $userB, $userC)
        message = 'commerce packet'; expire_seconds = 3600; delivery_scope = 'private'; context_id = 1001
    }
    $packetId = [int]$packet.packet_id
    Assert-Close (Get-Balance $headersA) ($startA - 30) 'red packet escrow debits sender'
    $senderPacketDetail = Invoke-Api GET "/api/user/red-packets/$packetId" $headersA
    Assert-True ([bool]$senderPacketDetail.item.can_claim -and -not [bool]$senderPacketDetail.item.can_return) 'designated sender can claim but cannot return own share'
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/refund" $headersA @(409) @{}
    $senderClaim = Invoke-Api POST "/api/user/red-packets/$packetId/claim" $headersA @{}
    Assert-Close ([decimal]$senderClaim.amount) 10 'sender can claim designated own share'
    Assert-Close (Get-Balance $headersA) ($startA - 20) 'sender own claim credits designated share'
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/claim" $headersA @(409) @{}
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/refund" $headersA @(409) @{}
    $claim = Invoke-Api POST "/api/user/red-packets/$packetId/claim" $headersB @{}
    Assert-Close ([decimal]$claim.amount) 10 'equal red packet claim amount'
    Assert-Close (Get-Balance $headersB) ($startB + 10) 'red packet claim credits receiver'
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/refund" $headersB @(409) @{}
    $packetDetail = Invoke-Api GET "/api/user/red-packets/$packetId" $headersB
    Assert-True ([int]$packetDetail.item.remain_count -eq 1 -and @($packetDetail.item.claims).Count -eq 2) 'red packet detail includes sender and receiver claims'
    Assert-True (-not [bool]$packetDetail.item.can_claim) 'same user cannot claim twice'
    $packetReturn = Invoke-Api POST "/api/user/red-packets/$packetId/refund" $headersC @{}
    Assert-Close ([decimal]$packetReturn.return_amount) 10 'recipient return amount'
    Assert-Close (Get-Balance $headersA) ($startA - 10) 'sender net cost excludes own claim and returned share'
    Assert-Close (Get-Balance $headersC) $startC 'returning recipient balance is unchanged'
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/claim" $headersB @(409) @{}
    Assert-HttpFailure POST "/api/user/red-packets/$packetId/claim" $headersC @(409) @{}
    $returnedPacket = Invoke-Api GET "/api/user/red-packets/$packetId" $headersC
    Assert-True ([int]$returnedPacket.item.remain_count -eq 0 -and @($returnedPacket.item.returns).Count -eq 1) 'red packet detail includes return list'
    Assert-True ([bool]$returnedPacket.item.returned -and -not [bool]$returnedPacket.item.can_return) 'returned packet is final for recipient'

    $groupPacket = Invoke-Api POST '/api/user/red-packets' $headersA @{
        packet_type = 'equal'; total_amount = 10; to_user_ids = @($userA, $userC)
        message = 'group packet'; expire_seconds = 3600; delivery_scope = 'group'; context_id = $groupContextId
    }
    $groupPacketId = [int]$groupPacket.packet_id
    $groupPacketDetail = Invoke-Api GET "/api/user/red-packets/$groupPacketId" $headersC
    Assert-True ($groupPacketDetail.item.delivery_scope -eq 'group' -and -not [bool]$groupPacketDetail.item.can_return) 'group packet cannot be returned by ordinary recipient'
    Assert-HttpFailure POST "/api/user/red-packets/$groupPacketId/refund" $headersC @(403) @{}
    $groupSenderClaim = Invoke-Api POST "/api/user/red-packets/$groupPacketId/claim" $headersA @{}
    Assert-Close ([decimal]$groupSenderClaim.amount) 5 'sender can claim own designated group packet share'
    $groupRecipientClaim = Invoke-Api POST "/api/user/red-packets/$groupPacketId/claim" $headersC @{}
    Assert-Close ([decimal]$groupRecipientClaim.amount) 5 'group packet recipient can claim normally'

    $beforeExclusivePrivate = Get-Balance $headersA
    $exclusivePrivate = Invoke-Api POST '/api/user/red-packets' $headersA @{
        include_sender = $false
        packet_type = 'equal'; total_amount = 8; to_user_ids = @($userB)
        message = 'recipient-only private packet'; expire_seconds = 3600; delivery_scope = 'private'; context_id = 1002
    }
    $exclusivePrivateId = [int]$exclusivePrivate.packet_id
    $exclusiveSenderDetail = Invoke-Api GET "/api/user/red-packets/$exclusivePrivateId" $headersA
    Assert-True (-not [bool]$exclusiveSenderDetail.item.can_claim -and -not [bool]$exclusiveSenderDetail.item.can_return) 'sender excluded from recipient list can only inspect packet'
    Assert-HttpFailure POST "/api/user/red-packets/$exclusivePrivateId/claim" $headersA @(403) @{}
    $exclusiveReturn = Invoke-Api POST "/api/user/red-packets/$exclusivePrivateId/refund" $headersB @{}
    Assert-Close ([decimal]$exclusiveReturn.return_amount) 8 'designated private recipient can return full exclusive packet'
    Assert-Close (Get-Balance $headersA) $beforeExclusivePrivate 'exclusive private return restores sender balance'

    $beforeManagedGroup = Get-Balance $headersA
    $managedGroup = Invoke-Api POST '/api/user/red-packets' $headersA @{
        include_sender = $false
        packet_type = 'equal'; total_amount = 12; to_user_ids = @($userB, $userC)
        message = 'manager group packet'; expire_seconds = 3600; delivery_scope = 'group'; context_id = $groupContextId
    }
    $managedGroupId = [int]$managedGroup.packet_id
    $managedGroupClaim = Invoke-Api POST "/api/user/red-packets/$managedGroupId/claim" $headersB @{}
    Assert-Close ([decimal]$managedGroupClaim.amount) 6 'group recipient claims one share before manager intervention'
    Assert-HttpFailure POST "/api/user/red-packets/$managedGroupId/refund" $headersC @(403) @{}
    $adminRefund = Invoke-Api POST "/api/admin/apps/$appId/red-packets/$managedGroupId/force-refund" $adminHeaders @{}
    Assert-Close ([decimal]$adminRefund.refund_amount) 6 'level 3 manager refunds only unclaimed group balance'
    Assert-Close (Get-Balance $headersA) ($beforeManagedGroup - 6) 'manager refund preserves claimed cost and restores remaining group balance'
    Assert-HttpFailure POST "/api/user/red-packets/$managedGroupId/claim" $headersC @(409) @{}

    $beforeManagedActivity = Get-Balance $headersA
    $managedActivity = Invoke-Api POST '/api/user/red-packets' $headersA @{
        include_sender = $false
        packet_type = 'equal'; total_amount = 9; to_user_ids = @($userC)
        message = 'managed activity packet'; expire_seconds = 3600; delivery_scope = 'activity'; context_id = 3001
    }
    $managedActivityId = [int]$managedActivity.packet_id
    $activityDetail = Invoke-Api GET "/api/user/red-packets/$managedActivityId" $headersC
    Assert-True ($activityDetail.item.delivery_scope -eq 'activity' -and -not [bool]$activityDetail.item.can_return) 'activity packet is visible but cannot be returned by ordinary user'
    Assert-HttpFailure POST "/api/user/red-packets/$managedActivityId/refund" $headersC @(403) @{}
    $platformRefund = Invoke-Api POST "/api/platform/apps/$appId/red-packets/$managedActivityId/force-refund" $operatorHeaders @{}
    Assert-Close ([decimal]$platformRefund.refund_amount) 9 'level 2 manager can end activity packet and refund remaining balance'
    Assert-Close (Get-Balance $headersA) $beforeManagedActivity 'level 2 activity refund restores sender balance'
    Assert-HttpFailure POST "/api/user/red-packets/$managedActivityId/claim" $headersC @(409) @{}


    $splitPacket = Invoke-Api POST '/api/user/red-packets' $headersA @{
        packet_type = 'random'; distribution_mode = 'count_split'; eligibility_mode = 'selected'
        total_amount = 1.00; total_count = 2; to_user_ids = @($userA, $userB, $userC)
        include_sender = $true; message = 'two shares for three participants'
        expire_seconds = 3600; delivery_scope = 'private'; context_id = 1003
    }
    $splitPacketId = [int]$splitPacket.packet_id
    Assert-True ($splitPacket.distribution_mode -eq 'count_split' -and $splitPacket.eligibility_mode -eq 'selected') 'count split modes are returned'
    Assert-True ([int]$splitPacket.total_count -eq 2 -and [int]$splitPacket.participant_count -eq 3) 'two shares are independent from three eligible participants'
    $splitClaimA = Invoke-Api POST "/api/user/red-packets/$splitPacketId/claim" $headersA @{}
    $splitClaimB = Invoke-Api POST "/api/user/red-packets/$splitPacketId/claim" $headersB @{}
    Assert-True ([decimal]$splitClaimA.amount -ge 0.01 -and [decimal]$splitClaimB.amount -ge 0.01) 'each random share respects minimum currency unit'
    Assert-Close ([decimal]$splitClaimA.amount + [decimal]$splitClaimB.amount) 1.00 'random shares exactly settle total amount'
    Assert-HttpFailure POST "/api/user/red-packets/$splitPacketId/claim" $headersC @(409) @{}
    $splitDetail = Invoke-Api GET "/api/user/red-packets/$splitPacketId" $headersC
    Assert-True ([int]$splitDetail.item.remain_count -eq 0 -and @($splitDetail.item.claims).Count -eq 2) 'count split closes after configured shares are exhausted'
    Assert-True (@($splitDetail.item.claims | Where-Object { [bool]$_.is_luckiest }).Count -eq 1) 'count split marks one luckiest claimant'

    $randomPoolBeforeA = Get-Balance $headersA
    $randomPoolBeforeB = Get-Balance $headersB
    $randomPool = Invoke-Api POST '/api/user/red-packets' $headersA @{
        packet_type = 'random'; distribution_mode = 'single_race'; eligibility_mode = 'context_all'
        total_amount = 1.00; total_count = 99; include_sender = $true
        message = 'legacy mode normalized to random amount pool'; expire_seconds = 3600
        delivery_scope = 'activity'; context_id = 3002
    }
    $randomPoolId = [int]$randomPool.packet_id
    Assert-True ($randomPool.distribution_mode -eq 'random_grab' -and $randomPool.eligibility_mode -eq 'context_all') 'legacy mode is returned as random amount pool'
    Assert-True ([int]$randomPool.total_count -eq 3 -and [int]$randomPool.participant_count -eq 3) 'random pool allows every active participant one attempt'
    Assert-Close (Get-Balance $headersA) ($randomPoolBeforeA - 1.00) 'random pool escrows the total amount once'
    $randomClaim = Invoke-Api POST "/api/user/red-packets/$randomPoolId/claim" $headersB @{}
    Assert-True ([decimal]$randomClaim.amount -ge 0.01 -and [decimal]$randomClaim.amount -le 1.00) 'random pool claim uses a valid amount from the remaining pool'
    Assert-Close (Get-Balance $headersB) ($randomPoolBeforeB + [decimal]$randomClaim.amount) 'random pool claimant receives the exact claimed amount'
    Assert-HttpFailure POST "/api/user/red-packets/$randomPoolId/claim" $headersB @(409) @{}
    $randomPoolDetail = Invoke-Api GET "/api/user/red-packets/$randomPoolId" $headersC
    Assert-True (@($randomPoolDetail.item.claims).Count -eq 1 -and [bool]$randomPoolDetail.item.claims[0].is_luckiest) 'random pool records the claimant and current luckiest participant'
    Assert-True ([int]$randomPoolDetail.item.participant_count -eq 3 -and [int]$randomPoolDetail.item.remain_count -eq 2) 'random pool exposes participant and remaining-attempt metadata'
    $beforeTransferA = Get-Balance $headersA
    $beforeTransferB = Get-Balance $headersB
    $transfer = Invoke-Api POST '/api/user/transfers' $headersA @{
        to_user_ids = @($userB); amount = 12.50; message = 'accepted transfer'; expire_seconds = 3600
    }
    $transferId = [int]$transfer.transfer_id
    Assert-Close (Get-Balance $headersA) ($beforeTransferA - 12.50) 'transfer escrow debits sender'
    $transferDetail = Invoke-Api GET "/api/user/transfers/$transferId" $headersB
    Assert-True ([bool]$transferDetail.item.can_accept -and [bool]$transferDetail.item.can_refund) 'receiver sees transfer actions'
    Invoke-Api POST "/api/user/transfers/$transferId/accept" $headersB @{} | Out-Null
    Assert-Close (Get-Balance $headersB) ($beforeTransferB + 12.50) 'accepted transfer credits receiver'
    $acceptedTransfer = Invoke-Api GET "/api/user/transfers/$transferId" $headersA
    Assert-True ($acceptedTransfer.item.status -eq 'accepted' -and -not [bool]$acceptedTransfer.item.can_refund) 'accepted transfer is final'

    $beforeRefundTransfer = Get-Balance $headersA
    $refundTransfer = Invoke-Api POST '/api/user/transfers' $headersA @{
        to_user_id = $userB; amount = 7.25; message = 'refund transfer'; expire_seconds = 3600
    }
    $refundTransferId = [int]$refundTransfer.transfer_id
    Invoke-Api POST "/api/user/transfers/$refundTransferId/refund" $headersB @{} | Out-Null
    Assert-Close (Get-Balance $headersA) $beforeRefundTransfer 'receiver refund restores sender balance'
    Assert-HttpFailure POST '/api/user/transfers' $headersA @(422) @{ to_user_id = $userA; amount = 1 }

    $catalog = Invoke-Api GET '/api/user/gift-catalog' $headersA
    $gift = @($catalog.items)[0]
    Assert-True ([int]$gift.id -gt 0 -and [decimal]$gift.price -gt 0) 'gift catalog is available'
    $beforeGiftA = Get-Balance $headersA
    $giftSend = Invoke-Api POST '/api/user/gifts' $headersA @{
        gift_id = [int]$gift.id; to_user_ids = @($userB); quantity = 2; message = 'accepted gift'; expire_seconds = 3600
    }
    $giftId = [int]$giftSend.gift_record_id
    $giftCost = [decimal]$gift.price * 2
    Assert-Close (Get-Balance $headersA) ($beforeGiftA - $giftCost) 'gift escrow debits sender'
    $giftDetail = Invoke-Api GET "/api/user/gifts/$giftId" $headersB
    Assert-True ([bool]$giftDetail.item.can_accept -and $giftDetail.item.gift_name -eq $gift.gift_name) 'receiver sees gift detail'
    Invoke-Api POST "/api/user/gifts/$giftId/accept" $headersB @{} | Out-Null
    $acceptedGift = Invoke-Api GET "/api/user/gifts/$giftId" $headersA
    Assert-True ($acceptedGift.item.status -eq 'accepted' -and -not [bool]$acceptedGift.item.can_refund) 'accepted gift is final'

    $beforeRefundGift = Get-Balance $headersA
    $giftRefund = Invoke-Api POST '/api/user/gifts' $headersA @{
        gift_id = [int]$gift.id; to_user_id = $userB; quantity = 3; message = 'refund gift'; expire_seconds = 3600
    }
    $giftRefundId = [int]$giftRefund.gift_record_id
    Invoke-Api POST "/api/user/gifts/$giftRefundId/refund" $headersB @{} | Out-Null
    Assert-Close (Get-Balance $headersA) $beforeRefundGift 'gift refund restores sender balance'
    Assert-HttpFailure POST '/api/user/gifts' $headersA @(422) @{ gift_id = [int]$gift.id; to_user_id = $userA; quantity = 1 }

    $favoriteSource = Invoke-Api POST '/api/user/messages/private' $headersB @{
        to_user_id = $userA; content = 'favorite source message'
    }
    Invoke-Api POST "/api/user/messages/$([int]$favoriteSource.message_id)/state" $headersA @{ action = 'favorite' } | Out-Null
    $favoriteCenter = Invoke-Api GET '/api/user/favorites?category=messages' $headersA
    $favoriteItem = @($favoriteCenter.items | Where-Object { [int]$_.id -eq [int]$favoriteSource.message_id })[0]
    Assert-True ($null -ne $favoriteItem -and $favoriteItem.favorite_type -eq 'message') 'favorite center returns sendable message metadata'

    $attachments = @(
        @{ media_type = 'red_packet'; url = "/api/user/red-packets/$packetId"; file_name = 'red packet'; metadata = @{ packet_id = $packetId } },
        @{ media_type = 'transfer'; url = "/api/user/transfers/$transferId"; file_name = 'transfer'; metadata = @{ transfer_id = $transferId } },
        @{ media_type = 'contact_card'; url = "/api/user/profiles/$userB"; file_name = 'contact card'; metadata = @{ user_id = $userB; display_name = $accountB } },
        @{ media_type = 'gift'; url = "/api/user/gifts/$giftId"; file_name = 'gift'; metadata = @{ gift_record_id = $giftId } },
        @{ media_type = 'favorite'; url = '/api/user/favorites'; file_name = 'favorite'; metadata = $favoriteItem }
    )
    $sentIds = @()
    foreach ($attachment in $attachments) {
        $sent = Invoke-Api POST '/api/user/messages/private' $headersA @{
            to_user_id = $userB; content = ''; attachments = @($attachment); tags = @($attachment.media_type)
        }
        $sentIds += [int]$sent.message_id
        if (-not $conversationId) { $conversationId = [int]$sent.conversation_id }
    }
    $messageList = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersB
    foreach ($index in 0..($sentIds.Count - 1)) {
        $message = Find-Message @($messageList.items) $sentIds[$index]
        Assert-True ($null -ne $message -and [string]$message.content -eq '') "structured message $index has no text bubble content"
        Assert-True (@($message.attachments).Count -eq 1 -and $message.attachments[0].media_type -eq $attachments[$index].media_type) "structured message $index keeps attachment type"
    }

    $transferList = Invoke-Api GET '/api/user/transfers' $headersA
    $giftList = Invoke-Api GET '/api/user/gifts' $headersB
    Assert-True (@($transferList.items).Count -ge 2) 'transfer history contains accepted and refunded records'
    Assert-True (@($giftList.items).Count -ge 2) 'gift history contains accepted and refunded records'
    $walletLogs = Invoke-Api GET '/api/user/wallet/logs?limit=200' $headersA
    Assert-True (@($walletLogs.items | Where-Object { $_.scene -eq 'red_packet_recipient_return' }).Count -ge 2) 'each recipient return has a wallet ledger entry'
    Assert-True (@($walletLogs.items | Where-Object { $_.scene -eq 'transfer_escrow' }).Count -ge 2) 'transfer escrows have wallet ledger entries'
    Assert-True (@($walletLogs.items | Where-Object { $_.scene -eq 'gift_refund' }).Count -eq 1) 'gift refund has wallet ledger entry'

    Write-Host 'Chat commerce smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks operator_id=$operatorId app_id=$appId"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary chat-commerce branch cleanup failed: $($_.Exception.Message)" }
    }
}
