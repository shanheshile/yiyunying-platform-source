function Assert-LegacyCompatBadging {
    param(
        [Parameter(Mandatory = $true)][string] $BadgingText,
        [Parameter(Mandatory = $true)][string] $ExpectedPackage,
        [Parameter(Mandatory = $true)][string] $ExpectedVersionName,
        [Parameter(Mandatory = $true)][int] $ExpectedVersionCode
    )
    $line = [string](($BadgingText -split '\r?\n') |
        Where-Object { $_ -match '^package:' } | Select-Object -First 1)
    $match = [regex]::Match(
        $line,
        "^package:\s+name='(?<Package>[^']+)'\s+versionCode='(?<Code>\d+)'\s+versionName='(?<Name>[^']+)'"
    )
    if (-not $match.Success -or
        $match.Groups['Package'].Value -ne $ExpectedPackage -or
        [int] $match.Groups['Code'].Value -ne $ExpectedVersionCode -or
        $match.Groups['Name'].Value -ne $ExpectedVersionName) {
        throw '兼容 APK 的包名或版本身份不匹配。'
    }
    if ($BadgingText -match '(?m)^application-debuggable\s*$' -or
        $BadgingText -match '(?im)^application-test-only\s*$' -or
        $BadgingText -match "(?i)testOnly\s*=\s*'?true'?") {
        throw '兼容 APK 不得为 debuggable 或 testOnly。'
    }
    return [ordered]@{
        Package = $match.Groups['Package'].Value
        Code = [int] $match.Groups['Code'].Value
        Name = $match.Groups['Name'].Value
    }
}

function Assert-LegacyCompatSignerOutput {
    param(
        [Parameter(Mandatory = $true)][string] $SignerText,
        [Parameter(Mandatory = $true)][string] $ExpectedSignerSha256
    )
    if ($SignerText -notmatch '(?m)^Verified using v2 scheme \(APK Signature Scheme v2\): true\s*$') {
        throw '兼容 APK 必须通过 APK Signature Scheme v2 验证。'
    }
    if ($SignerText -notmatch '(?m)^Number of signers: 1\s*$') {
        throw '兼容 APK 必须恰好包含一个签名者。'
    }
    $matches = [regex]::Matches(
        $SignerText,
        '(?m)^Signer #1 certificate SHA-256 digest:\s*(?<Digest>[0-9A-Fa-f]{64})\s*$'
    )
    $expected = $ExpectedSignerSha256.ToUpperInvariant()
    if ($matches.Count -ne 1 -or
        $matches[0].Groups['Digest'].Value.ToUpperInvariant() -ne $expected) {
        throw '兼容 APK 签名与冻结旧 Debug 签名不一致。'
    }
    return $expected
}

function Get-LegacyCompatUtf8Sha256 {
    param([Parameter(Mandatory = $true)][string] $Value)
    $utf8 = New-Object Text.UTF8Encoding($false, $true)
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha256.ComputeHash($utf8.GetBytes($Value))) -replace '-', '').ToLowerInvariant()
    }
    finally { $sha256.Dispose() }
}

function Read-LegacyUpgradeIdentityAnchor {
    param([Parameter(Mandatory = $true)][string] $Path)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw 'Tracked legacy upgrade identity anchor is missing.'
    }
    $anchor = Get-Content -Raw -LiteralPath $Path -Encoding UTF8 | ConvertFrom-Json
    $roles = @('user', 'admin', 'authorized', 'owner')
    $packageNames = @($anchor.packages.PSObject.Properties.Name)
    $connectionFields = @(
        'appKeySha256',
        'platformKeySha256',
        'authorizedPlatformKeySha256'
    )
    $connectionNames = @($anchor.connectionIdentity.PSObject.Properties.Name)
    $signer = [string] $anchor.legacyPackageSignerSha256
    if ($anchor.schemaVersion -ne 1 -or
        $anchor.legacyUpgradeMaximumVersionCode -ne 60 -or
        $signer -notmatch '^[0-9A-F]{64}$' -or
        $packageNames.Count -ne $roles.Count -or
        @($packageNames | Where-Object { $_ -notin $roles }).Count -ne 0 -or
        $connectionNames.Count -ne $connectionFields.Count -or
        @($connectionNames | Where-Object { $_ -notin $connectionFields }).Count -ne 0) {
        throw 'Tracked legacy upgrade identity anchor is malformed.'
    }
    $packages = [ordered]@{}
    foreach ($role in $roles) {
        $package = [string] $anchor.packages.$role
        if ($package -cne $package.Trim() -or
            $package -notmatch '^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\.debug$') {
            throw "Tracked legacy package identity is invalid: $role"
        }
        $packages[$role] = $package
    }
    $connectionIdentity = [ordered]@{}
    foreach ($field in $connectionFields) {
        $digest = [string] $anchor.connectionIdentity.$field
        if ($digest -notmatch '^[0-9a-f]{64}$') {
            throw "Tracked legacy connection identity is invalid: $field"
        }
        $connectionIdentity[$field] = $digest
    }
    return [pscustomobject]@{
        SchemaVersion = 1
        MaximumVersionCode = 60
        SignerSha256 = $signer
        Packages = $packages
        ConnectionIdentity = $connectionIdentity
    }
}

