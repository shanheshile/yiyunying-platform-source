param(
    [string]$BaseUrl = 'http://127.0.0.1:8789'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Invoke-Api {
    param(
        [Parameter(Mandatory)][string]$Method,
        [Parameter(Mandatory)][string]$Path,
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
        $parameters.Body = $Body | ConvertTo-Json -Depth 20 -Compress
    }
    try {
        $response = Invoke-RestMethod @parameters
    } catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $detail = $reader.ReadToEnd()
                $reader.Dispose()
            } catch { }
        }
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path 请求失败：$detail"
    }
    if ([int]$response.code -ne 1) {
        throw "$Method $Path 业务失败：$($response.msg)"
    }
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "断言失败：$Message" }
}

$adminHeaders = @{}
$appId = 0
$appKey = ''

try {
    $health = Invoke-Api GET '/api/health'
    Assert-True ($null -ne $health) '健康检查可用'

    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        account = 'admin'
        password = '123456'
        device = '简云能力闭环测试'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "简云能力闭环 $suffix"
        description = '访问统计、在线心跳、登录卡、论坛审核举报和群恢复自动化测试'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key

    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{
        settings = @{
            forum_post_audit = $true
            forum_comment_audit = $true
            card_login_enabled = $true
            public_app_statistics_enabled = $true
            heartbeat_online_seconds = 120
            group_restore_days = 7
        }
    } | Out-Null

    $visitOne = Invoke-Api POST '/api/public/app/visit' @{ 'X-App-Key' = $appKey } @{
        visitor_id = "visitor-$suffix"
        source = 'android-test'
        path = '/首页'
    }
    $visitTwo = Invoke-Api POST '/api/public/app/visit' @{ 'X-App-Key' = $appKey } @{
        visitor_id = "visitor-$suffix"
        source = 'android-test'
        path = '/论坛'
    }
    Assert-True ([bool]$visitOne.unique_today) '首次访问计为独立访客'
    Assert-True (-not [bool]$visitTwo.unique_today) '同日同访客不重复计数'
    $publicStats = Invoke-Api GET "/api/public/app/statistics?app_key=$appKey"
    Assert-True ([int]$publicStats.visits.today.views -eq 2) '访问量累计为 2'
    Assert-True ([int]$publicStats.visits.today.visitors -eq 1) '独立访客累计为 1'

    $userA = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey
        account = "jy_a_$suffix"
        password = '123456'
        password_confirmation = '123456'
        nickname = '简云测试甲'
        device = '闭环测试设备甲'
    }
    $userB = Invoke-Api POST '/api/user/register' @{} @{
        app_key = $appKey
        account = "jy_b_$suffix"
        password = '123456'
        password_confirmation = '123456'
        nickname = '简云测试乙'
        device = '闭环测试设备乙'
    }
    $headersA = @{ Authorization = "Bearer $($userA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($userB.access_token)"; 'X-App-Key' = $appKey }
    $userAId = [int]$userA.user.id
    $userBId = [int]$userB.user.id
    $userBUid = [string]$userB.user.uid

    $userSearch = Invoke-Api GET "/api/user/users/search?keyword=$([uri]::EscapeDataString($userBUid))" $headersA
    Assert-True ([int]$userSearch.pagination.total -eq 1) '可以按 UID 搜索当前应用用户'
    Assert-True ([int]$userSearch.items[0].user_id -eq $userBId) '搜索结果返回正确用户并可联动资料页'
    Assert-True ([string]$userSearch.search_scope -eq 'current_app_only') '用户搜索严格限制在当前应用'

    Invoke-Api POST "/api/user/profiles/$userBId/follow" $headersA @{} | Out-Null
    $followStatus = Invoke-Api GET "/api/user/profiles/$userBId/follow-status" $headersA
    Assert-True ([bool]$followStatus.following) '关注状态可以明确查询'

    $plate = Invoke-Api POST "/api/admin/apps/$appId/forum-plates" $adminHeaders @{
        name = '能力测试板块'
        description = '专项闭环测试板块'
        sort_order = 100
    }
    $post = Invoke-Api POST '/api/user/forum-posts' $headersA @{
        plate_id = [int]$plate.plate_id
        title = "待审核帖子 $suffix"
        content = '这是用于验证发帖、审核、评论、点赞、举报和用户视图的正文。'
        tags = @('专项测试', '接口闭环')
    }
    Assert-True ([string]$post.audit_status -eq 'pending') '开启审核后帖子进入待审核'
    $postId = [int]$post.post_id
    Invoke-Api PUT "/api/admin/apps/$appId/forum-posts/$postId/audit" $adminHeaders @{
        audit_status = 'approved'
        reason = '自动化测试审核通过'
    } | Out-Null

    $comment = Invoke-Api POST "/api/user/forum-posts/$postId/comments" $headersB @{
        content = '这是一条需要管理员审核的评论。'
    }
    Assert-True ([string]$comment.audit_status -eq 'pending') '开启审核后评论进入待审核'
    $commentId = [int]$comment.comment_id
    $pendingComments = Invoke-Api GET "/api/admin/apps/$appId/forum-comments?audit_status=pending" $adminHeaders
    Assert-True ([int]$pendingComments.pagination.total -ge 1) '管理员可以看到待审核评论'
    Invoke-Api PUT "/api/admin/apps/$appId/forum-comments/$commentId/audit" $adminHeaders @{
        audit_status = 'approved'
        reason = '评论内容正常'
    } | Out-Null
    Invoke-Api POST "/api/user/forum-posts/$postId/like" $headersB @{} | Out-Null
    $likes = Invoke-Api GET "/api/user/forum-posts/$postId/likes" $headersA
    Assert-True ([int]$likes.pagination.total -eq 1) '帖子点赞列表可查询'
    $mine = Invoke-Api GET '/api/user/forum-posts/mine' $headersA
    $liked = Invoke-Api GET '/api/user/forum-posts/liked' $headersB
    Assert-True ([int]$mine.pagination.total -eq 1) '我的帖子视图可查询'
    Assert-True ([int]$liked.pagination.total -eq 1) '点赞过的帖子视图可查询'

    $reportTags = Invoke-Api GET '/api/user/forum-report-tags' $headersB
    Assert-True (@($reportTags.items).Count -ge 4) '新应用自动创建默认举报标签'
    $report = Invoke-Api POST '/api/user/reports' $headersB @{
        target_type = 'post'
        target_id = $postId
        report_tag_id = [int]$reportTags.items[0].id
        reason = '专项测试举报，不代表真实违规'
    }
    $myReports = Invoke-Api GET '/api/user/forum-reports' $headersB
    Assert-True ([int]$myReports.pagination.total -eq 1) '用户可以查看自己的举报进度'
    $adminReports = Invoke-Api GET "/api/admin/apps/$appId/reports" $adminHeaders
    Assert-True ([int]$adminReports.pagination.total -eq 1) '管理员可以查看举报目标详情'
    Invoke-Api PUT "/api/admin/apps/$appId/reports/$([int]$report.report_id)" $adminHeaders @{
        status = 'handled'
        handle_remark = '自动化测试已处理'
    } | Out-Null

    $room = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "可恢复群聊 $suffix"
        join_mode = 'approval'
        max_members = 100
        announcement = '群恢复能力测试'
    }
    $roomId = [int]$room.room.id
    Invoke-Api DELETE "/api/user/chat-rooms/$roomId" $headersA @{ confirm = 'DELETE' } | Out-Null
    $dissolved = Invoke-Api GET '/api/user/chat-rooms/dissolved' $headersA
    Assert-True ([int]$dissolved.pagination.total -eq 1) '群主可以查看已解散群聊'
    $restored = Invoke-Api POST "/api/user/chat-rooms/$roomId/restore" $headersA @{}
    Assert-True ([int]$restored.room.id -eq $roomId) '群聊在期限内可以恢复'

    $loginBatch = Invoke-Api POST "/api/admin/apps/$appId/card-batches" $adminHeaders @{
        name = "登录卡批次 $suffix"
        card_type = 'login'
        total_count = 1
        max_use = 99
        value_json = @{ balance = 25; document_credit = 3; vip_days = 1 }
        prefix = 'LOGIN'
    }
    $loginCard = [string]$loginBatch.codes[0]
    $deviceId = "device-$suffix"
    $cardSession = Invoke-Api POST '/api/public/card-login' @{ 'X-App-Key' = $appKey } @{
        app_key = $appKey
        card_code = $loginCard
        device_id = $deviceId
        device_label = '专项测试设备'
    }
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$cardSession.device_secret)) '首次登录返回一次性设备密钥'
    $duplicateBindingRejected = $false
    try {
        Invoke-Api POST '/api/public/card-login' @{ 'X-App-Key' = $appKey } @{
            app_key = $appKey
            card_code = $loginCard
            device_id = "other-$deviceId"
            device_label = '另一台测试设备'
        } | Out-Null
    } catch {
        $duplicateBindingRejected = $_.Exception.Message -match '绑定|使用'
    }
    Assert-True $duplicateBindingRejected '登录卡首次绑定后不能被另一设备重复绑定'
    $autoSession = Invoke-Api POST '/api/public/card-auto-login' @{ 'X-App-Key' = $appKey } @{
        app_key = $appKey
        device_id = $deviceId
        device_secret = [string]$cardSession.device_secret
    }
    Assert-True ([int]$autoSession.user.id -eq [int]$cardSession.user.id) '设备密钥自动登录回到同一用户'
    $wrongSecretRejected = $false
    try {
        Invoke-Api POST '/api/public/card-auto-login' @{ 'X-App-Key' = $appKey } @{
            app_key = $appKey
            device_id = $deviceId
            device_secret = '错误的设备密钥'
        } | Out-Null
    } catch {
        $wrongSecretRejected = $_.Exception.Message -match '密钥|失效|登录'
    }
    Assert-True $wrongSecretRejected '错误设备密钥不能自动登录'
    $cardHeaders = @{ Authorization = "Bearer $($autoSession.access_token)"; 'X-App-Key' = $appKey }
    $heartbeat = Invoke-Api POST '/api/user/heartbeat' $cardHeaders @{ device = '专项测试设备' }
    Assert-True ([string]$heartbeat.status_name -eq '在线') '心跳接口返回中文在线状态'
    $walletLogs = Invoke-Api GET '/api/user/wallet/logs' $cardHeaders
    Assert-True ([int]$walletLogs.pagination.total -ge 1) '登录卡奖励写入统一资产账单'
    $bindings = Invoke-Api GET "/api/admin/apps/$appId/card-login-bindings?keyword=$([uri]::EscapeDataString($loginCard))" $adminHeaders
    Assert-True ([int]$bindings.pagination.total -eq 1) '管理员可以查询登录卡设备绑定'
    $onlineStats = Invoke-Api GET "/api/public/app/statistics?app_key=$appKey"
    Assert-True ([int]$onlineStats.users.online -ge 1) '公开统计能看到在线人数'

    Write-Host '简云能力原生重构专项闭环测试通过。'
    Write-Host "app_id=$appId"
    Write-Host '覆盖：访问统计、用户搜索、关注、论坛审核、举报、群恢复、登录卡、心跳、账单。'
} finally {
    if ($appId -gt 0 -and $adminHeaders.Count -gt 0) {
        try {
            Invoke-Api DELETE "/api/admin/apps/$appId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null
        } catch {
            Write-Warning "临时应用清理失败：$($_.Exception.Message)"
        }
    }
}
