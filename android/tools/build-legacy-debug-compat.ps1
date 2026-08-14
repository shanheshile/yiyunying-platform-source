[CmdletBinding()]
param(
    [string] $JavaHome = $env:JAVA_HOME,
    [switch] $ReplaceExisting
)

$ErrorActionPreference = 'Stop'
$androidRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$repositoryRoot = (Resolve-Path (Join-Path $androidRoot '..')).Path
$versionFile = Join-Path $androidRoot 'version.properties'
$gradleFile = Join-Path $androidRoot 'app\build.gradle'
$mainNetworkConfig = Join-Path $androidRoot 'app\src\main\res\xml\network_security_config.xml'
$apiClientFile = Join-Path $androidRoot 'app\src\main\java\xyz\jjmxg\yiyunying\data\api\ApiClient.java'
$legacyIdentityAnchorFile = Join-Path $androidRoot 'legacy-debug-upgrade-identity.json'
$frozenManifestFile = Join-Path $repositoryRoot 'releases\2.7.15\release-manifest.json'
$artifactSecurityScript = Join-Path $PSScriptRoot 'legacy-debug-compat-security.ps1'
$productionApiBaseUrl = 'https://appht.jjmxg.xyz/'
. $artifactSecurityScript

function Read-VersionProperties {
    param([Parameter(Mandatory = $true)][int] $LegacyMaximumVersionCode)
    $values = @{}
    foreach ($line in Get-Content -LiteralPath $versionFile -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') {
            $values[$Matches[1]] = $Matches[2]
        }
    }
    if ($values.VERSION_NAME -notmatch '^\d+\.\d+\.\d+$') {
        throw 'VERSION_NAME 必须使用 major.minor.patch 格式。'
    }
    $code = 0
    if (-not [int]::TryParse($values.VERSION_CODE, [ref] $code) -or
        $code -le $LegacyMaximumVersionCode) {
        throw "旧 Debug 安全覆盖包必须使用全局 VERSION_CODE，且必须大于 $LegacyMaximumVersionCode。"
    }
    return [ordered]@{ Name = $values.VERSION_NAME; Code = $code }
}

function Get-AndroidSdkRoots {
    $roots = New-Object System.Collections.Generic.List[string]
    foreach ($candidate in @($env:ANDROID_SDK_ROOT, $env:ANDROID_HOME, 'D:\AndroidToolchain\sdk')) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path -LiteralPath $candidate)) {
            $resolved = (Resolve-Path -LiteralPath $candidate).Path
            if (-not $roots.Contains($resolved)) { $roots.Add($resolved) }
        }
    }
    $localProperties = Join-Path $androidRoot 'local.properties'
    if (Test-Path -LiteralPath $localProperties) {
        foreach ($line in Get-Content -LiteralPath $localProperties -Encoding UTF8) {
            if ($line -match '^\s*sdk\.dir\s*=\s*(.+?)\s*$') {
                $candidate = $Matches[1] -replace '\\\\', '\'
                if (Test-Path -LiteralPath $candidate) {
                    $resolved = (Resolve-Path -LiteralPath $candidate).Path
                    if (-not $roots.Contains($resolved)) { $roots.Add($resolved) }
                }
            }
        }
    }
    return $roots
}

function Resolve-AndroidBuildTool([string] $FileName) {
    foreach ($sdkRoot in Get-AndroidSdkRoots) {
        $buildTools = Join-Path $sdkRoot 'build-tools'
        if (-not (Test-Path -LiteralPath $buildTools)) { continue }
        $versions = Get-ChildItem -LiteralPath $buildTools -Directory | Sort-Object {
            try { [Version] $_.Name } catch { [Version] '0.0' }
        } -Descending
        foreach ($version in $versions) {
            $candidate = Join-Path $version.FullName $FileName
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }
    }
    throw "未找到 Android 构建工具：$FileName"
}