function Assert-FrozenDebugManifestMatchesAnchor {
    param(
        [Parameter(Mandatory = $true)] $Anchor,
        [Parameter(Mandatory = $true)][string] $ManifestPath
    )
    if (-not (Test-Path -LiteralPath $ManifestPath -PathType Leaf)) {
        throw 'Frozen Debug manifest is missing.'
    }
    $manifest = Get-Content -Raw -LiteralPath $ManifestPath -Encoding UTF8 | ConvertFrom-Json
    $releases = @($manifest.releases)
    if ($manifest.versionCode -ne $Anchor.MaximumVersionCode -or $releases.Count -ne 4) {
        throw 'Frozen Debug manifest does not match the tracked legacy anchor.'
    }
    $seen = @{}
    foreach ($release in $releases) {
        $role = [string] $release.id
        if (-not $Anchor.Packages.Contains($role) -or $seen.ContainsKey($role)) {
            throw 'Frozen Debug manifest has an unknown or duplicate legacy role.'
        }
        $seen[$role] = $true
        if ([string] $release.packageName -cne [string] $Anchor.Packages[$role] -or
            ([string] $release.signerSha256).ToUpperInvariant() -cne $Anchor.SignerSha256) {
            throw "Frozen Debug identity differs from the tracked legacy anchor: $role"
        }
    }
    if ($seen.Count -ne $Anchor.Packages.Count) {
        throw 'Frozen Debug manifest is missing a tracked legacy role.'
    }
    if ($null -ne $manifest.connectionIdentity) {
        foreach ($field in $Anchor.ConnectionIdentity.Keys) {
            if ([string] $manifest.connectionIdentity.$field -cne
                [string] $Anchor.ConnectionIdentity[$field]) {
                throw "Frozen Debug connection identity differs from the tracked legacy anchor: $field"
            }
        }
    }
    return $Anchor.SignerSha256
}

function Read-RequiredLegacyCompatEnvironment {
    param([Parameter(Mandatory = $true)][string] $Name)
    $value = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "Legacy compatibility Build is missing required connection identity: $Name"
    }
    if ($value -cne $value.Trim()) {
        throw "Legacy compatibility connection identity has surrounding whitespace: $Name"
    }
    foreach ($character in $value.ToCharArray()) {
        if ([char]::IsControl($character)) {
            throw "Legacy compatibility connection identity has a control character: $Name"
        }
    }
    $normalized = $value.ToLowerInvariant()
    $knownPlaceholders = @(
        'yiyunying-local', 'local-platform', 'local-authorized-platform',
        'changeme', 'change-me', 'placeholder', 'default', 'example', 'test', 'demo'
    )
    if ($normalized -in $knownPlaceholders -or
        $normalized -match '^(local|test|demo|example|placeholder)([-_].*)?$' -or
        $normalized -match '^your[-_].*') {
        throw "Legacy compatibility connection identity is a placeholder: $Name"
    }
    return $value
}

