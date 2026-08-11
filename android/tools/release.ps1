[CmdletBinding()]
param(
    [ValidateSet('Build', 'Finalize')]
    [string] $Phase = 'Build',
    [ValidateSet('none', 'patch', 'minor', 'major', 'build')]
    [string] $Bump = 'none',
    [string] $JavaHome = $env:JAVA_HOME,
    [string] $DownloadRootBase = '/downloads',
    [switch] $SkipVerification,
    [switch] $SkipDownloadMetadata,
    [switch] $DryRun,
    [string] $ExpectedSignerSha256,
    [string[]] $ReleaseNotes
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = (Resolve-Path (Join-Path $projectRoot '..')).Path
$downloadSiteRoot = Join-Path $workspaceRoot 'download-site'
$metadataFile = Join-Path $downloadSiteRoot 'release-metadata.json'
$versionScript = Join-Path $PSScriptRoot 'version.ps1'
$verifyScript = Join-Path $PSScriptRoot 'verify.ps1'
$packageScript = Join-Path $workspaceRoot 'scripts\package-project.ps1'
$releaseIdentityFile = Join-Path $workspaceRoot 'backend\config\release-identity.json'
$releaseRoot = Join-Path $workspaceRoot 'releases'

function Invoke-VersionCommand {
    param(
        [Parameter(Mandatory = $true)][string] $Action,
        [switch] $Preview,
        [string] $Name,
        [int] $Code,
        [switch] $Force
    )

    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $versionScript, '-Action', $Action, '-Json')
    if ($Preview) { $arguments += '-DryRun' }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        $arguments += @('-VersionName', $Name, '-VersionCode', [string] $Code)
    }
    if ($Force) { $arguments += '-Force' }

    $raw = & powershell.exe @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "版本命令执行失败：$Action"
    }
    return ($raw | Select-Object -Last 1 | ConvertFrom-Json)
}

function Format-FileSize([long] $Bytes) {
    return ('{0:N2} MB' -f ($Bytes / 1MB))
}

function Write-Utf8JsonAtomic {
    param([string] $Path, $Value)

    $parent = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }

    $json = $Value | ConvertTo-Json -Depth 20
    $temporaryFile = "$Path.$([Guid]::NewGuid().ToString('N')).tmp"
    [System.IO.File]::WriteAllText(
        $temporaryFile,
        $json + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
    try {
        Move-Item -LiteralPath $temporaryFile -Destination $Path -Force
    }
    finally {
        Remove-Item -LiteralPath $temporaryFile -Force -ErrorAction SilentlyContinue
    }
}

function Assert-DownloadRoot([string] $Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) {
        throw '下载根地址不能为空。'
    }
    if ($Value -eq '/downloads') {
        return
    }

    $uri = $null
    if (-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $uri)) {
        throw "下载根地址必须使用 /downloads 或合法的生产地址：$Value"
    }
    if ($uri.Scheme -notin @('http', 'https')) {
        throw "下载根地址协议不受支持：$($uri.Scheme)"
    }
    if ($uri.IsLoopback -or $uri.Host -in @('localhost', '127.0.0.1', '0.0.0.0')) {
        throw "下载根地址不能指向本机：$Value"
    }
}

function Get-AndroidSdkRoots {
    $roots = New-Object System.Collections.Generic.List[string]
    foreach ($candidate in @($env:ANDROID_SDK_ROOT, $env:ANDROID_HOME, 'D:\AndroidToolchain\sdk')) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path -LiteralPath $candidate)) {
            $resolved = (Resolve-Path -LiteralPath $candidate).Path
            if (-not $roots.Contains($resolved)) { $roots.Add($resolved) }
        }
    }

    $localProperties = Join-Path $projectRoot 'local.properties'
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

        $versions = Get-ChildItem -LiteralPath $buildTools -Directory |
            Sort-Object {
                try { [Version] $_.Name }
                catch { [Version] '0.0' }
            } -Descending
        foreach ($version in $versions) {
            $candidate = Join-Path $version.FullName $FileName
            if (Test-Path -LiteralPath $candidate) {
                return $candidate
            }
        }
    }
    throw "未找到 Android 构建工具：$FileName"
}

