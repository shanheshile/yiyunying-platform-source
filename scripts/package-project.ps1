[CmdletBinding()]
param(
    [string] $ReleaseRoot,
    [switch] $AllowDirty
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$versionFile = Join-Path $projectRoot 'android\version.properties'

function Read-VersionName {
    foreach ($line in Get-Content -LiteralPath $versionFile -Encoding UTF8) {
        if ($line -match '^\s*VERSION_NAME\s*=\s*(\d+\.\d+\.\d+)\s*$') {
            return $Matches[1]
        }
    }
    throw "无法从 $versionFile 读取 VERSION_NAME"
}

function Get-Sha256([string] $Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Write-Utf8Json([string] $Path, $Value) {
    $json = $Value | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText(
        $Path,
        $json + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Assert-GitSucceeded([string] $Operation) {
    if ($LASTEXITCODE -ne 0) {
        throw "$Operation 失败，Git 退出码：$LASTEXITCODE"
    }
}

$version = Read-VersionName
$releaseDirectory = if ([string]::IsNullOrWhiteSpace($ReleaseRoot)) {
    Join-Path $projectRoot "releases\$version"
}
else {
    Join-Path (Resolve-Path $ReleaseRoot).Path $version
}

if (-not (Test-Path -LiteralPath (Join-Path $releaseDirectory 'release-manifest.json'))) {
    throw "请先生成 Android 发布产物：$releaseDirectory"
}

if (-not $AllowDirty) {
    $dirty = @(& git '-C' $projectRoot 'status' '--porcelain' '--untracked-files=no')
    Assert-GitSucceeded '读取工作区状态'
    if ($dirty.Count -gt 0) {
        throw '完整项目包必须从干净提交生成；请先提交本次改动。'
    }
}

$commit = (& git '-C' $projectRoot 'rev-parse' 'HEAD').Trim()
    Assert-GitSucceeded '读取 Git 提交'
    $sourceName = "yiyunying-source-v$version.zip"
    $historyName = "yiyunying-git-history-v$version.bundle"
    $deliveryName = "yiyunying-project-delivery-v$version.zip"
    $sourcePath = Join-Path $releaseDirectory $sourceName
    $historyPath = Join-Path $releaseDirectory $historyName
    $deliveryPath = Join-Path $releaseDirectory $deliveryName

    Remove-Item -LiteralPath $sourcePath, $historyPath, $deliveryPath -Force -ErrorAction SilentlyContinue
    & git '-C' $projectRoot 'archive' '--format=zip' "--output=$sourcePath" 'HEAD'
    Assert-GitSucceeded '生成源码快照'
    & git '-C' $projectRoot 'bundle' 'create' $historyPath '--all'
    Assert-GitSucceeded '生成 Git 历史包'

    $temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("yiyunying-delivery-" + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temporary -Force | Out-Null
    try {
        $sourceDirectory = Join-Path $temporary 'source'
        Expand-Archive -LiteralPath $sourcePath -DestinationPath $sourceDirectory -Force
        Copy-Item -LiteralPath $historyPath -Destination (Join-Path $temporary $historyName)
        Copy-Item -LiteralPath (Join-Path $releaseDirectory 'release-manifest.json') -Destination $temporary
        Copy-Item -LiteralPath (Join-Path $releaseDirectory 'SHA256SUMS.txt') -Destination $temporary

        $handoffDirectory = Join-Path $temporary 'handoff'
        New-Item -ItemType Directory -Path $handoffDirectory -Force | Out-Null
        foreach ($relative in @(
            'README.md',
            'CHANGELOG.md',
            'docs\CURRENT_STATUS.md',
            'docs\PROJECT_INDEX.md',
            'docs\MASTER_REQUIREMENTS_AND_IMPLEMENTATION_PLAN.md',
            'docs\NEW_TASK_HANDOFF.md',
            'docs\project-handoff.json',
            "docs\releases\$version.md"
        )) {
            $candidate = Join-Path $projectRoot $relative
            if (Test-Path -LiteralPath $candidate) {
                $safeName = $relative -replace '[\\/]', '__'
                Copy-Item -LiteralPath $candidate -Destination (Join-Path $handoffDirectory $safeName)
            }
        }

        Compress-Archive -Path (Join-Path $temporary '*') -DestinationPath $deliveryPath -CompressionLevel Optimal -Force
    }
    finally {
        Remove-Item -LiteralPath $temporary -Recurse -Force -ErrorAction SilentlyContinue
    }

    $assets = @()
    foreach ($name in @($sourceName, $historyName, $deliveryName)) {
        $path = Join-Path $releaseDirectory $name
        $file = Get-Item -LiteralPath $path
        $assets += [ordered]@{
            fileName = $name
            sizeBytes = $file.Length
            sha256 = Get-Sha256 $path
        }
    }

    $manifest = [ordered]@{
        schemaVersion = 1
        versionName = $version
        gitCommit = $commit
        generatedAt = [DateTimeOffset]::Now.ToString('o')
        security = [ordered]@{
            containsCredentials = $false
            containsSigningKeys = $false
            containsProductionData = $false
        }
        assets = $assets
    }
    Write-Utf8Json -Path (Join-Path $releaseDirectory 'project-assets-manifest.json') -Value $manifest

Write-Host "完整项目产物已生成：$releaseDirectory"
Write-Host "版本：$version"
Write-Host "Git 提交：$commit"
Write-Host '已生成源码快照、完整 Git 历史、项目交接总包和 SHA-256 校验清单。'