function Read-LegacyCompatConnectionIdentity {
    param(
        [Parameter(Mandatory = $true)][string] $StableManifestPath,
        [Parameter(Mandatory = $true)][string] $ExpectedVersionName,
        [Parameter(Mandatory = $true)][int] $ExpectedVersionCode,
        [Parameter(Mandatory = $true)][string] $ExpectedApiBaseUrl,
        [Parameter(Mandatory = $true)] $LegacyIdentityAnchor
    )
    if (-not (Test-Path -LiteralPath $StableManifestPath -PathType Leaf)) {
        throw 'Tracked Stable pending manifest is missing.'
    }
    $stable = Get-Content -Raw -LiteralPath $StableManifestPath -Encoding UTF8 | ConvertFrom-Json
    if ($stable.schemaVersion -lt 4 -or
        [string] $stable.channel -cne 'Stable' -or
        [string] $stable.finalizationStatus -cne 'pending' -or
        [string] $stable.versionName -cne $ExpectedVersionName -or
        $stable.versionCode -ne $ExpectedVersionCode -or
        [string] $stable.connectionIdentity.apiBaseUrl -cne $ExpectedApiBaseUrl) {
        throw 'Tracked Stable pending manifest does not match the global compatibility identity.'
    }
    $mapping = [ordered]@{
        YIYUNYING_APP_KEY = 'appKeySha256'
        YIYUNYING_PLATFORM_KEY = 'platformKeySha256'
        YIYUNYING_AUTHORIZED_PLATFORM_KEY = 'authorizedPlatformKeySha256'
    }
    $evidence = [ordered]@{}
    foreach ($entry in $mapping.GetEnumerator()) {
        $value = Read-RequiredLegacyCompatEnvironment -Name $entry.Key
        $digest = Get-LegacyCompatUtf8Sha256 -Value $value
        $expected = [string] $stable.connectionIdentity.($entry.Value)
        $historical = [string] $LegacyIdentityAnchor.ConnectionIdentity[$entry.Value]
        if ($expected -notmatch '^[0-9a-f]{64}$' -or $expected -cne $historical) {
            throw "Stable pending connection identity differs from the tracked historical anchor: $($entry.Value)"
        }
        if ($digest -cne $expected) {
            throw "Legacy compatibility connection identity SHA does not match Stable pending metadata: $($entry.Key)"
        }
        $evidence[$entry.Value] = $digest
    }
    return $evidence
}

function Assert-LegacyCompatNetworkSecurityOutput {
    param([Parameter(Mandatory = $true)][string] $NetworkSecurityText)
    if ($NetworkSecurityText -notmatch '(?m)A:\s+cleartextTrafficPermitted=false(?:\s|$)' -or
        $NetworkSecurityText -match '(?m)A:\s+cleartextTrafficPermitted=true(?:\s|$)') {
        throw '兼容 APK 的编译网络配置必须明确 cleartext=false。'
    }
    $sourceLines = @($NetworkSecurityText -split '\r?\n' |
        Where-Object { $_ -match '^\s*A:\s+src(?:=|\()' })
    if ($sourceLines.Count -eq 0) {
        throw 'Compatibility network security config must enumerate certificate sources.'
    }
    foreach ($sourceLine in $sourceLines) {
        if ($sourceLine -notmatch '^\s*A:\s+src="system"(?:\s|$)') {
            throw 'Compatibility network security certificate sources must all be system.'
        }
    }
}

function Resolve-LegacyCompatNetworkSecurityResource {
    param(
        [Parameter(Mandatory = $true)][string] $ManifestText,
        [Parameter(Mandatory = $true)][string] $ResourcesText
    )
    $referenceMatches = [regex]::Matches(
        $ManifestText,
        'android:networkSecurityConfig[^=]*=@0x(?<Id>[0-9A-Fa-f]{8})'
    )
    if ($referenceMatches.Count -ne 1) {
        throw 'Compiled manifest must contain one networkSecurityConfig resource reference.'
    }
    $resourceId = '0x' + $referenceMatches[0].Groups['Id'].Value.ToLowerInvariant()
    $lines = $ResourcesText -split '\r?\n'
    $resourceIndex = -1
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match ('^\s*resource\s+' + [regex]::Escape($resourceId) + '\s+xml/\S+\s*$')) {
            if ($resourceIndex -ge 0) { throw 'Compiled network security resource id is duplicated.' }
            $resourceIndex = $index
        }
    }
    if ($resourceIndex -lt 0) { throw 'Compiled network security resource id is absent from the resource table.' }
    for ($index = $resourceIndex + 1; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match '^\s*resource\s+0x[0-9A-Fa-f]+\s+') { break }
        $fileMatch = [regex]::Match(
            $lines[$index],
            '^\s*\(\)\s+\(file\)\s+(?<Path>res/[A-Za-z0-9._/-]+\.xml)\s+type=XML\s*$'
        )
        if ($fileMatch.Success) {
            $path = $fileMatch.Groups['Path'].Value
            if ($path.Contains('..') -or $path.Contains('//')) {
                throw 'Compiled network security resource path is unsafe.'
            }
            return $path
        }
    }
    throw 'Compiled network security resource has no default XML file.'
}