function Assert-SourceTransportContract {
    $gradle = Get-Content -Raw -LiteralPath $gradleFile -Encoding UTF8
    foreach ($marker in @(
        'legacyCompat {',
        'initWith release',
        'debuggable false',
        "applicationIdSuffix '.debug'",
        "signingConfig signingConfigs.debug",
        "YIYUNYING_LEGACY_COMPAT_STRICT",
        "buildConfigField 'boolean', 'ALLOW_HTTP_ENDPOINTS', 'false'",
        "buildConfigField 'String', 'DEFAULT_API_BASE_URL', asBuildConfigString('https://appht.jjmxg.xyz/')"
    )) {
        if (-not $gradle.Contains($marker)) { throw "兼容构建合同缺失：$marker" }
    }
    $network = Get-Content -Raw -LiteralPath $mainNetworkConfig -Encoding UTF8
    if ($network -notmatch 'cleartextTrafficPermitted="false"' -or
        $network -match 'cleartextTrafficPermitted="true"') {
        throw '主网络安全配置没有保持 cleartext=false。'
    }
    $apiClient = Get-Content -Raw -LiteralPath $apiClientFile -Encoding UTF8
    if (-not $apiClient.Contains('.followRedirects(false)') -or
        -not $apiClient.Contains('.followSslRedirects(false)')) {
        throw 'API 客户端必须继续拒绝 HTTP 与 SSL 重定向。'
    }
}

$legacyIdentity = Read-LegacyUpgradeIdentityAnchor -Path $legacyIdentityAnchorFile
$version = Read-VersionProperties -LegacyMaximumVersionCode $legacyIdentity.MaximumVersionCode
$stableManifestFile = Join-Path $repositoryRoot "releases\$($version.Name)\release-manifest.json"
$connectionIdentity = Read-LegacyCompatConnectionIdentity `
    -StableManifestPath $stableManifestFile `
    -ExpectedVersionName $version.Name `
    -ExpectedVersionCode $version.Code `
    -ExpectedApiBaseUrl $productionApiBaseUrl `
    -LegacyIdentityAnchor $legacyIdentity
Assert-SourceTransportContract
$legacySigner = Assert-FrozenDebugManifestMatchesAnchor `
    -Anchor $legacyIdentity -ManifestPath $frozenManifestFile
$aapt2 = Resolve-AndroidBuildTool 'aapt2.exe'
$apksigner = Resolve-AndroidBuildTool 'apksigner.bat'
$roles = [ordered]@{
    user = [ordered]@{ Flavor = 'user'; FileStem = 'user'; VersionSuffix = 'user'; Package = $legacyIdentity.Packages.user }
    admin = [ordered]@{ Flavor = 'admin'; FileStem = 'admin'; VersionSuffix = 'admin'; Package = $legacyIdentity.Packages.admin }
    authorized = [ordered]@{ Flavor = 'authorizedPlatform'; FileStem = 'authorized-platform'; VersionSuffix = 'authorized-platform'; Package = $legacyIdentity.Packages.authorized }
    owner = [ordered]@{ Flavor = 'platformOwner'; FileStem = 'platform-owner'; VersionSuffix = 'platform-owner'; Package = $legacyIdentity.Packages.owner }
}

$previousJavaHome = $env:JAVA_HOME
$previousStrictIdentity = $env:YIYUNYING_LEGACY_COMPAT_STRICT
try {
    if (-not [string]::IsNullOrWhiteSpace($JavaHome)) { $env:JAVA_HOME = $JavaHome }
    $env:YIYUNYING_LEGACY_COMPAT_STRICT = '1'
    Push-Location $androidRoot
    try {
        $tasks = @($roles.Values | ForEach-Object { "assemble$($_.Flavor.Substring(0,1).ToUpperInvariant())$($_.Flavor.Substring(1))LegacyCompat" })
        & .\gradlew.bat @tasks --no-daemon --stacktrace
        if ($LASTEXITCODE -ne 0) { throw "旧 Debug 安全覆盖构建失败：$LASTEXITCODE" }
    }
    finally { Pop-Location }
}
finally {
    $env:JAVA_HOME = $previousJavaHome
    $env:YIYUNYING_LEGACY_COMPAT_STRICT = $previousStrictIdentity
}