function ConvertFrom-AaptPackageLine {
    param(
        [string] $PackageLine,
        [string] $Source = 'APK'
    )

    $pattern = "^package:\s+name='(?<PackageName>[^']+)'\s+versionCode='(?<VersionCode>\d+)'\s+versionName='(?<VersionName>[^']+)'(?:\s|$)"
    $match = [regex]::Match(
        $PackageLine,
        $pattern,
        [System.Text.RegularExpressions.RegexOptions]::CultureInvariant
    )
    if (-not $match.Success) {
        throw "APK 元数据格式无法识别：$Source"
    }

    return [ordered]@{
        packageName = $match.Groups['PackageName'].Value
        versionCode = [int] $match.Groups['VersionCode'].Value
        versionName = $match.Groups['VersionName'].Value
    }
}

function Assert-ApkIdentityParser {
    $fixture = "package: name='xyz.jjmxg.yiyunying.user.debug' versionCode='123' versionName='9.8.7-user-debug' platformBuildVersionName='16' platformBuildVersionCode='36' compileSdkVersion='36' compileSdkVersionCodename='16'"
    $identity = ConvertFrom-AaptPackageLine -PackageLine $fixture -Source '内置回归样例'
    if ($identity.packageName -ne 'xyz.jjmxg.yiyunying.user.debug' -or
        $identity.versionCode -ne 123 -or
        $identity.versionName -ne '9.8.7-user-debug') {
        throw 'APK 元数据解析回归检查失败。'
    }
}

function Read-ApkIdentity {
    param(
        [string] $AaptPath,
        [string] $ApkPath
    )

    $badging = & $AaptPath dump badging $ApkPath 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "无法读取 APK 元数据：$ApkPath"
    }
    $packageLine = [string]($badging | Where-Object { $_ -match '^package:' } | Select-Object -First 1)
    return ConvertFrom-AaptPackageLine -PackageLine $packageLine -Source $ApkPath
}

function Assert-Apk {
    param(
        [string] $Path,
        [string] $ExpectedPackage,
        [string] $ExpectedVersionName,
        [int] $ExpectedVersionCode,
        [string] $AaptPath,
        [string] $ApkSignerPath
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "构建结束但未找到 APK：$Path"
    }
    $file = Get-Item -LiteralPath $Path
    if ($file.Length -lt 1MB) {
        throw "APK 体积异常，拒绝发布：$Path"
    }

    $identity = Read-ApkIdentity -AaptPath $AaptPath -ApkPath $Path
    if ($identity.packageName -ne $ExpectedPackage) {
        throw "APK 包名不一致：期望 $ExpectedPackage，实际 $($identity.packageName)"
    }
    if ($identity.versionName -ne $ExpectedVersionName) {
        throw "APK 版本名不一致：期望 $ExpectedVersionName，实际 $($identity.versionName)"
    }
    if ($identity.versionCode -ne $ExpectedVersionCode) {
        throw "APK 版本号不一致：期望 $ExpectedVersionCode，实际 $($identity.versionCode)"
    }

    $signerOutput = @(& $ApkSignerPath verify --verbose --print-certs $Path 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "APK 签名校验失败：$Path"
    }
    $signerDigests = @($signerOutput | ForEach-Object {
        $line = [string] $_
        if ($line -match '^Signer #(?<SignerNumber>\d+) certificate SHA-256 digest:\s*(?<Digest>[0-9A-Fa-f]{64})\s*$') {
            [pscustomobject]@{
                SignerNumber = [int] $Matches['SignerNumber']
                Digest = $Matches['Digest'].ToUpperInvariant()
            }
        }
    })
    if ($signerDigests.Count -ne 1 -or $signerDigests[0].SignerNumber -ne 1) {
        throw "APK 必须恰好包含一个签名者：$Path"
    }
    if (-not [string]::IsNullOrWhiteSpace($ExpectedSignerSha256) -and
        $signerDigests[0].Digest -ne $ExpectedSignerSha256.ToUpperInvariant()) {
        throw "APK 签名证书不一致：期望 $($ExpectedSignerSha256.ToUpperInvariant())，实际 $($signerDigests[0].Digest)"
    }
    $identity['signerSha256'] = $signerDigests[0].Digest
    return $identity
}

function Assert-ReleaseNotes([string[]] $Notes) {
    if (-not $Notes -or $Notes.Count -eq 0) {
        throw '发布说明不能为空。'
    }
    foreach ($note in $Notes) {
        if ([string]::IsNullOrWhiteSpace($note) -or $note.Contains([char]0xFFFD)) {
            throw '发布说明包含空项或损坏字符。'
        }
    }
}