function Assert-LegacyCompatDexTransport {
    param([Parameter(Mandatory = $true)][string] $ApkPath)
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($ApkPath)
    $required = 'https://appht.jjmxg.xyz/' + [char] 0
    $forbidden = @(
        'http://appht.jjmxg.xyz/',
        'http://appht.jjmxg.xyz',
        'http://127.0.0.1:8788/',
        'http://127.0.0.1:8788',
        'http://10.0.2.2:8788/',
        'http://10.0.2.2:8788'
    ) | ForEach-Object { $_ + [char] 0 }
    $requiredFound = $false
    try {
        $dexEntries = @($archive.Entries | Where-Object { $_.FullName -match '^classes(?:\d+)?\.dex$' })
        if ($dexEntries.Count -eq 0) { throw '兼容 APK 中没有 DEX。' }
        foreach ($entry in $dexEntries) {
            $stream = $entry.Open()
            $memory = New-Object IO.MemoryStream
            try {
                $stream.CopyTo($memory)
                $text = [Text.Encoding]::GetEncoding(28591).GetString($memory.ToArray())
            }
            finally {
                $stream.Dispose()
                $memory.Dispose()
            }
            if ($text.IndexOf($required, [StringComparison]::Ordinal) -ge 0) {
                $requiredFound = $true
            }
            foreach ($terminatedNeedle in $forbidden) {
                if ($text.IndexOf($terminatedNeedle, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
                    throw ('Compatibility DEX contains a forbidden exact endpoint: ' + $terminatedNeedle.TrimEnd([char] 0))
                }
            }
        }
    }
    finally { $archive.Dispose() }
    if (-not $requiredFound) {
        throw 'Compatibility DEX is missing the exact production HTTPS endpoint.'
    }
}

function Assert-LegacyCompatApk {
    param(
        [Parameter(Mandatory = $true)][string] $Aapt2,
        [Parameter(Mandatory = $true)][string] $ApkSigner,
        [Parameter(Mandatory = $true)][string] $ApkPath,
        [Parameter(Mandatory = $true)][string] $ExpectedPackage,
        [Parameter(Mandatory = $true)][string] $ExpectedVersionName,
        [Parameter(Mandatory = $true)][int] $ExpectedVersionCode,
        [Parameter(Mandatory = $true)][string] $ExpectedSignerSha256
    )
    $badgingOutput = & $Aapt2 dump badging $ApkPath 2>&1
    if ($LASTEXITCODE -ne 0) { throw "无法读取 APK 身份：$ApkPath" }
    $identity = Assert-LegacyCompatBadging `
        -BadgingText ($badgingOutput -join "`n") `
        -ExpectedPackage $ExpectedPackage `
        -ExpectedVersionName $ExpectedVersionName `
        -ExpectedVersionCode $ExpectedVersionCode

    $signerOutput = & $ApkSigner verify --verbose --print-certs $ApkPath 2>&1
    if ($LASTEXITCODE -ne 0) { throw "APK 签名验证失败：$ApkPath" }
    $signer = Assert-LegacyCompatSignerOutput `
        -SignerText ($signerOutput -join "`n") `
        -ExpectedSignerSha256 $ExpectedSignerSha256

    $manifestOutput = & $Aapt2 dump xmltree $ApkPath --file AndroidManifest.xml 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Unable to read compiled manifest: $ApkPath" }
    $resourcesOutput = & $Aapt2 dump resources $ApkPath 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Unable to read compiled resources: $ApkPath" }
    $networkResource = Resolve-LegacyCompatNetworkSecurityResource `
        -ManifestText ($manifestOutput -join "`n") `
        -ResourcesText ($resourcesOutput -join "`n")
    $networkOutput = & $Aapt2 dump xmltree $ApkPath --file $networkResource 2>&1
    if ($LASTEXITCODE -ne 0) { throw "无法读取 APK 编译网络配置：$ApkPath" }
    Assert-LegacyCompatNetworkSecurityOutput -NetworkSecurityText ($networkOutput -join "`n")
    Assert-LegacyCompatDexTransport -ApkPath $ApkPath

    return [pscustomobject]@{
        Identity = $identity
        SignerSha256 = $signer
        NetworkSecurityResource = $networkResource
    }
}
