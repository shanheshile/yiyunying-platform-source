[CmdletBinding()]
param(
    [string] $ReleaseRoot,
    [string] $ExpectedTag,
    [ValidateSet('Debug', 'Stable')]
    [string] $Channel = 'Debug',
    [string] $RiskWaiverConfirmationToken
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseIdentityFile = Join-Path $projectRoot 'backend\config\release-identity.json'
$downloadMetadataFile = Join-Path $projectRoot 'download-site\release-metadata.json'
$deviceGateScript = Join-Path $projectRoot 'android\tools\verify-release-device-gate.ps1'
$riskWaiverPublicNotice = '真机验证待用户完成（不得声明真机通过）'

function Get-Sha256([string] $Path) {
    $stream = [System.IO.File]::OpenRead($Path)
    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha256.ComputeHash($stream)) -replace '-', '').ToLowerInvariant()
    }
    finally {
        $sha256.Dispose()
        $stream.Dispose()
    }
}

function Assert-DeviceGateArtifact {
    param(
        [Parameter(Mandatory = $true)][string] $Directory,
        [Parameter(Mandatory = $true)] $DeviceValidation
    )

    $path = Join-Path $Directory ([string] $DeviceValidation.evidenceFileName)
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw '真机门禁证据在 Finalize staging 中缺失。'
    }
    $item = Get-Item -LiteralPath $path -Force
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0 -or
        $item.Length -le 0 -or
        (Get-Sha256 $path) -cne [string] $DeviceValidation.evidenceSha256) {
        throw '真机门禁证据在验证后发生替换，拒绝 Finalize。'
    }
}

function Get-ZipEntrySha256 {
    param(
        [Parameter(Mandatory = $true)][string] $ZipPath,
        [Parameter(Mandatory = $true)][string] $EntryName
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $entries = @($archive.Entries | Where-Object { $_.FullName -eq $EntryName })
        if ($entries.Count -ne 1) {
            throw "源码快照必须且只能包含一个发布身份文件：$EntryName"
        }
        $stream = $entries[0].Open()
        $sha256 = [System.Security.Cryptography.SHA256]::Create()
        try {
            return ([BitConverter]::ToString($sha256.ComputeHash($stream)) -replace '-', '').ToLowerInvariant()
        }
        finally {
            $sha256.Dispose()
            $stream.Dispose()
        }
    }
    finally {
        $archive.Dispose()
    }
}

function Assert-ConnectionIdentityEvidence($Value, [string] $Source) {
    if ($null -eq $Value) {
        throw "$Source 缺少 connectionIdentity。"
    }
    $apiBaseUrl = [string] $Value.apiBaseUrl
    $uri = $null
    if (-not [Uri]::TryCreate($apiBaseUrl, [UriKind]::Absolute, [ref] $uri) -or
        $uri.Scheme -notin @('http', 'https') -or
        $uri.IsLoopback -or
        $uri.Host.Trim('[', ']').ToLowerInvariant() -in @('localhost', '0.0.0.0', '::', '::1', '10.0.2.2')) {
        throw "$Source 的 connectionIdentity.apiBaseUrl 不是合法的非本机绝对地址。"
    }
    if ($Channel -eq 'Stable' -and $uri.Scheme -ne 'https') {
        throw "$Source 的 Stable connectionIdentity.apiBaseUrl 必须使用 HTTPS。"
    }
    foreach ($field in @('appKeySha256', 'platformKeySha256', 'authorizedPlatformKeySha256')) {
        if ([string] $Value.$field -notmatch '^[0-9A-Fa-f]{64}$') {
            throw "$Source 的 connectionIdentity.$field 不是有效 SHA-256。"
        }
    }
    foreach ($forbidden in @('appKey', 'platformKey', 'authorizedPlatformKey')) {
        if ($null -ne $Value.PSObject.Properties[$forbidden]) {
            throw "$Source 的 connectionIdentity 不得包含 KEY 明文。"
        }
    }
}

