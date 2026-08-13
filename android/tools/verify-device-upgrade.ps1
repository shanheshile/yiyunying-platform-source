[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('user', 'admin', 'authorized', 'owner')]
    [string] $Role,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[^\s\x00-\x1f\x7f]+$')]
    [string] $Serial,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $RcApk,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $StableApk,

    [string] $AaptPath,
    [string] $ApkSignerPath,
    [string] $AdbPath,
    [ValidateRange(5, 120)]
    [int] $LaunchTimeoutSeconds = 30
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$androidRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = (Resolve-Path (Join-Path $androidRoot '..')).Path
$versionFile = Join-Path $androidRoot 'version.properties'
$releaseIdentityFile = Join-Path $workspaceRoot 'backend\config\release-identity.json'

function Get-Sha256Text {
    param([Parameter(Mandatory = $true)][string] $Value)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
    $hash = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($hash.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $hash.Dispose()
    }
}

function Get-SafeTargetLabel {
    return ('sha256:{0}' -f (Get-Sha256Text -Value $Serial).Substring(0, 12))
}

function ConvertTo-SafeDiagnostic {
    param([object[]] $Lines)

    $text = (($Lines | ForEach-Object { [string] $_ }) -join [Environment]::NewLine)
    foreach ($value in @($Serial, $RcApk, $StableApk)) {
        if (-not [string]::IsNullOrWhiteSpace($value)) {
            $text = $text.Replace($value, '<redacted>')
        }
    }
    $text = [regex]::Replace($text, '(?i)\bAuthorization\s*:\s*Bearer\s+\S+', 'Authorization: Bearer <redacted>')
    $text = [regex]::Replace($text, '(?i)\bBearer\s+\S+', 'Bearer <redacted>')
    $text = [regex]::Replace(
        $text,
        '(?i)\b(password|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|token)\s*[:=]\s*(?:"[^"]*"|''[^'']*''|\S+)',
        '$1=<redacted>'
    )
    if ($text.Length -gt 1200) {
        $text = $text.Substring(0, 1200) + '...<truncated>'
    }
    return $text.Trim()
}

function Get-AndroidSdkRoots {
    $roots = New-Object System.Collections.Generic.List[string]
    $candidates = @(
        $env:ANDROID_SDK_ROOT,
        $env:ANDROID_HOME,
        (Join-Path (Split-Path -Parent $workspaceRoot) '.android-sdk'),
        (Join-Path $env:LOCALAPPDATA 'Android\Sdk'),
        'D:\AndroidToolchain\sdk'
    )
    $localProperties = Join-Path $androidRoot 'local.properties'
    if (Test-Path -LiteralPath $localProperties -PathType Leaf) {
        foreach ($line in Get-Content -LiteralPath $localProperties -Encoding UTF8) {
            if ($line -match '^\s*sdk\.dir\s*=\s*(.+?)\s*$') {
                $candidates += ($Matches[1] -replace '\\\\', '\')
            }
        }
    }
    foreach ($candidate in $candidates) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and
            (Test-Path -LiteralPath $candidate -PathType Container)) {
            $resolved = (Resolve-Path -LiteralPath $candidate).Path
            if (-not $roots.Contains($resolved)) { $roots.Add($resolved) }
        }
    }
    return $roots
}

function Resolve-Executable {
    param(
        [string] $RequestedPath,
        [Parameter(Mandatory = $true)][string] $CommandName,
        [Parameter(Mandatory = $true)]
        [ValidateSet('platform-tools', 'build-tools')]
        [string] $SdkArea
    )

    if (-not [string]::IsNullOrWhiteSpace($RequestedPath)) {
        if (-not (Test-Path -LiteralPath $RequestedPath -PathType Leaf)) {
            throw "Tool not found: $CommandName"
        }
        return (Resolve-Path -LiteralPath $RequestedPath).Path
    }
    $command = Get-Command $CommandName -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $command -and (Test-Path -LiteralPath $command.Source -PathType Leaf)) {
        return $command.Source
    }
    foreach ($sdkRoot in Get-AndroidSdkRoots) {
        if ($SdkArea -eq 'platform-tools') {
            $candidate = Join-Path $sdkRoot "platform-tools\$CommandName"
            if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
            continue
        }
        $buildTools = Join-Path $sdkRoot 'build-tools'
        if (-not (Test-Path -LiteralPath $buildTools -PathType Container)) { continue }
        $versions = Get-ChildItem -LiteralPath $buildTools -Directory | Sort-Object {
            try { [Version] $_.Name } catch { [Version] '0.0' }
        } -Descending
        foreach ($version in $versions) {
            $candidate = Join-Path $version.FullName $CommandName
            if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
        }
    }
    throw "Required tool not found: $CommandName. Pass an explicit path or configure Android SDK."
}

