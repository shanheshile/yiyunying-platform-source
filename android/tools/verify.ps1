param(
    [string] $JavaHome = $env:JAVA_HOME,
    [ValidateSet('Debug', 'Stable')]
    [string] $Channel = 'Debug'
)

$ErrorActionPreference = 'Stop'
$buildType = if ($Channel -eq 'Stable') { 'Release' } else { 'Debug' }
$buildTypeDirectory = $buildType.ToLowerInvariant()
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runRoot = $projectRoot

if ([string]::IsNullOrWhiteSpace($JavaHome)) {
    throw 'JAVA_HOME is not set. Pass -JavaHome or configure JDK 17 first.'
}
$JavaHome = $JavaHome.TrimEnd([char[]]@('\', '/'))

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

function Invoke-GradlePhase {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Label,
        [Parameter(Mandatory = $true)]
        [string[]] $Tasks
    )

    Write-Host "Gradle verification phase: $Label"
    & .\gradlew.bat --no-daemon --no-parallel --max-workers=1 --rerun-tasks @Tasks
    if ($LASTEXITCODE -ne 0) {
        throw "Android verification phase '$Label' failed with exit code $LASTEXITCODE."
    }
}

Push-Location $runRoot
try {
    $unitTestTasks = @(
        'testPlatformOwnerDebugUnitTest',
        'testAuthorizedPlatformDebugUnitTest',
        'testAdminDebugUnitTest',
        'testUserDebugUnitTest'
    )
    $cleanRequired = $true
    foreach ($unitTestTask in $unitTestTasks) {
        $phaseTasks = if ($cleanRequired) { @('clean', $unitTestTask) } else { @($unitTestTask) }
        $cleanRequired = $false
        Invoke-GradlePhase -Label "unit test: $unitTestTask" -Tasks $phaseTasks
    }

    # Each edition gets a fresh, bounded Gradle process. This releases unit-test
    # workers before lint/R8 and keeps Stable on Release lint + assemble tasks.
    $editions = @('PlatformOwner', 'AuthorizedPlatform', 'Admin', 'User')
    foreach ($edition in $editions) {
        Invoke-GradlePhase -Label "$edition $buildType lint and assemble" -Tasks @(
            "lint${edition}${buildType}",
            "assemble${edition}${buildType}"
        )
    }
    $apks = @(
        "app\build\outputs\apk\platformOwner\$buildTypeDirectory\app-platformOwner-$buildTypeDirectory.apk",
        "app\build\outputs\apk\authorizedPlatform\$buildTypeDirectory\app-authorizedPlatform-$buildTypeDirectory.apk",
        "app\build\outputs\apk\admin\$buildTypeDirectory\app-admin-$buildTypeDirectory.apk",
        "app\build\outputs\apk\user\$buildTypeDirectory\app-user-$buildTypeDirectory.apk"
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
