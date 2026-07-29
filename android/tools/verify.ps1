param(
    [string] $JavaHome = $env:JAVA_HOME
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runRoot = $projectRoot

if ([string]::IsNullOrWhiteSpace($JavaHome)) {
    throw 'JAVA_HOME is not set. Pass -JavaHome or configure JDK 17 first.'
}

if ($projectRoot -match '[^\x00-\x7F]') {
    $runRoot = $null
    foreach ($index in 1..20) {
        $candidate = if ($index -eq 1) { 'C:\YiyunyingAndroidVerify' } else { "C:\YiyunyingAndroidVerify$index" }
        if (Test-Path -LiteralPath $candidate) {
            $item = Get-Item -LiteralPath $candidate -Force
            if ($item.LinkType -eq 'Junction' -and $item.Target -contains $projectRoot) {
                $runRoot = $candidate
                break
            }
            continue
        }
        New-Item -ItemType Junction -Path $candidate -Target $projectRoot | Out-Null
        $runRoot = $candidate
        break
    }
    if ($null -eq $runRoot) {
        throw 'Could not allocate an ASCII-only verification junction under C:\.'
    }
}

$env:JAVA_HOME = $JavaHome
Push-Location $runRoot
try {
    $tasks = @(
        'clean',
        'testPlatformOwnerDebugUnitTest',
        'testAuthorizedPlatformDebugUnitTest',
        'testAdminDebugUnitTest',
        'testUserDebugUnitTest',
        'lintPlatformOwnerDebug',
        'lintAuthorizedPlatformDebug',
        'lintAdminDebug',
        'lintUserDebug',
        'assemblePlatformOwnerDebug',
        'assembleAuthorizedPlatformDebug',
        'assembleAdminDebug',
        'assembleUserDebug'
    )
    & .\gradlew.bat --no-daemon --rerun-tasks @tasks
    if ($LASTEXITCODE -ne 0) {
        throw "Android verification failed with exit code $LASTEXITCODE."
    }
    $apks = @(
        'app\build\outputs\apk\platformOwner\debug\app-platformOwner-debug.apk',
        'app\build\outputs\apk\authorizedPlatform\debug\app-authorizedPlatform-debug.apk',
        'app\build\outputs\apk\admin\debug\app-admin-debug.apk',
        'app\build\outputs\apk\user\debug\app-user-debug.apk'
    )
    foreach ($relativePath in $apks) {
        $apk = Join-Path $projectRoot $relativePath
        if (-not (Test-Path -LiteralPath $apk)) {
            throw "Build succeeded but APK was not found: $apk"
        }
        $hash = (Get-FileHash -LiteralPath $apk -Algorithm SHA256).Hash
        Write-Host "APK: $apk`nSHA256: $hash"
    }
    Write-Host 'Four-edition Android verification passed.'
}
finally {
    Pop-Location
}
