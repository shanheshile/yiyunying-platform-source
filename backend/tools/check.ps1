$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$required = @(
    'config\release-identity.json',
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
    'database\migrations\upgrade_20260801_auto_cache_media_policy.sql',
    'database\migrations\upgrade_20260801_forum_comment_threads.sql',
    'database\migrations\upgrade_20260801_call_message_cards.sql',
    'database\migrations\upgrade_20260802_moment_comment_interactions.sql',
    'database\migrations\upgrade_20260810_chat_experience_controls.sql',
    'database\migrations\upgrade_20260810_profile_space_avatar_controls.sql',
    'database\migrations\upgrade_20260810_forum_content_unlocks.sql',
    'database\migrations\upgrade_20260810_forum_data_consistency.sql',
    'database\migrations\upgrade_20260811_content_moderation_closure.sql',
    'database\migrations\upgrade_20260811_short_video_controls.sql',
    'database\migrations\upgrade_20260811_resource_store_review_closure.sql',
    'database\migrations\upgrade_20260811_management_shell_restructure.sql',
    'database\migrations\upgrade_20260814_secure_mail_settings.sql',
    'public\index.php',
    'public\router.php',
    'public\api-docs.html',
    'public\control\index.php',
    'public\control\control.css',
    'public\control\control.js',
    'routes\api.php',
    'docs\API.md',
    'docs\API_FULL.md',
    'docs\PLATFORM_GOVERNANCE.md',
    'docs\ROUTES.md',
    'docs\SCHEMA.md',
    'docs\JIANYUN_CAPABILITY_MAPPING.md',
    'docs\FORUM_EXPERIENCE.md',
    'docs\COMMUNICATION_TAKEOVER.md',
    'docs\CONTENT_MODERATION.md',
    'docs\RESOURCE_STORE_REVIEW.md',
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
    'tools\test-user-feature-enforcement-contract.php',
    'tools\test-red-packet-amount.php',
    'tools\test-red-packet-rules.php',
    'tools\check-commerce-refund-policy.php',
    'tools\test-moment-pinned-visibility.php',
    'tools\test-forum-comment-thread-contract.php',
    'tools\test-comment-voice-contract.php',
    'tools\test-chat-experience-controls.php',
    'tools\test-profile-space-avatar-controls.php',
    'tools\test-forum-content-unlock-contract.php',
    'tools\test-forum-legacy-paid-visibility.php',
    'tools\test-private-forum-media-contract.php',
    'tools\test-forum-data-consistency.php',
    'tools\test-purchased-content-immutability.php',
    'tools\test-content-moderation-contract.php',
    'tools\test-short-video-contract.php',
    'tools\test-resource-store-review-contract.php',
    'tools\test-management-shell-contract.php',
    'tools\test-login-build-identity-contract.php',
    'tools\test-bootstrap-credential-safety-contract.php',
    'tools\audit-default-credentials.php',
    'tools\test-default-credential-audit-contract.php',
    'tools\test-auth-session-revocation-contract.php',
    'tools\test-root-control-console-contract.php',
    'tools\test-account-security-boundaries.php',
    'tools\test-maintenance-write-guard-contract.php',
    'tools\test-purchase-history-foreign-key-contract.php',
    'tools\test-catalog-private-migration-contract.php',
    'tools\catalog-legacy-upload-binding.php',
    'tools\audit-catalog-legacy-upload-bindings.php',
    'tools\backfill-catalog-source-uploads.php',
    'tools\test-catalog-legacy-upload-binding.php',
    'tools\catalog-private-retention.php',
    'tools\catalog-public-quarantine-contract.php',
    'tools\quarantine-catalog-public-files.php',
    'tools\test-catalog-public-quarantine-contract.php',
    'tools\test-upload-library-reference-guards.php',
    'tools\test-upload-reference-write-toctou-contract.php',
    'tools\test-wallet-amount-regression.php',
    'tools\test-public-upload-svg-safety.php',
    'tools\catalog-public-upload-type.php',
    'tools\test-resource-comment-management-contract.php',
    'tools\test-shop-goods-comment-management-contract.php',
    'tools\test-message-presentation.php',
    'tools\test-update-package-metadata-contract.php',
    'tools\test-forum-forward-snapshot-contract.php',
    'tools\test-verification-email-delivery-contract.php',
    'tools\test-secure-mail-settings-contract.php',
    'tools\export-account-credential-packages.ps1',
    'tools\export-desktop-credential-console.ps1',
    'tools\credential-console-server.py',
    'tools\credential-console.js',
    'tools\credential-console.html',
    'tools\credential-console-tests.html',
    'tools\credential-console-readme.md',
    'tools\get-or-create-internal-download-secret.ps1',
    'tools\view-account-credential-package.ps1',
    'tools\tests\test-export-account-credential-packages.ps1',
    'tools\tests\test-export-desktop-credential-console.ps1',
    'tools\tests\test-credential-console-xss.ps1',
    'tools\tests\test_credential_console_server.py',
    'tools\tests\test-internal-download-secret.ps1',
    'tools\deploy-ssh.py',
    'tools\publish-android-ssh.py',
    'tools\verify-production-release-ssh.py',
    'tools\requirements-release.txt',
    'tools\test-deploy-ssh-safety.py',
    'tools\tests\test_publish_android_ssh_security.py',
    'tools\tests\test_connection_identity_release_gate.py',
    'tools\tests\test_device_upgrade_gate.py',
    'tools\tests\test_download_audience_separation.py',
    'tools\tests\test_internal_download_server.py',
    'tools\tests\test_verification_email_smtp.py',
    'tools\tests\test_download_site_atomic_publish.py',
    'tools\tests\test_download_site_security_remediation.py',
    'tools\tests\test_internal_apk_private_deploy.py',
    'tools\tests\test_release_evidence_chain.py',
    '..\download-site\scripts\deploy-site-security-remediation.py',
    '..\download-site\scripts\deploy-internal-apks.py',
    '..\download-site\deploy\internal-apk-verifier.php',
    '..\download-site\deploy\nginx-internal-apks-auth-request.conf',
    'tools\migrate-catalog-private-files.php',
    'tools\verify-catalog-migration-report.php',
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

$credentialPackageContract = Join-Path $root 'tools\tests\test-export-account-credential-packages.ps1'
& powershell -NoProfile -ExecutionPolicy Bypass -File $credentialPackageContract
if ($LASTEXITCODE -ne 0) {
    throw 'DPAPI account credential package offline contract failed.'
}
$desktopCredentialConsoleContract = Join-Path $root 'tools\tests\test-export-desktop-credential-console.ps1'
& powershell -NoProfile -ExecutionPolicy Bypass -File $desktopCredentialConsoleContract
if ($LASTEXITCODE -ne 0) {
    throw 'Private desktop credential console export contract failed.'
}
$desktopCredentialConsoleXssContract = Join-Path $root 'tools\tests\test-credential-console-xss.ps1'
& powershell -NoProfile -ExecutionPolicy Bypass -File $desktopCredentialConsoleXssContract
if ($LASTEXITCODE -ne 0) {
    throw 'Private desktop credential console XSS contract failed.'
}
$internalDownloadSecretContract = Join-Path $root 'tools\tests\test-internal-download-secret.ps1'
& powershell -NoProfile -ExecutionPolicy Bypass -File $internalDownloadSecretContract
if ($LASTEXITCODE -ne 0) {
    throw 'DPAPI internal-download signing secret contract failed.'
}

$python = Get-Command python -ErrorAction SilentlyContinue
if ($null -eq $python) {
    throw 'Python is required for deployment and release safety checks.'
}
$pythonFiles = @(
    'tools\deploy-ssh.py',
    'tools\publish-android-ssh.py',
    'tools\verify-production-release-ssh.py',
    'tools\credential-console-server.py',
    'tools\test-deploy-ssh-safety.py',
    'tools\tests\test_publish_android_ssh_security.py',
    'tools\tests\test_connection_identity_release_gate.py',
    'tools\tests\test_device_upgrade_gate.py',
    'tools\tests\test_download_audience_separation.py',
    'tools\tests\test_internal_download_server.py',
    'tools\tests\test_verification_email_smtp.py',
    'tools\tests\test_credential_console_server.py',
    'tools\tests\test_download_site_atomic_publish.py',
    'tools\tests\test_download_site_security_remediation.py',
    'tools\tests\test_internal_apk_private_deploy.py',
    '..\download-site\scripts\deploy-internal-apks.py',
    '..\download-site\scripts\deploy-site-security-remediation.py',
    'tools\tests\test_release_evidence_chain.py'
)
& $python.Source -W error -m py_compile @($pythonFiles | ForEach-Object { Join-Path $root $_ })
if ($LASTEXITCODE -ne 0) {
    throw 'Python deployment/release tooling compilation failed.'
}
foreach ($testFile in @(
    'tools\test-deploy-ssh-safety.py',
    'tools\tests\test_publish_android_ssh_security.py',
    'tools\tests\test_connection_identity_release_gate.py',
    'tools\tests\test_device_upgrade_gate.py',
    'tools\tests\test_download_audience_separation.py',
    'tools\tests\test_internal_download_server.py',
    'tools\tests\test_verification_email_smtp.py',
    'tools\tests\test_credential_console_server.py',
    'tools\tests\test_download_site_atomic_publish.py',
    'tools\tests\test_download_site_security_remediation.py',
    'tools\tests\test_internal_apk_private_deploy.py',
    'tools\tests\test_release_evidence_chain.py'
)) {
    $testPath = Join-Path $root $testFile
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $pythonOutput = & $python.Source $testPath 2>&1
        $pythonExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($pythonExitCode -ne 0) {
        throw "Python release safety checks failed: $testFile`n$pythonOutput"
    }
}
Write-Host 'Python deployment/release safety checks: passed'

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
    $userFeatureOutput = & $php.Source (Join-Path $root 'tools\test-user-feature-enforcement-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "User feature enforcement contract failed.`n$userFeatureOutput"
    }
    Write-Host "User feature enforcement contract: passed"
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
    $commentVoiceOutput = & $php.Source (Join-Path $root 'tools\test-comment-voice-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Comment voice contract checks failed.`n$commentVoiceOutput"
    }
    Write-Host "Comment voice contract checks: passed"
    $chatExperienceOutput = & $php.Source (Join-Path $root 'tools\test-chat-experience-controls.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Chat experience control checks failed.`n$chatExperienceOutput"
    }
    Write-Host "Chat experience control checks: passed"
    $profileAvatarOutput = & $php.Source (Join-Path $root 'tools\test-profile-space-avatar-controls.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Profile-space avatar control checks failed.`n$profileAvatarOutput"
    }
    Write-Host "Profile-space avatar control checks: passed"
    $forumUnlockOutput = & $php.Source (Join-Path $root 'tools\test-forum-content-unlock-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Forum content unlock contract checks failed.`n$forumUnlockOutput"
    }
    Write-Host "Forum content unlock contract checks: passed"
    $forumConsistencyOutput = & $php.Source (Join-Path $root 'tools\test-forum-data-consistency.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Forum data consistency contract checks failed.`n$forumConsistencyOutput"
    }
    Write-Host "Forum data consistency contract checks: passed"
    $forumLegacyVisibilityOutput = & $php.Source (Join-Path $root 'tools\test-forum-legacy-paid-visibility.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Forum legacy paid visibility contract checks failed.`n$forumLegacyVisibilityOutput"
    }
    Write-Host "Forum legacy paid visibility contract checks: passed"
    $privateForumMediaOutput = & $php.Source (Join-Path $root 'tools\test-private-forum-media-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Private forum media contract checks failed.`n$privateForumMediaOutput"
    }
    Write-Host "Private forum media contract checks: passed"
    $purchasedContentOutput = & $php.Source (Join-Path $root 'tools\test-purchased-content-immutability.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Purchased-content immutability contract checks failed.`n$purchasedContentOutput"
    }
    Write-Host "Purchased-content immutability contract checks: passed"
    $contentModerationOutput = & $php.Source (Join-Path $root 'tools\test-content-moderation-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Content moderation contract checks failed.`n$contentModerationOutput"
    }
    Write-Host "Content moderation contract checks: passed"
    $shortVideoOutput = & $php.Source (Join-Path $root 'tools\test-short-video-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Short-video contract checks failed.`n$shortVideoOutput"
    }
    Write-Host "Short-video contract checks: passed"
    $resourceStoreReviewOutput = & $php.Source (Join-Path $root 'tools\test-resource-store-review-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Resource/store review contract checks failed.`n$resourceStoreReviewOutput"
    }
    Write-Host "Resource/store review contract checks: passed"
    $managementShellOutput = & $php.Source (Join-Path $root 'tools\test-management-shell-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Management-shell contract checks failed.`n$managementShellOutput"
    }
    Write-Host "Management-shell contract checks: passed"
    $loginBuildIdentityOutput = & $php.Source (Join-Path $root 'tools\test-login-build-identity-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Login build identity contract checks failed.`n$loginBuildIdentityOutput"
    }
    Write-Host "Login build identity contract checks: passed"
    $bootstrapCredentialOutput = & $php.Source (Join-Path $root 'tools\test-bootstrap-credential-safety-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Bootstrap credential safety contract failed.`n$bootstrapCredentialOutput"
    }
    Write-Host "Bootstrap credential safety contract: passed"
    $defaultCredentialAuditOutput = & $php.Source (Join-Path $root 'tools\test-default-credential-audit-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Default credential audit static contract failed.`n$defaultCredentialAuditOutput"
    }
    Write-Host "Default credential audit static contract: passed"
    $authSessionRevocationOutput = & $php.Source (Join-Path $root 'tools\test-auth-session-revocation-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Auth/session revocation contract checks failed.`n$authSessionRevocationOutput"
    }
    Write-Host "Auth/session revocation contract checks: passed"
    $rootControlOutput = & $php.Source (Join-Path $root 'tools\test-root-control-console-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Root control console contract checks failed.`n$rootControlOutput"
    }
    Write-Host "Root control console contract checks: passed"
    $accountSecurityOutput = & $php.Source (Join-Path $root 'tools\test-account-security-boundaries.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Account security boundary contract checks failed.`n$accountSecurityOutput"
    }
    Write-Host "Account security boundary contract checks: passed"
    $maintenanceWriteGuardOutput = & $php.Source (Join-Path $root 'tools\test-maintenance-write-guard-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Maintenance write guard contract checks failed.`n$maintenanceWriteGuardOutput"
    }
    Write-Host "Maintenance write guard contract checks: passed"
    $purchaseHistoryOutput = & $php.Source (Join-Path $root 'tools\test-purchase-history-foreign-key-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Purchase-history foreign-key contract checks failed.`n$purchaseHistoryOutput"
    }
    Write-Host "Purchase-history foreign-key contract checks: passed"
    $catalogMigrationOutput = & $php.Source (Join-Path $root 'tools\test-catalog-private-migration-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Catalog private migration contract checks failed.`n$catalogMigrationOutput"
    }
    Write-Host "Catalog private migration contract checks: passed"
    $catalogBindingOutput = & $php.Source (Join-Path $root 'tools\test-catalog-legacy-upload-binding.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Catalog legacy upload binding checks failed.`n$catalogBindingOutput"
    }
    Write-Host "Catalog legacy upload binding checks: passed"
    $catalogPublicQuarantineOutput = & $php.Source (Join-Path $root 'tools\test-catalog-public-quarantine-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Catalog public quarantine contract checks failed.`n$catalogPublicQuarantineOutput"
    }
    Write-Host "Catalog public quarantine contract checks: passed"
    $uploadReferenceOutput = & $php.Source (Join-Path $root 'tools\test-upload-library-reference-guards.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Upload-library reference guard checks failed.`n$uploadReferenceOutput"
    }
    Write-Host "Upload-library reference guard checks: passed"
    $uploadWriteReferenceOutput = & $php.Source (Join-Path $root 'tools\test-upload-reference-write-toctou-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Upload-reference write TOCTOU checks failed.`n$uploadWriteReferenceOutput"
    }
    Write-Host "Upload-reference write TOCTOU checks: passed"
    $walletAmountOutput = & $php.Source (Join-Path $root 'tools\test-wallet-amount-regression.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Wallet amount regression checks failed.`n$walletAmountOutput"
    }
    Write-Host "Wallet amount regression checks: passed"
    $publicSvgOutput = & $php.Source (Join-Path $root 'tools\test-public-upload-svg-safety.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Public upload SVG safety checks failed.`n$publicSvgOutput"
    }
    Write-Host "Public upload SVG safety checks: passed"
    $resourceCommentOutput = & $php.Source (Join-Path $root 'tools\test-resource-comment-management-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Resource comment management contract checks failed.`n$resourceCommentOutput"
    }
    Write-Host "Resource comment management contract checks: passed"
    $shopGoodsCommentOutput = & $php.Source (Join-Path $root 'tools\test-shop-goods-comment-management-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Shop goods comment management contract checks failed.`n$shopGoodsCommentOutput"
    }
    Write-Host "Shop goods comment management contract checks: passed"
    $messagePresentationOutput = & $php.Source (Join-Path $root 'tools\test-message-presentation.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Message presentation contract checks failed.`n$messagePresentationOutput"
    }
    Write-Host "Message presentation contract checks: passed"
    $updateMetadataOutput = & $php.Source (Join-Path $root 'tools\test-update-package-metadata-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Update package metadata contract checks failed.`n$updateMetadataOutput"
    }
    Write-Host "Update package metadata contract checks: passed"
    $forumForwardOutput = & $php.Source (Join-Path $root 'tools\test-forum-forward-snapshot-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Forum forward snapshot contract checks failed.`n$forumForwardOutput"
    }
    Write-Host "Forum forward snapshot contract checks: passed"
    $verificationEmailOutput = & $php.Source (Join-Path $root 'tools\test-verification-email-delivery-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Verification email delivery contract checks failed.`n$verificationEmailOutput"
    }
    Write-Host "Verification email delivery contract checks: passed"
    $secureMailOutput = & $php.Source (Join-Path $root 'tools\test-secure-mail-settings-contract.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Secure root mail settings contract checks failed.`n$secureMailOutput"
    }
    Write-Host "Secure root mail settings contract checks: passed"
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
