[CmdletBinding()]
param(
    [switch]$SkipInstall,
    [switch]$SkipAndroid
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

& (Join-Path $PSScriptRoot 'secret-scan.ps1') -Root $root

$versionProperties = @{}
Get-Content -LiteralPath (Join-Path $root 'android/version.properties') | ForEach-Object {
    if ($_ -match '^([^#=]+)=(.+)$') { $versionProperties[$matches[1].Trim()] = $matches[2].Trim() }
}
$package = Get-Content -LiteralPath (Join-Path $root 'download-site/package.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$release = Get-Content -LiteralPath (Join-Path $root 'download-site/release-metadata.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if ($versionProperties.VERSION_NAME -ne $package.version -or $versionProperties.VERSION_NAME -ne $release.versionName) {
    throw "Version name mismatch: Android=$($versionProperties.VERSION_NAME), package=$($package.version), release=$($release.versionName)"
}
if ([int]$versionProperties.VERSION_CODE -ne [int]$release.versionCode) {
    throw "Version code mismatch: Android=$($versionProperties.VERSION_CODE), release=$($release.versionCode)"
}

$downloadRoot = [string]$release.downloadRootBase
if ([string]::IsNullOrWhiteSpace($downloadRoot)) {
    throw 'Release downloadRootBase must not be empty.'
}
if ($downloadRoot -ne '/downloads') {
    $downloadUri = $null
    if (-not [Uri]::TryCreate($downloadRoot, [UriKind]::Absolute, [ref]$downloadUri) -or
        $downloadUri.Scheme -notin @('http', 'https') -or
        $downloadUri.Host -in @('localhost', '127.0.0.1', '0.0.0.0', '::1')) {
        throw "Release downloadRootBase is not production-safe: $downloadRoot"
    }
}

$releaseEntries = @($release.releases)
if ($releaseEntries.Count -ne 4) {
    throw "Release metadata must contain exactly four APK entries; found $($releaseEntries.Count)."
}
$versionPattern = [Regex]::Escape([string]$release.versionName)
foreach ($entry in $releaseEntries) {
    if ([int]$entry.versionCode -ne [int]$release.versionCode -or
        [string]$entry.versionName -notmatch "^$versionPattern-" -or
        [string]$entry.fileName -notmatch "v$versionPattern.*\.apk$") {
        throw "Release APK metadata mismatch: $($entry.id) / $($entry.fileName)"
    }
}

$releaseNotes = @($release.releaseNotes)
if ($releaseNotes.Count -eq 0 -or ($releaseNotes | Where-Object { [string]::IsNullOrWhiteSpace([string]$_) })) {
    throw 'Release notes must contain at least one non-empty item.'
}
Write-Host "Version chain passed: $($release.versionName) ($($release.versionCode))." -ForegroundColor Green

$userShell = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/res/layout/fragment_user_shell.xml') -Raw -Encoding UTF8
$managementShell = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/res/layout/fragment_management_shell.xml') -Raw -Encoding UTF8
$dimensions = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/res/values/dimens.xml') -Raw -Encoding UTF8
$glassSheet = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/common/GlassBottomSheet.java') -Raw -Encoding UTF8
$glassSheetTest = Get-Content -LiteralPath (Join-Path $root 'android/app/src/test/java/xyz/jjmxg/yiyunying/ui/common/GlassBottomSheetTest.java') -Raw -Encoding UTF8
$bottomDockStyler = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/common/BottomDockStyler.java') -Raw -Encoding UTF8
$userShellSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/home/UserShellFragment.java') -Raw -Encoding UTF8
$managementShellSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/main/ManagementShellFragment.java') -Raw -Encoding UTF8
$messageNotificationSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/service/MessageNotificationService.java') -Raw -Encoding UTF8
$callNotificationSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/service/VoiceCallForegroundService.java') -Raw -Encoding UTF8
$dialogBuilderSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/common/YiyunyingDialogBuilder.java') -Raw -Encoding UTF8
$momentTimelineSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/moment/MomentTimelineActivity.java') -Raw -Encoding UTF8
$socialDirectorySource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/social/SocialDirectoryActivity.java') -Raw -Encoding UTF8
$friendQrSource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/ui/social/FriendQrActivity.java') -Raw -Encoding UTF8
$moduleRegistrySource = Get-Content -LiteralPath (Join-Path $root 'android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java') -Raw -Encoding UTF8
$chatRoomServiceSource = Get-Content -LiteralPath (Join-Path $root 'backend/app/Services/ChatRoomService.php') -Raw -Encoding UTF8
$userGroupControllerSource = Get-Content -LiteralPath (Join-Path $root 'backend/app/Controllers/User/GroupController.php') -Raw -Encoding UTF8
$adminGroupControllerSource = Get-Content -LiteralPath (Join-Path $root 'backend/app/Controllers/Admin/GroupController.php') -Raw -Encoding UTF8
$communicationControllerSource = Get-Content -LiteralPath (Join-Path $root 'backend/app/Controllers/Admin/CommunicationController.php') -Raw -Encoding UTF8
$userOverviewSource = Get-Content -LiteralPath (Join-Path $root 'backend/app/Services/UserOverviewService.php') -Raw -Encoding UTF8
$databaseInstallSource = Get-Content -LiteralPath (Join-Path $root 'backend/database/install.sql') -Raw -Encoding UTF8
$roomKindMigrationPath = Join-Path $root 'backend/database/migrations/upgrade_20260731_chat_room_kind.sql'
$roomKindMigrationSource = Get-Content -LiteralPath $roomKindMigrationPath -Raw -Encoding UTF8

foreach ($shell in @($userShell, $managementShell)) {
    if ($shell -notmatch 'app:labelVisibilityMode="labeled"' -or
        $shell -notmatch 'app:itemHorizontalTranslationEnabled="false"') {
        throw 'Bottom dock must keep labels visible and disable horizontal translation.'
    }
}
if ($dimensions -notmatch '<dimen name="bottom_dock_height">(?:6[0-9]|[7-9][0-9])dp</dimen>') {
    throw 'Bottom dock height must reserve at least 60dp before navigation insets.'
}
if ($glassSheet -match 'setInset(?:Top|Bottom)\(') {
    throw 'GlassBottomSheet must not mutate MaterialButton insets after installing a custom background.'
}
if ($glassSheetTest -notmatch 'actionButtonKeepsSoftwareDrawnRoundedRippleWithoutVendorOutline') {
    throw 'GlassBottomSheet custom-background regression test is missing.'
}
if ($bottomDockStyler -notmatch 'LABEL_VISIBILITY_LABELED' -or
    $bottomDockStyler -notmatch 'setItemHorizontalTranslationEnabled\(false\)') {
    throw 'BottomDockStyler must keep every dock label visible and disable shifting.'
}
foreach ($shellSource in @($userShellSource, $managementShellSource)) {
    if ($shellSource -notmatch 'BottomDockStyler\.apply') {
        throw 'Every main shell must apply BottomDockStyler.'
    }
}
foreach ($notificationSource in @($messageNotificationSource, $callNotificationSource)) {
    if ($notificationSource -notmatch 'NotificationIconResolver\.smallIcon') {
        throw 'Message and call notifications must follow the selected launcher icon.'
    }
}
if ($dialogBuilderSource -notmatch 'isOverwrittenMaterialButtonBackground' -or
    $dialogBuilderSource -match 'catch \(RuntimeException \| LinkageError error\) \{\s*// Appearance must never') {
    throw 'Dialog appearance may only swallow the known overwritten MaterialButton background failure.'
}
if ($momentTimelineSource -notmatch 'targetUserId <= 0' -or
    $momentTimelineSource -notmatch 'section_label' -or
    $momentTimelineSource -notmatch 'pinnedSectionAdded' -or
    $momentTimelineSource -notmatch 'regularSectionAdded') {
    throw 'Moment timeline must separate profile-only pinned content from the public feed.'
}

if ($socialDirectorySource -notmatch 'addProperty\("room_kind", chatroom \? "chat_room" : "group"\)' -or
    $socialDirectorySource -notmatch 'roomEntity\(JsonObject item\)') {
    throw 'Android room creation and directory UI must preserve the selected room kind.'
}
if ($friendQrSource -notmatch 'room_kind' -or
    $friendQrSource -notmatch 'chat_room' -or
    $friendQrSource -notmatch 'roomEntity\(') {
    throw 'QR previews and joins must preserve whether the target is a group or chat room.'
}
if ($moduleRegistrySource -notmatch 'ModuleSpec\.builder\("chat_rooms"' -or
    $moduleRegistrySource -notmatch 'secondary\("room_kind"' -or
    $moduleRegistrySource -notmatch 'field\("room_kind"' -or
    $moduleRegistrySource -notmatch 'withDefault\("group"\)') {
    throw 'Module registry must expose type-aware group and chat-room management.'
}
if ($chatRoomServiceSource -notmatch "ROOM_CHATROOM = 'chat_room'" -or
    $chatRoomServiceSource -notmatch 'function roomKind') {
    throw 'ChatRoomService must centrally validate stable room kinds.'
}
foreach ($controllerSource in @($userGroupControllerSource, $adminGroupControllerSource, $communicationControllerSource)) {
    if ($controllerSource -notmatch 'room_kind') {
        throw 'Every chat-room creation controller must persist room_kind.'
    }
}
if ($userGroupControllerSource -notmatch 'room_kind_name' -or
    $userGroupControllerSource -notmatch '\$entity\s*=\s*ChatRoomService::roomKind' -or
    $userGroupControllerSource -notmatch 'LogService::userOperation\(\$request, \$user, ChatRoomService::roomKind') {
    throw 'User QR responses and audit targets must expose the normalized room kind.'
}if ($userOverviewSource -notmatch 'r\.room_kind' -or $userOverviewSource -match 'owner_user_id\s+IS\s+NULL\s+THEN') {
    throw 'User overview must classify rooms from room_kind instead of owner inference.'
}
if (-not (Test-Path -LiteralPath $roomKindMigrationPath) -or
    $roomKindMigrationSource -notmatch 'idx_chat_rooms_kind' -or
    $roomKindMigrationSource -notmatch 'user_chatroom_create_enabled' -or
    $databaseInstallSource -notmatch '`room_kind` VARCHAR\(20\)' -or
    $databaseInstallSource -notmatch 'idx_chat_rooms_kind') {
    throw 'Database install and idempotent migration must contain stable room-kind schema and settings.'
}
Write-Host 'Linting PHP files...'
Get-ChildItem -LiteralPath (Join-Path $root 'backend') -Recurse -Filter '*.php' -File | ForEach-Object {
    & php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
}

Write-Host 'Testing production environment loading...'
$environmentLoaderTest = Join-Path $root 'backend/tools/test-env-loader.php'
& php $environmentLoaderTest
if ($LASTEXITCODE -ne 0) { throw "Environment loader test failed with exit code $LASTEXITCODE" }
& php -d disable_functions=putenv $environmentLoaderTest
if ($LASTEXITCODE -ne 0) { throw "Environment loader fallback test failed with exit code $LASTEXITCODE" }

$androidDirectory = Join-Path $root 'android'
$localProperties = Join-Path $androidDirectory 'local.properties'
if (-not (Test-Path -LiteralPath $localProperties) -and
    -not $env:ANDROID_HOME -and
    -not $env:ANDROID_SDK_ROOT) {
    $sdkCandidates = @(
        (Join-Path $env:LOCALAPPDATA 'Android\Sdk'),
        'D:\AndroidToolchain\sdk'
    )
    $detectedSdk = $sdkCandidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if ($detectedSdk) {
        $env:ANDROID_HOME = $detectedSdk
        $env:ANDROID_SDK_ROOT = $detectedSdk
        Write-Host "Using detected Android SDK: $detectedSdk"
    } else {
        throw 'Android SDK not found. Set ANDROID_HOME or ANDROID_SDK_ROOT, or create android/local.properties.'
    }
}

if ($SkipAndroid) {
    Write-Warning 'Android verification was explicitly skipped. Run without -SkipAndroid before release or deployment.'
} else {
    Push-Location $androidDirectory
    try {
        & .\gradlew.bat testPlatformOwnerDebugUnitTest testAuthorizedPlatformDebugUnitTest testAdminDebugUnitTest testUserDebugUnitTest assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug --stacktrace
        if ($LASTEXITCODE -ne 0) { throw "Android verification failed with exit code $LASTEXITCODE" }
    } finally {
        Pop-Location
    }
}

Push-Location (Join-Path $root 'download-site')
try {
    $pnpmCommand = Get-Command 'pnpm.cmd' -ErrorAction SilentlyContinue
    if ($pnpmCommand) {
        $pnpmPath = $pnpmCommand.Source
    } else {
        $pnpmCandidates = @(
            (Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\bin\fallback\pnpm.cmd')
        )
        $pnpmPath = $pnpmCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
        if (-not $pnpmPath) {
            throw 'pnpm was not found. Install pnpm or add pnpm.cmd to PATH.'
        }
    }

    $nodeCommand = Get-Command 'node.exe' -ErrorAction SilentlyContinue
    if (-not $nodeCommand) {
        $runtimeDependencies = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies'
        $bundledNode = Join-Path $runtimeDependencies 'node\bin\node.exe'
        if (Test-Path -LiteralPath $bundledNode) {
            $runtimePaths = @(
                (Split-Path -Parent $bundledNode),
                (Join-Path $runtimeDependencies 'bin\override'),
                (Join-Path $runtimeDependencies 'bin\fallback')
            ) | Where-Object { Test-Path -LiteralPath $_ }
            $env:PATH = ($runtimePaths -join [IO.Path]::PathSeparator) + [IO.Path]::PathSeparator + $env:PATH
            Write-Host "Using bundled Node.js: $bundledNode"
        } else {
            throw 'Node.js was not found. Install Node.js or add node.exe to PATH.'
        }
    }

    if (-not $SkipInstall) {
        & $pnpmPath install --frozen-lockfile
        if ($LASTEXITCODE -ne 0) { throw "pnpm install failed with exit code $LASTEXITCODE" }
    }
    & $pnpmPath lint
    if ($LASTEXITCODE -ne 0) { throw "Download-center lint failed with exit code $LASTEXITCODE" }
    & $pnpmPath test
    if ($LASTEXITCODE -ne 0) { throw "Download-center tests failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

if ($SkipAndroid) {
    Write-Host 'All non-Android automated verification passed.' -ForegroundColor Yellow
} else {
    Write-Host 'All automated verification passed.' -ForegroundColor Green
}
