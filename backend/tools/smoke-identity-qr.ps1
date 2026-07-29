param([string]$BaseUrl = 'http://127.0.0.1:8789')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0
$ignoredActionableText = -join @(
    [char]0x5DF2, [char]0x5FFD, [char]0x7565, [char]0xFF0C, [char]0x53EF,
    [char]0x7EE7, [char]0x7EED, [char]0x5904, [char]0x7406
)

function Invoke-Api([string]$Method, [string]$Path, [hashtable]$Headers = @{}, $Body = $null) {
    $request = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers }
    if ($null -ne $Body) {
        $request.ContentType = 'application/json; charset=utf-8'
        $request.Body = $Body | ConvertTo-Json -Depth 20 -Compress
    }
    try {
        $response = Invoke-RestMethod @request
    } catch {
        $raw = ''
        if ($null -ne $_.Exception.Response) {
            $reader = [IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
            $raw = $reader.ReadToEnd()
        }
        throw "$Method $Path failed: $raw"
    }
    if ([int]$response.code -ne 1) {
        throw "$Method $Path failed: $($response | ConvertTo-Json -Depth 10 -Compress)"
    }
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'; password = '123456'; device = 'identity-qr-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
$appId = 1
$appKey = 'yiyunying-demo'
$userIds = @()
$roomId = 0
$settingsBefore = Invoke-Api GET "/api/admin/apps/$appId/settings" $adminHeaders

try {
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
        settings = @{
            registration_enabled = $true
            daily_register_limit = 100000
            register_ip_daily_limit = 100000
            registration_nickname_enabled = $true
            registration_nickname_required = $true
            registration_email_enabled = $false
            registration_email_required = $false
            registration_phone_enabled = $true
            registration_phone_required = $true
            group_chat_enabled = $true
            relationship_request_valid_days = 30
            relationship_request_valid_days_inherit = $false
        }
    } | Out-Null

    $bootstrap = Invoke-Api GET "/api/public/bootstrap?app_key=$appKey"
    Assert-True ($bootstrap.registration_policy.phone.enabled -eq $true) 'phone field enabled by app policy'
    Assert-True ($bootstrap.registration_policy.phone.required -eq $true) 'phone field required by app policy'
    Assert-True ($bootstrap.registration_policy.email.enabled -eq $false) 'email field hidden by app policy'
    Assert-True ($bootstrap.registration_policy.password_confirmation_required -eq $true) 'password confirmation policy'

    $number = ([long]$suffix % 90000000) + 10000000
    $phoneA = "138$number"
    $phoneB = "139$number"
    $registerA = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "identity_a_$suffix"; nickname = 'Identity A'
        password = '123456'; password_confirmation = '123456'; phone = $phoneA
    }
    $registerB = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "identity_b_$suffix"; nickname = 'Identity B'
        password = '123456'; password_confirmation = '123456'; phone = $phoneB
    }
    $userIds += [int]$registerA.user.id
    $userIds += [int]$registerB.user.id
    $uidA = [string]$registerA.user.uid
    $uidB = [string]$registerB.user.uid
    Assert-True ($uidA -match '^\d{10,16}$') 'UID A is server-generated variable-length numeric code'
    Assert-True ($uidB -match '^\d{10,16}$' -and $uidB -ne $uidA) 'UID B is unique'
    Assert-True ($uidA -ne [string]$registerA.user.account) 'UID and user-entered account are separate'

    $headersA = @{ Authorization = "Bearer $($registerA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($registerB.access_token)"; 'X-App-Key' = $appKey }
    $qr = Invoke-Api GET '/api/user/friends/qr-code' $headersA
    Assert-True ([string]$qr.uid -eq $uidA -and [string]$qr.qr_payload -ne '') 'signed friend QR generated'
    $friendRequest = Invoke-Api POST '/api/user/friends/scan-qr' $headersB @{
        qr_payload = [string]$qr.qr_payload; message = 'QR friend request smoke test'
    }
    Assert-True ([int]$friendRequest.request_id -gt 0) 'QR scan creates friend request'
    Assert-True ([int]$friendRequest.valid_days -eq 30) 'level 3 app rule applies a 30-day relationship request validity period'
    $friendExpiry = [datetime]$friendRequest.expired_at
    Assert-True ($friendExpiry -gt (Get-Date).AddDays(29) -and $friendExpiry -lt (Get-Date).AddDays(31)) 'friend request expiry is stored with an explicit day unit'

    $ignoredFriend = Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/ignore" $headersA @{
        reason = 'Smoke test keeps ignored request actionable'
    }
    Assert-True ([string]$ignoredFriend.status -eq 'ignored' -and -not [bool]$ignoredFriend.sender_notified) 'ignored friend request does not notify the sender'
    $filteredFriend = Invoke-Api GET '/api/user/relationship-notices?category=friend_filtered&limit=100' $headersA
    Assert-True ([int]$filteredFriend.relationship_policy.effective_days -eq 30) 'relationship notice response exposes the actual effective validity period for the visual client'
    $filteredFriendItem = @($filteredFriend.items | Where-Object { [int]$_.id -eq [int]$friendRequest.request_id })[0]
    Assert-True ($null -ne $filteredFriendItem -and [bool]$filteredFriendItem.can_decide) 'ignored friend request remains actionable for the receiver'
    Assert-True ([string]$filteredFriendItem.status_text -eq $ignoredActionableText) 'ignored friend request has a clear Chinese state'
    $outgoingFriend = Invoke-Api GET '/api/user/relationship-notices?category=friend_outgoing&limit=100' $headersB
    $outgoingFriendItem = @($outgoingFriend.items | Where-Object { [int]$_.id -eq [int]$friendRequest.request_id })[0]
    Assert-True ([string]$outgoingFriendItem.status -eq 'pending' -and [string]::IsNullOrEmpty([string]$outgoingFriendItem.ignore_reason)) 'sender cannot tell that the request was ignored'
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersA @{} | Out-Null
    $friends = Invoke-Api GET "/api/user/friends?keyword=$uidB" $headersA
    Assert-True ($friends.items.Count -eq 1 -and [string]$friends.items[0].uid -eq $uidB) 'friend can be found by UID'

    $room = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "Relationship Room $suffix"; join_mode = 'open'; max_members = 20
    }
    $roomId = [int]$room.room.id
    $invitation = Invoke-Api POST "/api/user/chat-rooms/$roomId/invitations" $headersA @{
        user_id = [int]$registerB.user.id; message = 'Relationship invitation smoke test'
    }
    $invitationId = [int]$invitation.invitation.id
    Assert-True ($invitationId -gt 0 -and [int]$invitation.valid_days -eq 30) 'group invitation inherits the same explicit 30-day validity rule'
    $ignoredInvitation = Invoke-Api POST "/api/user/chat-room-invitations/$invitationId/ignore" $headersB @{
        reason = 'Smoke test keeps ignored invitation actionable'
    }
    Assert-True ([string]$ignoredInvitation.status -eq 'ignored' -and -not [bool]$ignoredInvitation.inviter_notified) 'ignored group invitation does not notify the inviter'
    $filteredGroup = Invoke-Api GET '/api/user/relationship-notices?category=group_filtered&limit=100' $headersB
    $filteredInvitation = @($filteredGroup.items | Where-Object { [int]$_.id -eq $invitationId -and [string]$_.notice_type -eq 'group_invitation' })[0]
    Assert-True ($null -ne $filteredInvitation -and [bool]$filteredInvitation.can_decide) 'ignored group invitation remains actionable'
    Invoke-Api POST "/api/user/chat-room-invitations/$invitationId/accept" $headersB @{} | Out-Null
    $members = Invoke-Api GET "/api/user/chat-rooms/$roomId/members?limit=100" $headersA
    Assert-True (@($members.items | Where-Object { [int]$_.user_id -eq [int]$registerB.user.id }).Count -eq 1) 'ignored invitation can later be accepted normally'

    $favorites = Invoke-Api GET '/api/user/favorites?category=all&limit=20' $headersA
    Assert-True ($favorites.categories.Count -eq 8) 'unified favorite center categories'

    $unbind = Invoke-Api POST '/api/user/identity-unbind-requests' $headersA @{
        identity_type = 'phone'; reason = 'Verify direct reviewer assignment'
    }
    $unbindId = [int]$unbind.request.id
    Assert-True ($unbindId -gt 0 -and [string]$unbind.request.reviewer_type -eq 'admin') 'L4 request is assigned directly to its L3 admin'
    $reviewList = Invoke-Api GET '/api/admin/identity-unbind-requests?status=pending' $adminHeaders
    Assert-True (@($reviewList.items | Where-Object { [int]$_.id -eq $unbindId }).Count -eq 1) 'direct admin review list contains request'
    $reviewed = Invoke-Api POST "/api/admin/identity-unbind-requests/$unbindId/review" $adminHeaders @{
        action = 'approve'; remark = 'Direct reviewer approved'
    }
    Assert-True ([string]$reviewed.request.status -eq 'approved') 'direct reviewer approves unbind'
    Assert-True ([string]$reviewed.request.review_mode -eq 'direct') 'review is recorded as direct, not escalated'
    $meA = Invoke-Api GET '/api/user/me' $headersA
    Assert-True ([string]::IsNullOrEmpty([string]$meA.user.phone)) 'approved identity is removed from account'

    $registerC = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "identity_c_$suffix"; nickname = 'Identity C'
        password = '123456'; password_confirmation = '123456'; phone = $phoneA
    }
    $userIds += [int]$registerC.user.id
    Assert-True ([string]$registerC.user.phone -eq $phoneA) 'approved unbound identity can be rebound to another account'
} finally {
    if ($roomId -gt 0 -and $headersA.Count -gt 0) {
        try { Invoke-Api DELETE "/api/user/chat-rooms/$roomId" $headersA @{} | Out-Null } catch { Write-Warning $_ }
    }
    foreach ($userId in $userIds) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId/users/$userId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null } catch { Write-Warning $_ }
    }
    try {
        Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
            settings = @{
                registration_enabled = [bool]$settingsBefore.settings.registration_enabled
                daily_register_limit = [int]$settingsBefore.settings.daily_register_limit
                register_ip_daily_limit = [int]$settingsBefore.settings.register_ip_daily_limit
                registration_nickname_enabled = [bool]$settingsBefore.settings.registration_nickname_enabled
                registration_nickname_required = [bool]$settingsBefore.settings.registration_nickname_required
                registration_email_enabled = [bool]$settingsBefore.settings.registration_email_enabled
                registration_email_required = [bool]$settingsBefore.settings.registration_email_required
                registration_phone_enabled = [bool]$settingsBefore.settings.registration_phone_enabled
                registration_phone_required = [bool]$settingsBefore.settings.registration_phone_required
                group_chat_enabled = [bool]$settingsBefore.settings.group_chat_enabled
                relationship_request_valid_days = [int]$settingsBefore.settings.relationship_request_valid_days
                relationship_request_valid_days_inherit = [bool]$settingsBefore.settings.relationship_request_valid_days_inherit
            }
        } | Out-Null
    } catch { Write-Warning $_ }
}

Write-Host 'Identity, UID, QR and direct-unbind smoke test passed.'
Write-Host "checks=$script:Checks"
