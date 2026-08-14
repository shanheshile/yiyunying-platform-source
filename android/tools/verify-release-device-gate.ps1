[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ReleaseDirectory,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ExpectedVersionName,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, [int]::MaxValue)]
    [int] $ExpectedVersionCode,

    [Parameter(Mandatory = $true)]
    [ValidateSet('Stable')]
    [string] $ExpectedChannel,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9A-Fa-f]{40}([0-9A-Fa-f]{24})?$')]
    [string] $ExpectedBuildSourceCommit,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9A-Fa-f]{40}([0-9A-Fa-f]{24})?$')]
    [string] $ExpectedReleaseEvidenceCommit,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ExpectedReleaseTag,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9A-Fa-f]{64}$')]
    [string] $ExpectedPendingManifestSha256,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9A-Fa-f]{64}$')]
    [string] $ExpectedStableSignerSha256,

    [string] $RiskWaiverConfirmationToken
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$riskWaiverToken = 'I_ACCEPT_1.0.0_CODE66_RELEASE_WITH_DEVICE_VALIDATION_PENDING'
$riskWaiverTokenSha256 = 'df6e749945125dc45fddb3cfc433436b349beca063c0eb64a72aa0627e05afe5'
$riskWaiverCreatedAt = '2026-08-15'
$roleOrder = @('user', 'admin', 'authorized', 'owner')
$unexecutedChecks = @(
    'stable-code62-to-code66-in-place-upgrade-four-roles',
    'legacy-debug-code60-to-code66-compat-upgrade-four-roles',
    'four-role-login',
    'four-role-data-continuity',
    'four-role-core-function-smoke',
    'multi-vendor-device-matrix'
)
$acknowledgements = @(
    '真机验证尚未执行，不得声明真机通过。',
    '用户接受本次在真机验证完成前发布，并承担后续四角色真机验收。',
    '发现真机问题后必须修复并重新发布，不得用本豁免冒充验收证据。'
)
$roleIdentity = @{
    user = @{ PackageName = 'xyz.jjmxg.yiyunying.user'; Suffix = 'user' }
    admin = @{ PackageName = 'xyz.jjmxg.yiyunying.admin'; Suffix = 'admin' }
    authorized = @{ PackageName = 'xyz.jjmxg.yiyunying.authorized'; Suffix = 'authorized-platform' }
    owner = @{ PackageName = 'xyz.jjmxg.yiyunying.platformowner'; Suffix = 'platform-owner' }
}

function Assert-ExactProperties {
    param(
        [Parameter(Mandatory = $true)] $Value,
        [Parameter(Mandatory = $true)][string[]] $Expected,
        [Parameter(Mandatory = $true)][string] $Source
    )

    if ($null -eq $Value -or $Value -is [string] -or $Value -is [System.Collections.IList]) {
        throw "$Source 必须是 JSON object。"
    }
    $actual = @($Value.PSObject.Properties | ForEach-Object { $_.Name } | Sort-Object)
    $wanted = @($Expected | Sort-Object)
    if ($actual.Count -ne $wanted.Count -or (Compare-Object $wanted $actual)) {
        throw "$Source 字段必须严格匹配 schema；拒绝缺失或 unknown 字段。"
    }
}

function Assert-ExactStringArray {
    param(
        [Parameter(Mandatory = $true)] $Actual,
        [Parameter(Mandatory = $true)][string[]] $Expected,
        [Parameter(Mandatory = $true)][string] $Source
    )

    $values = @($Actual)
    if ($values.Count -ne $Expected.Count) {
        throw "$Source 数量不一致。"
    }
    for ($index = 0; $index -lt $Expected.Count; $index++) {
        if ($values[$index] -isnot [string] -or [string] $values[$index] -cne $Expected[$index]) {
            throw "$Source 必须按固定顺序精确匹配。"
        }
    }
}