function Invoke-Tool {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [Parameter(Mandatory = $true)][string] $Operation
    )

    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $Path @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) {
        $safe = ConvertTo-SafeDiagnostic -Lines $output
        throw "$Operation failed (target $(Get-SafeTargetLabel); exit=$exitCode): $safe"
    }
    return $output
}

function Invoke-Adb {
    param(
        [Parameter(Mandatory = $true)][string[]] $Arguments,
        [Parameter(Mandatory = $true)][string] $Operation
    )

    return Invoke-Tool -Path $script:ResolvedAdb -Arguments (@('-s', $Serial) + $Arguments) -Operation $Operation
}

function Read-VersionState {
    if (-not (Test-Path -LiteralPath $versionFile -PathType Leaf)) {
        throw 'Android version file is missing.'
    }
    $values = @{}
    foreach ($line in Get-Content -LiteralPath $versionFile -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') { $values[$Matches[1]] = $Matches[2] }
    }
    if ($values.VERSION_NAME -notmatch '^\d+\.\d+\.\d+$' -or $values.VERSION_CODE -notmatch '^\d+$') {
        throw 'Android version file is invalid.'
    }
    if ([int] $values.VERSION_CODE -le 1) {
        throw "Upgrade gate requires a Stable versionCode greater than 1; source is $($values.VERSION_CODE)."
    }
    return [pscustomobject]@{ Name = $values.VERSION_NAME; Code = [int] $values.VERSION_CODE }
}

function Read-ProductionSigner {
    if (-not (Test-Path -LiteralPath $releaseIdentityFile -PathType Leaf)) {
        throw 'Stable release identity is missing.'
    }
    $identity = Get-Content -Raw -LiteralPath $releaseIdentityFile -Encoding UTF8 | ConvertFrom-Json
    $digest = ([string] $identity.stable_signer_sha256).Trim().ToUpperInvariant()
    if ($digest -notmatch '^[0-9A-F]{64}$') { throw 'Stable signer digest is invalid.' }
    return $digest
}

function Read-ApkEvidence {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Label
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label APK does not exist." }
    if ((Get-Item -LiteralPath $Path).Length -lt 1MB) { throw "$Label APK is unexpectedly small." }

    $badging = Invoke-Tool -Path $script:ResolvedAapt -Arguments @('dump', 'badging', $Path) -Operation "Read $Label APK metadata"
    $packageLine = [string]($badging | Where-Object { [string] $_ -match '^package:' } | Select-Object -First 1)
    $match = [regex]::Match(
        $packageLine,
        "^package:\s+name='(?<package>[^']+)'\s+versionCode='(?<code>\d+)'\s+versionName='(?<name>[^']+)'(?:\s|$)"
    )
    if (-not $match.Success) { throw "$Label APK metadata is not recognized." }
    $debuggable = @($badging | Where-Object { [string] $_ -match '^application-debuggable' }).Count -gt 0

    $signerOutput = Invoke-Tool -Path $script:ResolvedApkSigner -Arguments @(
        'verify', '--verbose', '--print-certs', $Path
    ) -Operation "Verify $Label APK signature"
    $signers = @($signerOutput | ForEach-Object {
        if ([string] $_ -match '^Signer #(?<number>\d+) certificate SHA-256 digest:\s*(?<digest>[0-9A-Fa-f]{64})\s*$') {
            [pscustomobject]@{
                Number = [int] $Matches.number
                Digest = $Matches.digest.ToUpperInvariant()
            }
        }
    })
    if ($signers.Count -ne 1 -or $signers[0].Number -ne 1) {
        throw "$Label APK must have exactly one signer."
    }
    $v2Verified = @($signerOutput | Where-Object {
        [string] $_ -match '^Verified using v2 scheme \(APK Signature Scheme v2\):\s*true\s*$'
    }).Count -eq 1

    return [pscustomobject]@{
        PackageName = $match.Groups['package'].Value
        VersionCode = [int] $match.Groups['code'].Value
        VersionName = $match.Groups['name'].Value
        Signer = $signers[0].Digest
        V2Verified = $v2Verified
        Debuggable = $debuggable
    }
}

