<#
.SYNOPSIS
Creates the private desktop credential folder from DPAPI account packages.

.DESCRIPTION
The source packages are decrypted only in this process. Plaintext credentials are
written solely below the requested private desktop folder because the user asked
for editable JSON. Console output contains counts and paths, never account names,
passwords, application IDs, or application secrets.
#>
[CmdletBinding()]
param(
    [string]$PackageDirectory = (Join-Path $env:LOCALAPPDATA 'YiyunyingDeploy\account-packages\credential-remediation-20260813T080532Z-edcd6f485c13'),
    [string]$OutputDirectory,
    [switch]$AllowNonDefaultOutputForTest,
    [switch]$ReplaceExisting
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security

function Get-Sha256Hex([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function ConvertFrom-CodePoints([int[]]$CodePoints) {
    return -join @($CodePoints | ForEach-Object { [char]$_ })
}

function Get-Sha256Text([string]$Text) {
    $bytes = [Text.UTF8Encoding]::new($false).GetBytes($Text)
    try { return Get-Sha256Hex $bytes }
    finally { [Array]::Clear($bytes, 0, $bytes.Length) }
}

function Assert-NotReparse([string]$Path, [string]$Context) {
    if ((Get-Item -LiteralPath $Path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw "$Context must not be a reparse point."
    }
}

function Set-PrivateAcl([string]$Path) {
    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
    foreach ($sid in @($currentSid, $systemSid)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Assert-PrivateAcl([string]$Path) {
    $allowed = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18')
    $acl = Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected) { throw "ACL inheritance is enabled on $Path." }
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if ($rules.Count -ne 2) { throw "Unexpected ACL rule count on $Path." }
    foreach ($rule in $rules) {
        if ($rule.IdentityReference.Value -notin $allowed -or
            $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
            (($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl)) {
            throw "Unexpected ACL rule on $Path."
        }
    }
}

function Assert-ExactProperties([object]$Object, [string[]]$Allowed, [string]$Context) {
    $unexpected = @($Object.PSObject.Properties.Name | Where-Object { $_ -notin $Allowed })
    if ($unexpected.Count -ne 0) { throw "$Context has unsupported fields." }
}

function Read-Package([string]$Path) {
    Assert-NotReparse $Path 'Package file'
    try { $wrapper = [IO.File]::ReadAllText($Path, [Text.Encoding]::UTF8) | ConvertFrom-Json }
    catch { throw 'A package is not valid JSON.' }
    Assert-ExactProperties $wrapper @('schemaVersion','platform','count','batchId','protection','payloadSha256','entropyBase64','ciphertextBase64','ciphertextSha256') 'Package wrapper'
    if ($wrapper.schemaVersion -ne 1 -or $wrapper.protection -ne 'Windows-DPAPI-CurrentUser') { throw 'Unsupported package wrapper.' }
    $ciphertext = [Convert]::FromBase64String([string]$wrapper.ciphertextBase64)
    $entropy = [Convert]::FromBase64String([string]$wrapper.entropyBase64)
    try {
        if ((Get-Sha256Hex $ciphertext) -ne [string]$wrapper.ciphertextSha256) { throw 'Package ciphertext hash mismatch.' }
        $plain = [Security.Cryptography.ProtectedData]::Unprotect($ciphertext, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        try {
            if ((Get-Sha256Hex $plain) -ne [string]$wrapper.payloadSha256) { throw 'Package payload hash mismatch.' }
            $payload = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json
            Assert-ExactProperties $payload @('schemaVersion','batchId','platform','packageClass','appId','adminId','accounts','appSecret') 'Package payload'
            if ($payload.schemaVersion -ne 1 -or $payload.batchId -ne $wrapper.batchId -or $payload.platform -ne $wrapper.platform -or @($payload.accounts).Count -ne [int]$wrapper.count) {
                throw 'Package wrapper/payload identity mismatch.'
            }
            if ([string]$payload.packageClass -notin @('platform-owner','authorized-platform','admin','user')) { throw 'Unsupported package class.' }
            return $payload
        }
        finally { [Array]::Clear($plain, 0, $plain.Length) }
    }
    finally {
        [Array]::Clear($ciphertext, 0, $ciphertext.Length)
        [Array]::Clear($entropy, 0, $entropy.Length)
    }
}

function Write-Utf8Atomic([string]$Path, [string]$Content) {
    $partial = "$Path.$([Guid]::NewGuid().ToString('N')).partial"
    try {
        [IO.File]::WriteAllText($partial, $Content, [Text.UTF8Encoding]::new($false))
        Move-Item -LiteralPath $partial -Destination $Path
    }
    finally { if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial -Force } }
}

$folderName = ConvertFrom-CodePoints @(0x6613,0x8fd0,0x76c8,0x5e73,0x53f0,0x8d26,0x53f7,0x5bc6,0x7801)
$stateName = (ConvertFrom-CodePoints @(0x8d26,0x53f7,0x603b,0x8868)) + '.json'
$adminPageName = (ConvertFrom-CodePoints @(0x8d26,0x53f7,0x7ba1,0x7406)) + '.html'
$testPageName = (ConvertFrom-CodePoints @(0x6d4b,0x8bd5,0x8d26,0x53f7)) + '.html'
$openAdminName = (ConvertFrom-CodePoints @(0x6253,0x5f00,0x8d26,0x53f7,0x7ba1,0x7406)) + '.cmd'
$openTestName = (ConvertFrom-CodePoints @(0x6253,0x5f00,0x6d4b,0x8bd5,0x8d26,0x53f7)) + '.cmd'
$readmeName = (ConvertFrom-CodePoints @(0x4f7f,0x7528,0x8bf4,0x660e)) + '.md'
$platformConsoleLabel = ConvertFrom-CodePoints @(0x5e73,0x53f0,0x603b,0x63a7,0x5236,0x7aef)
$packagePath = (Resolve-Path -LiteralPath $PackageDirectory).Path
if (-not (Test-Path -LiteralPath $packagePath -PathType Container)) { throw 'Package directory is missing.' }
Assert-NotReparse $packagePath 'Package directory'
$packageFiles = @(Get-ChildItem -LiteralPath $packagePath -File -Filter '*.json' | Sort-Object Name)
if ($packageFiles.Count -eq 0) { throw 'No credential packages were found.' }

$defaultOutput = Join-Path ([Environment]::GetFolderPath('Desktop')) $folderName
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) { $OutputDirectory = $defaultOutput }
$fullOutput = [IO.Path]::GetFullPath($OutputDirectory)
if (-not $AllowNonDefaultOutputForTest -and $fullOutput -ne [IO.Path]::GetFullPath($defaultOutput)) {
    throw 'Non-default output requires -AllowNonDefaultOutputForTest.'
}
$outputParent = Split-Path -Parent $fullOutput
if (-not (Test-Path -LiteralPath $outputParent -PathType Container)) { throw 'Output parent is missing.' }
Assert-NotReparse $outputParent 'Output parent'
if (Test-Path -LiteralPath $fullOutput) {
    Assert-NotReparse $fullOutput 'Existing output directory'
    if (-not $ReplaceExisting) { throw 'Output directory already exists; use -ReplaceExisting for an explicit replace.' }
}

$now = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
$accounts = [Collections.Generic.List[object]]::new()
$recordIds = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
$batchIds = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
$activeCount = 0
$disabledCount = 0
foreach ($file in $packageFiles) {
    $payload = Read-Package $file.FullName
    [void]$batchIds.Add([string]$payload.batchId)
    foreach ($sourceAccount in @($payload.accounts)) {
        Assert-ExactProperties $sourceAccount @('kind','id','loginAccount','level','platform','appId','adminId','status','password') 'Package account'
        $status = ([string]$sourceAccount.status).ToLowerInvariant()
        if ($status -notin @('active','disabled','inactive')) { throw 'Unsupported account status.' }
        $key = @([string]$sourceAccount.platform,[string]$payload.packageClass,[string]$sourceAccount.id,[string]$sourceAccount.appId,[string]$sourceAccount.loginAccount) -join "`n"
        $recordId = Get-Sha256Text $key
        if (-not $recordIds.Add($recordId)) { throw 'Duplicate account identity found across packages.' }
        $software = if ($null -ne $sourceAccount.appId -and -not [string]::IsNullOrWhiteSpace([string]$sourceAccount.appId)) { [string]$sourceAccount.appId } else { $platformConsoleLabel }
        $account = [ordered]@{
            recordId = $recordId
            platform = [string]$sourceAccount.platform
            software = $software
            role = [string]$sourceAccount.level
            packageClass = [string]$payload.packageClass
            accountId = [string]$sourceAccount.id
            loginAccount = [string]$sourceAccount.loginAccount
            password = [string]$sourceAccount.password
            appId = if ($null -eq $sourceAccount.appId) { $null } else { [string]$sourceAccount.appId }
            adminId = if ($null -eq $sourceAccount.adminId) { $null } else { [string]$sourceAccount.adminId }
            appSecret = if ($payload.packageClass -eq 'admin' -and $null -ne $payload.appSecret) { [string]$payload.appSecret } else { $null }
            status = $status
            environment = 'unknown'
            canLogin = ($status -eq 'active')
            loginEvidence = 'source-status-only-not-live-verified'
            deleted = $false
            notes = ''
            createdAtUtc = $now
            updatedAtUtc = $now
        }
        if ($status -eq 'active') { $activeCount++ } else { $disabledCount++ }
        $accounts.Add([pscustomobject]$account)
    }
    $payload = $null
}

$stage = Join-Path $outputParent ('.credential-console.' + [Guid]::NewGuid().ToString('N') + '.partial')
$rollback = $null
try {
    [void](New-Item -ItemType Directory -Path $stage)
    Set-PrivateAcl $stage
    foreach ($directory in @('JSON','Backups')) { [void](New-Item -ItemType Directory -Path (Join-Path $stage $directory)) }
    $state = [ordered]@{
        schemaVersion = 1
        title = $folderName
        revision = 1
        createdAtUtc = $now
        updatedAtUtc = $now
        source = [ordered]@{
            packageDirectory = $packagePath
            packageCount = $packageFiles.Count
            payloadCount = $accounts.Count
            batchIds = @($batchIds | Sort-Object)
            exportedAtUtc = $now
        }
        accounts = @($accounts)
    }
    Write-Utf8Atomic (Join-Path $stage $stateName) (($state | ConvertTo-Json -Depth 12) + "`n")
    $toolsRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
    Copy-Item -LiteralPath (Join-Path $toolsRoot 'credential-console-server.py') -Destination (Join-Path $stage 'credential-console-server.py')
    Copy-Item -LiteralPath (Join-Path $toolsRoot 'credential-console.js') -Destination (Join-Path $stage 'credential-console.js')
    Copy-Item -LiteralPath (Join-Path $toolsRoot 'credential-console.html') -Destination (Join-Path $stage $adminPageName)
    Copy-Item -LiteralPath (Join-Path $toolsRoot 'credential-console-tests.html') -Destination (Join-Path $stage $testPageName)
    $launcherAll = @'
@echo off
setlocal
where python >nul 2>nul || (echo Python is required.& pause & exit /b 1)
python "%~dp0credential-console-server.py" --root "%~dp0" --view all
endlocal
'@
    $launcherTests = @'
@echo off
setlocal
where python >nul 2>nul || (echo Python is required.& pause & exit /b 1)
python "%~dp0credential-console-server.py" --root "%~dp0" --view test
endlocal
'@
    Write-Utf8Atomic (Join-Path $stage $openAdminName) $launcherAll
    Write-Utf8Atomic (Join-Path $stage $openTestName) $launcherTests
    Copy-Item -LiteralPath (Join-Path $toolsRoot 'credential-console-readme.md') -Destination (Join-Path $stage $readmeName)
    & python (Join-Path $stage 'credential-console-server.py') --root $stage --rebuild-only | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Credential JSON derivation failed.' }
    Assert-PrivateAcl $stage
    if (Test-Path -LiteralPath $fullOutput) {
        $rollback = "$fullOutput.rollback-$([DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'))"
        Move-Item -LiteralPath $fullOutput -Destination $rollback
    }
    try { Move-Item -LiteralPath $stage -Destination $fullOutput }
    catch {
        if ($null -ne $rollback -and (Test-Path -LiteralPath $rollback) -and -not (Test-Path -LiteralPath $fullOutput)) {
            Move-Item -LiteralPath $rollback -Destination $fullOutput
        }
        throw
    }
    if ($null -ne $rollback -and (Test-Path -LiteralPath $rollback)) { Remove-Item -LiteralPath $rollback -Recurse -Force }
    Assert-PrivateAcl $fullOutput
    $jsonCount = @(Get-ChildItem -LiteralPath (Join-Path $fullOutput 'JSON') -Recurse -File -Filter '*.json').Count
    [pscustomobject][ordered]@{
        status = 'created-private-desktop-credential-console'
        outputDirectory = $fullOutput
        packageCount = $packageFiles.Count
        accountCount = $accounts.Count
        activeSourceStatusCount = $activeCount
        disabledOrInactiveCount = $disabledCount
        initiallyMarkedTestCount = 0
        initiallyMarkedProductionCount = 0
        initiallyUnknownEnvironmentCount = $activeCount
        derivedJsonFileCount = $jsonCount
        secretsPrinted = $false
        acl = 'CurrentUser+SYSTEM-only'
    } | ConvertTo-Json -Depth 3
}
finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