function Assert-Binding {
    param(
        [Parameter(Mandatory = $true)] $Value,
        [Parameter(Mandatory = $true)][string] $Source
    )

    if ([string] $Value.versionName -cne $ExpectedVersionName -or
        $Value.versionCode -isnot [int] -or [int] $Value.versionCode -ne $ExpectedVersionCode -or
        [string] $Value.channel -cne $ExpectedChannel -or
        [string] $Value.buildSourceCommit -cne $ExpectedBuildSourceCommit.ToLowerInvariant() -or
        [string] $Value.releaseEvidenceCommit -cne $ExpectedReleaseEvidenceCommit.ToLowerInvariant() -or
        [string] $Value.releaseTag -cne $ExpectedReleaseTag -or
        [string] $Value.pendingManifestSha256 -cne $ExpectedPendingManifestSha256.ToLowerInvariant()) {
        throw "$Source 未精确绑定当前版本、pending manifest、Build 提交、证据提交或注释标签。"
    }
}

function Read-StrictJsonFile {
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "门禁证据不是普通文件：$Path"
    }
    $item = Get-Item -LiteralPath $Path -Force
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.Length -le 0 -or $item.Length -gt 1MB) {
        throw "门禁证据必须是 1MB 以内的非链接普通文件。"
    }
    try {
        return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        throw "门禁证据不是有效 JSON：$($_.Exception.Message)"
    }
}

