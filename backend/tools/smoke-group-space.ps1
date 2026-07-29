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
$adminHeaders = @{}

try {
    $admin = Invoke-Api POST '/api/admin/login' @{} @{ account = 'admin'; password = '123456'; device = 'group-space-smoke' }
    $adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{ name = "Group Space Test $suffix"; description = 'Automated group space closure test' }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key

    $a = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "group_a_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'Group User A'
    }
    $b = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "group_b_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = 'Group User B'
    }
    $headersA = @{ Authorization = "Bearer $($a.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($b.access_token)"; 'X-App-Key' = $appKey }

    $createdRoom = Invoke-Api POST '/api/user/chat-rooms' $headersA @{ name = "Group Space $suffix"; join_mode = 'open'; max_members = 20 }
    $roomId = [int]$createdRoom.room.id
    Invoke-Api POST "/api/user/chat-rooms/$roomId/join" $headersB @{} | Out-Null

    $rootFile = Invoke-Api POST "/api/user/chat-rooms/$roomId/files" $headersA @{
        name = 'group-manual.pdf'; file_url = '/uploads/group/manual.pdf'; mime_type = 'application/pdf'; size_bytes = 4096
    }
    $folder = Invoke-Api POST "/api/user/chat-rooms/$roomId/files" $headersA @{
        name = 'Project Files'; is_folder = $true
    }
    $folderId = [int]$folder.file_id
    $nestedFolder = Invoke-Api POST "/api/user/chat-rooms/$roomId/files" $headersA @{
        name = 'Archive'; is_folder = $true; parent_id = $folderId
    }
    $nestedFile = Invoke-Api POST "/api/user/chat-rooms/$roomId/files" $headersA @{
        name = 'group-plan.docx'; file_url = '/uploads/group/plan.docx'; mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; size_bytes = 8192; parent_id = $folderId
    }

    $rootFiles = Invoke-Api GET "/api/user/chat-rooms/$roomId/files" $headersB
    Assert-True (@($rootFiles.items).Count -eq 2) 'root lists one file and one folder'
    $listedRootFile = @($rootFiles.items | Where-Object { [int]$_.id -eq [int]$rootFile.file_id })[0]
    $listedFolder = @($rootFiles.items | Where-Object { [int]$_.id -eq $folderId })[0]
    Assert-True ($listedRootFile.name -eq 'group-manual.pdf') 'group file preserves file name'
    Assert-True ([bool]$listedFolder.is_folder) 'folder is marked as a folder'
    Assert-True ([int]$listedFolder.child_count -eq 2) 'folder reports direct child count'

    $folderFiles = Invoke-Api GET "/api/user/chat-rooms/$roomId/files?parent_id=$folderId" $headersB
    Assert-True ([int]$folderFiles.parent.id -eq $folderId) 'folder response includes current parent'
    Assert-True (@($folderFiles.items).Count -eq 2) 'folder lists nested folder and file'
    Assert-True (@($folderFiles.items | Where-Object { $_.name -eq 'group-plan.docx' }).Count -eq 1) 'nested file appears only in its folder'

    $download = Invoke-Api POST "/api/user/chat-rooms/$roomId/files/$($nestedFile.file_id)/download" $headersB @{}
    Assert-True ([int]$download.download_count -eq 1) 'download endpoint increments the counter'
    $folderFilesAfterDownload = Invoke-Api GET "/api/user/chat-rooms/$roomId/files?parent_id=$folderId" $headersA
    $downloadedFile = @($folderFilesAfterDownload.items | Where-Object { [int]$_.id -eq [int]$nestedFile.file_id })[0]
    Assert-True ([int]$downloadedFile.download_count -eq 1) 'download count persists in file list'

    $deletedFolder = Invoke-Api DELETE "/api/user/chat-rooms/$roomId/files/$folderId" $headersA @{}
    Assert-True ([int]$deletedFolder.deleted_count -eq 3) 'deleting a folder recursively removes its descendants'
    $rootAfterDelete = Invoke-Api GET "/api/user/chat-rooms/$roomId/files" $headersB
    Assert-True (@($rootAfterDelete.items).Count -eq 1) 'recursive delete keeps unrelated root files'
    Assert-True ([int]$rootAfterDelete.items[0].id -eq [int]$rootFile.file_id) 'unrelated root file remains available'

    $album = Invoke-Api POST "/api/user/chat-rooms/$roomId/albums" $headersA @{ name = 'Event Album'; description = 'Unified image and video preview' }
    $albumId = [int]$album.album_id
    $image = Invoke-Api POST "/api/user/chat-rooms/$roomId/albums/$albumId/photos" $headersA @{
        image_url = '/uploads/group/photo.jpg'; media_type = 'image'; mime_type = 'image/jpeg'; size_bytes = 52428800; caption = 'large-original.jpg'
    }
    $video = Invoke-Api POST "/api/user/chat-rooms/$roomId/albums/$albumId/photos" $headersB @{
        image_url = '/uploads/group/video.mp4'; media_type = 'video'; mime_type = 'video/mp4'; size_bytes = 104857600; caption = 'event-video.mp4'
    }
    $albums = Invoke-Api GET "/api/user/chat-rooms/$roomId/albums" $headersA
    $listedAlbum = @($albums.items | Where-Object { [int]$_.id -eq $albumId })[0]
    Assert-True ([int]$listedAlbum.photo_count -eq 2) 'album counts image and video'
    Assert-True (@($listedAlbum.photos | Where-Object { $_.media_type -eq 'image' }).Count -eq 1) 'album exposes image media type'
    Assert-True (@($listedAlbum.photos | Where-Object { $_.media_type -eq 'video' }).Count -eq 1) 'album exposes video media type'
    Assert-True ([int64](@($listedAlbum.photos | Where-Object { $_.media_type -eq 'video' })[0].size_bytes) -eq 104857600) 'album preserves video size'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$listedAlbum.creator_nickname)) 'album includes creator display name'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$listedAlbum.photos[0].uploader_nickname)) 'media includes uploader display name'

    $vote = Invoke-Api POST "/api/user/chat-rooms/$roomId/votes" $headersA @{
        title = 'Weekend Plan'; options = @('Movie', 'Park', 'Home'); multiple_choice = $true; min_select = 1; max_select = 2
    }
    $voteDetail = Invoke-Api GET "/api/user/chat-rooms/$roomId/votes/$($vote.vote_id)" $headersB
    $optionIds = @($voteDetail.vote.options | Select-Object -First 2 | ForEach-Object { [int]$_.id })
    Invoke-Api POST "/api/user/chat-rooms/$roomId/votes/$($vote.vote_id)/submit" $headersB @{ option_ids = $optionIds } | Out-Null
    $voteAfter = Invoke-Api GET "/api/user/chat-rooms/$roomId/votes/$($vote.vote_id)" $headersA
    $recordedVotes = 0
    @($voteAfter.vote.options) | ForEach-Object { $recordedVotes += [int]$_.vote_count }
    Assert-True ($recordedVotes -eq 2) 'group vote records both selected options'

    $solitaire = Invoke-Api POST "/api/user/chat-rooms/$roomId/solitaires" $headersA @{ title = 'Signup Chain'; description = 'Enter name and headcount' }
    Invoke-Api POST "/api/user/chat-rooms/$roomId/solitaires/$($solitaire.solitaire_id)/join" $headersA @{ content = 'Group User A, 2' } | Out-Null
    Invoke-Api POST "/api/user/chat-rooms/$roomId/solitaires/$($solitaire.solitaire_id)/join" $headersB @{ content = 'Group User B, 1' } | Out-Null
    $solitaireDetail = Invoke-Api GET "/api/user/chat-rooms/$roomId/solitaires/$($solitaire.solitaire_id)" $headersA
    Assert-True (@($solitaireDetail.solitaire.entries).Count -eq 2) 'solitaire detail lists both entries'
    Assert-True ($solitaireDetail.solitaire.entries[0].content -eq 'Group User A, 2') 'solitaire preserves insertion order'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$solitaireDetail.solitaire.entries[1].nickname)) 'solitaire includes participant name'

    $messages = Invoke-Api GET "/api/user/chat-rooms/$roomId/messages?limit=100" $headersB
    Assert-True (@($messages.items | Where-Object { $_.sender_role -eq 'system' -or $_.content_type -eq 'system' }).Count -ge 2) 'group operations create visible system notices'

    Write-Host "Group space smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for app $appId`: $($_.Exception.Message)" }
    }
}
