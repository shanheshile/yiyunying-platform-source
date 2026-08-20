param(
    [string]$MariaDbRoot = "$env:LOCALAPPDATA\CodexRuntimes\mariadb-11.4.5-winx64",
    [string]$EvidenceRoot = '',
    [ValidateSet('core', 'forum', 'notifications', 'chat-commerce')]
    [string[]]$Suites = @('core', 'forum', 'notifications', 'chat-commerce')
)

$ErrorActionPreference = 'Stop'

$backendRoot = Split-Path -Parent $PSScriptRoot
$repositoryRoot = Split-Path -Parent $backendRoot
$workspaceRoot = Split-Path -Parent $repositoryRoot
$php = (Get-Command php -ErrorAction Stop).Source
$phpRoot = Split-Path -Parent $php
$mariaBin = Join-Path $MariaDbRoot 'bin'

$requiredExecutables = @(
    'mariadb-install-db.exe',
    'mariadbd.exe',
    'mysql.exe',
    'mysqladmin.exe'
)
foreach ($name in $requiredExecutables) {
    $path = Join-Path $mariaBin $name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "MINIMUM_CLOSURE_MARIADB_MISSING: $path"
    }
}

foreach ($extension in @('php_pdo_mysql.dll', 'php_curl.dll', 'php_mbstring.dll')) {
    $path = Join-Path $phpRoot "ext\$extension"
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "MINIMUM_CLOSURE_PHP_EXTENSION_MISSING: $path"
    }
}

if ([string]::IsNullOrWhiteSpace($EvidenceRoot)) {
    $EvidenceRoot = Join-Path $workspaceRoot '.test-evidence\minimum-closure'
}
$resolvedEvidenceParent = [System.IO.Path]::GetFullPath($EvidenceRoot)
$resolvedRepositoryRoot = [System.IO.Path]::GetFullPath($repositoryRoot)
if ($resolvedEvidenceParent.StartsWith(
        $resolvedRepositoryRoot.TrimEnd('\') + '\',
        [System.StringComparison]::OrdinalIgnoreCase
    )) {
    throw 'MINIMUM_CLOSURE_EVIDENCE_INSIDE_REPOSITORY: evidence must remain outside Git'
}

$runId = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N')
$stage = Join-Path $resolvedEvidenceParent $runId
$data = Join-Path $stage 'data'
$logs = Join-Path $stage 'logs'
New-Item -ItemType Directory -Path $data, $logs -Force | Out-Null

function Get-FreeLoopbackPort {
    $listener = [System.Net.Sockets.TcpListener]::new(
        [System.Net.IPAddress]::Loopback,
        0
    )
    $listener.Start()
    try {
        return ([System.Net.IPEndPoint] $listener.LocalEndpoint).Port
    } finally {
        $listener.Stop()
    }
}

function New-RandomHex {
    param([ValidateRange(16, 128)][int]$Bytes = 32)
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($buffer)
    } finally {
        $generator.Dispose()
    }
    return -join ($buffer | ForEach-Object { $_.ToString('x2') })
}

function Get-Sha256Hex {
    param([Parameter(Mandatory)][byte[]]$Bytes)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $digest = $algorithm.ComputeHash($Bytes)
    } finally {
        $algorithm.Dispose()
    }
    return -join ($digest | ForEach-Object { $_.ToString('x2') })
}

function Protect-ProcessArgument {
    param([Parameter(Mandatory)][string]$Value)
    if ($Value -notmatch '[\s"]') {
        return $Value
    }
    return '"' + ($Value -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"'
}

function Start-HiddenProcess {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$StdoutPath,
        [Parameter(Mandatory)][string]$StderrPath
    )
    $argumentLine = ($Arguments | ForEach-Object { Protect-ProcessArgument $_ }) -join ' '
    return Start-Process -FilePath $FilePath -ArgumentList $argumentLine `
        -WorkingDirectory $WorkingDirectory `
        -RedirectStandardOutput $StdoutPath `
        -RedirectStandardError $StderrPath `
        -WindowStyle Hidden -PassThru
}

function Invoke-SmokeSuite {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$BaseUrl
    )
    $relativePath = switch ($Name) {
        'core' { 'tools\smoke.ps1' }
        'forum' { 'tools\smoke-forum-experience.ps1' }
        'notifications' { 'tools\smoke-notification-center.ps1' }
        'chat-commerce' { 'tools\smoke-chat-commerce.ps1' }
        default { throw "MINIMUM_CLOSURE_SUITE_UNKNOWN: $Name" }
    }
    $path = Join-Path $backendRoot $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "MINIMUM_CLOSURE_SUITE_MISSING: $path"
    }
    Write-Host "[minimum-closure] smoke:$Name"
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $path -BaseUrl $BaseUrl
    if ($LASTEXITCODE -ne 0) {
        throw "MINIMUM_CLOSURE_SUITE_FAILED: $Name exit=$LASTEXITCODE"
    }
}

