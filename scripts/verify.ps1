[CmdletBinding()]
param(
    [switch]$SkipInstall
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
Write-Host "Version chain passed: $($release.versionName) ($($release.versionCode))." -ForegroundColor Green

Write-Host 'Linting PHP files...'
Get-ChildItem -LiteralPath (Join-Path $root 'backend') -Recurse -Filter '*.php' -File | ForEach-Object {
    & php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
}

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

Push-Location $androidDirectory
try {
    & .\gradlew.bat testPlatformOwnerDebugUnitTest testAuthorizedPlatformDebugUnitTest testAdminDebugUnitTest testUserDebugUnitTest assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug --stacktrace
    if ($LASTEXITCODE -ne 0) { throw "Android verification failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
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

Write-Host 'All automated verification passed.' -ForegroundColor Green