$outputParent = Join-Path $repositoryRoot 'releases\internal\legacy-debug-compat'
$outputRoot = Join-Path $outputParent $version.Name
if ((Test-Path -LiteralPath $outputRoot) -and -not $ReplaceExisting) {
    throw "输出目录已存在；核对后使用 -ReplaceExisting：$outputRoot"
}
New-Item -ItemType Directory -Path $outputParent -Force | Out-Null
$stagingRoot = Join-Path $outputParent ('.partial-' + [Guid]::NewGuid().ToString('N'))
$backupRoot = Join-Path $outputParent ('.previous-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stagingRoot | Out-Null
$releases = New-Object System.Collections.Generic.List[object]
$activated = $false
try {
    foreach ($entry in $roles.GetEnumerator()) {
        $role = $entry.Key
        $policy = $entry.Value
        $buildDirectory = Join-Path $androidRoot "app\build\outputs\apk\$($policy.Flavor)\legacyCompat"
        $sourceApks = @(Get-ChildItem -LiteralPath $buildDirectory -Filter '*.apk' -File)
        if ($sourceApks.Count -ne 1) { throw "兼容变体输出数量异常：$buildDirectory" }
        $fileName = "yiyunying-$($policy.FileStem)-v$($version.Name)-debug.apk"
        $target = Join-Path $stagingRoot $fileName
        Copy-Item -LiteralPath $sourceApks[0].FullName -Destination $target
        $expectedPackage = [string] $policy.Package
        $expectedVersionName = "$($version.Name)-$($policy.VersionSuffix)-debug"
        $verified = Assert-LegacyCompatApk `
            -Aapt2 $aapt2 -ApkSigner $apksigner -ApkPath $target `
            -ExpectedPackage $expectedPackage `
            -ExpectedVersionName $expectedVersionName `
            -ExpectedVersionCode $version.Code `
            -ExpectedSignerSha256 $legacySigner
        $signer = $verified.SignerSha256
        $file = Get-Item -LiteralPath $target
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $target).Hash.ToUpperInvariant()
        $releases.Add([ordered]@{
            id = $role
            fileName = $fileName
            packageName = $expectedPackage
            versionName = $expectedVersionName
            versionCode = $version.Code
            signerSha256 = $signer
            networkSecurityResource = $verified.NetworkSecurityResource
            sizeBytes = [long] $file.Length
            sha256 = $hash
        })
    }
    $manifest = [ordered]@{
        schemaVersion = 2
        channel = 'DebugCompatibility'
        finalizationStatus = 'internal'
        distribution = 'internal-only'
        versionName = $version.Name
        versionCode = $version.Code
        buildType = 'legacyCompat'
        debuggable = $false
        testOnly = $false
        apiBaseUrl = $productionApiBaseUrl
        cleartextTrafficPermitted = $false
        trustAnchors = @('system')
        followRedirects = $false
        apkSignatureSchemeV2 = $true
        signerCount = 1
        dexTransportVerified = $true
        legacyUpgradeMaximumVersionCode = $legacyIdentity.MaximumVersionCode
        legacyPackageSignerSha256 = $legacySigner
        connectionIdentity = $connectionIdentity
        generatedAt = [DateTimeOffset]::Now.ToString('o')
        releases = $releases
    }
    $manifestPath = Join-Path $stagingRoot 'release-manifest.json'
    [IO.File]::WriteAllText(
        $manifestPath,
        ($manifest | ConvertTo-Json -Depth 12) + [Environment]::NewLine,
        (New-Object Text.UTF8Encoding($false))
    )
    if (Test-Path -LiteralPath $outputRoot) {
        Move-Item -LiteralPath $outputRoot -Destination $backupRoot
    }
    try {
        Move-Item -LiteralPath $stagingRoot -Destination $outputRoot
        $activated = $true
    }
    catch {
        if (Test-Path -LiteralPath $backupRoot) {
            Move-Item -LiteralPath $backupRoot -Destination $outputRoot
        }
        throw
    }
    if (Test-Path -LiteralPath $backupRoot) {
        Remove-Item -LiteralPath $backupRoot -Recurse -Force
    }
}
finally {
    if (-not $activated -and (Test-Path -LiteralPath $stagingRoot)) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}

Write-Output "PASS: 旧 Debug 安全覆盖包构建及离线产物门禁验证通过（未进行真机验证）：$outputRoot"
Write-Output "清单：$(Join-Path $outputRoot 'release-manifest.json')"
