param([string]$BaseUrl = 'http://127.0.0.1:8792')

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$Checks = 0

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
    if ([int]$response.code -ne 1) { throw "$Method $Path failed: $($response | ConvertTo-Json -Depth 10 -Compress)" }
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'; password = '123456'; device = 'contact-group-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

try {
    $app = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Contact Group Smoke $suffix"; description = 'Friend and room group tenant-isolation test'
    }
    $appId = [int]$app.app.id
    $appKey = [string]$app.app.app_key
    $publicHeaders = @{ 'X-App-Key' = $appKey }
    $primary = Invoke-Api POST '/api/user/register' $publicHeaders @{
        app_key = $appKey; account = "contact_owner_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'Contact Owner'
    }
    $primaryHeaders = @{ Authorization = "Bearer $($primary.access_token)"; 'X-App-Key' = $appKey }
    $secondary = Invoke-Api POST '/api/user/register' $publicHeaders @{
        app_key = $appKey; account = "contact_peer_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'Contact Peer'
    }
    $secondaryId = [int]$secondary.user.id
    $secondaryHeaders = @{ Authorization = "Bearer $($secondary.access_token)"; 'X-App-Key' = $appKey }

    $friendRequest = Invoke-Api POST '/api/user/friends/requests' $primaryHeaders @{
        to_uid = [string]$secondary.user.uid; message = 'Contact group verification'
    }
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $secondaryHeaders @{} | Out-Null
    $friendGroup = Invoke-Api POST '/api/user/friend-groups' $primaryHeaders @{ name = "Customers-$suffix" }
    Invoke-Api PUT "/api/user/friends/$secondaryId" $primaryHeaders @{ remark = 'Important Contact'; group_id = [int]$friendGroup.group_id } | Out-Null
    $friends = Invoke-Api GET "/api/user/friends?group_id=$($friendGroup.group_id)&keyword=$([uri]::EscapeDataString('Important Contact'))" $primaryHeaders
    Assert-True ($friends.items.Count -eq 1) 'friend group and remark filter'
    Assert-True ([string]$friends.items[0].uid -eq [string]$secondary.user.uid) 'server-generated UID'
    Invoke-Api DELETE "/api/user/friends/$secondaryId" $primaryHeaders @{} | Out-Null
    Invoke-Api DELETE "/api/user/friend-groups/$($friendGroup.group_id)" $primaryHeaders @{} | Out-Null

    $roomGroup = Invoke-Api POST '/api/user/chat-room-groups' $primaryHeaders @{ name = "Work-$suffix" }
    $room = Invoke-Api POST '/api/user/chat-rooms' $primaryHeaders @{
        name = "Group-$suffix"; description = 'Room group smoke test'; join_mode = 'approval'; tags = @('work', 'test')
    }
    $roomId = [int]$room.room.id
    Invoke-Api PUT "/api/user/chat-rooms/$roomId/user-settings" $primaryHeaders @{
        group_id = [int]$roomGroup.group_id; remark = 'My Work Group'
    } | Out-Null
    $rooms = Invoke-Api GET "/api/user/chat-rooms?group_id=$($roomGroup.group_id)&keyword=$([uri]::EscapeDataString('My Work Group'))&limit=20" $primaryHeaders
    Assert-True ($rooms.items.Count -eq 1) 'chat-room group and remark filter'
    Assert-True ([string]$rooms.items[0].user_remark -eq 'My Work Group') 'chat-room remark persisted'
    Invoke-Api DELETE "/api/user/chat-rooms/$roomId" $primaryHeaders @{} | Out-Null
    Invoke-Api DELETE "/api/user/chat-room-groups/$($roomGroup.group_id)" $primaryHeaders @{} | Out-Null
} finally {
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary app cleanup failed: $($_.Exception.Message)" }
    }
}

Write-Host 'Contact and chat-room group smoke test passed.'
Write-Host "checks=$Checks"