$environmentNames = @(
    'MYSQL_PWD', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
    'PASSWORD_MIN_LENGTH', 'AI_ENABLED', 'MAIL_TRANSPORT',
    'STT_PROVIDER', 'STT_API_URL', 'STT_API_KEY',
    'QR_SIGNING_KEY', 'MEDIA_SIGNING_KEY', 'YY_TMP_PASSWORD',
    'YY_SMOKE_TEST_PASSWORD'
)
$savedEnvironment = @{}
foreach ($name in $environmentNames) {
    $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$databasePort = Get-FreeLoopbackPort
$apiPort = Get-FreeLoopbackPort
$databasePassword = New-RandomHex -Bytes 24
$testPassword = New-RandomHex -Bytes 16
$databaseProcess = $null
$phpProcess = $null
$passed = [System.Collections.Generic.List[string]]::new()

try {
    Write-Host "[minimum-closure] evidence=$stage"
    Write-Host "[minimum-closure] initialize MariaDB $databasePort"
    $savedPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & (Join-Path $mariaBin 'mariadb-install-db.exe') `
            "--datadir=$data" "--password=$databasePassword" "--port=$databasePort" --silent
        $databaseInitExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $savedPreference
    }
    if ($databaseInitExit -ne 0) {
        throw "MINIMUM_CLOSURE_DATABASE_INIT_FAILED: exit=$databaseInitExit"
    }

    $databaseProcess = Start-HiddenProcess `
        -FilePath (Join-Path $mariaBin 'mariadbd.exe') `
        -Arguments @(
            "--datadir=$data",
            "--port=$databasePort",
            '--bind-address=127.0.0.1',
            '--skip-name-resolve',
            '--max-connections=150',
            '--console'
        ) `
        -WorkingDirectory $stage `
        -StdoutPath (Join-Path $logs 'mariadb.stdout.log') `
        -StderrPath (Join-Path $logs 'mariadb.stderr.log')

    [Environment]::SetEnvironmentVariable('MYSQL_PWD', $databasePassword, 'Process')
    $databaseReady = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        $savedPreference = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        try {
            & (Join-Path $mariaBin 'mysqladmin.exe') --protocol=tcp --host=127.0.0.1 `
                "--port=$databasePort" --user=root ping --silent 2>$null | Out-Null
            $pingExit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $savedPreference
        }
        if ($pingExit -eq 0) {
            $databaseReady = $true
            break
        }
        if ($databaseProcess.HasExited) {
            throw "MINIMUM_CLOSURE_DATABASE_EXITED: exit=$($databaseProcess.ExitCode)"
        }
        Start-Sleep -Milliseconds 500
    }
    if (-not $databaseReady) {
        throw 'MINIMUM_CLOSURE_DATABASE_TIMEOUT'
    }

    Write-Host '[minimum-closure] bootstrap current schema and isolated identities'
    [Environment]::SetEnvironmentVariable('YY_TMP_PASSWORD', $testPassword, 'Process')
    [Environment]::SetEnvironmentVariable('YY_SMOKE_TEST_PASSWORD', $testPassword, 'Process')
    $passwordHash = & $php -r 'echo password_hash(getenv(\"YY_TMP_PASSWORD\"), PASSWORD_DEFAULT);'
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($passwordHash)) {
        throw 'MINIMUM_CLOSURE_PASSWORD_HASH_FAILED'
    }
    $appSecret = New-RandomHex
    $appSecretHash = Get-Sha256Hex -Bytes ([Text.Encoding]::UTF8.GetBytes($appSecret))
    # The Windows MariaDB client cannot reliably open an absolute SOURCE path
    # containing non-ASCII directory names. Run it from the repository root and
    # keep the client-side SOURCE token ASCII-only.
    $installPath = 'backend/database/install.sql'
$bootstrapSql = @"
CREATE DATABASE yiyunying_closure CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE yiyunying_closure;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @YY_BOOTSTRAP_ROOT_PLATFORM_KEY = 'yiyunying-root';
SET @YY_BOOTSTRAP_ROOT_ACCOUNT = 'root';
SET @YY_BOOTSTRAP_ROOT_PASSWORD_HASH = '$passwordHash';
SET @YY_BOOTSTRAP_ADMIN_ACCOUNT = 'admin';
SET @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH = '$passwordHash';
SET @YY_BOOTSTRAP_APP_KEY = 'yiyunying-demo';
SET @YY_BOOTSTRAP_APP_SECRET_HASH = '$appSecretHash';
SET @YY_BOOTSTRAP_USER_UID = '10000000001';
SET @YY_BOOTSTRAP_USER_ACCOUNT = 'closure-user';
SET @YY_BOOTSTRAP_USER_PASSWORD_HASH = '$passwordHash';
SOURCE $installPath;
"@
    $savedPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    Push-Location $repositoryRoot
    try {
        $bootstrapSql | & (Join-Path $mariaBin 'mysql.exe') --protocol=tcp --host=127.0.0.1 `
            "--port=$databasePort" --user=root --default-character-set=utf8mb4 `
            --abort-source-on-error --batch
        $databaseBootstrapExit = $LASTEXITCODE
    } finally {
        Pop-Location
        $ErrorActionPreference = $savedPreference
    }
    if ($databaseBootstrapExit -ne 0) {
        throw "MINIMUM_CLOSURE_DATABASE_BOOTSTRAP_FAILED: exit=$databaseBootstrapExit"
    }

    $serverEnvironment = @{
        APP_ENV = 'testing'
        APP_DEBUG = 'true'
        APP_URL = "http://127.0.0.1:$apiPort"
        DB_HOST = '127.0.0.1'
        DB_PORT = [string]$databasePort
        DB_NAME = 'yiyunying_closure'
        DB_USER = 'root'
        DB_PASSWORD = $databasePassword
        PASSWORD_MIN_LENGTH = '6'
        AI_ENABLED = 'false'
        MAIL_TRANSPORT = 'disabled'
        STT_PROVIDER = 'openai-compatible'
        STT_API_URL = ''
        STT_API_KEY = ''
        QR_SIGNING_KEY = (New-RandomHex)
        MEDIA_SIGNING_KEY = (New-RandomHex)
    }
    foreach ($entry in $serverEnvironment.GetEnumerator()) {
        [Environment]::SetEnvironmentVariable($entry.Key, [string]$entry.Value, 'Process')
    }

    Write-Host "[minimum-closure] start PHP API $apiPort"
    $phpProcess = Start-HiddenProcess `
        -FilePath $php `
        -Arguments @(
            '-d', "extension_dir=$phpRoot\ext",
            '-d', 'extension=php_pdo_mysql.dll',
            '-d', 'extension=php_curl.dll',
            '-d', 'extension=php_mbstring.dll',
            '-S', "127.0.0.1:$apiPort",
            '-t', (Join-Path $backendRoot 'public'),
            (Join-Path $backendRoot 'public\router.php')
        ) `
        -WorkingDirectory $backendRoot `
        -StdoutPath (Join-Path $logs 'php.stdout.log') `
        -StderrPath (Join-Path $logs 'php.stderr.log')

    $baseUrl = "http://127.0.0.1:$apiPort"
    $apiReady = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $health = Invoke-RestMethod -Uri "$baseUrl/api/health" -TimeoutSec 2
            if ($health.code -eq 1 -and $health.data.database -eq 'connected') {
                $apiReady = $true
                break
            }
        } catch { }
        if ($phpProcess.HasExited) {
            throw "MINIMUM_CLOSURE_PHP_EXITED: exit=$($phpProcess.ExitCode)"
        }
        Start-Sleep -Milliseconds 500
    }
    if (-not $apiReady) {
        throw 'MINIMUM_CLOSURE_PHP_TIMEOUT'
    }

    foreach ($suite in $Suites) {
        Invoke-SmokeSuite -Name $suite -BaseUrl $baseUrl
        $passed.Add($suite)
    }

    Write-Host "MINIMUM_CLOSURE_PASS=$($passed -join ',')"
} finally {
    if ($phpProcess -and -not $phpProcess.HasExited) {
        Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
        $phpProcess.WaitForExit(10000) | Out-Null
    }
    if ($databaseProcess -and -not $databaseProcess.HasExited) {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $databasePassword, 'Process')
        $savedPreference = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        try {
            & (Join-Path $mariaBin 'mysqladmin.exe') --protocol=tcp --host=127.0.0.1 `
                "--port=$databasePort" --user=root shutdown --silent 2>$null | Out-Null
        } finally {
            $ErrorActionPreference = $savedPreference
        }
        if (-not $databaseProcess.WaitForExit(15000)) {
            Stop-Process -Id $databaseProcess.Id -Force -ErrorAction SilentlyContinue
        }
    }
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
    }
    Write-Host "[minimum-closure] services stopped; evidence preserved at $stage"
}
