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

function Find-Message([object[]]$Items, [int]$MessageId) {
    return @($Items | Where-Object { [int]$_.id -eq $MessageId })[0]
}

try {
    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

    $operatorAccount = "edit_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Message Edit Level 2'
        membership_days = 30; admin_quota = 2; balance = 20; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "edit_l3_$suffix"
    $createdAdmin = Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Message Edit Level 3'
        vip_days = 30; app_quota = 1; remote_document_quota = 3; balance = 20
    }
    $adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }
    $createdApp = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Message Edit $suffix"; app_key = "edit_$suffix"; description = 'Automated message edit smoke test'
    }
    $appId = [int]$createdApp.app.id
    $appKey = [string]$createdApp.app.app_key
    Invoke-Api PUT "/api/admin/apps/$appId/settings" $adminHeaders @{ settings = @{
        private_message_enabled = $true; group_chat_enabled = $true
    } } | Out-Null

    $accountA = "edit_a_$suffix"
    $accountB = "edit_b_$suffix"
    $createdA = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $accountA; password = '123456'; nickname = 'Edit Alpha'
    }
    $createdB = Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $accountB; password = '123456'; nickname = 'Edit Beta'
    }
    $userA = [int]$createdA.user.id
    $userB = [int]$createdB.user.id
    $loginA = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountA; password = '123456' }
    $loginB = Invoke-Api POST '/api/user/login' @{} @{ app_key = $appKey; account = $accountB; password = '123456' }
    $headersA = @{ Authorization = "Bearer $($loginA.access_token)"; 'X-App-Key' = $appKey }
    $headersB = @{ Authorization = "Bearer $($loginB.access_token)"; 'X-App-Key' = $appKey }

    $private = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = 'private original'
    }
    $privateId = [int]$private.message_id
    $conversationId = [int]$private.conversation_id
    $edit1 = Invoke-Api PUT "/api/user/messages/$privateId" $headersA @{ content = 'private edit one' }
    $edit2 = Invoke-Api PUT "/api/user/messages/$privateId" $headersA @{ content = 'private edit two' }
    Assert-True ([int]$edit1.edit_count -eq 1 -and [int]$edit2.edit_count -eq 2) 'private edit count increments'
    Assert-HttpFailure PUT "/api/user/messages/$privateId" $headersB @(403,404) @{ content = 'peer spoof edit' }
    $privateHistory = Invoke-Api GET "/api/user/messages/$privateId/edits" $headersB
    Assert-True ([int]$privateHistory.edit_count -eq 2 -and $privateHistory.current_content -eq 'private edit two') 'peer can read private edit history'
    Assert-True ($privateHistory.items[0].old_content -eq 'private original' -and $privateHistory.items[1].new_content -eq 'private edit two') 'private versions are complete'
    $privateList = Invoke-Api GET "/api/user/conversations/$conversationId/messages?limit=100" $headersB
    $privateItem = Find-Message @($privateList.items) $privateId
    Assert-True ([bool]$privateItem.edited -and [int]$privateItem.edit_count -eq 2 -and $privateItem.content -eq 'private edit two') 'private list hydrates edit state'

    $media = Invoke-Api POST '/api/user/messages/private' $headersA @{
        to_user_id = $userB; content = ''; attachments = @(@{
            media_type = 'image'; url = 'https://example.com/edit-smoke.png'; mime_type = 'image/png'
        })
    }
    Assert-HttpFailure PUT "/api/user/messages/$([int]$media.message_id)" $headersA @(422) @{ content = 'media cannot become text' }

    $group = Invoke-Api POST '/api/user/chat-rooms' $headersA @{
        name = "Edit Group $suffix"; join_mode = 'open'; max_members = 20
    }
    $groupId = [int]$group.room.id
    Invoke-Api POST "/api/user/chat-rooms/$groupId/join" $headersB @{} | Out-Null
    $groupMessage = Invoke-Api POST "/api/user/chat-rooms/$groupId/messages" $headersA @{ content = 'group original' }
    $groupMessageId = [int]$groupMessage.message_id
    $groupEdit = Invoke-Api PUT "/api/user/chat-rooms/$groupId/messages/$groupMessageId" $headersA @{ content = 'group edited' }
    Assert-True ([int]$groupEdit.edit_count -eq 1) 'group message edit succeeds'
    Assert-HttpFailure PUT "/api/user/chat-rooms/$groupId/messages/$groupMessageId" $headersB @(403,404) @{ content = 'member spoof edit' }
    $groupHistory = Invoke-Api GET "/api/user/chat-rooms/$groupId/messages/$groupMessageId/edits" $headersB
    Assert-True ([int]$groupHistory.edit_count -eq 1 -and $groupHistory.current_content -eq 'group edited') 'group member reads edit history'
    $groupList = Invoke-Api GET "/api/user/chat-rooms/$groupId/messages?limit=100" $headersB
    $groupItem = Find-Message @($groupList.items) $groupMessageId
    Assert-True ([bool]$groupItem.edited -and [int]$groupItem.edit_count -eq 1 -and $groupItem.content -eq 'group edited') 'group list hydrates edit state'

    Invoke-Api POST "/api/user/messages/$privateId/recall" $headersA @{ notice_text = 'Edit Alpha recalled a message' } | Out-Null
    Assert-HttpFailure PUT "/api/user/messages/$privateId" $headersA @(404,410) @{ content = 'edit after recall' }

    Write-Host 'Message edit smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks operator_id=$operatorId app_id=$appId"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary message-edit branch cleanup failed: $($_.Exception.Message)" }
    }
}
