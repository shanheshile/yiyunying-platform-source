param([string]$BaseUrl = 'http://127.0.0.1:8789')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    try { $response = Invoke-RestMethod @params }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $stream = $_.Exception.Response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = New-Object System.IO.StreamReader($stream, [System.Text.Encoding]::UTF8)
                    $detail = $reader.ReadToEnd()
                    $reader.Dispose()
                }
            }
            catch { }
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

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$quotaRaised = $false
$membershipRaised = $false

$root = Invoke-Api POST '/api/platform/login' @{} @{
    account = 'root'; password = '123456'; device = 'voice-call-smoke'
}
$rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
$admin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'; password = '123456'; device = 'voice-call-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
$adminId = [int]$admin.admin.id
$originalQuota = [int]$admin.admin.app_quota
$originalMembershipStatus = [string]$admin.admin.membership_status
$originalMembershipExpiredAt = $admin.admin.membership_expired_at

try {
    $membershipExpired = [string]::IsNullOrWhiteSpace([string]$originalMembershipExpiredAt)
    if (-not $membershipExpired) {
        $membershipExpired = ([DateTimeOffset]::Parse([string]$originalMembershipExpiredAt) -le [DateTimeOffset]::Now)
    }
    if ($originalMembershipStatus -ne 'active' -or $membershipExpired) {
        Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
            membership_status = 'active'
            membership_expired_at = (Get-Date).AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')
            remark = 'Temporary membership for network call smoke test'
        } | Out-Null
        $membershipRaised = $true
    }
    Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
        entitlement_type = 'app_quota'; operation = 'set'; amount = ($originalQuota + 1)
        remark = 'Temporary app quota for network call smoke test'
    } | Out-Null
    $quotaRaised = $true

    $created = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Network call smoke $suffix"; description = 'Temporary audio and video call state-machine app'
    }
    $appId = [int]$created.app.id
    $appKey = [string]$created.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        private_message_enabled = $true
        system_notification_enabled = $true
    } } | Out-Null

    $a = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "call_a_$suffix"; password = '123456'
        password_confirmation = '123456'; nickname = 'Call User A'
    }
    $b = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "call_b_$suffix"; password = '123456'
        password_confirmation = '123456'; nickname = 'Call User B'
    }
    $userA = [int]$a.user.id
    $userB = [int]$b.user.id
    $headersA = @{ Authorization = "Bearer $($a.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($b.access_token)"; 'X-App-Key' = $appKey }

    $friendRequest = Invoke-Api POST '/api/user/friends/requests' $headersA @{
        to_user_id = $userB; message = 'Network call smoke friend request'
    }
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersB @{} | Out-Null

    $privateMention = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content_type = 'text'; content = '@Call User B private mention smoke'
        mentions = @($userB)
    }
    $privateNotices = Invoke-Api GET '/api/user/notifications?limit=100' $headersB
    $privateNotice = @($privateNotices.items | Where-Object { $_.notification_type -eq 'chat_mention' }) | Select-Object -First 1
    Assert-True ($null -ne $privateNotice) 'private @ mention creates a notification-center record'
    $privateMentionData = $privateNotice.data_json | ConvertFrom-Json
    Assert-True ([int]$privateMentionData.conversation_id -eq [int]$privateMention.conversation_id) 'private @ notification points to the conversation'
    Assert-True ([int]$privateMentionData.message_id -eq [int]$privateMention.message_id) 'private @ notification points to the exact message'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$privateMentionData.sender_name)) 'private @ notification contains a readable sender name'

    $audio = Invoke-Api POST '/api/user/voice-calls' $headersA @{
        to_user_id = $userB; call_type = 'audio'
    }
    $audioId = [int]$audio.call.id
    Assert-True ($audio.call.call_type -eq 'audio') 'audio call type is persisted'
    Assert-True ($audio.call.status -eq 'ringing' -and $audio.call.direction -eq 'outgoing') 'caller sees an outgoing ringing call'
    $iceServersJson = $audio.call.ice_servers | ConvertTo-Json -Depth 20 -Compress
    Assert-True ($iceServersJson -match 'turn:') 'call response contains a TURN relay server'
    $ringWindow = ([DateTimeOffset]::Parse([string]$audio.call.expires_at) - [DateTimeOffset]::Parse([string]$audio.call.started_at)).TotalSeconds
    Assert-True ($ringWindow -ge 55 -and $ringWindow -le 65) 'incoming calls ring for about 60 seconds before timing out'

    $incoming = Invoke-Api GET '/api/user/voice-calls/incoming' $headersB
    Assert-True ([int]$incoming.call.id -eq $audioId) 'callee receives the incoming call'
    Assert-True ([bool]$incoming.call.can_answer) 'callee can answer a ringing call'

    $ringReuse = Invoke-Api POST '/api/user/voice-calls' $headersA @{
        to_user_id = $userB; call_type = 'audio'
    }
    Assert-True ([int]$ringReuse.call.id -eq $audioId) 'repeated caller tap reuses the same ringing call'
    Assert-True ([bool]$ringReuse.call.reused -and [bool]$ringReuse.call.resume_offer) 'ringing reuse asks the caller to replace the old offer'

    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersA @{
        signal_type = 'offer'; payload = @{ sdp = 'smoke-offer' }
    } | Out-Null
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersA @{
        signal_type = 'ice'; payload = @{ sdp_mid = '0'; sdp_mline_index = 0; candidate = 'caller-smoke-candidate' }
    } | Out-Null
    $offerSignals = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersB
    Assert-True (@($offerSignals.items).Count -eq 2) 'callee receives caller offer and ICE signaling'
    Assert-True (@($offerSignals.items | Where-Object { $_.signal_type -eq 'offer' }).Count -eq 1) 'offer signaling type is preserved'
    Assert-True (@($offerSignals.items | Where-Object { $_.signal_type -eq 'ice' }).Count -eq 1) 'caller ICE candidate reaches the callee'
    $callerOwnSignals = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersA
    Assert-True (@($callerOwnSignals.items).Count -eq 0) 'caller never receives its own offer or ICE signaling'

    $answered = Invoke-Api POST "/api/user/voice-calls/$audioId/answer" $headersB @{}
    Assert-True ($answered.call.status -eq 'active') 'callee can answer the audio call'
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersB @{
        signal_type = 'answer'; payload = @{ sdp = 'smoke-answer' }
    } | Out-Null
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersB @{
        signal_type = 'ice'; payload = @{ sdp_mid = '0'; sdp_mline_index = 0; candidate = 'callee-smoke-candidate' }
    } | Out-Null
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersB @{
        signal_type = 'media'; payload = @{ camera_enabled = $false }
    } | Out-Null
    $answerSignals = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersA
    Assert-True (@($answerSignals.items | Where-Object { $_.signal_type -eq 'answer' }).Count -eq 1) 'caller receives callee answer signaling'
    Assert-True (@($answerSignals.items | Where-Object { $_.signal_type -eq 'ice' }).Count -eq 1) 'callee ICE candidate reaches the caller'
    Assert-True (@($answerSignals.items | Where-Object { $_.signal_type -eq 'media' -and $_.payload.camera_enabled -eq $false }).Count -eq 1) 'remote camera state reaches the caller'
    $calleeOwnSignals = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersB
    Assert-True (@($calleeOwnSignals.items | Where-Object { $_.signal_type -eq 'answer' -or $_.signal_type -eq 'media' }).Count -eq 0) 'callee never receives its own answer or media state'

    $callerRejoin = Invoke-Api POST '/api/user/voice-calls' $headersA @{
        to_user_id = $userB; call_type = 'audio'
    }
    Assert-True ([int]$callerRejoin.call.id -eq $audioId) 'caller rejoins the same active call instead of receiving busy'
    Assert-True ([bool]$callerRejoin.call.reused -and [bool]$callerRejoin.call.resume_offer) 'caller rejoin starts a clean renegotiation'
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersA @{
        signal_type = 'offer'; payload = @{ sdp = 'caller-rejoin-offer' }
    } | Out-Null
    $callerReoffer = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersB
    Assert-True (@($callerReoffer.items | Where-Object { $_.signal_type -eq 'offer' -and $_.payload.sdp -eq 'caller-rejoin-offer' }).Count -eq 1) 'callee receives the caller rejoin offer'
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersB @{
        signal_type = 'answer'; payload = @{ sdp = 'callee-rejoin-answer' }
    } | Out-Null
    $callerReanswer = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersA
    Assert-True (@($callerReanswer.items | Where-Object { $_.signal_type -eq 'answer' -and $_.payload.sdp -eq 'callee-rejoin-answer' }).Count -eq 1) 'caller receives the callee rejoin answer'

    $calleeRejoin = Invoke-Api POST '/api/user/voice-calls' $headersB @{
        to_user_id = $userA; call_type = 'audio'
    }
    Assert-True ([int]$calleeRejoin.call.id -eq $audioId) 'original callee can rejoin the same active call from a new screen'
    Assert-True ([bool]$calleeRejoin.call.reused -and [bool]$calleeRejoin.call.resume_offer) 'reverse-side rejoin can become the renegotiation offerer'
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersB @{
        signal_type = 'offer'; payload = @{ sdp = 'reverse-rejoin-offer' }
    } | Out-Null
    $reverseReoffer = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersA
    Assert-True (@($reverseReoffer.items | Where-Object { $_.signal_type -eq 'offer' -and $_.payload.sdp -eq 'reverse-rejoin-offer' }).Count -eq 1) 'active call accepts a re-offer from the reverse participant'
    Invoke-Api POST "/api/user/voice-calls/$audioId/signals" $headersA @{
        signal_type = 'answer'; payload = @{ sdp = 'reverse-rejoin-answer' }
    } | Out-Null
    $reverseReanswer = Invoke-Api GET "/api/user/voice-calls/$audioId/signals?after_id=0" $headersB
    Assert-True (@($reverseReanswer.items | Where-Object { $_.signal_type -eq 'answer' -and $_.payload.sdp -eq 'reverse-rejoin-answer' }).Count -eq 1) 'reverse participant receives the new answer'

    $ended = Invoke-Api POST "/api/user/voice-calls/$audioId/hangup" $headersB @{}
    Assert-True ($ended.call.status -eq 'ended' -and [bool]$ended.call.is_terminal) 'active audio call can be ended'
    $peerEnded = Invoke-Api GET "/api/user/voice-calls/$audioId" $headersA
    Assert-True ($peerEnded.call.status -eq 'ended' -and [bool]$peerEnded.call.is_terminal) 'the peer immediately observes the terminal state after hangup'

    $video = Invoke-Api POST '/api/user/voice-calls' $headersB @{
        to_user_id = $userA; call_type = 'video'
    }
    $videoId = [int]$video.call.id
    Assert-True ($video.call.call_type -eq 'video') 'video call type is persisted'
    $videoIncoming = Invoke-Api GET '/api/user/voice-calls/incoming' $headersA
    Assert-True ([int]$videoIncoming.call.id -eq $videoId -and $videoIncoming.call.call_type -eq 'video') 'video incoming call is distinguishable before answering'
    $declined = Invoke-Api POST "/api/user/voice-calls/$videoId/decline" $headersA @{}
    Assert-True ($declined.call.status -eq 'declined') 'callee can decline a video call'
    $videoState = Invoke-Api GET "/api/user/voice-calls/$videoId" $headersB
    Assert-True ([bool]$videoState.call.is_terminal -and $videoState.call.status -eq 'declined') 'caller sees the declined terminal state'

    $createdRoom = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "Call room $suffix"; join_mode = 'open'; max_members = 20
    }
    $roomId = [int]$createdRoom.room.id
    Invoke-Api POST "/api/user/chat-rooms/$roomId/join" $headersB @{} | Out-Null
    $groupMention = Invoke-Api POST "/api/user/chat-rooms/$roomId/messages" $headersA @{
        content_type = 'text'; content = '@Call User B group mention smoke'; mentions = @($userB)
    }
    $groupNotices = Invoke-Api GET '/api/user/notifications?limit=100' $headersB
    $groupNotice = @($groupNotices.items | Where-Object {
        $_.notification_type -eq 'chat_mention' -and $_.data_json -like "*`"room_id`":$roomId*"
    }) | Select-Object -First 1
    Assert-True ($null -ne $groupNotice) 'group @ mention creates a notification-center record'
    $groupMentionData = $groupNotice.data_json | ConvertFrom-Json
    Assert-True ([int]$groupMentionData.room_id -eq $roomId -and [int]$groupMentionData.message_id -eq [int]$groupMention.message_id) 'group @ notification points to the exact room message'
    Assert-True ([string]$groupMentionData.room_name -eq "Call room $suffix") 'group @ notification contains the room display name'
    $roomCall = Invoke-Api POST '/api/user/voice-calls' $headersA @{
        to_user_id = $userB; call_type = 'audio'; context_type = 'room'; context_id = $roomId
    }
    $roomCallId = [int]$roomCall.call.id
    Assert-True ($roomCall.call.context_type -eq 'room' -and [int]$roomCall.call.context_id -eq $roomId) 'group call keeps room context'
    Assert-True ($roomCall.call.context_name -eq "Call room $suffix") 'group call returns the room name'
    Invoke-Api POST "/api/user/voice-calls/$roomCallId/hangup" $headersA @{} | Out-Null
    $roomMessages = Invoke-Api GET "/api/user/chat-rooms/$roomId/messages?limit=100" $headersB
    $callRecords = @($roomMessages.items | Where-Object { $_.content_type -eq 'system' -and $_.sender_role -eq 'system' })
    if ($callRecords.Count -lt 1) {
        Write-Host ($roomMessages.items | ConvertTo-Json -Depth 20) -ForegroundColor Yellow
    }
    Assert-True ($callRecords.Count -ge 2) 'group call creates visible start and end records'

    Write-Host "Voice/video call smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for app $appId`: $($_.Exception.Message)" }
    }
    if ($quotaRaised) {
        try {
            Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
                entitlement_type = 'app_quota'; operation = 'set'; amount = $originalQuota
                remark = 'Restore app quota after network call smoke test'
            } | Out-Null
        }
        catch { Write-Warning "Failed to restore admin app quota: $($_.Exception.Message)" }
    }
    if ($membershipRaised) {
        try {
            Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $rootHeaders @{
                membership_status = $originalMembershipStatus
                membership_expired_at = $originalMembershipExpiredAt
                remark = 'Restore membership after network call smoke test'
            } | Out-Null
        }
        catch { Write-Warning "Failed to restore admin membership: $($_.Exception.Message)" }
    }
}
