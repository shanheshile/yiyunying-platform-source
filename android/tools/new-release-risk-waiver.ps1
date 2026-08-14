[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $RiskWaiverConfirmationToken,

    [string] $ReleaseRoot
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$exactConfirmationToken = 'I_ACCEPT_1.0.0_CODE66_RELEASE_WITH_DEVICE_VALIDATION_PENDING'
$confirmationTokenSha256 = 'df6e749945125dc45fddb3cfc433436b349beca063c0eb64a72aa0627e05afe5'
$publicNotice = '真机验证待用户完成（不得声明真机通过）'
$androidRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = (Resolve-Path (Join-Path $androidRoot '..')).Path
$identityPath = Join-Path $workspaceRoot 'backend\config\release-identity.json'
$metadataPath = Join-Path $workspaceRoot 'download-site\release-metadata.json'
$validatorPath = Join-Path $PSScriptRoot 'verify-release-device-gate.ps1'

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

function Invoke-GitText {
    param([Parameter(Mandatory = $true)][string[]] $Arguments, [Parameter(Mandatory = $true)][string] $Operation)

    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git '-C' $workspaceRoot @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) {
        throw "$Operation 失败：$($output -join [Environment]::NewLine)"
    }
    return ($output -join [Environment]::NewLine).Trim()
}

function Write-Utf8JsonAtomic {
    param([Parameter(Mandatory = $true)][string] $Path, [Parameter(Mandatory = $true)] $Value)

    $temporary = "$Path.$([Guid]::NewGuid().ToString('N')).tmp"
    [System.IO.File]::WriteAllText(
        $temporary,
        (($Value | ConvertTo-Json -Depth 12) + [Environment]::NewLine),
        (New-Object System.Text.UTF8Encoding($false))
    )
    try {
        if (Test-Path -LiteralPath $Path) {
            throw "风险豁免文件已存在，拒绝覆盖：$Path"
        }
        Move-Item -LiteralPath $temporary -Destination $Path
    }
    finally {
        Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
    }
}

if ($RiskWaiverConfirmationToken -cne $exactConfirmationToken) {
    throw 'RISK_WAIVER_EXACT_CONFIRMATION_REQUIRED: 必须显式传入本次 1.0.0/code66 精确确认令牌。'
}
foreach ($required in @($identityPath, $metadataPath, $validatorPath)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "生成风险豁免前缺少必需文件：$required"
    }
}

$identity = Get-Content -LiteralPath $identityPath -Raw -Encoding UTF8 | ConvertFrom-Json
$versionName = [string] $identity.version_name
$versionCode = [int] $identity.version_code
$stableSignerSha256 = ([string] $identity.stable_signer_sha256).ToLowerInvariant()
if ($versionName -cne '1.0.0' -or $versionCode -ne 66 -or $stableSignerSha256 -notmatch '^[0-9a-f]{64}$') {
    throw '风险豁免生成器仅允许本次 Stable 1.0.0/code66 且必须有有效生产签名身份。'
}