function Test-ConnectionIdentityEvidenceEqual($Left, $Right) {
    if ($null -eq $Left -or $null -eq $Right) { return $false }
    return (
        [string] $Left.apiBaseUrl -ceq [string] $Right.apiBaseUrl -and
        ([string] $Left.appKeySha256).ToLowerInvariant() -ceq ([string] $Right.appKeySha256).ToLowerInvariant() -and
        ([string] $Left.platformKeySha256).ToLowerInvariant() -ceq ([string] $Right.platformKeySha256).ToLowerInvariant() -and
        ([string] $Left.authorizedPlatformKeySha256).ToLowerInvariant() -ceq ([string] $Right.authorizedPlatformKeySha256).ToLowerInvariant()
    )
}

function Write-Utf8JsonAtomic([string] $Path, $Value) {
    $json = $Value | ConvertTo-Json -Depth 20
    $temporary = "$Path.$([Guid]::NewGuid().ToString('N')).tmp"
    [System.IO.File]::WriteAllText(
        $temporary,
        $json + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
    try {
        Move-Item -LiteralPath $temporary -Destination $Path -Force
    }
    finally {
        Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-GitText {
    param([Parameter(Mandatory = $true)][string[]] $Arguments, [string] $Operation)

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git '-C' $projectRoot @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw "$Operation 失败：$($output -join [Environment]::NewLine)"
    }
    return ($output -join [Environment]::NewLine).Trim()
}

function Assert-SafeTransactionPath {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $ResolvedReleaseRoot,
        [Parameter(Mandatory = $true)][string] $Version,
        [Parameter(Mandatory = $true)][string] $Token,
        [Parameter(Mandatory = $true)][ValidateSet('finalizing', 'build-backup')][string] $Kind
    )

    $resolvedPath = [System.IO.Path]::GetFullPath($Path).TrimEnd('\')
    $normalizedReleaseRoot = [System.IO.Path]::GetFullPath($ResolvedReleaseRoot).TrimEnd('\')
    $expected = [System.IO.Path]::GetFullPath((Join-Path $normalizedReleaseRoot ".$Version.$Token.$Kind")).TrimEnd('\')
    if (-not $resolvedPath.Equals($expected, [System.StringComparison]::OrdinalIgnoreCase) -or
        -not ([System.IO.Path]::GetDirectoryName($resolvedPath)).Equals($normalizedReleaseRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "拒绝操作不属于本次 Finalize 事务的路径：$resolvedPath"
    }
}

if (-not (Test-Path -LiteralPath $releaseIdentityFile)) {
    throw "缺少发布身份文件：$releaseIdentityFile"
}
$identityBytes = [System.IO.File]::ReadAllBytes($releaseIdentityFile)
$identity = [System.Text.Encoding]::UTF8.GetString($identityBytes) | ConvertFrom-Json
$version = [string] $identity.version_name
$versionCode = [int] $identity.version_code
if ($version -notmatch '^\d+\.\d+\.\d+$' -or $versionCode -le 0) {
    throw '发布身份中的版本格式无效。'
}
$stableSignerSha256 = ([string] $identity.stable_signer_sha256).ToUpperInvariant()
if ($Channel -eq 'Stable' -and $stableSignerSha256 -notmatch '^[0-9A-F]{64}$') {
    throw 'Stable Finalize 要求 committed release identity 包含有效 stable_signer_sha256。'
}
$tagSuffix = if ($Channel -eq 'Stable') { '' } else { '-debug' }
$expectedTagForChannel = "v$version$tagSuffix"
$identitySha256 = Get-Sha256 $releaseIdentityFile
$resolvedReleaseRoot = if ([string]::IsNullOrWhiteSpace($ReleaseRoot)) {
    $defaultRoot = Join-Path $projectRoot 'releases'
    if (-not (Test-Path -LiteralPath $defaultRoot)) {
        throw "发布根目录不存在：$defaultRoot"
    }
    (Resolve-Path -LiteralPath $defaultRoot).Path.TrimEnd('\')
}
else {
    (Resolve-Path -LiteralPath $ReleaseRoot).Path.TrimEnd('\')
}
$releaseDirectory = Join-Path $resolvedReleaseRoot $version
$releaseManifestPath = Join-Path $releaseDirectory 'release-manifest.json'
if (-not (Test-Path -LiteralPath $releaseManifestPath)) {
    throw "请先运行 release.ps1 -Phase Build：$releaseDirectory"
}

$releaseManifest = Get-Content -LiteralPath $releaseManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
if ([int] $releaseManifest.schemaVersion -ne 4) {
    throw 'Finalize 只接受 schemaVersion=4 的 Build 发布清单。'
}
$buildCommit = ([string] $releaseManifest.buildSourceCommit).ToLowerInvariant()
if ($buildCommit -notmatch '^[0-9A-Fa-f]{40}([0-9A-Fa-f]{24})?$') {
    throw '发布清单缺少有效的 buildSourceCommit。'
}
$manifestChannel = [string] $releaseManifest.channel
if ([string]::IsNullOrWhiteSpace($manifestChannel) -and $Channel -eq 'Debug') {
    $manifestChannel = 'Debug'
}
if ($releaseManifest.versionName -ne $version -or [int] $releaseManifest.versionCode -ne $versionCode -or $manifestChannel -ne $Channel) {
    throw '发布清单与后端发布身份版本不一致。'
}
if (([string] $releaseManifest.releaseIdentitySha256).ToLowerInvariant() -ne $identitySha256) {
    throw '发布清单未绑定到当前后端发布身份文件。'
}
Assert-ConnectionIdentityEvidence -Value $releaseManifest.connectionIdentity -Source 'Build 发布清单'
if ([string] $releaseManifest.finalizationStatus -ne 'pending' -or
    -not [string]::IsNullOrWhiteSpace([string] $releaseManifest.releaseEvidenceCommit) -or
    $null -ne $releaseManifest.PSObject.Properties['deviceValidation']) {
    throw '发布目录不是未收口的 Build 产物；拒绝覆盖或二次生成同版本项目资产。'
}
$deviceValidationPlan = [string] $releaseManifest.deviceValidationPlan
if (($Channel -eq 'Stable' -and $deviceValidationPlan -notin @('device-evidence', 'risk-waiver')) -or
    ($Channel -eq 'Debug' -and $deviceValidationPlan -notin @('', 'not-required-debug'))) {
    throw '发布清单的 deviceValidationPlan 与通道不匹配。'
}

if (-not (Test-Path -LiteralPath $downloadMetadataFile)) {
    throw "缺少由 Build 阶段生成并提交的下载元数据：$downloadMetadataFile"
}
[void] (Invoke-GitText -Arguments @('ls-files', '--error-unmatch', '--', 'download-site/release-metadata.json') -Operation '确认下载元数据已提交')
$downloadMetadata = Get-Content -LiteralPath $downloadMetadataFile -Raw -Encoding UTF8 | ConvertFrom-Json
$metadataChannel = [string] $downloadMetadata.channel
if ([string]::IsNullOrWhiteSpace($metadataChannel) -and $Channel -eq 'Debug') {
    $metadataChannel = 'Debug'
}
$pendingManifestSha256 = Get-Sha256 $releaseManifestPath
if ([int] $downloadMetadata.schemaVersion -ne 4 -or
    [string] $downloadMetadata.versionName -ne $version -or
    [int] $downloadMetadata.versionCode -ne $versionCode -or
    [string] $downloadMetadata.buildSourceCommit -ne $buildCommit -or
    ([string] $downloadMetadata.releaseIdentitySha256).ToLowerInvariant() -ne $identitySha256 -or
    $metadataChannel -ne $Channel -or
    [string] $downloadMetadata.releaseTag -ne $expectedTagForChannel -or
    [string] $downloadMetadata.finalizationStatus -ne 'pending' -or
    -not [string]::IsNullOrWhiteSpace([string] $downloadMetadata.releaseEvidenceCommit) -or
    [string] $downloadMetadata.deviceValidationPlan -cne $deviceValidationPlan -or
    $null -ne $downloadMetadata.PSObject.Properties['deviceValidation'] -or
    -not (Test-ConnectionIdentityEvidenceEqual -Left $downloadMetadata.connectionIdentity -Right $releaseManifest.connectionIdentity) -or
    ([string] $downloadMetadata.pendingManifestSha256).ToLowerInvariant() -ne $pendingManifestSha256) {
    throw 'B 提交中的下载元数据未精确绑定当前 pending 发布清单、Build 提交或发布身份；拒绝 Finalize。'
}

$tag = if ([string]::IsNullOrWhiteSpace($ExpectedTag)) { $expectedTagForChannel } else { $ExpectedTag }
if ($tag -ne $expectedTagForChannel -or [string] $releaseManifest.releaseTag -ne $tag) {
    throw "最终标签与发布通道不匹配：$expectedTagForChannel"
}

$sourceName = "yiyunying-source-v$version.zip"
$historyName = "yiyunying-git-history-v$version.bundle"
$deliveryName = "yiyunying-project-delivery-v$version.zip"
$assetsManifestName = 'project-assets-manifest.json'
$expectedProjectAssets = [ordered]@{
    source = $sourceName
    history = $historyName
    delivery = $deliveryName
    manifest = $assetsManifestName
}
$descriptors = @($releaseManifest.projectAssets)
if ($descriptors.Count -ne $expectedProjectAssets.Count -or
    @($descriptors | ForEach-Object { [string] $_.id } | Sort-Object -Unique).Count -ne $expectedProjectAssets.Count) {
    throw '发布清单必须且只能声明四个唯一项目资产。'
}
foreach ($descriptor in $descriptors) {
    $id = [string] $descriptor.id
    if (-not $expectedProjectAssets.Contains($id) -or [string] $descriptor.fileName -ne [string] $expectedProjectAssets[$id]) {
        throw "发布清单包含未知或错名项目资产：$id / $($descriptor.fileName)"
    }
}

$releases = @($releaseManifest.releases)
$requiredReleaseIds = @('user', 'admin', 'authorized', 'owner')
$actualReleaseIds = @($releases | ForEach-Object { [string] $_.id } | Sort-Object)
if ($releases.Count -ne 4 -or (Compare-Object ($requiredReleaseIds | Sort-Object) $actualReleaseIds)) {
    throw 'Build 发布清单必须且只能包含四个 Android 版本。'
}
$releaseSigners = @($releases | ForEach-Object { ([string] $_.signerSha256).ToUpperInvariant() } | Sort-Object -Unique)
if ($Channel -eq 'Stable' -and ($releaseSigners.Count -ne 1 -or $releaseSigners[0] -cne $stableSignerSha256)) {
    throw 'Stable 四端 APK 必须统一使用 committed release identity 声明的生产签名。'
}
$expectedBuildFiles = @('release-manifest.json', 'SHA256SUMS.txt')
foreach ($entry in $releases) {
    $fileName = [string] $entry.fileName
    if ([System.IO.Path]::GetFileName($fileName) -ne $fileName -or $fileName -notmatch '\.apk$') {
        throw "APK 文件名不安全：$fileName"
    }
    $expectedBuildFiles += $fileName
    $apkPath = Join-Path $releaseDirectory $fileName
    if (-not (Test-Path -LiteralPath $apkPath) -or
        (Get-Item -LiteralPath $apkPath).Length -ne [long] $entry.sizeBytes -or
        (Get-Sha256 $apkPath) -ne ([string] $entry.sha256).ToLowerInvariant()) {
        throw "Build APK 体积或 SHA-256 不一致：$fileName"
    }
}
$dirty = Invoke-GitText -Arguments @('status', '--porcelain', '--untracked-files=all') -Operation '读取 Git 工作区状态'
if (-not [string]::IsNullOrWhiteSpace($dirty)) {
    throw 'Finalize 必须从完全干净且已提交的 main 证据提交执行（包括未跟踪文件）。'
}
$branch = Invoke-GitText -Arguments @('symbolic-ref', '--short', 'HEAD') -Operation '读取 Git 分支'
if ($branch -ne 'main') {
    throw "Finalize 只允许从 main 分支执行，当前分支：$branch"
}
$evidenceCommit = (Invoke-GitText -Arguments @('rev-parse', '--verify', 'HEAD^{commit}') -Operation '读取发布证据提交').ToLowerInvariant()
$mainCommit = (Invoke-GitText -Arguments @('rev-parse', '--verify', 'refs/heads/main^{commit}') -Operation '读取 main 提交').ToLowerInvariant()
if ($mainCommit -ne $evidenceCommit) {
    throw 'main 分支未精确指向当前发布证据提交。'
}
if ($evidenceCommit -eq $buildCommit) {
    throw 'Finalize 必须在 Build 源码提交之后形成独立的元数据/证据提交。'
}
[void] (Invoke-GitText -Arguments @('cat-file', '-e', "$buildCommit^{commit}") -Operation '确认 Build 源码提交存在')
[void] (Invoke-GitText -Arguments @('merge-base', '--is-ancestor', $buildCommit, $evidenceCommit) -Operation '确认 Build 提交是证据提交祖先')
$tagType = Invoke-GitText -Arguments @('cat-file', '-t', "refs/tags/$tag") -Operation "读取最终标签类型 $tag"
if ($tagType -ne 'tag') {
    throw "最终标签必须是注释标签，不能是轻量标签：$tag"
}
$tagCommit = (Invoke-GitText -Arguments @('rev-list', '-n', '1', "refs/tags/$tag") -Operation "读取最终标签提交 $tag").ToLowerInvariant()
if ($tagCommit -ne $evidenceCommit) {
    throw "最终标签 $tag 必须精确指向发布证据提交 $evidenceCommit"
}
[void] (Invoke-GitText -Arguments @('ls-files', '--error-unmatch', '--', 'backend/config/release-identity.json') -Operation '确认发布身份已提交')

$deviceValidation = $null
if ($Channel -eq 'Stable') {
    if (-not (Test-Path -LiteralPath $deviceGateScript -PathType Leaf)) {
        throw "缺少 Stable Finalize 真机门禁验证器：$deviceGateScript"
    }
    $gateArguments = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $deviceGateScript,
        '-ReleaseDirectory', $releaseDirectory,
        '-ExpectedVersionName', $version,
        '-ExpectedVersionCode', [string] $versionCode,
        '-ExpectedChannel', 'Stable',
        '-ExpectedBuildSourceCommit', $buildCommit,
        '-ExpectedReleaseEvidenceCommit', $evidenceCommit,
        '-ExpectedReleaseTag', $tag,
        '-ExpectedPendingManifestSha256', $pendingManifestSha256,
        '-ExpectedStableSignerSha256', $stableSignerSha256
    )
    if (-not [string]::IsNullOrWhiteSpace($RiskWaiverConfirmationToken)) {
        $gateArguments += @('-RiskWaiverConfirmationToken', $RiskWaiverConfirmationToken)
    }
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $gateOutput = @(& powershell.exe @gateArguments 2>&1)
        $gateExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($gateExitCode -ne 0) {
        throw "Stable Finalize 真机证据/风险豁免门禁失败：$($gateOutput -join [Environment]::NewLine)"
    }
    try {
        $gateSummary = $gateOutput | Select-Object -Last 1 | ConvertFrom-Json
    }
    catch {
        throw 'Stable Finalize 真机门禁未返回有效结构化摘要。'
    }
    if ([string] $gateSummary.evidenceSha256 -notmatch '^[0-9a-f]{64}$') {
        throw 'Stable Finalize 真机门禁证据摘要无效。'
    }
    if ($deviceValidationPlan -eq 'risk-waiver') {
        if ([string] $gateSummary.mode -cne 'risk-waiver' -or
            [string] $gateSummary.status -cne 'pending-user-validation' -or
            [string] $gateSummary.evidenceFileName -cne 'release-risk-waiver.json' -or
            [string] $gateSummary.publicNotice -cne $riskWaiverPublicNotice -or
            $riskWaiverPublicNotice -cnotin @($releaseManifest.releaseNotes | ForEach-Object { [string] $_ })) {
            throw '风险豁免计划不得被写成真机通过，且必须在官网发布说明显示待用户完成。'
        }
    }
    elseif ([string] $gateSummary.mode -cne 'device-evidence' -or
        [string] $gateSummary.status -cne 'passed' -or
        [string] $gateSummary.evidenceFileName -cne 'device-upgrade-evidence.json' -or
        -not [string]::IsNullOrWhiteSpace($RiskWaiverConfirmationToken)) {
        throw '真机证据计划必须提供四角色完整真机证据，且不得使用豁免令牌。'
    }
    $expectedBuildFiles += [string] $gateSummary.evidenceFileName
    $deviceValidation = [ordered]@{
        plan = $deviceValidationPlan
        status = [string] $gateSummary.status
        evidenceFileName = [string] $gateSummary.evidenceFileName
        evidenceSha256 = [string] $gateSummary.evidenceSha256
        publicNotice = [string] $gateSummary.publicNotice
    }
}
elseif (-not [string]::IsNullOrWhiteSpace($RiskWaiverConfirmationToken)) {
    throw 'Debug Finalize 不得使用 Stable 真机风险豁免令牌。'
}

$actualBuildFiles = @(Get-ChildItem -LiteralPath $releaseDirectory -Force -File | ForEach-Object { $_.Name } | Sort-Object)
$actualBuildDirectories = @(Get-ChildItem -LiteralPath $releaseDirectory -Force -Directory)
if ($actualBuildDirectories.Count -ne 0 -or (Compare-Object ($expectedBuildFiles | Sort-Object) $actualBuildFiles)) {
    throw 'Build 发布目录包含缺失、额外或未经严格验证的门禁/项目资产；拒绝 Finalize。'
}

$token = [Guid]::NewGuid().ToString('N')
$stagingDirectory = Join-Path $resolvedReleaseRoot ".$version.$token.finalizing"
$backupDirectory = Join-Path $resolvedReleaseRoot ".$version.$token.build-backup"
Assert-SafeTransactionPath -Path $stagingDirectory -ResolvedReleaseRoot $resolvedReleaseRoot -Version $version -Token $token -Kind 'finalizing'
Assert-SafeTransactionPath -Path $backupDirectory -ResolvedReleaseRoot $resolvedReleaseRoot -Version $version -Token $token -Kind 'build-backup'
if (Test-Path -LiteralPath $stagingDirectory) { throw "Finalize staging 已存在：$stagingDirectory" }
if (Test-Path -LiteralPath $backupDirectory) { throw "Finalize backup 已存在：$backupDirectory" }

$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("yiyunying-finalize-$token")
$swapped = $false
try {
    New-Item -ItemType Directory -Path $stagingDirectory | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $releaseDirectory -Force) {
        Copy-Item -LiteralPath $item.FullName -Destination $stagingDirectory -Recurse
    }

    $finalManifest = Get-Content -LiteralPath (Join-Path $stagingDirectory 'release-manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $finalManifest.releaseEvidenceCommit = $evidenceCommit
    $finalManifest.releaseTag = $tag
    $finalManifest.finalizationStatus = 'finalized'
    if ($Channel -eq 'Stable') {
        $finalManifest | Add-Member -NotePropertyName deviceValidation -NotePropertyValue $deviceValidation -Force
    }
    $finalManifest | Add-Member -NotePropertyName finalizedAt -NotePropertyValue ([DateTimeOffset]::Now.ToString('o')) -Force
    $stagedManifestPath = Join-Path $stagingDirectory 'release-manifest.json'
    Write-Utf8JsonAtomic -Path $stagedManifestPath -Value $finalManifest

    $sourcePath = Join-Path $stagingDirectory $sourceName
    $historyPath = Join-Path $stagingDirectory $historyName
    $deliveryPath = Join-Path $stagingDirectory $deliveryName
    & git '-c' 'core.autocrlf=false' '-C' $projectRoot 'archive' '--format=zip' "--output=$sourcePath" $buildCommit
    if ($LASTEXITCODE -ne 0) { throw '从精确 Build 提交生成源码快照失败。' }
    $sourceIdentitySha256 = Get-ZipEntrySha256 -ZipPath $sourcePath -EntryName 'backend/config/release-identity.json'
    if ($sourceIdentitySha256 -ne ([string] $releaseManifest.releaseIdentitySha256).ToLowerInvariant()) {
        throw 'Build 源码 A 快照中的发布身份原始字节 SHA-256 与发布清单不一致；拒绝 Finalize。'
    }
    & git '-C' $projectRoot 'bundle' 'create' $historyPath 'refs/heads/main' "refs/tags/$tag"
    if ($LASTEXITCODE -ne 0) { throw '生成仅含最终 main 与注释发布标签的 Git Bundle 失败。' }
    [void] (Invoke-GitText -Arguments @('bundle', 'verify', $historyPath) -Operation '验证 Git Bundle')
    $bundleHeads = @((Invoke-GitText -Arguments @('bundle', 'list-heads', $historyPath) -Operation '检查 Git Bundle 引用') -split "`r?`n")
    $bundleRefs = @($bundleHeads | Where-Object { $_ } | ForEach-Object { ($_ -split '\s+', 2)[1] })
    if ($bundleRefs.Count -ne 2 -or 'refs/heads/main' -notin $bundleRefs -or "refs/tags/$tag" -notin $bundleRefs) {
        throw "Git Bundle 必须且只能公开 refs/heads/main 与 refs/tags/$tag。"
    }

    New-Item -ItemType Directory -Path $temporary | Out-Null
    Copy-Item -LiteralPath $sourcePath -Destination (Join-Path $temporary $sourceName)
    Copy-Item -LiteralPath $historyPath -Destination (Join-Path $temporary $historyName)
    Copy-Item -LiteralPath $stagedManifestPath -Destination $temporary
    Copy-Item -LiteralPath (Join-Path $stagingDirectory 'SHA256SUMS.txt') -Destination $temporary

    $evidenceZip = Join-Path $temporary 'evidence-commit.zip'
    $evidenceSource = Join-Path $temporary 'evidence-source'
    & git '-c' 'core.autocrlf=false' '-C' $projectRoot 'archive' '--format=zip' "--output=$evidenceZip" $evidenceCommit
    if ($LASTEXITCODE -ne 0) { throw '从精确证据提交生成交接文档快照失败。' }
    Expand-Archive -LiteralPath $evidenceZip -DestinationPath $evidenceSource
    $handoffDirectory = Join-Path $temporary 'handoff'
    New-Item -ItemType Directory -Path $handoffDirectory | Out-Null
    foreach ($relative in @(
        'HANDOFF.md',
        'README.md',
        'CHANGELOG.md',
        'docs\CURRENT_STATUS.md',
        'docs\PROJECT_INDEX.md',
        'docs\MASTER_REQUIREMENTS_AND_IMPLEMENTATION_PLAN.md',
        'docs\NEW_TASK_HANDOFF.md',
        'docs\project-handoff.json',
        "docs\releases\$version.md"
    )) {
        $candidate = Join-Path $evidenceSource $relative
        if (Test-Path -LiteralPath $candidate) {
            $safeName = $relative -replace '[\\/]', '__'
            Copy-Item -LiteralPath $candidate -Destination (Join-Path $handoffDirectory $safeName)
        }
    }
    Remove-Item -LiteralPath $evidenceZip -Force
    Remove-Item -LiteralPath $evidenceSource -Recurse -Force
    Compress-Archive -Path (Join-Path $temporary '*') -DestinationPath $deliveryPath -CompressionLevel Optimal

    $assets = @()
    foreach ($name in @($sourceName, $historyName, $deliveryName)) {
        $path = Join-Path $stagingDirectory $name
        if (-not (Test-Path -LiteralPath $path) -or (Get-Item -LiteralPath $path).Length -le 0) {
            throw "项目资产生成后缺失或为空：$name"
        }
        $file = Get-Item -LiteralPath $path
        $assets += [ordered]@{
            fileName = $name
            sizeBytes = $file.Length
            sha256 = Get-Sha256 $path
        }
    }

    $assetsManifest = [ordered]@{
        schemaVersion = 3
        channel = $Channel
        versionName = $version
        versionCode = $versionCode
        buildSourceCommit = $buildCommit
        releaseEvidenceCommit = $evidenceCommit
        releaseTag = $tag
        releaseIdentitySha256 = $identitySha256
        connectionIdentity = $releaseManifest.connectionIdentity
        releaseManifestSha256 = Get-Sha256 $stagedManifestPath
        generatedAt = [DateTimeOffset]::Now.ToString('o')
        bundleRefs = @('refs/heads/main', "refs/tags/$tag")
        security = [ordered]@{
            containsCredentials = $false
            containsSigningKeys = $false
            containsProductionData = $false
        }
        assets = $assets
    }
    if ($Channel -eq 'Stable') {
        $assetsManifest['deviceValidation'] = $deviceValidation
    }
    Write-Utf8JsonAtomic -Path (Join-Path $stagingDirectory $assetsManifestName) -Value $assetsManifest

    foreach ($name in @($sourceName, $historyName, $deliveryName, $assetsManifestName)) {
        $path = Join-Path $stagingDirectory $name
        if (-not (Test-Path -LiteralPath $path) -or (Get-Item -LiteralPath $path).Length -le 0) {
            throw "Finalize 项目资产闭环不完整：$name"
        }
    }
    foreach ($entry in $releases) {
        $fileName = [string] $entry.fileName
        $original = Join-Path $releaseDirectory $fileName
        $staged = Join-Path $stagingDirectory $fileName
        if ((Get-Item -LiteralPath $original).Length -ne (Get-Item -LiteralPath $staged).Length -or
            (Get-Sha256 $original) -ne (Get-Sha256 $staged)) {
            throw "Finalize staging 改变了 Build APK：$fileName"
        }
    }
    if ($Channel -eq 'Stable') {
        Assert-DeviceGateArtifact -Directory $stagingDirectory -DeviceValidation $deviceValidation
    }

    Move-Item -LiteralPath $releaseDirectory -Destination $backupDirectory
    try {
        Move-Item -LiteralPath $stagingDirectory -Destination $releaseDirectory
        $swapped = $true
    }
    catch {
        Move-Item -LiteralPath $backupDirectory -Destination $releaseDirectory
        throw
    }
    Assert-SafeTransactionPath -Path $backupDirectory -ResolvedReleaseRoot $resolvedReleaseRoot -Version $version -Token $token -Kind 'build-backup'
    Remove-Item -LiteralPath $backupDirectory -Recurse -Force
}
catch {
    if (-not $swapped -and (Test-Path -LiteralPath $backupDirectory) -and -not (Test-Path -LiteralPath $releaseDirectory)) {
        Move-Item -LiteralPath $backupDirectory -Destination $releaseDirectory
    }
    if (Test-Path -LiteralPath $stagingDirectory) {
        Assert-SafeTransactionPath -Path $stagingDirectory -ResolvedReleaseRoot $resolvedReleaseRoot -Version $version -Token $token -Kind 'finalizing'
        Remove-Item -LiteralPath $stagingDirectory -Recurse -Force
    }
    throw
}
finally {
    if (Test-Path -LiteralPath $temporary) {
        $resolvedTemporary = [System.IO.Path]::GetFullPath($temporary).TrimEnd('\')
        $expectedTemporary = Join-Path ([System.IO.Path]::GetTempPath().TrimEnd('\')) "yiyunying-finalize-$token"
        if (-not $resolvedTemporary.Equals($expectedTemporary, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "拒绝清理不属于本次 Finalize 事务的临时目录：$resolvedTemporary"
        }
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}

Write-Host "完整项目产物已一次性收口：$releaseDirectory"
Write-Host "版本：$version ($versionCode)"
Write-Host "Build 源码提交：$buildCommit"
Write-Host "发布证据提交：$evidenceCommit"
Write-Host "最终注释标签：$tag"
Write-Host "Git Bundle 引用：refs/heads/main, refs/tags/$tag"
if ($Channel -eq 'Stable') {
    Write-Host "真机门禁状态：$($deviceValidation.status)"
}
