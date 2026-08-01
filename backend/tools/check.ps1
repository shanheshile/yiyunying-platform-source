$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$required = @(
    'bootstrap.php',
    'config\app.php',
    'database\install.sql',
    'database\upgrade_20260712_complete_balance_hierarchy.sql',
    'database\upgrade_20260713_message_center.sql',
    'database\upgrade_20260713_message_recall_policy.sql',
    'database\upgrade_20260713_multimedia_social.sql',
    'database\upgrade_20260713_interaction_navigation.sql',
    'database\upgrade_20260713_profile_interactions.sql',
    'database\upgrade_20260713_contact_groups.sql',
    'database\upgrade_20260713_conversation_media_experience.sql',
    'database\upgrade_20260713_chat_media_forward_search.sql',
    'database\upgrade_20260713_identity_uid_registration.sql',
    'database\upgrade_20260713_identity_review_scope.sql',
    'database\upgrade_20260713_media_cache_cloud_sync.sql',
    'database\upgrade_20260713_jianyun_capabilities.sql',
    'database\upgrade_20260714_forum_experience.sql',
    'database\upgrade_20260714_communication_takeover.sql',
    'database\upgrade_20260714_forward_snapshot_privacy.sql',
    'database\upgrade_20260714_chat_identity_settings.sql',
    'database\upgrade_20260714_chat_commerce.sql',
    'database\upgrade_20260714_message_edits.sql',
    'database\upgrade_20260714_relationship_notifications.sql',
    'database\upgrade_20260715_group_album_media.sql',
    'database\upgrade_20260715_message_replies.sql',
    'database\upgrade_20260715_speech_transcription.sql',
    'database\upgrade_20260715_upload_limits.sql',
    'database\upgrade_20260715_voice_calls.sql',
    'database\upgrade_20260715_video_calls.sql',
    'database\upgrade_20260715_voice_calls_context.sql',
    'database\upgrade_20260717_group_file_folders.sql',
    'database\upgrade_20260717_local_ai_festival_update.sql',
    'database\upgrade_20260718_forum_taxonomy_privacy.sql',
    'database\upgrade_20260718_demo_admin_refresh.sql',
    'database\upgrade_20260719_privacy_notification_settings.sql',
    'database\upgrade_20260719_group_invite_history.sql',
    'database\migrations\upgrade_20260718_management_review_notes.sql',
    'database\migrations\upgrade_20260720_moments.sql',
    'database\migrations\upgrade_20260720_moment_privacy_interactions.sql',
    'database\migrations\upgrade_20260720_moment_like_visibility.sql',
    'database\migrations\upgrade_20260720_targeted_red_packets.sql',
    'database\migrations\upgrade_20260720_red_packet_recipient_returns.sql',
    'database\migrations\upgrade_20260721_red_packet_delivery_scope.sql',
    'database\migrations\upgrade_20260721_group_vote_option_images.sql',
    'database\migrations\upgrade_20260721_moment_pins.sql',
    'database\migrations\upgrade_20260721_shop_commerce_closure.sql',
    'database\migrations\upgrade_20260721_business_catalog_rewards.sql',
    'database\migrations\upgrade_20260721_role_permission_center.sql',
    'database\migrations\upgrade_20260722_random_red_packet_money.sql',
    'database\migrations\upgrade_20260722_red_packet_dispatch_modes.sql',
    'database\migrations\upgrade_20260722_remote_login_protection.sql',
    'database\migrations\upgrade_20260722_bounty_moderation.sql',
    'database\migrations\upgrade_20260725_submission_risk_metadata.sql',
    'database\migrations\upgrade_20260731_chat_room_kind.sql',
    'database\migrations\upgrade_20260801_forum_comment_threads.sql',
    'public\index.php',
    'public\router.php',
    'public\api-docs.html',
    'routes\api.php',
    'docs\API.md',
    'docs\API_FULL.md',
    'docs\PLATFORM_GOVERNANCE.md',
    'docs\ROUTES.md',
    'docs\SCHEMA.md',
    'docs\JIANYUN_CAPABILITY_MAPPING.md',
    'docs\FORUM_EXPERIENCE.md',
    'docs\COMMUNICATION_TAKEOVER.md',
    'docs\REQUIREMENT_VERIFICATION_20260721.md',
    'tools\smoke-maximum.ps1',
    'tools\smoke-platform.ps1',
    'tools\smoke-exchange.ps1',
    'tools\smoke-exchange-concurrency.ps1',
    'tools\smoke-message-entitlements.ps1',
    'tools\smoke-multimedia-visual.ps1',
    'tools\smoke-contact-groups.ps1',
    'tools\smoke-notification-center.ps1',
    'tools\smoke-identity-qr.ps1',
    'tools\smoke-jianyun-capabilities.ps1',
    'tools\smoke-forum-experience.ps1',
    'tools\smoke-communication-takeover.ps1',
    'tools\smoke-chat-commerce.ps1',
    'tools\smoke-message-edits.ps1',
    'tools\smoke-upload-types.ps1',
    'tools\smoke-voice-calls.ps1',
    'tools\exchange-concurrency-worker.php',
    'tools\check-role-permissions.php',
    'tools\test-red-packet-amount.php',
    'tools\test-red-packet-rules.php',
    'tools\check-commerce-refund-policy.php',
    'tools\test-moment-pinned-visibility.php',
    'tools\test-forum-comment-thread-contract.php',
    'tools\generate-requirement-verification.php',
    'tools\generate-reference.php',
    'tools\generate-api-html.php',
    'tools\generate-upgrade.php'
)

$missing = @()
foreach ($file in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $root $file))) {
        $missing += $file
    }
}
if ($missing.Count -gt 0) {
    throw "Missing files: $($missing -join ', ')"
}