$resolvedReleaseRoot = if ([string]::IsNullOrWhiteSpace($ReleaseRoot)) {
    (Resolve-Path -LiteralPath (Join-Path $workspaceRoot 'releases')).Path.TrimEnd('\')
}
else {
    (Resolve-Path -LiteralPath $ReleaseRoot).Path.TrimEnd('\')
}
$releaseDirectory = Join-Path $resolvedReleaseRoot $versionName
$resolvedReleaseDirectory = (Resolve-Path -LiteralPath $releaseDirectory).Path.TrimEnd('\')
if (-not ([System.IO.Path]::GetDirectoryName($resolvedReleaseDirectory)).Equals($resolvedReleaseRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw '拒绝在发布根目录直接子目录以外生成豁免文件。'
}
$manifestPath = Join-Path $resolvedReleaseDirectory 'release-manifest.json'
$waiverPath = Join-Path $resolvedReleaseDirectory 'release-risk-waiver.json'
$deviceEvidencePath = Join-Path $resolvedReleaseDirectory 'device-upgrade-evidence.json'
if (Test-Path -LiteralPath $waiverPath) {
    throw '风险豁免文件已存在，拒绝覆盖。'
}
if (Test-Path -LiteralPath $deviceEvidencePath) {
    throw '已存在真机证据，不得再生成风险豁免。'
}
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw '缺少 code66 Build 阶段 release-manifest.json。'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
$pendingManifestSha256 = Get-Sha256 $manifestPath
$buildCommit = ([string] $manifest.buildSourceCommit).ToLowerInvariant()
$expectedTag = 'v1.0.0'
if ([int] $manifest.schemaVersion -ne 4 -or
    [string] $manifest.channel -cne 'Stable' -or
    [string] $manifest.versionName -cne $versionName -or
    [int] $manifest.versionCode -ne $versionCode -or
    [string] $manifest.finalizationStatus -cne 'pending' -or
    -not [string]::IsNullOrWhiteSpace([string] $manifest.releaseEvidenceCommit) -or
    [string] $manifest.releaseTag -cne $expectedTag -or
    [string] $manifest.deviceValidationPlan -cne 'risk-waiver' -or
    $buildCommit -notmatch '^[0-9a-f]{40}([0-9a-f]{24})?$' -or
    $publicNotice -cnotin @($manifest.releaseNotes | ForEach-Object { [string] $_ })) {
    throw 'pending manifest 未固定为本次 code66 Stable 风险豁免计划，或缺少官网待验证提示。'
}

$metadata = Get-Content -LiteralPath $metadataPath -Raw -Encoding UTF8 | ConvertFrom-Json
if ([int] $metadata.schemaVersion -ne 4 -or
    [string] $metadata.channel -cne 'Stable' -or
    [string] $metadata.versionName -cne $versionName -or
    [int] $metadata.versionCode -ne $versionCode -or
    [string] $metadata.buildSourceCommit -cne $buildCommit -or
    [string] $metadata.finalizationStatus -cne 'pending' -or
    [string] $metadata.deviceValidationPlan -cne 'risk-waiver' -or
    ([string] $metadata.pendingManifestSha256).ToLowerInvariant() -cne $pendingManifestSha256 -or
    $publicNotice -cnotin @($metadata.releaseNotes | ForEach-Object { [string] $_ })) {
    throw '下载站 metadata 未精确绑定 pending manifest 或官网待验证提示。'
}

$dirty = Invoke-GitText -Arguments @('status', '--porcelain', '--untracked-files=all') -Operation '读取 Git 状态'
if (-not [string]::IsNullOrWhiteSpace($dirty)) {
    throw '风险豁免只能在完全干净、已提交的证据提交上生成。'
}
$branch = Invoke-GitText -Arguments @('symbolic-ref', '--short', 'HEAD') -Operation '读取 Git 分支'
if ($branch -cne 'main') {
    throw '风险豁免只能在 main 分支生成。'
}
$evidenceCommit = (Invoke-GitText -Arguments @('rev-parse', '--verify', 'HEAD^{commit}') -Operation '读取证据提交').ToLowerInvariant()
$mainCommit = (Invoke-GitText -Arguments @('rev-parse', '--verify', 'refs/heads/main^{commit}') -Operation '读取 main 提交').ToLowerInvariant()
if ($evidenceCommit -cne $mainCommit -or $evidenceCommit -ceq $buildCommit) {
    throw '风险豁免必须绑定与 Build A 不同的 main 证据提交 B。'
}
[void] (Invoke-GitText -Arguments @('merge-base', '--is-ancestor', $buildCommit, $evidenceCommit) -Operation '核验 Build A 为证据 B 的祖先')
$tagType = Invoke-GitText -Arguments @('cat-file', '-t', "refs/tags/$expectedTag") -Operation '读取发布标签类型'
$tagCommit = (Invoke-GitText -Arguments @('rev-list', '-n', '1', "refs/tags/$expectedTag") -Operation '读取发布标签提交').ToLowerInvariant()
if ($tagType -cne 'tag' -or $tagCommit -cne $evidenceCommit) {
    throw '风险豁免必须绑定精确指向证据 B 的注释标签 v1.0.0。'
}

$waiver = [ordered]@{
    schemaVersion = 1
    evidenceType = 'release-risk-waiver'
    versionName = '1.0.0'
    versionCode = 66
    channel = 'Stable'
    createdAt = '2026-08-15'
    decision = 'release-before-device-validation'
    deviceValidationStatus = 'pending-user-validation'
    roles = @('user', 'admin', 'authorized', 'owner')
    unexecutedChecks = @(
        'stable-code62-to-code66-in-place-upgrade-four-roles',
        'legacy-debug-code60-to-code66-compat-upgrade-four-roles',
        'four-role-login',
        'four-role-data-continuity',
        'four-role-core-function-smoke',
        'multi-vendor-device-matrix'
    )
    acknowledgements = @(
        '真机验证尚未执行，不得声明真机通过。',
        '用户接受本次在真机验证完成前发布，并承担后续四角色真机验收。',
        '发现真机问题后必须修复并重新发布，不得用本豁免冒充验收证据。'
    )
    buildSourceCommit = $buildCommit
    releaseEvidenceCommit = $evidenceCommit
    releaseTag = $expectedTag
    pendingManifestSha256 = $pendingManifestSha256
    confirmationTokenSha256 = $confirmationTokenSha256
}

$created = $false
try {
    Write-Utf8JsonAtomic -Path $waiverPath -Value $waiver
    $created = $true
    $validationOutput = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $validatorPath `
        -ReleaseDirectory $resolvedReleaseDirectory `
        -ExpectedVersionName $versionName `
        -ExpectedVersionCode $versionCode `
        -ExpectedChannel Stable `
        -ExpectedBuildSourceCommit $buildCommit `
        -ExpectedReleaseEvidenceCommit $evidenceCommit `
        -ExpectedReleaseTag $expectedTag `
        -ExpectedPendingManifestSha256 $pendingManifestSha256 `
        -ExpectedStableSignerSha256 $stableSignerSha256 `
        -RiskWaiverConfirmationToken $RiskWaiverConfirmationToken
    if ($LASTEXITCODE -ne 0) {
        throw '新生成的风险豁免未通过独立严格验证。'
    }
    $summary = $validationOutput | Select-Object -Last 1 | ConvertFrom-Json
    if ([string] $summary.status -cne 'pending-user-validation') {
        throw '风险豁免验证器返回了非待用户验证状态。'
    }
}
catch {
    if ($created -and (Test-Path -LiteralPath $waiverPath)) {
        Remove-Item -LiteralPath $waiverPath -Force
    }
    throw
}

Write-Host "本次风险豁免已原子生成：$waiverPath"
Write-Host $publicNotice