function Assert-ApkEvidence {
    param(
        [Parameter(Mandatory = $true)] $Evidence,
        [Parameter(Mandatory = $true)][string] $Label,
        [Parameter(Mandatory = $true)][int] $ExpectedCode,
        [Parameter(Mandatory = $true)][string] $ExpectedPackage,
        [Parameter(Mandatory = $true)][string] $ExpectedVersionName,
        [Parameter(Mandatory = $true)][string] $ExpectedSigner,
        [switch] $RequireNotDebuggable
    )

    if ($Evidence.PackageName -ne $ExpectedPackage) { throw "$Label APK package does not match the role." }
    if ($Evidence.VersionName -ne $ExpectedVersionName) { throw "$Label APK versionName does not match the role." }
    if ($Evidence.VersionCode -ne $ExpectedCode) { throw "$Label APK must use versionCode=$ExpectedCode." }
    if ($Evidence.Signer -ne $ExpectedSigner) { throw "$Label APK does not use the expected production signer." }
    if (-not $Evidence.V2Verified) { throw "$Label APK must verify with APK Signature Scheme v2." }
    if ($RequireNotDebuggable -and $Evidence.Debuggable) { throw "$Label APK must not be debuggable." }
}

function Assert-BaselineApkEvidence {
    param(
        [Parameter(Mandatory = $true)] $Evidence,
        [Parameter(Mandatory = $true)][string] $ExpectedPackage,
        [Parameter(Mandatory = $true)][string] $ExpectedVersionSuffix,
        [Parameter(Mandatory = $true)][int] $StableVersionCode,
        [Parameter(Mandatory = $true)][string] $ExpectedSigner
    )

    if ($Evidence.PackageName -ne $ExpectedPackage) { throw 'Baseline APK package does not match the role.' }
    $expectedPattern = '^\d+\.\d+\.\d+-' + [regex]::Escape($ExpectedVersionSuffix) + '$'
    if ($Evidence.VersionName -notmatch $expectedPattern) { throw 'Baseline APK versionName does not match the role.' }
    if ($Evidence.VersionCode -le 0 -or $Evidence.VersionCode -ge $StableVersionCode) {
        throw "Baseline APK versionCode must be lower than Stable versionCode=$StableVersionCode."
    }
    if ($Evidence.Signer -ne $ExpectedSigner) { throw 'Baseline APK does not use the expected production signer.' }
    if (-not $Evidence.V2Verified) { throw 'Baseline APK must verify with APK Signature Scheme v2.' }
    if ($Evidence.Debuggable) { throw 'Baseline APK must not be debuggable.' }
}

function Assert-DeviceOnline {
    $devices = Invoke-Tool -Path $script:ResolvedAdb -Arguments @('devices') -Operation 'Read ADB device list'
    $matches = @($devices | ForEach-Object {
        $line = [string] $_
        if ($line -match '^(.+?)\s+(device|offline|unauthorized|no permissions)\s*$' -and
            $Matches[1] -eq $Serial) {
            $Matches[2]
        }
    })
    if ($matches.Count -ne 1) { throw "Target $(Get-SafeTargetLabel) is not unique in the ADB device list." }
    if ($matches[0] -ne 'device') {
        throw "Target $(Get-SafeTargetLabel) state is $($matches[0]); expected device."
    }
    $state = ((Invoke-Adb -Arguments @('get-state') -Operation 'Verify ADB target state') -join '').Trim()
    if ($state -ne 'device') { throw "Target $(Get-SafeTargetLabel) is not online." }
}

