[CmdletBinding()]
param(
    [ValidateSet('none', 'patch', 'minor', 'major', 'build')]
    [string] $Bump = 'patch',
    [string] $JavaHome = $env:JAVA_HOME,
    [switch] $SkipVerification,
    [switch] $SkipDownloadMetadata,
    [switch] $DryRun,
    [string[]] $ReleaseNotes
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = (Resolve-Path (Join-Path $projectRoot '..')).Path
$downloadSiteRoot = Join-Path $workspaceRoot 'yiyunying-download-site'
$metadataFile = Join-Path $downloadSiteRoot 'release-metadata.json'
$versionScript = Join-Path $PSScriptRoot 'version.ps1'
$verifyScript = Join-Path $PSScriptRoot 'verify.ps1'

function Invoke-VersionCommand([string] $Action, [switch] $Preview) {
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $versionScript, '-Action', $Action, '-Json')
    if ($Preview) { $arguments += '-DryRun' }
    $raw = & powershell.exe @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "版本命令执行失败：$Action"
    }
    return ($raw | Select-Object -Last 1 | ConvertFrom-Json)
}

function Format-FileSize([long] $Bytes) {
    return ('{0:N2} MB' -f ($Bytes / 1MB))
}

function Write-Utf8Json([string] $Path, $Value) {
    $json = $Value | ConvertTo-Json -Depth 12
    [System.IO.File]::WriteAllText($Path, "$json`r`n", (New-Object System.Text.UTF8Encoding($false)))
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

if ($SkipVerification) {
    $env:JAVA_HOME = $JavaHome
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

$releaseDirectory = Join-Path (Join-Path $workspaceRoot 'releases') $version.versionName
New-Item -ItemType Directory -Path $releaseDirectory -Force | Out-Null

$roles = @(
    [ordered]@{
        id = 'user'; name = '易运盈用户端'; shortName = '用户端'; audience = '普通用户'
        description = '聊天、动态、活动、商城与个人中心'; accent = 'blue'
        source = 'app\build\outputs\apk\user\debug\app-user-debug.apk'
        fileName = "yiyunying-user-v$($version.versionName)-debug.apk"
    },
    [ordered]@{
        id = 'admin'; name = '易运盈管理员'; shortName = '管理员'; audience = '管理员'
        description = '用户、内容、订单与运营功能管理'; accent = 'green'
        source = 'app\build\outputs\apk\admin\debug\app-admin-debug.apk'
        fileName = "yiyunying-admin-v$($version.versionName)-debug.apk"
    },
    [ordered]@{
        id = 'authorized'; name = '易运盈授权平台'; shortName = '授权平台'; audience = '授权运营方'
        description = '授权应用、下级管理员与业务数据管理'; accent = 'amber'
        source = 'app\build\outputs\apk\authorizedPlatform\debug\app-authorizedPlatform-debug.apk'
        fileName = "yiyunying-authorized-platform-v$($version.versionName)-debug.apk"
    },
    [ordered]@{
        id = 'owner'; name = '易运盈平台总控'; shortName = '平台总控'; audience = '平台所有者'
        description = '全平台应用、权限、财务与审计总控'; accent = 'charcoal'
        source = 'app\build\outputs\apk\platformOwner\debug\app-platformOwner-debug.apk'
        fileName = "yiyunying-platform-owner-v$($version.versionName)-debug.apk"
    }
)

$releaseEntries = @()
$sumLines = @()
foreach ($role in $roles) {
    $source = Join-Path $projectRoot $role.source
    if (-not (Test-Path -LiteralPath $source)) {
        throw "构建结束但未找到 APK：$source"
    }
    $destination = Join-Path $releaseDirectory $role.fileName
    Copy-Item -LiteralPath $source -Destination $destination -Force
    $file = Get-Item -LiteralPath $destination
    $hash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash
    $sumLines += "$hash  $($role.fileName)"
    $releaseEntries += [ordered]@{
        id = $role.id
        name = $role.name
        shortName = $role.shortName
        audience = $role.audience
        description = $role.description
        fileName = $role.fileName
        sizeBytes = $file.Length
        size = Format-FileSize $file.Length
        sha256 = $hash
        accent = $role.accent
    }
}

$notes = if ($ReleaseNotes -and $ReleaseNotes.Count -gt 0) {
    $ReleaseNotes
}
elseif (Test-Path -LiteralPath $metadataFile) {
    @((Get-Content -LiteralPath $metadataFile -Encoding UTF8 -Raw | ConvertFrom-Json).releaseNotes)
}
else {
    @('四端客户端同步构建并完成发布校验')
}

$manifest = [ordered]@{
    schemaVersion = 1
    versionName = $version.versionName
    versionCode = $version.versionCode
    releaseDate = (Get-Date -Format 'yyyy-MM-dd')
    generatedAt = [DateTimeOffset]::Now.ToString('o')
    downloadRootBase = 'http://appht.jjmxg.xyz/downloads'
    releaseNotes = $notes
    releases = $releaseEntries
}

Write-Utf8Json -Path (Join-Path $releaseDirectory 'release-manifest.json') -Value $manifest
[System.IO.File]::WriteAllLines(
    (Join-Path $releaseDirectory 'SHA256SUMS.txt'),
    $sumLines,
    (New-Object System.Text.UTF8Encoding($false))
)

if (-not $SkipDownloadMetadata) {
    if (-not (Test-Path -LiteralPath $downloadSiteRoot)) {
        throw "下载站目录不存在：$downloadSiteRoot"
    }
    Write-Utf8Json -Path $metadataFile -Value $manifest
}

Write-Host "发布产物已生成：$releaseDirectory"
Write-Host "版本：$($version.versionName) ($($version.versionCode))"
Write-Host '提示：部署服务器前仍需人工确认版本说明、签名、灰度范围和回滚包。'