function Get-Sha256 {
    param([Parameter(Mandatory = $true)][string] $Path)
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

function Assert-RiskWaiver {
    param([Parameter(Mandatory = $true)] $Value)

    Assert-ExactProperties -Value $Value -Source 'release-risk-waiver.json' -Expected @(
        'schemaVersion', 'evidenceType', 'versionName', 'versionCode', 'channel', 'createdAt',
        'decision', 'deviceValidationStatus', 'roles', 'unexecutedChecks', 'acknowledgements',
        'buildSourceCommit', 'releaseEvidenceCommit', 'releaseTag', 'pendingManifestSha256',
        'confirmationTokenSha256'
    )
    Assert-Binding -Value $Value -Source 'release-risk-waiver.json'
    if ($ExpectedVersionName -cne '1.0.0' -or $ExpectedVersionCode -ne 66 -or $ExpectedChannel -cne 'Stable') {
        throw '真机风险豁免仅适用于本次 Stable 1.0.0/code66。'
    }
    if ($RiskWaiverConfirmationToken -cne $riskWaiverToken) {
        throw "RISK_WAIVER_EXACT_CONFIRMATION_REQUIRED: 必须在 Finalize 显式传入本次精确确认令牌。"
    }
    if ($Value.schemaVersion -isnot [int] -or [int] $Value.schemaVersion -ne 1 -or
        [string] $Value.evidenceType -cne 'release-risk-waiver' -or
        [string] $Value.createdAt -cne $riskWaiverCreatedAt -or
        [string] $Value.decision -cne 'release-before-device-validation' -or
        [string] $Value.deviceValidationStatus -cne 'pending-user-validation' -or
        [string] $Value.confirmationTokenSha256 -cne $riskWaiverTokenSha256) {
        throw '风险豁免类型、日期、决策、状态或确认摘要不符合本次固定 schema。'
    }
    Assert-ExactStringArray -Actual $Value.roles -Expected $roleOrder -Source 'release-risk-waiver.json.roles'
    Assert-ExactStringArray -Actual $Value.unexecutedChecks -Expected $unexecutedChecks -Source 'release-risk-waiver.json.unexecutedChecks'
    Assert-ExactStringArray -Actual $Value.acknowledgements -Expected $acknowledgements -Source 'release-risk-waiver.json.acknowledgements'
}

function Assert-DeviceEvidence {
    param([Parameter(Mandatory = $true)] $Value)

    Assert-ExactProperties -Value $Value -Source 'device-upgrade-evidence.json' -Expected @(
        'schemaVersion', 'evidenceType', 'versionName', 'versionCode', 'channel', 'createdAt',
        'status', 'roles', 'buildSourceCommit', 'releaseEvidenceCommit', 'releaseTag',
        'pendingManifestSha256'
    )
    Assert-Binding -Value $Value -Source 'device-upgrade-evidence.json'
    if ($Value.schemaVersion -isnot [int] -or [int] $Value.schemaVersion -ne 1 -or
        [string] $Value.evidenceType -cne 'android-device-upgrade' -or
        [string] $Value.status -cne 'PASS' -or
        [string] $Value.createdAt -notmatch '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$') {
        throw '真机证据的 schema、类型、状态或 UTC createdAt 无效。'
    }

    $roles = @($Value.roles)
    if ($roles.Count -ne $roleOrder.Count) {
        throw 'device-upgrade-evidence.json 必须包含四角色完整证据。'
    }
    for ($index = 0; $index -lt $roleOrder.Count; $index++) {
        $role = $roles[$index]
        $expectedRole = $roleOrder[$index]
        $identity = $roleIdentity[$expectedRole]
        Assert-ExactProperties -Value $role -Source "device-upgrade-evidence.json.roles[$index]" -Expected @(
            'status', 'gate', 'target', 'role', 'packageName', 'fromVersionCode', 'fromVersionName',
            'toVersionCode', 'versionName', 'signerSha256', 'signatureSchemeV2Verified',
            'uidPreserved', 'dataDirPreserved', 'launchVerifiedBeforeAndAfter'
        )
        if ([string] $role.status -cne 'PASS' -or
            [string] $role.gate -cne 'android-device-upgrade' -or
            [string] $role.target -notmatch '^sha256:[0-9a-f]{12}$' -or
            [string] $role.role -cne $expectedRole -or
            [string] $role.packageName -cne [string] $identity.PackageName -or
            $role.fromVersionCode -isnot [int] -or [int] $role.fromVersionCode -ne 62 -or
            [string] $role.fromVersionName -cne "2.8.0-$($identity.Suffix)" -or
            $role.toVersionCode -isnot [int] -or [int] $role.toVersionCode -ne $ExpectedVersionCode -or
            [string] $role.versionName -cne "$ExpectedVersionName-$($identity.Suffix)" -or
            [string] $role.signerSha256 -cne $ExpectedStableSignerSha256.ToLowerInvariant() -or
            $role.signatureSchemeV2Verified -isnot [bool] -or -not [bool] $role.signatureSchemeV2Verified -or
            $role.uidPreserved -isnot [bool] -or -not [bool] $role.uidPreserved -or
            $role.dataDirPreserved -isnot [bool] -or -not [bool] $role.dataDirPreserved -or
            $role.launchVerifiedBeforeAndAfter -isnot [bool] -or -not [bool] $role.launchVerifiedBeforeAndAfter) {
            throw "真机证据的 $expectedRole 角色未完整通过 code62 到当前 Stable 的原位升级门禁。"
        }
    }
    if (-not [string]::IsNullOrWhiteSpace($RiskWaiverConfirmationToken)) {
        throw '存在完整真机证据时不得传入风险豁免令牌。'
    }
}

$resolvedReleaseDirectory = (Resolve-Path -LiteralPath $ReleaseDirectory).Path
$deviceEvidencePath = Join-Path $resolvedReleaseDirectory 'device-upgrade-evidence.json'
$riskWaiverPath = Join-Path $resolvedReleaseDirectory 'release-risk-waiver.json'
$deviceEvidenceExists = Test-Path -LiteralPath $deviceEvidencePath
$riskWaiverExists = Test-Path -LiteralPath $riskWaiverPath
if ([int] $deviceEvidenceExists + [int] $riskWaiverExists -ne 1) {
    throw 'RELEASE_DEVICE_GATE_EXACTLY_ONE_REQUIRED: Finalize 必须且只能提供 device-upgrade-evidence.json 或 release-risk-waiver.json 之一。'
}

if ($deviceEvidenceExists) {
    $evidencePath = $deviceEvidencePath
    $evidence = Read-StrictJsonFile -Path $evidencePath
    Assert-DeviceEvidence -Value $evidence
    $summary = [ordered]@{
        mode = 'device-evidence'
        status = 'passed'
        evidenceFileName = 'device-upgrade-evidence.json'
        evidenceSha256 = Get-Sha256 -Path $evidencePath
        publicNotice = '真机升级验证已由完整证据通过'
    }
}
else {
    $evidencePath = $riskWaiverPath
    $evidence = Read-StrictJsonFile -Path $evidencePath
    Assert-RiskWaiver -Value $evidence
    $summary = [ordered]@{
        mode = 'risk-waiver'
        status = 'pending-user-validation'
        evidenceFileName = 'release-risk-waiver.json'
        evidenceSha256 = Get-Sha256 -Path $evidencePath
        publicNotice = '真机验证待用户完成（不得声明真机通过）'
    }
}

$summary | ConvertTo-Json -Compress