function Install-ApkUpgradeOnly {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Label
    )

    # Deliberately omit -d: a downgrade must fail instead of mutating the test device.
    $output = Invoke-Adb -Arguments @('install', '-r', $Path) -Operation "Install $Label APK"
    if ((($output | ForEach-Object { [string] $_ }) -join "`n") -notmatch '(?m)^Success\s*$') {
        throw "$Label APK installation did not return Success."
    }
}

function Read-InstalledPackage {
    param(
        [Parameter(Mandatory = $true)][string] $PackageName,
        [Parameter(Mandatory = $true)][int] $ExpectedCode
    )

    $dump = Invoke-Adb -Arguments @('shell', 'dumpsys', 'package', $PackageName) -Operation 'Read installed package identity'
    $text = ($dump | ForEach-Object { [string] $_ }) -join "`n"
    $packageMatch = [regex]::Match($text, '(?m)^\s*Package \[(?<package>[^\]]+)\]')
    $codeMatch = [regex]::Match($text, '(?m)^\s*versionCode=(\d+)(?:\s|$)')
    $nameMatch = [regex]::Match($text, '(?m)^\s*versionName=(\S+)\s*$')
    $uidMatch = [regex]::Match($text, '(?m)^\s*userId=(\d+)\s*$')
    $dataMatch = [regex]::Match($text, '(?m)^\s*dataDir=(\S+)\s*$')
    if (-not $packageMatch.Success -or -not $codeMatch.Success -or -not $nameMatch.Success -or
        -not $uidMatch.Success -or -not $dataMatch.Success) {
        throw 'dumpsys package lacks packageName/versionCode/versionName/userId/dataDir evidence.'
    }
    if ($packageMatch.Groups['package'].Value -ne $PackageName) { throw 'Installed packageName does not match.' }
    if ([int] $codeMatch.Groups[1].Value -ne $ExpectedCode) {
        throw "Installed versionCode is not $ExpectedCode."
    }

    return [pscustomobject]@{
        PackageName = $packageMatch.Groups['package'].Value
        VersionCode = [int] $codeMatch.Groups[1].Value
        VersionName = $nameMatch.Groups[1].Value
        UserId = $uidMatch.Groups[1].Value
        DataDir = $dataMatch.Groups[1].Value
    }
}