$sqlPath = Join-Path $root 'database\install.sql'
$bytes = [System.IO.File]::ReadAllBytes($sqlPath)
if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    throw 'install.sql has a UTF-8 BOM.'
}

$sql = [System.IO.File]::ReadAllText($sqlPath)
$tableCount = ([regex]::Matches($sql, 'CREATE TABLE IF NOT EXISTS')).Count
$routeText = [System.IO.File]::ReadAllText((Join-Path $root 'routes\api.php'))
$routeCount = ([regex]::Matches($routeText, '\$router->(get|post|put|delete)\(')).Count

if ($tableCount -lt 193) {
    throw "Table count is below the required baseline (193): $tableCount"
}
if ($routeCount -lt 678) {
    throw "API route count is below the required baseline (678): $routeCount"
}

$verificationPath = Join-Path $root 'docs\REQUIREMENT_VERIFICATION_20260721.md'
$verificationText = [System.IO.File]::ReadAllText($verificationPath)
$verificationRows = ([regex]::Matches($verificationText, '(?m)^\|\s*\d+\s*\|')).Count
if ($verificationRows -lt 50) {
    throw "Requirement verification rows are below the required baseline (50): $verificationRows"
}

$fullApi = [System.IO.File]::ReadAllText((Join-Path $root 'docs\API_FULL.md'))
$documented = [regex]::Matches($fullApi, '\|\s*(GET|POST|PUT|DELETE)\s*\|\s*`([^`]+)`')
$registered = [regex]::Matches($routeText, '\$router->(get|post|put|delete)\(''([^'']+)''')
$routeSet = @{}
foreach ($match in $registered) {
    $routeSet[($match.Groups[1].Value.ToUpper() + ' ' + $match.Groups[2].Value)] = $true
}
$missingRoutes = @()
foreach ($match in $documented) {
    $key = $match.Groups[1].Value.ToUpper() + ' ' + $match.Groups[2].Value
    if (-not $routeSet.ContainsKey($key)) {
        $missingRoutes += $key
    }
}
if ($documented.Count -lt 252) {
    throw "Unexpected documented endpoint count: $($documented.Count)"
}
if ($missingRoutes.Count -gt 0) {
    throw "Documented routes are missing: $($missingRoutes -join ', ')"
}

$powerShellFiles = Get-ChildItem -LiteralPath (Join-Path $root 'tools') -Filter '*.ps1' -File
foreach ($file in $powerShellFiles) {
    $parseErrors = $null
    [System.Management.Automation.Language.Parser]::ParseFile(
        $file.FullName,
        [ref]$null,
        [ref]$parseErrors
    ) | Out-Null
    if ($parseErrors.Count -gt 0) {
        throw "PowerShell parse failed: $($file.FullName)`n$($parseErrors -join "`n")"
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -ne $php) {
    $env:YIYUN_BACKEND_ROOT = $root
    $phpFiles = Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' -File
    foreach ($file in $phpFiles) {
        $output = & $php.Source -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "PHP lint failed: $($file.FullName)`n$output"
        }
    }
    $handlerCheck = @'
<?php
chdir((string) getenv('YIYUN_BACKEND_ROOT'));
require 'bootstrap.php';
$router = require 'routes/api.php';
$invalid = array_filter($router->routes(), static fn(array $route): bool => !is_callable($route['handler']));
foreach ($invalid as $route) {
    $handler = is_array($route['handler'])
        ? $route['handler'][0] . '::' . $route['handler'][1]
        : 'callable';
    fwrite(STDERR, $route['method'] . ' ' . $route['path'] . ' => ' . $handler . PHP_EOL);
}
exit($invalid === [] ? 0 : 1);
'@ | & $php.Source
    if ($LASTEXITCODE -ne 0) {
        throw 'One or more route handlers are not callable.'
    }
    $permissionOutput = & $php.Source (Join-Path $root 'tools\check-role-permissions.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Role permission checks failed.`n$permissionOutput"
    }
    Write-Host "Role permission checks: passed"
    $redPacketOutput = & $php.Source (Join-Path $root 'tools\test-red-packet-amount.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Red packet amount checks failed.`n$redPacketOutput"
    }
    Write-Host "Red packet amount checks: passed"
    $redPacketRulesOutput = & $php.Source (Join-Path $root 'tools\test-red-packet-rules.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Red packet rule checks failed.`n$redPacketRulesOutput"
    }
    Write-Host "Red packet rule checks: passed"
    $refundPolicyOutput = & $php.Source (Join-Path $root 'tools\check-commerce-refund-policy.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Commerce refund policy checks failed.`n$refundPolicyOutput"
    }
    Write-Host "Commerce refund policy checks: passed"
    $momentPinnedOutput = & $php.Source (Join-Path $root 'tools\test-moment-pinned-visibility.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Moment pinned visibility checks failed.`n$momentPinnedOutput"
    }
    Write-Host "Moment pinned visibility checks: passed"
    $forumThreadOutput = & $php.Source (Join-Path $root 'tools\test-forum-comment-thread-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Forum comment thread contract checks failed.`n$forumThreadOutput"
    }
    Write-Host "Forum comment thread contract checks: passed"
    Write-Host "PHP lint: passed ($($phpFiles.Count) files)"
} else {
    Write-Host 'PHP is not in PATH; php -l was skipped.'
}

Write-Host 'SQL BOM: none'
Write-Host "Tables: $tableCount"
Write-Host "API routes: $routeCount"
Write-Host "Documented endpoints: $($documented.Count)"
Write-Host "Requirement verification rows: $verificationRows"
Write-Host 'Documented route coverage: complete'
Write-Host "PowerShell parse: passed ($($powerShellFiles.Count) files)"
Write-Host 'Static checks: passed'
