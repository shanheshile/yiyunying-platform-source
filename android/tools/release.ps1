[CmdletBinding()]
param(
    [ValidateSet('none', 'patch', 'minor', 'major', 'build')]
    [string] $Bump = 'patch',
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

Assert-ApkIdentityParser
Assert-DownloadRoot -Value $DownloadRootBase
if (-not [string]::IsNullOrWhiteSpace($ExpectedSignerSha256) -and $ExpectedSignerSha256 -notmatch '^[0-9A-Fa-f]{64}$') {
    throw 'ExpectedSignerSha256 必须是 64 位十六进制 SHA-256。'
}
$action = if ($Bump -eq 'none') { 'show' } else { $Bump }
$version = Invoke-VersionCommand -Action $action -Preview:$DryRun
if ($DryRun) {
    Write-Host "计划发布：$($version.versionName) ($($version.versionCode))"
    Write-Host '干运行结束：未构建 APK、未写入版本和下载站元数据。'
    exit 0
}

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
$releaseDirectory = Join-Path $releaseRoot $version.versionName
$stagingDirectory = Join-Path $releaseRoot (".$($version.versionName).$([Guid]::NewGuid().ToString('N')).staging")
$backupDirectory = "$releaseDirectory.backup"
$nativeValidationDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("yiyunying-apk-verify-" + [Guid]::NewGuid().ToString('N'))
$metadataBackup = $null

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
            '新增账号隔离的自动缓存策略、分类清理与服务端平台上限',
            '优化图片原图、GIF 动图、动态照片、视频、语音、音频、文档与普通文件的类型化预览',
            '完善视频封面、缓冲、进度、倍速、全屏和按网络自动播放策略',
            '补齐数据库迁移、十一领域验收矩阵与发布前静态检查'
        )
    }
    Assert-ReleaseNotes -Notes $notes

    $projectAssets = @(
        [ordered]@{
            id = 'source'
            fileName = "yiyunying-source-v$($version.versionName).zip"
            label = '完整源码快照'
            description = '当前发布提交的完整源码，不含构建缓存、密钥与本地凭据。'
        },
        [ordered]@{
            id = 'history'
            fileName = "yiyunying-git-history-v$($version.versionName).bundle"
            label = '完整 Git 历史'
            description = '可离线克隆和恢复全部分支、标签与提交历史的 Git Bundle。'
        },
        [ordered]@{
            id = 'delivery'
            fileName = "yiyunying-project-delivery-v$($version.versionName).zip"
            label = '项目交接总包'
            description = '源码、Git 历史、版本说明、架构与新任务交接文档的完整交付包。'
        },
        [ordered]@{
            id = 'manifest'
            fileName = 'project-assets-manifest.json'
            label = '项目文件校验清单'
            description = '列出项目下载文件的体积、SHA-256、版本和对应 Git 提交。'
        }
    )

    $manifest = [ordered]@{
        schemaVersion = 3
        versionName = $version.versionName
        versionCode = $version.versionCode
        releaseDate = (Get-Date -Format 'yyyy-MM-dd')
        generatedAt = [DateTimeOffset]::Now.ToString('o')
        downloadRootBase = $DownloadRootBase
        releaseNotes = $notes
        releases = $releaseEntries
        projectAssets = $projectAssets
    }

    Write-Utf8JsonAtomic -Path (Join-Path $stagingDirectory 'release-manifest.json') -Value $manifest
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

    if (-not $SkipDownloadMetadata) {
        if (Test-Path -LiteralPath $metadataFile) {
            $metadataBackup = Get-Content -LiteralPath $metadataFile -Raw -Encoding UTF8
        }
        Write-Utf8JsonAtomic -Path $metadataFile -Value $manifest
    }

    if (Test-Path -LiteralPath $backupDirectory) {
        Remove-Item -LiteralPath $backupDirectory -Recurse -Force
    }

    Write-Host "发布产物已生成：$releaseDirectory"
    Write-Host "版本：$($version.versionName) ($($version.versionCode))"
    Write-Host "下载根地址：$DownloadRootBase"
    Write-Host '四端 APK 的包名、包内版本、签名、体积与 SHA-256 已全部通过校验。'
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