function Start-And-WaitForApp {
    param([Parameter(Mandatory = $true)][string] $PackageName)

    $component = "$PackageName/$PackageName.launcher.DefaultLauncher"
    try {
        $launchOutput = Invoke-Adb -Arguments @(
            'shell', 'am', 'start', '-W', '-n', $component
        ) -Operation 'Launch explicit launcher activity'
        if ((($launchOutput | ForEach-Object { [string] $_ }) -join "`n") -notmatch '(?m)^Status:\s+ok\s*$') {
            throw 'Explicit launcher activity did not return Status: ok.'
        }
    }
    catch {
        Invoke-Adb -Arguments @(
            'shell', 'monkey', '-p', $PackageName, '-c', 'android.intent.category.LAUNCHER', '1'
        ) -Operation 'Launch app with monkey fallback' | Out-Null
    }

    $deadline = [DateTime]::UtcNow.AddSeconds($LaunchTimeoutSeconds)
    do {
        $processIds = @()
        try {
            $processIds = Invoke-Adb -Arguments @('shell', 'pidof', $PackageName) -Operation 'Wait for app process'
        }
        catch { $processIds = @() }
        $windows = @()
        try {
            $windows = Invoke-Adb -Arguments @('shell', 'dumpsys', 'window', 'windows') -Operation 'Wait for focused app window'
        }
        catch { $windows = @() }
        $windowText = ($windows | ForEach-Object { [string] $_ }) -join "`n"
        $escapedPackage = [regex]::Escape($PackageName)
        $focused = $windowText -match "(?m)^\s*m(?:CurrentFocus|FocusedApp)=.*(?<![A-Za-z0-9_.])$escapedPackage/"
        if ((($processIds -join '').Trim() -match '^\d+(\s+\d+)*$') -and $focused) {
            return
        }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'App did not establish both a process and focused window before timeout.'
}

try {
    $version = Read-VersionState
    $roleMap = @{
        user = @{ Package = 'xyz.jjmxg.yiyunying.user'; Suffix = 'user' }
        admin = @{ Package = 'xyz.jjmxg.yiyunying.admin'; Suffix = 'admin' }
        authorized = @{ Package = 'xyz.jjmxg.yiyunying.authorized'; Suffix = 'authorized-platform' }
        owner = @{ Package = 'xyz.jjmxg.yiyunying.platformowner'; Suffix = 'platform-owner' }
    }
    $expected = $roleMap[$Role]
    $expectedVersionName = "$($version.Name)-$($expected.Suffix)"
    $productionSigner = Read-ProductionSigner

    $script:ResolvedAapt = Resolve-Executable -RequestedPath $AaptPath -CommandName 'aapt2.exe' -SdkArea 'build-tools'
    $script:ResolvedApkSigner = Resolve-Executable -RequestedPath $ApkSignerPath -CommandName 'apksigner.bat' -SdkArea 'build-tools'
    $script:ResolvedAdb = Resolve-Executable -RequestedPath $AdbPath -CommandName 'adb.exe' -SdkArea 'platform-tools'

    # All local APK evidence is fail-closed and completes before any ADB installation.
    $rc = Read-ApkEvidence -Path $RcApk -Label 'RC'
    $stable = Read-ApkEvidence -Path $StableApk -Label 'Stable'
    Assert-BaselineApkEvidence -Evidence $rc -ExpectedPackage $expected.Package `
        -ExpectedVersionSuffix $expected.Suffix -StableVersionCode $version.Code `
        -ExpectedSigner $productionSigner
    Assert-ApkEvidence -Evidence $stable -Label 'Stable' -ExpectedCode $version.Code `
        -ExpectedPackage $expected.Package -ExpectedVersionName $expectedVersionName `
        -ExpectedSigner $productionSigner -RequireNotDebuggable
    if ($rc.Signer -ne $stable.Signer) { throw 'RC and Stable APK signers do not match.' }

    Assert-DeviceOnline
    Install-ApkUpgradeOnly -Path $RcApk -Label "baseline code$($rc.VersionCode)"
    $rcInstalled = Read-InstalledPackage -PackageName $expected.Package -ExpectedCode $rc.VersionCode
    if ($rcInstalled.VersionName -ne $rc.VersionName) { throw 'Installed baseline versionName does not match.' }
    Start-And-WaitForApp -PackageName $expected.Package

    Install-ApkUpgradeOnly -Path $StableApk -Label "Stable code$($version.Code)"
    $stableInstalled = Read-InstalledPackage -PackageName $expected.Package -ExpectedCode $version.Code
    if ($stableInstalled.VersionName -ne $expectedVersionName) { throw 'Installed Stable versionName does not match.' }
    if ($stableInstalled.PackageName -ne $rcInstalled.PackageName -or
        $stableInstalled.UserId -ne $rcInstalled.UserId -or
        $stableInstalled.DataDir -ne $rcInstalled.DataDir) {
        throw 'packageName/userId/dataDir changed across the in-place upgrade.'
    }
    Invoke-Adb -Arguments @(
        'shell', 'am', 'force-stop', $expected.Package
    ) -Operation 'Force-stop upgraded app' | Out-Null
    Start-And-WaitForApp -PackageName $expected.Package

    [ordered]@{
        status = 'PASS'
        gate = 'android-device-upgrade'
        target = Get-SafeTargetLabel
        role = $Role
        packageName = $expected.Package
        fromVersionCode = $rc.VersionCode
        fromVersionName = $rc.VersionName
        toVersionCode = $version.Code
        versionName = $expectedVersionName
        signerSha256 = $productionSigner.ToLowerInvariant()
        signatureSchemeV2Verified = $true
        uidPreserved = $true
        dataDirPreserved = $true
        launchVerifiedBeforeAndAfter = $true
    } | ConvertTo-Json -Compress
}
catch {
    $safeError = ConvertTo-SafeDiagnostic -Lines @($_.Exception.Message)
    Write-Error "DEVICE_UPGRADE_GATE_FAIL (target $(Get-SafeTargetLabel)): $safeError"
    exit 1
}
