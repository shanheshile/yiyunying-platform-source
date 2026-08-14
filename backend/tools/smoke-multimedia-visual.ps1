param([string]$BaseUrl = 'http://127.0.0.1:8792')

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

function Invoke-Upload {
    param(
        [hashtable]$Headers,
        [string]$FilePath,
        [string]$Scene,
        [string]$ContentType = 'application/zip'
    )
    Add-Type -AssemblyName System.Net.Http
    $client = [System.Net.Http.HttpClient]::new()
    $multipart = [System.Net.Http.MultipartFormDataContent]::new()
    try {
        $token = [string]$Headers.Authorization -replace '^Bearer\s+', ''
        $client.DefaultRequestHeaders.Authorization = [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $token)
        if ($Headers.ContainsKey('X-App-Key')) {
            $client.DefaultRequestHeaders.Add('X-App-Key', [string]$Headers['X-App-Key'])
        }
        $bytes = [System.IO.File]::ReadAllBytes($FilePath)
        $fileContent = New-Object System.Net.Http.ByteArrayContent -ArgumentList @(,$bytes)
        $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::new($ContentType)
        $multipart.Add($fileContent, 'file', [System.IO.Path]::GetFileName($FilePath))
        $multipart.Add([System.Net.Http.StringContent]::new($Scene), 'scene')
        $httpResponse = $client.PostAsync("$BaseUrl/api/user/uploads", $multipart).Result
        $json = $httpResponse.Content.ReadAsStringAsync().Result | ConvertFrom-Json
        if (-not $httpResponse.IsSuccessStatusCode -or $json.code -ne 1) {
            throw "POST /api/user/uploads failed: $($json.msg)"
        }
        $script:Checks++
        return $json.data
    } finally {
        $multipart.Dispose()
        $client.Dispose()
    }
}

function New-ZipFixture {
    param([string]$Path, [hashtable]$Entries)
    Add-Type -AssemblyName System.IO.Compression
    $stream = [System.IO.File]::Open($Path, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create, $true)
        try {
            foreach ($name in $Entries.Keys) {
                $entry = $archive.CreateEntry([string]$name)
                $writer = [System.IO.StreamWriter]::new($entry.Open(), [System.Text.UTF8Encoding]::new($false))
                try { $writer.Write([string]$Entries[$name]) } finally { $writer.Dispose() }
            }
        } finally { $archive.Dispose() }
    } finally { $stream.Dispose() }
}

function Media-Fixture([int]$StickerId) {
    return @(
        @{ media_type = 'image'; url = 'https://example.com/media/image-1.png'; mime_type = 'image/png'; width = 800; height = 600 },
        @{ media_type = 'image'; url = 'https://example.com/media/image-2.jpg'; mime_type = 'image/jpeg'; width = 1200; height = 800 },
        @{ sticker_id = $StickerId },
        @{ media_type = 'audio'; url = 'https://example.com/media/voice.m4a'; mime_type = 'audio/mp4'; duration_ms = 3200 },
        @{ media_type = 'video'; url = 'https://example.com/media/demo.mp4'; mime_type = 'video/mp4'; duration_ms = 9800 },
        @{ media_type = 'file'; url = 'https://example.com/media/readme.pdf'; mime_type = 'application/pdf'; file_name = '说明书.pdf'; size_bytes = 2048 }
    )
}

$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$appId = 0
$fixturePath = Join-Path $env:TEMP "yiyunying-multimedia-$suffix.zip"

$root = Invoke-Api POST '/api/platform/login' @{} @{ account = 'root'; password = '123456'; device = 'multimedia-visual-smoke' }
$rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
$admin = Invoke-Api POST '/api/admin/login' @{} @{ account = 'admin'; password = '123456'; device = 'multimedia-visual-smoke' }
$adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }

try {
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "多媒体可视化测试 $suffix"; description = '临时自动化闭环测试应用'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        forum_post_audit = $false; resource_submit_audit = $false; user_poll_create_enabled = $true
        private_message_enabled = $true; message_recall_seconds = 600
    } } | Out-Null

    $a = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "media_a_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '媒体甲'
    }
    $b = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey; account = "media_b_$suffix"; password = '123456'; password_confirmation = '123456'; nickname = '媒体乙'
    }
    $userA = [int]$a.user.id
    $userB = [int]$b.user.id
    $headersA = @{ Authorization = "Bearer $($a.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($b.access_token)"; 'X-App-Key' = $appKey }

    New-ZipFixture $fixturePath @{ 'README.txt' = 'verified multimedia smoke fixture' }
    $forumUploadA = Invoke-Upload $headersA $fixturePath 'forum_post'
    $forumUploadB = Invoke-Upload $headersB $fixturePath 'forum_comment'

    Invoke-Api PUT '/api/user/profile' $headersA @{
        nickname = '媒体甲'; qq = '10086'; signature = '隐藏详情测试'; public_profile = $false
    } | Out-Null
    $hiddenProfile = Invoke-Api GET "/api/user/profiles/$userA" $headersB
    Assert-True ([bool]$hiddenProfile.profile.details_hidden) 'hidden profile remains reachable'
    Assert-True ($hiddenProfile.profile.profile_visibility -eq 'basic') 'hidden profile returns basic visibility'
    Assert-True (-not ($hiddenProfile.profile.PSObject.Properties.Name -contains 'qq')) 'hidden profile does not leak QQ'
    Assert-True ($hiddenProfile.profile.nickname -eq '媒体甲') 'hidden profile still exposes basic nickname'

    $friendRequest = Invoke-Api POST '/api/user/friends/requests' $headersA @{ to_user_id = $userB; message = '多媒体测试好友' }
    Invoke-Api POST "/api/user/friends/requests/$($friendRequest.request_id)/accept" $headersB @{} | Out-Null

    $pack = Invoke-Api POST '/api/user/sticker-packs' $headersA @{ name = '我的测试表情' }
    $sticker = Invoke-Api POST "/api/user/sticker-packs/$($pack.pack_id)/stickers" $headersA @{
        name = '开心'; image_url = 'https://example.com/media/sticker.png'; width = 256; height = 256
    }
    $packs = Invoke-Api GET '/api/user/sticker-packs' $headersA
    Assert-True (@($packs.items).Count -eq 1 -and @($packs.items[0].stickers).Count -eq 1) 'sticker pack is fully readable'
    $media = Media-Fixture ([int]$sticker.sticker_id)

    $private = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = '文字、图片和附件一起发送 😀'; attachments = $media
    }
    $privateRead = Invoke-Api GET "/api/user/conversations/$($private.conversation_id)/messages?limit=100" $headersB
    $privateItem = @($privateRead.items | Where-Object { [int]$_.id -eq [int]$private.message_id })[0]
    Assert-True ($privateItem.content_type -eq 'mixed') 'private mixed message type'
    Assert-True ([int]$privateItem.attachment_count -eq 6) 'private mixed attachment count'
    Assert-True (@($privateItem.attachments | Where-Object { $_.media_type -eq 'image' }).Count -eq 2) 'private message has two images'
    Assert-True (@($privateItem.attachments | Where-Object { $_.media_type -eq 'sticker' }).Count -eq 1) 'private message has sticker'

    $service = Invoke-Api POST '/api/user/service/messages' $headersA @{
        subject = '多媒体客服'; content = '客服图文消息'; attachments = @($media[0], $media[3], $media[5])
    }
    $serviceRead = Invoke-Api GET '/api/user/service/messages?limit=100' $headersA
    $serviceItem = @($serviceRead.items | Where-Object { [int]$_.id -eq [int]$service.message_id })[0]
    Assert-True ([int]$serviceItem.attachment_count -eq 3) 'service message supports mixed media'
    Assert-True (-not [bool]$serviceItem.can_recall) 'service message cannot be recalled'
    Assert-True (-not [bool]$serviceRead.message_recall_allowed) 'service session forbids recall'

    $room = Invoke-Api POST '/api/user/chat-rooms' $headersA @{ name = "媒体群 $suffix"; join_mode = 'open'; max_members = 20 }
    $roomId = [int]$room.room.id
    Invoke-Api POST "/api/user/chat-rooms/$roomId/join" $headersB @{} | Out-Null
    $group = Invoke-Api POST "/api/user/chat-rooms/$roomId/messages" $headersA @{
        content = '群聊混合消息'; attachments = @($media[0], $media[1], $media[4], $media[5])
    }
    $groupRead = Invoke-Api GET "/api/user/chat-rooms/$roomId/messages?limit=100" $headersB
    $groupItem = @($groupRead.items | Where-Object { [int]$_.id -eq [int]$group.message_id })[0]
    Assert-True ([int]$groupItem.attachment_count -eq 4) 'group message supports mixed media'

    $plate = Invoke-Api POST "/api/admin/apps/$appId/forum-plates" $adminHeaders @{
        name = "可视化板块 $suffix"; description = '点击板块进入帖子列表'
    }
    $post = Invoke-Api POST '/api/user/forum-posts' $headersA @{
        plate_id = [int]$plate.plate_id; title = '可点击进入的多媒体帖子'; content = '帖子正文 😀'
        attachments = @(@{ upload_id = [int]$forumUploadA.upload_id }, $media[2])
    }
    $comment = Invoke-Api POST "/api/user/forum-posts/$($post.post_id)/comments" $headersB @{
        content = '带已验证附件的评论'; attachments = @(@{ upload_id = [int]$forumUploadB.upload_id })
    }
    Invoke-Api POST "/api/user/forum-posts/$($post.post_id)/favorite" $headersB @{} | Out-Null
    $forumByPlate = Invoke-Api GET "/api/user/forum-posts?plate_id=$($plate.plate_id)&limit=100" $headersB
    Assert-True (@($forumByPlate.items | Where-Object { [int]$_.id -eq [int]$post.post_id }).Count -eq 1) 'plate filter returns its post'
    $postDetail = Invoke-Api GET "/api/user/forum-posts/$($post.post_id)" $headersB
    Assert-True ([int]$postDetail.post.user_id -eq $userA) 'post detail links author user id'
    Assert-True ([int]$postDetail.post.attachment_count -eq 2) 'post detail exposes only ID-bound public media'
    $commentItem = @($postDetail.post.comments | Where-Object { [int]$_.id -eq [int]$comment.comment_id })[0]
    Assert-True ([int]$commentItem.attachment_count -eq 1) 'forum comment exposes verified upload media'

    $category = Invoke-Api POST '/api/user/poll-categories' $headersA @{ name = '产品偏好'; color = '#1677ff' }
    $poll = Invoke-Api POST '/api/user/polls' $headersA @{
        title = '你更喜欢哪种展示'; description = '选项应当可视化显示'; category_ids = @([int]$category.category_id)
        options = @(
            @{ option_text = '列表'; image_url = 'https://example.com/media/list.png' },
            @{ option_text = '宫格'; image_url = 'https://example.com/media/grid.png' },
            @{ option_text = '时间线' }
        )
        multiple_choice = $true; min_select = 1; max_select = 2; result_visibility = 'always'
    }
    $pollDetail = Invoke-Api GET "/api/user/polls/$($poll.poll_id)" $headersB
    Assert-True (@($pollDetail.poll.categories).Count -eq 1) 'poll detail exposes categories'
    Assert-True (@($pollDetail.poll.options).Count -eq 3) 'poll detail exposes concrete options'
    Invoke-Api POST "/api/user/polls/$($poll.poll_id)/vote" $headersB @{
        option_ids = @([int]$pollDetail.poll.options[0].id, [int]$pollDetail.poll.options[1].id)
    } | Out-Null

    $resourceCategory = Invoke-Api POST "/api/admin/apps/$appId/resource-categories" $adminHeaders @{ name = '媒体资源' }
    $resourceUpload = Invoke-Upload $headersA $fixturePath 'resource_source'
    $resource = Invoke-Api POST '/api/user/resources' $headersA @{
        category_id = [int]$resourceCategory.category_id; title = '图文资源'; description = '资源详情'
        resource_type = 'source_market'; source_upload_id = [int]$resourceUpload.upload_id
        attachments = @(@{ upload_id = [int]$forumUploadA.upload_id }, $media[2])
    }
    Invoke-Api PUT "/api/admin/apps/$appId/resources/$($resource.resource_id)/audit" $adminHeaders @{
        audit_status = 'approved'; override_risk = $true
    } | Out-Null
    $resourceDetail = Invoke-Api GET "/api/user/resources/$($resource.resource_id)" $headersB
    Assert-True ([int]$resourceDetail.resource.attachment_count -eq 2) 'resource detail exposes only ID-bound media'

    Invoke-Api POST "/api/user/messages/$($private.message_id)/recall" $headersA @{} | Out-Null
    $afterRecall = Invoke-Api GET "/api/user/conversations/$($private.conversation_id)/messages?limit=100" $headersB
    $recalledUserItem = @($afterRecall.items | Where-Object { [int]$_.id -eq [int]$private.message_id })[0]
    Assert-True ([bool]$recalledUserItem.recalled -and -not [string]::IsNullOrWhiteSpace([string]$recalledUserItem.content) -and $recalledUserItem.content -notlike '文字、图片*') 'normal user sees configured recall notice'
    Assert-True ([int]$recalledUserItem.attachment_count -eq 0) 'normal user cannot access recalled attachments'
    Assert-True ([bool]$recalledUserItem.attachments_hidden_by_recall) 'recall attachment masking is explicit'

    $adminAudit = Invoke-Api GET "/api/admin/apps/$appId/users/$userA/communications?channel_type=private&channel_id=$($private.conversation_id)&limit=100" $adminHeaders
    $adminOriginal = @($adminAudit.items | Where-Object { [int]$_.id -eq [int]$private.message_id })[0]
    Assert-True ([bool]$adminOriginal.recalled -and [int]$adminOriginal.attachment_count -eq 6) 'admin audit preserves recalled original media'
    Assert-True ($adminOriginal.content -like '文字、图片*') 'admin audit preserves recalled original text'

    $adminOverview = Invoke-Api GET "/api/admin/apps/$appId/users/$userA" $adminHeaders
    Assert-True ($adminOverview.sections.PSObject.Properties.Name -contains '消息类') 'admin profile has message section'
    Assert-True ($adminOverview.sections.PSObject.Properties.Name -contains '社交类') 'admin profile has social section'
    Assert-True ($adminOverview.sections.PSObject.Properties.Name -contains '内容类') 'admin profile has content section'
    Assert-True (@($adminOverview.sections.'内容类'.'论坛帖子').Count -ge 1) 'admin profile links authored posts'

    $platformOverview = Invoke-Api GET "/api/platform/apps/$appId/users/$userA/overview" $rootHeaders
    Assert-True ([int]$platformOverview.scope.app_id -eq $appId) 'root oversight reaches app user'
    $platformAudit = Invoke-Api GET "/api/platform/apps/$appId/users/$userA/communications?channel_type=private&channel_id=$($private.conversation_id)&limit=100" $rootHeaders
    Assert-True (@($platformAudit.items | Where-Object { [int]$_.id -eq [int]$private.message_id }).Count -eq 1) 'root can audit recalled conversation in scope'

    Write-Host "Multimedia/visual smoke passed: $script:Checks checks" -ForegroundColor Green
}
finally {
    Remove-Item -LiteralPath $fixturePath -Force -ErrorAction SilentlyContinue
    if ($appId -gt 0) {
        try { Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Cleanup failed for app $appId`: $($_.Exception.Message)" }
    }
}
