param(
    [string]$BaseUrl = 'http://127.0.0.1:8788'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )
    $parameters = @{
        Method = $Method
        Uri = "$BaseUrl$Path"
        Headers = $Headers
        UseBasicParsing = $true
    }
    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json; charset=utf-8'
        $parameters.Body = $Body | ConvertTo-Json -Depth 16 -Compress
    }
    try {
        $response = Invoke-RestMethod @parameters
    } catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) { throw "$Method $Path failed: $($response.msg)" }
    return $response.data
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw "Assertion failed: $Message" }
}

$adminHeaders = $null
$appId = 0
try {
    Invoke-Api -Method GET -Path '/api/health' | Out-Null
    $admin = Invoke-Api -Method POST -Path '/api/admin/login' -Body @{
        account = 'admin'; password = '123456'; device = 'forum-experience-smoke'
    }
    $adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $app = Invoke-Api -Method POST -Path '/api/admin/apps' -Headers $adminHeaders -Body @{
        name = "论坛体验测试 $suffix"; description = '论坛分节、互动、热度与管理下钻自动测试'
    }
    $appId = [long]$app.app.id
    $appKey = [string]$app.app.app_key
    $plate = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/forum-plates" -Headers $adminHeaders -Body @{
        name = '体验板块'; description = '用于自动化闭环测试'; sort_order = 10
    }
    $plateId = [long]$plate.plate_id
    $category = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/forum-categories" -Headers $adminHeaders -Body @{
        plate_id = $plateId; name = '游戏攻略'; description = '二级分类自动化测试'; sort_order = 20
    }
    $categoryId = [long]$category.category_id
    $tag = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/forum-tags" -Headers $adminHeaders -Body @{
        plate_id = $plateId; category_id = $categoryId; name = '我的世界'; aliases = @('MC', 'Minecraft', '麦块');
        description = '规范标签与别名自动化测试'; sort_order = 20
    }
    Assert-True ([long]$tag.tag_id -gt 0) 'admin must create a canonical forum tag'

    $firstAccount = "forum_a_$suffix"
    $secondAccount = "forum_b_$suffix"
    $first = Invoke-Api -Method POST -Path '/api/user/register' -Body @{
        app_key = $appKey; account = $firstAccount; nickname = '内容作者'; password = '123456';
        password_confirmation = '123456'; device = 'forum-smoke-a'
    }
    $second = Invoke-Api -Method POST -Path '/api/user/register' -Body @{
        app_key = $appKey; account = $secondAccount; nickname = '内容读者'; password = '123456';
        password_confirmation = '123456'; device = 'forum-smoke-b'
    }
    $firstHeaders = @{ Authorization = "Bearer $($first.access_token)"; 'X-App-Key' = $appKey }
    $secondHeaders = @{ Authorization = "Bearer $($second.access_token)"; 'X-App-Key' = $appKey }

    $userCategories = Invoke-Api -Method GET -Path "/api/user/forum-categories?plate_id=$plateId" -Headers $firstHeaders
    Assert-True (@($userCategories.items | Where-Object { [long]$_.id -eq $categoryId }).Count -eq 1) 'user must read the second-level category'
    $userTags = Invoke-Api -Method GET -Path "/api/user/forum-tags?plate_id=$plateId&category_id=$categoryId&keyword=MC" -Headers $firstHeaders
    $canonicalTag = @($userTags.items | Where-Object { $_.name -eq '我的世界' })[0]
    Assert-True ($null -ne $canonicalTag) 'tag alias search must find the canonical tag'
    Assert-True (@($canonicalTag.aliases) -contains 'Minecraft') 'tag response must expose normalized aliases'

    $structureRequest = Invoke-Api -Method POST -Path '/api/user/forum-structure-requests' -Headers $firstHeaders -Body @{
        request_type = 'category'; plate_id = $plateId; name = '玩家作品';
        description = '用户申请创建的二级分类'; reason = '验证申请和审核闭环'
    }
    $requestId = [long]$structureRequest.request_id
    $pendingRequests = Invoke-Api -Method GET -Path "/api/admin/apps/$appId/forum-structure-requests?status=pending" -Headers $adminHeaders
    Assert-True (@($pendingRequests.items | Where-Object { [long]$_.id -eq $requestId }).Count -eq 1) 'admin must see the pending structure request'
    $review = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/forum-structure-requests/$requestId/review" -Headers $adminHeaders -Body @{
        decision = 'approve'; review_comment = '自动化测试通过'
    }
    Assert-True ([long]$review.created_id -gt 0) 'approved category request must create a category'
    $categoriesAfterReview = Invoke-Api -Method GET -Path "/api/user/forum-categories?plate_id=$plateId&keyword=%E7%8E%A9%E5%AE%B6%E4%BD%9C%E5%93%81" -Headers $secondHeaders
    Assert-True (@($categoriesAfterReview.items | Where-Object { $_.name -eq '玩家作品' }).Count -eq 1) 'approved category must be visible to users'

    $card = Invoke-Api -Method POST -Path "/api/admin/apps/$appId/card-batches" -Headers $adminHeaders -Body @{
        name = '论坛购买测试卡'; total_count = 1; max_use = 1; value_json = @{ balance = 20 }
    }
    Invoke-Api -Method POST -Path '/api/user/cards/redeem' -Headers $secondHeaders -Body @{
        card_code = $card.codes[0]
    } | Out-Null

    $created = Invoke-Api -Method POST -Path '/api/user/forum-posts' -Headers $firstHeaders -Body @{
        plate_id = $plateId
        title = '可排序的免费与付费内容节'
        content = '这是公开导语。'
        tags = @('论坛闭环', '分节付费')
        sections = @(
            @{ section_type = 'free'; title = '公开说明'; content = '免费节正文'; tags = @('免费') },
            @{ section_type = 'paid'; title = '付费教程'; content = '只有购买者和管理者能看到的正文'; price_balance = 2; tags = @('付费') }
        )
    }
    $postId = [long]$created.post_id
    Assert-True (@($created.section_ids).Count -eq 2) 'post must create two ordered sections'

    $firstView = Invoke-Api -Method GET -Path "/api/user/forum-posts/$postId" -Headers $secondHeaders
    $firstUnique = [long]$firstView.post.unique_view_count
    $secondView = Invoke-Api -Method GET -Path "/api/user/forum-posts/$postId" -Headers $secondHeaders
    Assert-True ([long]$secondView.post.unique_view_count -eq $firstUnique) 'same user must not increase unique views twice'
    $paid = @($secondView.post.sections | Where-Object { $_.section_type -eq 'paid' })[0]
    Assert-True ([bool]$paid.locked) 'paid section must be locked before purchase'
    Assert-True ([string]::IsNullOrEmpty([string]$paid.content)) 'locked section must hide content'

    Invoke-Api -Method POST -Path "/api/user/forum-posts/$postId/sections/$($paid.id)/buy" -Headers $secondHeaders -Body @{} | Out-Null
    $afterPurchase = Invoke-Api -Method GET -Path "/api/user/forum-posts/$postId" -Headers $secondHeaders
    $unlocked = @($afterPurchase.post.sections | Where-Object { $_.id -eq $paid.id })[0]
    Assert-True (-not [bool]$unlocked.locked) 'buyer must unlock purchased section'
    Assert-True ([string]$unlocked.content -eq '只有购买者和管理者能看到的正文') 'buyer must receive paid content'

    $comment = Invoke-Api -Method POST -Path "/api/user/forum-posts/$postId/comments" -Headers $secondHeaders -Body @{
        content = '这是一级评论'; tags = @('讨论')
    }
    $commentId = [long]$comment.comment_id
    $reply = Invoke-Api -Method POST -Path "/api/user/forum-posts/$postId/comments" -Headers $firstHeaders -Body @{
        content = '这是对评论的回复'; parent_id = $commentId
    }
    Assert-True ([long]$reply.comment_id -gt 0) 'reply must be created in the same post'
    Invoke-Api -Method POST -Path "/api/user/forum-content/comment/$commentId/like" -Headers $firstHeaders -Body @{} | Out-Null
    Invoke-Api -Method POST -Path "/api/user/forum-content/comment/$commentId/favorite" -Headers $firstHeaders -Body @{} | Out-Null
    Invoke-Api -Method PUT -Path "/api/user/forum-posts/$postId/comments/$commentId/pin" -Headers $firstHeaders -Body @{
        enabled = $true; sort_order = 10
    } | Out-Null
    Invoke-Api -Method PUT -Path "/api/user/forum-personal/plate/$plateId/position" -Headers $secondHeaders -Body @{
        position = 'top'; sort_order = 1
    } | Out-Null
    Invoke-Api -Method PUT -Path "/api/user/forum-personal/post/$postId/position" -Headers $secondHeaders -Body @{
        position = 'bottom'; sort_order = 1
    } | Out-Null

    $userDetail = Invoke-Api -Method GET -Path "/api/user/forum-posts/$postId" -Headers $secondHeaders
    $pinnedComment = @($userDetail.post.comments | Where-Object { $_.id -eq $commentId })[0]
    Assert-True ([int]$pinnedComment.is_pinned -eq 1) 'post author must pin a comment'
    Assert-True ([int]$pinnedComment.like_count -eq 1) 'comment like count must be synchronized'
    Assert-True ([int]$pinnedComment.favorite_count -eq 1) 'comment favorite count must be synchronized'

    $adminDetail = Invoke-Api -Method GET -Path "/api/admin/apps/$appId/forum-posts/$postId" -Headers $adminHeaders
    Assert-True (@($adminDetail.post.sections).Count -eq 2) 'admin must inspect all sections'
    Assert-True (@($adminDetail.post.comments).Count -eq 2) 'admin must inspect comments and replies'

    $root = Invoke-Api -Method POST -Path '/api/platform/login' -Body @{
        account = 'root'; password = '123456'; device = 'forum-platform-smoke'
    }
    $rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
    $platformPlates = Invoke-Api -Method GET -Path "/api/platform/apps/$appId/forum-plates" -Headers $rootHeaders
    $platformPosts = Invoke-Api -Method GET -Path "/api/platform/apps/$appId/forum-posts?plate_id=$plateId" -Headers $rootHeaders
    $platformDetail = Invoke-Api -Method GET -Path "/api/platform/apps/$appId/forum-posts/$postId" -Headers $rootHeaders
    Assert-True (@($platformPlates.items).Count -eq 1) 'platform must drill down to forum plates'
    Assert-True (@($platformPosts.items).Count -eq 1) 'platform must drill down to forum posts'
    Assert-True (@($platformDetail.post.sections).Count -eq 2) 'platform must inspect paid sections for governance'

    Write-Host 'Forum experience closed-loop smoke test passed.'
    Write-Host "app_id=$appId post_id=$postId plate_id=$plateId"
} finally {
    if ($appId -gt 0 -and $null -ne $adminHeaders) {
        try {
            Invoke-Api -Method DELETE -Path "/api/admin/apps/$appId" -Headers $adminHeaders -Body @{ confirm = 'DELETE' } | Out-Null
        } catch {
            Write-Warning "Test app cleanup failed: $($_.Exception.Message)"
        }
    }
}