function Invoke-GitText {
    param([Parameter(Mandatory = $true)][string[]] $Arguments, [string] $Operation)

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git '-C' $workspaceRoot @Arguments 2>&1)
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

function Read-GitBlobBytes {
    param(
        [Parameter(Mandatory = $true)][string] $ObjectId,
        [Parameter(Mandatory = $true)][string] $Operation
    )

    if ($ObjectId -notmatch '^[0-9A-Fa-f]{40}([0-9A-Fa-f]{24})?$') {
        throw "$Operation 失败：Git blob 标识格式无效。"
    }
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = 'git'
    $startInfo.Arguments = "-C `"$workspaceRoot`" cat-file blob $ObjectId"
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    $memory = New-Object System.IO.MemoryStream
    try {
        if (-not $process.Start()) {
            throw "$Operation 失败：无法启动 Git。"
        }
        $standardError = $process.StandardError.ReadToEndAsync()
        $process.StandardOutput.BaseStream.CopyTo($memory)
        $process.WaitForExit()
        $errorText = $standardError.Result
        if ($process.ExitCode -ne 0) {
            throw "$Operation 失败：$errorText"
        }
        $bytes = $memory.ToArray()
        Write-Output -NoEnumerate $bytes
    }
    finally {
        $memory.Dispose()
        $process.Dispose()
    }
}

function Get-ByteArraySha256 {
    param([Parameter(Mandatory = $true)][byte[]] $Bytes)

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha256.ComputeHash($Bytes)) -replace '-', '').ToLowerInvariant()
    }
    finally {
        $sha256.Dispose()
    }
}

function Read-CommittedReleaseEvidence($Version) {
    if (-not (Test-Path -LiteralPath $releaseIdentityFile)) {
        throw "缺少后端发布身份文件：$releaseIdentityFile"
    }
    $dirty = Invoke-GitText -Arguments @('status', '--porcelain', '--untracked-files=all') -Operation '读取 Git 工作区状态'
    if (-not [string]::IsNullOrWhiteSpace($dirty)) {
        throw '发布构建必须从完全干净且已提交的源码生成（包括未跟踪文件）；请先提交或清理工作区。'
    }
    $branch = Invoke-GitText -Arguments @('symbolic-ref', '--short', 'HEAD') -Operation '读取 Git 分支'
    if ($branch -ne 'main') {
        throw "正式发布只允许从 main 分支生成，当前分支：$branch"
    }
    $commit = Invoke-GitText -Arguments @('rev-parse', '--verify', 'HEAD^{commit}') -Operation '读取构建源码提交'
    if ($commit -notmatch '^[0-9A-Fa-f]{40}([0-9A-Fa-f]{24})?$') {
        throw "构建源码提交格式异常：$commit"
    }
    [void] (Invoke-GitText -Arguments @('ls-files', '--error-unmatch', '--', 'backend/config/release-identity.json') -Operation '确认发布身份已提交')

    $identityBlob = Invoke-GitText -Arguments @('rev-parse', '--verify', 'HEAD:backend/config/release-identity.json') -Operation '读取已提交发布身份 blob'
    [byte[]] $committedIdentityBytes = Read-GitBlobBytes -ObjectId $identityBlob -Operation '读取已提交发布身份原始字节'
    [byte[]] $worktreeIdentityBytes = [System.IO.File]::ReadAllBytes($releaseIdentityFile)
    $identityBytesEqual = $worktreeIdentityBytes.Length -eq $committedIdentityBytes.Length
    if ($identityBytesEqual) {
        for ($index = 0; $index -lt $worktreeIdentityBytes.Length; $index++) {
            if ($worktreeIdentityBytes[$index] -ne $committedIdentityBytes[$index]) {
                $identityBytesEqual = $false
                break
            }
        }
    }
    if (-not $identityBytesEqual) {
        throw '发布身份文件工作树原始字节与 HEAD Git blob 不一致；即使 Git 状态显示 clean 也拒绝 Build，请修复换行符或编码后重新提交。'
    }

    $strictUtf8 = New-Object System.Text.UTF8Encoding($false, $true)
    $identity = $strictUtf8.GetString($committedIdentityBytes) | ConvertFrom-Json
    if ($identity.version_name -ne $Version.versionName -or [int] $identity.version_code -ne [int] $Version.versionCode) {
        throw 'Android 版本与后端发布身份不一致，拒绝构建。'
    }
    $identityHash = Get-ByteArraySha256 -Bytes $committedIdentityBytes
    return [ordered]@{
        buildSourceCommit = $commit.ToLowerInvariant()
        releaseIdentitySha256 = $identityHash
    }
}

Assert-ApkIdentityParser
Assert-DownloadRoot -Value $DownloadRootBase
if (-not [string]::IsNullOrWhiteSpace($ExpectedSignerSha256) -and $ExpectedSignerSha256 -notmatch '^[0-9A-Fa-f]{64}$') {
    throw 'ExpectedSignerSha256 必须是 64 位十六进制 SHA-256。'
}
if (-not $DryRun -and $Bump -ne 'none') {
    throw '证据绑定发布不允许在构建或收口过程中修改版本；请先单独更新版本并提交到 main。'
}
$action = if ($Bump -eq 'none') { 'show' } else { $Bump }
$version = Invoke-VersionCommand -Action $action -Preview:$DryRun
if ($DryRun) {
    Write-Host "计划阶段：$Phase"
    Write-Host "计划发布：$($version.versionName) ($($version.versionCode))"
    Write-Host '干运行结束：未构建 APK、未生成项目资产、未写入版本和下载站元数据。'
    exit 0
}

$releaseDirectory = Join-Path $releaseRoot $version.versionName
if ($Phase -eq 'Finalize') {
    if (-not (Test-Path -LiteralPath $releaseDirectory)) {
        throw "缺少 Build 阶段发布目录：$releaseDirectory"
    }
    $expectedTag = "v$($version.versionName)-debug"
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $packageScript `
        -ReleaseRoot $releaseRoot -ExpectedTag $expectedTag
    if ($LASTEXITCODE -ne 0) {
        throw "Finalize 阶段项目资产生成失败，退出码：$LASTEXITCODE"
    }

    $finalManifestPath = Join-Path $releaseDirectory 'release-manifest.json'
    $finalManifest = Get-Content -LiteralPath $finalManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $evidenceCommit = (Invoke-GitText -Arguments @('rev-parse', '--verify', 'HEAD^{commit}') -Operation '读取发布证据提交').ToLowerInvariant()
    if ($finalManifest.versionName -ne $version.versionName -or
        [int] $finalManifest.versionCode -ne [int] $version.versionCode -or
        [string] $finalManifest.releaseEvidenceCommit -ne $evidenceCommit -or
        [string] $finalManifest.releaseTag -ne $expectedTag -or
        [string] $finalManifest.finalizationStatus -ne 'finalized') {
        throw 'Finalize 后发布清单未绑定到当前证据提交、最终标签和版本。'
    }
    $projectAssetsManifestPath = Join-Path $releaseDirectory 'project-assets-manifest.json'
    $projectAssetsManifest = Get-Content -LiteralPath $projectAssetsManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $finalManifestSha256 = (Get-FileHash -LiteralPath $finalManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ([int] $projectAssetsManifest.schemaVersion -ne 3 -or
        @($projectAssetsManifest.assets).Count -ne 3 -or
        [string] $projectAssetsManifest.buildSourceCommit -ne [string] $finalManifest.buildSourceCommit -or
        [string] $projectAssetsManifest.releaseEvidenceCommit -ne $evidenceCommit -or
        [string] $projectAssetsManifest.releaseTag -ne $expectedTag -or
        [string] $projectAssetsManifest.releaseIdentitySha256 -ne [string] $finalManifest.releaseIdentitySha256 -or
        ([string] $projectAssetsManifest.releaseManifestSha256).ToLowerInvariant() -ne $finalManifestSha256) {
        throw '项目资产清单未同时绑定 Build 源码提交与 Finalize 证据提交。'
    }
    foreach ($asset in @($finalManifest.projectAssets)) {
        $assetPath = Join-Path $releaseDirectory ([string] $asset.fileName)
        if (-not (Test-Path -LiteralPath $assetPath) -or (Get-Item -LiteralPath $assetPath).Length -le 0) {
            throw "Finalize 后发布清单声明的项目资产缺失或为空：$($asset.fileName)"
        }
    }
    foreach ($assetEvidence in @($projectAssetsManifest.assets)) {
        $assetPath = Join-Path $releaseDirectory ([string] $assetEvidence.fileName)
        if (-not (Test-Path -LiteralPath $assetPath)) {
            throw "项目资产证据指向缺失文件：$($assetEvidence.fileName)"
        }
        $assetFile = Get-Item -LiteralPath $assetPath
        $assetHash = (Get-FileHash -LiteralPath $assetPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($assetFile.Length -ne [long] $assetEvidence.sizeBytes -or
            $assetHash -ne ([string] $assetEvidence.sha256).ToLowerInvariant()) {
            throw "项目资产体积或 SHA-256 与证据清单不一致：$($assetEvidence.fileName)"
        }
    }

    Write-Host "Finalize 完成：$releaseDirectory"
    Write-Host "Build 源码提交：$($finalManifest.buildSourceCommit)"
    Write-Host "发布证据提交：$evidenceCommit"
    Write-Host "最终注释标签：$expectedTag"
    exit 0
}

$releaseEvidence = Read-CommittedReleaseEvidence -Version $version
$buildSourceCommit = [string] $releaseEvidence.buildSourceCommit
$releaseIdentitySha256 = [string] $releaseEvidence.releaseIdentitySha256

if ([string]::IsNullOrWhiteSpace($JavaHome)) {
    throw 'JAVA_HOME 未配置，请通过 -JavaHome 指定 JDK 17。'
}
if (-not (Test-Path -LiteralPath $JavaHome)) {
    throw "JDK 路径不存在：$JavaHome"
}
if (-not (Test-Path -LiteralPath $downloadSiteRoot)) {
    throw "下载站目录不存在：$downloadSiteRoot"
}

$versionChanged = [bool] $version.changed
$stagingDirectory = Join-Path $releaseRoot (".$($version.versionName).$([Guid]::NewGuid().ToString('N')).staging")
$backupDirectory = "$releaseDirectory.backup"
$nativeValidationDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("yiyunying-apk-verify-" + [Guid]::NewGuid().ToString('N'))
$metadataBackup = $null
$releaseDirectoryCreated = $false

try {
    if (Test-Path -LiteralPath $releaseDirectory) {
        throw "固定版本发布目录已存在，拒绝覆盖：$releaseDirectory"
    }
    $env:JAVA_HOME = $JavaHome
    if ($SkipVerification) {
        Push-Location $projectRoot
        try {
            & .\gradlew.bat --no-daemon assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug
            if ($LASTEXITCODE -ne 0) {
                throw "四端 APK 构建失败，退出码：$LASTEXITCODE"
            }
        }
        finally {
            Pop-Location
        }
    }
    else {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $verifyScript -JavaHome $JavaHome
        if ($LASTEXITCODE -ne 0) {
            throw "四端验证失败，退出码：$LASTEXITCODE"
        }
    }

    $aapt = Resolve-AndroidBuildTool -FileName 'aapt.exe'
    $apksigner = Resolve-AndroidBuildTool -FileName 'apksigner.bat'
    New-Item -ItemType Directory -Path $stagingDirectory -Force | Out-Null
    New-Item -ItemType Directory -Path $nativeValidationDirectory -Force | Out-Null

    $roles = @(
        [ordered]@{
            id = 'user'; name = '易运盈用户端'; shortName = '用户端'; audience = '普通用户'
            description = '聊天、动态、活动、商城与个人中心'; accent = 'blue'
            source = 'app\build\outputs\apk\user\debug\app-user-debug.apk'
            fileName = "yiyunying-user-v$($version.versionName)-debug.apk"
            expectedPackage = 'xyz.jjmxg.yiyunying.user.debug'
            expectedVersionName = "$($version.versionName)-user-debug"
        },
        [ordered]@{
            id = 'admin'; name = '易运盈管理员'; shortName = '管理员'; audience = '管理员'
            description = '用户、内容、订单与运营功能管理'; accent = 'green'
            source = 'app\build\outputs\apk\admin\debug\app-admin-debug.apk'
            fileName = "yiyunying-admin-v$($version.versionName)-debug.apk"
            expectedPackage = 'xyz.jjmxg.yiyunying.admin.debug'
            expectedVersionName = "$($version.versionName)-admin-debug"
        },
        [ordered]@{
            id = 'authorized'; name = '易运盈授权平台'; shortName = '授权平台'; audience = '授权运营方'
            description = '授权应用、下级管理员与业务数据管理'; accent = 'amber'
            source = 'app\build\outputs\apk\authorizedPlatform\debug\app-authorizedPlatform-debug.apk'
            fileName = "yiyunying-authorized-platform-v$($version.versionName)-debug.apk"
            expectedPackage = 'xyz.jjmxg.yiyunying.authorized.debug'
            expectedVersionName = "$($version.versionName)-authorized-platform-debug"
        },
        [ordered]@{
            id = 'owner'; name = '易运盈平台总控'; shortName = '平台总控'; audience = '平台所有者'
            description = '全平台应用、权限、财务与审计总控'; accent = 'charcoal'
            source = 'app\build\outputs\apk\platformOwner\debug\app-platformOwner-debug.apk'
            fileName = "yiyunying-platform-owner-v$($version.versionName)-debug.apk"
            expectedPackage = 'xyz.jjmxg.yiyunying.platformowner.debug'
            expectedVersionName = "$($version.versionName)-platform-owner-debug"
        }
    )

    $releaseEntries = @()
    $sumLines = @()
    foreach ($role in $roles) {
        $source = Join-Path $projectRoot $role.source
        $validationApk = Join-Path $nativeValidationDirectory $role.fileName
        Copy-Item -LiteralPath $source -Destination $validationApk -Force
        $apkArguments = @{
            Path = $validationApk
            ExpectedPackage = $role.expectedPackage
            ExpectedVersionName = $role.expectedVersionName
            ExpectedVersionCode = $version.versionCode
            AaptPath = $aapt
            ApkSignerPath = $apksigner
        }
        $identity = Assert-Apk @apkArguments

        $destination = Join-Path $stagingDirectory $role.fileName
        Copy-Item -LiteralPath $validationApk -Destination $destination -Force
        $file = Get-Item -LiteralPath $destination
        $hash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToUpperInvariant()
        if ($hash -notmatch '^[0-9A-F]{64}$') {
            throw "APK 哈希格式异常：$destination"
        }
        $sumLines += "$hash  $($role.fileName)"
        $releaseEntries += [ordered]@{
            id = $role.id
            name = $role.name
            shortName = $role.shortName
            audience = $role.audience
            description = $role.description
            fileName = $role.fileName
            packageName = $identity.packageName
            versionName = $identity.versionName
            versionCode = $identity.versionCode
            sizeBytes = $file.Length
            size = Format-FileSize $file.Length
            sha256 = $hash
            signerSha256 = $identity.signerSha256
            accent = $role.accent
        }
    }

    $notes = if ($ReleaseNotes -and $ReleaseNotes.Count -gt 0) {
        @($ReleaseNotes)
    }
    else {
        @(
            '重构管理端主页、源码示例、交流与我的四栏，并完善多应用切换和权限管制',
            '新增用户端短视频与快捷业务模块，完善群聊、论坛、资源、商城和审核闭环',
            '优化拍摄变焦与录像聚焦、拍后预览确认、主题字体按钮颜色和系统栏适配',
            '补齐登录身份、迁移门禁、四端发布证据和最小功能闭环测试'
        )
    }
    Assert-ReleaseNotes -Notes $notes

    $projectAssets = @(
        [ordered]@{
            id = 'source'
            fileName = "yiyunying-source-v$($version.versionName).zip"
            label = 'APK 构建源码快照'
            description = '精确对应四端 APK 的 Build 源码提交，不含构建缓存、密钥与本地凭据。'
        },
        [ordered]@{
            id = 'history'
            fileName = "yiyunying-git-history-v$($version.versionName).bundle"
            label = '主线 Git 历史'
            description = '可离线恢复 main 全部可达历史与本次最终注释标签；不包含未发布本地分支和 reflog。'
        },
        [ordered]@{
            id = 'delivery'
            fileName = "yiyunying-project-delivery-v$($version.versionName).zip"
            label = '项目交接总包'
            description = '包含 Build 源码、最终主线 Git 历史、版本说明、架构与证据提交交接文档。'
        },
        [ordered]@{
            id = 'manifest'
            fileName = 'project-assets-manifest.json'
            label = '项目文件校验清单'
            description = '列出项目下载文件体积、SHA-256、Build 源码提交、证据提交和最终注释标签。'
        }
    )

    $manifest = [ordered]@{
        schemaVersion = 4
        versionName = $version.versionName
        versionCode = $version.versionCode
        buildSourceCommit = $buildSourceCommit
        releaseEvidenceCommit = $null
        releaseTag = "v$($version.versionName)-debug"
        finalizationStatus = 'pending'
        releaseIdentitySha256 = $releaseIdentitySha256
        releaseDate = (Get-Date -Format 'yyyy-MM-dd')
        generatedAt = [DateTimeOffset]::Now.ToString('o')
        downloadRootBase = $DownloadRootBase
        releaseNotes = $notes
        releases = $releaseEntries
        projectAssets = $projectAssets
    }

    $pendingManifestPath = Join-Path $stagingDirectory 'release-manifest.json'
    Write-Utf8JsonAtomic -Path $pendingManifestPath -Value $manifest
    $pendingManifestSha256 = (Get-FileHash -LiteralPath $pendingManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllLines(
        (Join-Path $stagingDirectory 'SHA256SUMS.txt'),
        $sumLines,
        (New-Object System.Text.UTF8Encoding($false))
    )

    if (Test-Path -LiteralPath $backupDirectory) {
        Remove-Item -LiteralPath $backupDirectory -Recurse -Force
    }
    if (Test-Path -LiteralPath $releaseDirectory) {
        Move-Item -LiteralPath $releaseDirectory -Destination $backupDirectory
    }
    Move-Item -LiteralPath $stagingDirectory -Destination $releaseDirectory
    $releaseDirectoryCreated = $true

    if (-not $SkipDownloadMetadata) {
        if (Test-Path -LiteralPath $metadataFile) {
            $metadataBackup = Get-Content -LiteralPath $metadataFile -Raw -Encoding UTF8
        }
        $downloadMetadata = [ordered]@{}
        foreach ($entry in $manifest.GetEnumerator()) {
            $downloadMetadata[$entry.Key] = $entry.Value
        }
        $downloadMetadata['pendingManifestSha256'] = $pendingManifestSha256
        Write-Utf8JsonAtomic -Path $metadataFile -Value $downloadMetadata
    }

    if (Test-Path -LiteralPath $backupDirectory) {
        Remove-Item -LiteralPath $backupDirectory -Recurse -Force
    }

    Write-Host "Build 阶段产物已生成：$releaseDirectory"
    Write-Host "版本：$($version.versionName) ($($version.versionCode))"
    Write-Host "Build 源码提交：$buildSourceCommit"
    Write-Host "Pending 发布清单 SHA-256：$pendingManifestSha256"
    Write-Host "下载根地址：$DownloadRootBase"
    Write-Host '四端 APK 的包名、包内版本、签名、体积与 SHA-256 已全部通过校验。'
    Write-Host "请提交下载元数据与部署证据，再创建注释标签 v$($version.versionName)-debug 并运行 -Phase Finalize。"
    Write-Host '提示：当前产物为 Debug 验证包，正式公开发布前必须改用受保护的生产签名。'
}
catch {
    Remove-Item -LiteralPath $stagingDirectory -Recurse -Force -ErrorAction SilentlyContinue

    if (Test-Path -LiteralPath $backupDirectory) {
        if (Test-Path -LiteralPath $releaseDirectory) {
            Remove-Item -LiteralPath $releaseDirectory -Recurse -Force
        }
        Move-Item -LiteralPath $backupDirectory -Destination $releaseDirectory
    }
    elseif ($releaseDirectoryCreated -and (Test-Path -LiteralPath $releaseDirectory)) {
        Remove-Item -LiteralPath $releaseDirectory -Recurse -Force
    }
    if ($null -ne $metadataBackup) {
        [System.IO.File]::WriteAllText(
            $metadataFile,
            $metadataBackup,
            (New-Object System.Text.UTF8Encoding($false))
        )
    }
    if ($versionChanged) {
        try {
            $rollbackArguments = @{
                Action = 'set'
                Name = $version.previousVersionName
                Code = $version.previousVersionCode
                Force = $true
            }
            Invoke-VersionCommand @rollbackArguments | Out-Null
        }
        catch {
            Write-Warning '发布失败后版本自动回滚失败，请立即运行 version.ps1 人工核对。'
        }
    }
    throw
}
finally {
    Remove-Item -LiteralPath $nativeValidationDirectory -Recurse -Force -ErrorAction SilentlyContinue
}
