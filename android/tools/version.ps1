[CmdletBinding()]
param(
    [ValidateSet('show', 'patch', 'minor', 'major', 'build', 'set')]
    [string] $Action = 'show',
    [string] $VersionName,
    [int] $VersionCode,
    [switch] $DryRun,
    [switch] $Force,
    [switch] $Json
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workspaceRoot = (Resolve-Path (Join-Path $projectRoot '..')).Path
$versionFile = Join-Path $projectRoot 'version.properties'
$downloadPackageFile = Join-Path $workspaceRoot 'download-site\package.json'
$backendReleaseFile = Join-Path $workspaceRoot 'backend\config\release-identity.json'
$lockFile = Join-Path $projectRoot '.version.lock'
$historyFile = Join-Path $projectRoot 'version-history.jsonl'

function Open-VersionLock {
    $deadline = [DateTime]::UtcNow.AddSeconds(15)
    do {
        try {
            return [System.IO.File]::Open(
                $lockFile,
                [System.IO.FileMode]::OpenOrCreate,
                [System.IO.FileAccess]::ReadWrite,
                [System.IO.FileShare]::None
            )
        }
        catch [System.IO.IOException] {
            if ([DateTime]::UtcNow -ge $deadline) {
                throw '版本文件正在被另一个发布任务使用，请稍后重试。'
            }
            Start-Sleep -Milliseconds 250
        }
    } while ($true)
}

function Read-VersionState {
    if (-not (Test-Path -LiteralPath $versionFile)) {
        throw "缺少版本文件：$versionFile"
    }

    $values = @{}
    foreach ($line in Get-Content -LiteralPath $versionFile -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') {
            $values[$Matches[1]] = $Matches[2]
        }
    }

    if ($values.VERSION_NAME -notmatch '^(\d+)\.(\d+)\.(\d+)$') {
        throw 'VERSION_NAME 必须使用 major.minor.patch 格式。'
    }
    $major = [int] $Matches[1]
    $minor = [int] $Matches[2]
    $patch = [int] $Matches[3]
    if ($values.VERSION_CODE -notmatch '^[1-9]\d*$') {
        throw 'VERSION_CODE 必须是正整数。'
    }

    return [ordered]@{
        versionName = [string] $values.VERSION_NAME
        versionCode = [int] $values.VERSION_CODE
        major = $major
        minor = $minor
        patch = $patch
    }
}

function Compare-SemanticVersion([string] $Left, [string] $Right) {
    $leftParts = $Left.Split('.') | ForEach-Object { [int] $_ }
    $rightParts = $Right.Split('.') | ForEach-Object { [int] $_ }
    foreach ($index in 0..2) {
        if ($leftParts[$index] -lt $rightParts[$index]) { return -1 }
        if ($leftParts[$index] -gt $rightParts[$index]) { return 1 }
    }
    return 0
}

function Write-VersionState([string] $Name, [int] $Code) {
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    $temporaryFile = "$versionFile.$([Guid]::NewGuid().ToString('N')).tmp"
    $backupFile = "$versionFile.bak"
    $content = "VERSION_CODE=$Code`r`nVERSION_NAME=$Name`r`n"
    [System.IO.File]::WriteAllText($temporaryFile, $content, $utf8)
    try {
        [System.IO.File]::Replace($temporaryFile, $versionFile, $backupFile, $true)
        Remove-Item -LiteralPath $backupFile -Force -ErrorAction SilentlyContinue
    }
    finally {
        Remove-Item -LiteralPath $temporaryFile -Force -ErrorAction SilentlyContinue
    }
}

function Read-DownloadPackageVersion {
    if (-not (Test-Path -LiteralPath $downloadPackageFile)) {
        throw "缺少下载站版本文件：$downloadPackageFile"
    }
    $package = Get-Content -LiteralPath $downloadPackageFile -Raw -Encoding UTF8 | ConvertFrom-Json
    if ([string]::IsNullOrWhiteSpace([string] $package.version)) {
        throw '下载站 package.json 缺少 version。'
    }
    return [string] $package.version
}

