$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
. (Join-Path $root 'android\tools\legacy-debug-compat-security.ps1')

function Assert-Throws([scriptblock] $Action, [string] $Label) {
    $threw = $false
    try { & $Action | Out-Null } catch { $threw = $true }
    if (-not $threw) { throw "Expected rejection: $Label" }
}

$trackedAnchorPath = Join-Path $root 'android\legacy-debug-upgrade-identity.json'
$trackedAnchor = Read-LegacyUpgradeIdentityAnchor -Path $trackedAnchorPath
if ($trackedAnchor.MaximumVersionCode -ne 60 -or
    $trackedAnchor.SignerSha256 -cne '10162EBB7147EA0823C281D9F86FEFF2A353984A41497F17E196E50614E9B76E' -or
    $trackedAnchor.Packages.Count -ne 4 -or
    $trackedAnchor.ConnectionIdentity.appKeySha256 -cne '05872e1f0465c7ab48df13e37dfefa3a95c882c268f3836264ed83f6c0b9f264' -or
    $trackedAnchor.ConnectionIdentity.platformKeySha256 -cne 'e8260e22cd152015735ab5a05e392fed162b3e71d639f4392fb8550ae886ef54' -or
    $trackedAnchor.ConnectionIdentity.authorizedPlatformKeySha256 -cne '9d300ae4617dc8f0ebc22444733ab3b8681636ac74ce2676059c7754eab7ff82') {
    throw 'Tracked legacy upgrade identity anchor changed unexpectedly.'
}
Assert-FrozenDebugManifestMatchesAnchor `
    -Anchor $trackedAnchor `
    -ManifestPath (Join-Path $root 'releases\2.7.15\release-manifest.json') | Out-Null

$packageLine = "package: name='xyz.jjmxg.yiyunying.user.debug' versionCode='65' versionName='1.0.0-user-debug'"
Assert-LegacyCompatBadging `
    -BadgingText ($packageLine + "`napplication: label='user'") `
    -ExpectedPackage 'xyz.jjmxg.yiyunying.user.debug' `
    -ExpectedVersionName '1.0.0-user-debug' `
    -ExpectedVersionCode 65 | Out-Null
Assert-Throws {
    Assert-LegacyCompatBadging -BadgingText ($packageLine + "`napplication-debuggable") `
        -ExpectedPackage 'xyz.jjmxg.yiyunying.user.debug' `
        -ExpectedVersionName '1.0.0-user-debug' -ExpectedVersionCode 65
} 'debuggable'
Assert-Throws {
    Assert-LegacyCompatBadging -BadgingText ($packageLine + "`ntestOnly='true'") `
        -ExpectedPackage 'xyz.jjmxg.yiyunying.user.debug' `
        -ExpectedVersionName '1.0.0-user-debug' -ExpectedVersionCode 65
} 'testOnly'

$digest = '10' * 32
$signer = @"
Verifies
Verified using v2 scheme (APK Signature Scheme v2): true
Number of signers: 1
Signer #1 certificate SHA-256 digest: $digest
"@
Assert-LegacyCompatSignerOutput -SignerText $signer -ExpectedSignerSha256 $digest | Out-Null
Assert-Throws {
    Assert-LegacyCompatSignerOutput `
        -SignerText ($signer -replace 'Scheme v2\): true', 'Scheme v2): false') `
        -ExpectedSignerSha256 $digest
} 'v2 false'
Assert-Throws {
    Assert-LegacyCompatSignerOutput `
        -SignerText ($signer -replace 'Number of signers: 1', 'Number of signers: 2') `
        -ExpectedSignerSha256 $digest
} 'two signers'

$network = @"
E: network-security-config
  E: base-config
    A: cleartextTrafficPermitted=false
      E: trust-anchors
        E: certificates
          A: src="system"
"@
Assert-LegacyCompatNetworkSecurityOutput -NetworkSecurityText $network
Assert-Throws {
    Assert-LegacyCompatNetworkSecurityOutput `
        -NetworkSecurityText ($network -replace 'cleartextTrafficPermitted=false', 'cleartextTrafficPermitted=true')
} 'cleartext true'
Assert-Throws {
    Assert-LegacyCompatNetworkSecurityOutput `
        -NetworkSecurityText ($network + "`n          A: src=`"user`"")
} 'user trust'
Assert-Throws {
    Assert-LegacyCompatNetworkSecurityOutput `
        -NetworkSecurityText ($network + "`n          A: src=@raw/custom_ca")
} 'custom raw certificate trust'
Assert-LegacyCompatNetworkSecurityOutput `
    -NetworkSecurityText ($network + "`n          A: src=`"system`"")

$compiledManifest = 'A: http://schemas.android.com/apk/res/android:networkSecurityConfig(0x01010527)=@0x7f140006'
$compiledResources = @"
    resource 0x7f140006 xml/network_security_config
      () (file) res/8G.xml type=XML
