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
    if ([int]$response.code -ne 1) {
        throw "$Method $Path failed: $($response | ConvertTo-Json -Depth 10 -Compress)"
    }
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function Register-TestUser([string]$AppKey, [string]$Account, [string]$Nickname) {
    $headers = @{ 'X-App-Key' = $AppKey }
    return Invoke-Api POST '/api/user/register' $headers @{
        app_key = $AppKey
        account = $Account
        password = '123456'
        password_confirmation = '123456'
        nickname = $Nickname
    }
}

function User-Headers($Registration, [string]$AppKey) {
    return @{
        Authorization = "Bearer $($Registration.access_token)"
        'X-App-Key' = $AppKey
    }
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    account = 'admin'
    password = '123456'
    device = 'moment-like-visibility-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

try {
    $app = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Moment Like Visibility Smoke $suffix"
        description = 'Moment like identity privacy boundary test'
    }
    $appId = [int]$app.app.id
    $appKey = [string]$app.app.app_key

    $owner = Register-TestUser $appKey "moment_owner_$suffix" 'Moment owner'
    $common = Register-TestUser $appKey "moment_common_$suffix" 'Mutual friend liker'
    $stranger = Register-TestUser $appKey "moment_stranger_$suffix" 'Stranger liker'
    $viewer = Register-TestUser $appKey "moment_viewer_$suffix" 'Moment viewer'

    $ownerHeaders = User-Headers $owner $appKey
    $commonHeaders = User-Headers $common $appKey
    $strangerHeaders = User-Headers $stranger $appKey
    $viewerHeaders = User-Headers $viewer $appKey

    # The liker is a friend of both the author and the viewer.
    $requestOne = Invoke-Api POST '/api/user/friends/requests' $ownerHeaders @{
        to_uid = [string]$common.user.uid
        message = 'Owner adds mutual friend'
    }
    Invoke-Api POST "/api/user/friends/requests/$($requestOne.request_id)/accept" $commonHeaders @{} | Out-Null
    $requestTwo = Invoke-Api POST '/api/user/friends/requests' $viewerHeaders @{
        to_uid = [string]$common.user.uid
        message = 'Viewer adds mutual friend'
    }
    Invoke-Api POST "/api/user/friends/requests/$($requestTwo.request_id)/accept" $commonHeaders @{} | Out-Null

    $created = Invoke-Api POST '/api/user/moments' $ownerHeaders @{
        content = 'Moment like identity privacy regression test'
        attachments = @()
        visibility_mode = 'public'
    }
    $momentId = [int]$created.moment.id

    Invoke-Api POST "/api/user/moments/$momentId/like" $commonHeaders @{} | Out-Null
    Invoke-Api POST "/api/user/moments/$momentId/like" $strangerHeaders @{} | Out-Null

    $viewerLikes = Invoke-Api GET "/api/user/moments/$momentId/likes?page=1&limit=20" $viewerHeaders
    Assert-True ([int]$viewerLikes.like_visibility.total_count -eq 2) 'The total like count is not filtered.'
    Assert-True ([int]$viewerLikes.like_visibility.visible_count -eq 1) 'Only mutual-friend liker identities are visible by default.'
    Assert-True ([int]$viewerLikes.like_visibility.hidden_count -eq 1) 'The stranger liker identity is hidden by default.'
    Assert-True ($viewerLikes.items.Count -eq 1) 'The viewer receives exactly one visible liker.'
    Assert-True ([int]$viewerLikes.items[0].user_id -eq [int]$common.user.id) 'The visible liker is the mutual friend.'
    Assert-True ([bool]$viewerLikes.items[0].is_common_friend) 'The mutual-friend marker is correct.'

    $ownerLikes = Invoke-Api GET "/api/user/moments/$momentId/likes?page=1&limit=20" $ownerHeaders
    Assert-True ([int]$ownerLikes.like_visibility.visible_count -eq 2) 'The moment owner sees every liker.'
    Assert-True ($ownerLikes.items.Count -eq 2) 'The owner API returns every liker identity.'

    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
        settings = @{ moment_like_non_friend_visible = $true }
    } | Out-Null
    $publicLikes = Invoke-Api GET "/api/user/moments/$momentId/likes?page=1&limit=20" $viewerHeaders
    Assert-True ([string]$publicLikes.like_visibility.mode -eq 'all') 'The admin switch changes visibility to all.'
    Assert-True ([int]$publicLikes.like_visibility.visible_count -eq 2) 'Non-friend liker identities are visible after enabling the switch.'
    Assert-True ($publicLikes.items.Count -eq 2) 'Every liker is returned after enabling the switch.'

    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
        settings = @{ moment_like_non_friend_visible = $false }
    } | Out-Null
    $restored = Invoke-Api GET "/api/user/moments/$momentId/likes?page=1&limit=20" $viewerHeaders
    Assert-True ([string]$restored.like_visibility.mode -eq 'mutual_friends') 'Disabling the switch restores the mutual-friend policy.'
    Assert-True ([int]$restored.like_visibility.visible_count -eq 1) 'The stranger liker is hidden again after disabling the switch.'
} finally {
    if ($appId -gt 0) {
        try {
            Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null
        } catch {
            Write-Warning "Temporary app cleanup failed: $($_.Exception.Message)"
        }
    }
}

Write-Host 'Moment like visibility smoke test passed.'
Write-Host "checks=$Checks"