function Write-DownloadPackageVersion([string] $Name) {
    $package = Get-Content -LiteralPath $downloadPackageFile -Raw -Encoding UTF8 | ConvertFrom-Json
    $package.version = $Name
    $json = $package | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText(
        $downloadPackageFile,
        $json + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Read-BackendReleaseIdentity {
    if (-not (Test-Path -LiteralPath $backendReleaseFile)) {
        throw "缺少后端发布身份文件：$backendReleaseFile"
    }
    $identity = Get-Content -LiteralPath $backendReleaseFile -Raw -Encoding UTF8 | ConvertFrom-Json
    if ([string] $identity.version_name -notmatch '^\d+\.\d+\.\d+$' -or [int] $identity.version_code -le 0) {
        throw '后端发布身份文件格式无效。'
    }
    return [ordered]@{
        versionName = [string] $identity.version_name
        versionCode = [int] $identity.version_code
    }
}

function Write-BackendReleaseIdentity([string] $Name, [int] $Code) {
    $identity = [ordered]@{ version_name = $Name; version_code = $Code }
    $json = $identity | ConvertTo-Json
    [System.IO.File]::WriteAllText(
        $backendReleaseFile,
        $json + [Environment]::NewLine,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Write-Result($Result) {
    if ($Json) {
        $Result | ConvertTo-Json -Compress
        return
    }

    $prefix = if ($Result.changed) {
        if ($Result.dryRun) { '计划版本' } else { '当前版本' }
    }
    else {
        '当前版本'
    }
    Write-Host "$prefix：$($Result.versionName) ($($Result.versionCode))"
    if ($Result.changed -and $Result.dryRun) {
        Write-Host '这是干运行，版本文件未修改。'
    }
}

$lock = Open-VersionLock
try {
    $current = Read-VersionState
    $downloadVersion = Read-DownloadPackageVersion
    $backendRelease = Read-BackendReleaseIdentity
    if ($downloadVersion -ne $current.versionName) {
        throw "版本链不一致：Android=$($current.versionName)，下载站=$downloadVersion。请先修复再发布。"
    }
    if ($backendRelease.versionName -ne $current.versionName -or $backendRelease.versionCode -ne $current.versionCode) {
        throw "版本链不一致：Android=$($current.versionName) ($($current.versionCode))，后端=$($backendRelease.versionName) ($($backendRelease.versionCode))。请先修复再发布。"
    }
    $nextName = $current.versionName
    $nextCode = $current.versionCode

    switch ($Action) {
        'show' { }
        'patch' {
            $nextName = "$($current.major).$($current.minor).$($current.patch + 1)"
            $nextCode++
        }
        'minor' {
            $nextName = "$($current.major).$($current.minor + 1).0"
            $nextCode++
        }
        'major' {
            $nextName = "$($current.major + 1).0.0"
            $nextCode++
        }
        'build' {
            $nextCode++
        }
        'set' {
            if ([string]::IsNullOrWhiteSpace($VersionName) -or $VersionName -notmatch '^\d+\.\d+\.\d+$') {
                throw '使用 set 时必须提供合法的 -VersionName major.minor.patch。'
            }
            $nextName = $VersionName
            $nextCode = if ($PSBoundParameters.ContainsKey('VersionCode')) {
                $VersionCode
            }
            else {
                $current.versionCode + 1
            }
            if ($nextCode -le 0) {
                throw '-VersionCode 必须是正整数。'
            }
            if (-not $Force) {
                if ($nextCode -le $current.versionCode) {
                    throw '新 VERSION_CODE 必须大于当前值；确需回退时请显式使用 -Force。'
                }
                if ((Compare-SemanticVersion $nextName $current.versionName) -lt 0) {
                    throw '新 VERSION_NAME 不能低于当前版本；确需回退时请显式使用 -Force。'
                }
            }
        }
    }

    $changed = $nextName -ne $current.versionName -or $nextCode -ne $current.versionCode
    $result = [ordered]@{
        action = $Action
        changed = $changed
        dryRun = [bool] $DryRun
        previousVersionName = $current.versionName
        previousVersionCode = $current.versionCode
        versionName = $nextName
        versionCode = $nextCode
        versionFile = $versionFile
        downloadPackageFile = $downloadPackageFile
        backendReleaseFile = $backendReleaseFile
    }

    if ($changed -and -not $DryRun) {
        $originalPackage = Get-Content -LiteralPath $downloadPackageFile -Raw -Encoding UTF8
        $originalBackendRelease = Get-Content -LiteralPath $backendReleaseFile -Raw -Encoding UTF8
        try {
            Write-VersionState -Name $nextName -Code $nextCode
            Write-DownloadPackageVersion -Name $nextName
            Write-BackendReleaseIdentity -Name $nextName -Code $nextCode
        }
        catch {
            Write-VersionState -Name $current.versionName -Code $current.versionCode
            [System.IO.File]::WriteAllText(
                $downloadPackageFile,
                $originalPackage,
                (New-Object System.Text.UTF8Encoding($false))
            )
            [System.IO.File]::WriteAllText(
                $backendReleaseFile,
                $originalBackendRelease,
                (New-Object System.Text.UTF8Encoding($false))
            )
            throw
        }
        $audit = [ordered]@{
            timestamp = [DateTimeOffset]::Now.ToString('o')
            action = $Action
            from = "$($current.versionName) ($($current.versionCode))"
            to = "$nextName ($nextCode)"
            user = [Environment]::UserName
            computer = [Environment]::MachineName
        } | ConvertTo-Json -Compress
        [System.IO.File]::AppendAllText(
            $historyFile,
            "$audit`r`n",
            (New-Object System.Text.UTF8Encoding($false))
        )
    }

    Write-Result $result
}
finally {
    $lock.Dispose()
}