"@
$resolvedNetwork = Resolve-LegacyCompatNetworkSecurityResource `
    -ManifestText $compiledManifest -ResourcesText $compiledResources
if ($resolvedNetwork -ne 'res/8G.xml') { throw 'Obfuscated compiled XML path was not resolved.' }
Assert-Throws {
    Resolve-LegacyCompatNetworkSecurityResource `
        -ManifestText 'A: android:networkSecurityConfig=@0x7f140007' `
        -ResourcesText $compiledResources
} 'missing resource id'
Assert-Throws {
    Resolve-LegacyCompatNetworkSecurityResource `
        -ManifestText $compiledManifest `
        -ResourcesText ($compiledResources -replace 'res/8G.xml', 'res/../unsafe.xml')
} 'unsafe compiled XML path'
Assert-Throws {
    Resolve-LegacyCompatNetworkSecurityResource `
        -ManifestText 'no network resource' -ResourcesText $compiledResources
} 'missing manifest reference'

Add-Type -AssemblyName System.IO.Compression
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('legacy-compat-test-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$connectionEnvironmentNames = @(
    'YIYUNYING_APP_KEY',
    'YIYUNYING_PLATFORM_KEY',
    'YIYUNYING_AUTHORIZED_PLATFORM_KEY'
)
$previousConnectionEnvironment = @{}
foreach ($name in $connectionEnvironmentNames) {
    $previousConnectionEnvironment[$name] = [Environment]::GetEnvironmentVariable($name)
}
try {
    $invalidAnchorPath = Join-Path $temporary 'invalid-anchor.json'
    $invalidAnchor = Get-Content -Raw -LiteralPath $trackedAnchorPath -Encoding UTF8 | ConvertFrom-Json
    $invalidAnchor.schemaVersion = 2
    [IO.File]::WriteAllText(
        $invalidAnchorPath,
        ($invalidAnchor | ConvertTo-Json -Depth 8),
        (New-Object Text.UTF8Encoding($false))
    )
    Assert-Throws { Read-LegacyUpgradeIdentityAnchor -Path $invalidAnchorPath } 'anchor schema'

    $invalidFrozenPath = Join-Path $temporary 'invalid-frozen-manifest.json'
    $invalidFrozen = Get-Content -Raw `
        -LiteralPath (Join-Path $root 'releases\2.7.15\release-manifest.json') `
        -Encoding UTF8 | ConvertFrom-Json
    $invalidFrozen.connectionIdentity.appKeySha256 = '0' * 64
    [IO.File]::WriteAllText(
        $invalidFrozenPath,
        ($invalidFrozen | ConvertTo-Json -Depth 12),
        (New-Object Text.UTF8Encoding($false))
    )
    Assert-Throws {
        Assert-FrozenDebugManifestMatchesAnchor `
            -Anchor $trackedAnchor -ManifestPath $invalidFrozenPath
    } 'frozen connection identity replacement'

    $appKey = 'fixture-app-key-a91d'
    $platformKey = 'fixture-platform-key-b42e'
    $authorizedKey = 'fixture-authorized-key-c73f'
    [Environment]::SetEnvironmentVariable('YIYUNYING_APP_KEY', $appKey)
    [Environment]::SetEnvironmentVariable('YIYUNYING_PLATFORM_KEY', $platformKey)
    [Environment]::SetEnvironmentVariable('YIYUNYING_AUTHORIZED_PLATFORM_KEY', $authorizedKey)
    $stableManifestPath = Join-Path $temporary 'stable-manifest.json'
    $stableManifest = [ordered]@{
        schemaVersion = 4
        channel = 'Stable'
        finalizationStatus = 'pending'
        versionName = '1.0.0'
        versionCode = 65
        connectionIdentity = [ordered]@{
            apiBaseUrl = 'https://appht.jjmxg.xyz/'
            appKeySha256 = Get-LegacyCompatUtf8Sha256 -Value $appKey
            platformKeySha256 = Get-LegacyCompatUtf8Sha256 -Value $platformKey
            authorizedPlatformKeySha256 = Get-LegacyCompatUtf8Sha256 -Value $authorizedKey
        }
    }
    $fixtureAnchor = [pscustomobject]@{
        ConnectionIdentity = [ordered]@{
            appKeySha256 = $stableManifest.connectionIdentity.appKeySha256
            platformKeySha256 = $stableManifest.connectionIdentity.platformKeySha256
            authorizedPlatformKeySha256 = $stableManifest.connectionIdentity.authorizedPlatformKeySha256
        }
    }
    [IO.File]::WriteAllText(
        $stableManifestPath,
        ($stableManifest | ConvertTo-Json -Depth 8),
        (New-Object Text.UTF8Encoding($false))
    )
    $connectionEvidence = Read-LegacyCompatConnectionIdentity `
        -StableManifestPath $stableManifestPath `
        -ExpectedVersionName '1.0.0' `
        -ExpectedVersionCode 65 `
        -ExpectedApiBaseUrl 'https://appht.jjmxg.xyz/' `
        -LegacyIdentityAnchor $fixtureAnchor
    $evidenceJson = $connectionEvidence | ConvertTo-Json -Compress
    if ($connectionEvidence.Count -ne 3 -or
        $evidenceJson.Contains($appKey) -or
        $evidenceJson.Contains($platformKey) -or
        $evidenceJson.Contains($authorizedKey)) {
        throw 'Compatibility identity evidence must contain only three hashes.'
    }
    [Environment]::SetEnvironmentVariable('YIYUNYING_APP_KEY', 'yiyunying-local')
    Assert-Throws {
        Read-LegacyCompatConnectionIdentity `
            -StableManifestPath $stableManifestPath `
            -ExpectedVersionName '1.0.0' -ExpectedVersionCode 65 `
            -ExpectedApiBaseUrl 'https://appht.jjmxg.xyz/' `
            -LegacyIdentityAnchor $fixtureAnchor
    } 'placeholder connection identity'
    [Environment]::SetEnvironmentVariable('YIYUNYING_APP_KEY', "fixture-app-key-a91d`n")
    Assert-Throws {
        Read-LegacyCompatConnectionIdentity `
            -StableManifestPath $stableManifestPath `
            -ExpectedVersionName '1.0.0' -ExpectedVersionCode 65 `
            -ExpectedApiBaseUrl 'https://appht.jjmxg.xyz/' `
            -LegacyIdentityAnchor $fixtureAnchor
    } 'control character connection identity'
    $replacementAppKey = 'synchronized-replacement-app-key-d84a'
    [Environment]::SetEnvironmentVariable('YIYUNYING_APP_KEY', $replacementAppKey)
    $stableManifest.connectionIdentity.appKeySha256 = `
        Get-LegacyCompatUtf8Sha256 -Value $replacementAppKey
    [IO.File]::WriteAllText(
        $stableManifestPath,
        ($stableManifest | ConvertTo-Json -Depth 8),
        (New-Object Text.UTF8Encoding($false))
    )
    Assert-Throws {
        Read-LegacyCompatConnectionIdentity `
            -StableManifestPath $stableManifestPath `
            -ExpectedVersionName '1.0.0' -ExpectedVersionCode 65 `
            -ExpectedApiBaseUrl 'https://appht.jjmxg.xyz/' `
            -LegacyIdentityAnchor $fixtureAnchor
    } 'synchronized Stable and environment replacement'

    function New-FixtureApk([string] $Path, [string] $DexText) {
        $file = [IO.File]::Open($Path, [IO.FileMode]::CreateNew)
        try {
            $archive = New-Object IO.Compression.ZipArchive(
                $file, [IO.Compression.ZipArchiveMode]::Create, $false
            )
            try {
                $entry = $archive.CreateEntry('classes.dex')
                $stream = $entry.Open()
                try {
                    $bytes = [Text.Encoding]::UTF8.GetBytes($DexText)
                    $stream.Write($bytes, 0, $bytes.Length)
                }
                finally { $stream.Dispose() }
            }
            finally { $archive.Dispose() }
        }
        finally { $file.Dispose() }
    }

    $nul = [char] 0
    $good = Join-Path $temporary 'good.apk'
    New-FixtureApk $good ("https://appht.jjmxg.xyz/$nul third-party localhost text http://localhost:9999/$nul http://10.0.2.2:9999/$nul")
    Assert-LegacyCompatDexTransport -ApkPath $good
    foreach ($needle in @(
        'http://appht.jjmxg.xyz/', 'http://appht.jjmxg.xyz',
        'http://127.0.0.1:8788/', 'http://127.0.0.1:8788',
        'http://10.0.2.2:8788/', 'http://10.0.2.2:8788'
    )) {
        $bad = Join-Path $temporary (([Guid]::NewGuid().ToString('N')) + '.apk')
        New-FixtureApk $bad ("https://appht.jjmxg.xyz/$nul$needle$nul")
        Assert-Throws { Assert-LegacyCompatDexTransport -ApkPath $bad } "DEX $needle"
    }
    $longer = Join-Path $temporary 'longer-third-party.apk'
    New-FixtureApk $longer ("https://appht.jjmxg.xyz/$nul http://appht.jjmxg.xyz/documentation$nul localhost$nul")
    Assert-LegacyCompatDexTransport -ApkPath $longer
    $missing = Join-Path $temporary 'missing.apk'
    New-FixtureApk $missing ("no production endpoint$nul")
    Assert-Throws { Assert-LegacyCompatDexTransport -ApkPath $missing } 'missing HTTPS'
}
finally {
    foreach ($name in $connectionEnvironmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousConnectionEnvironment[$name])
    }
    Remove-Item -LiteralPath $temporary -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Output 'PASS: legacy Debug compatibility APK security gates'
