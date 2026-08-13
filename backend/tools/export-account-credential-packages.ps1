<#
.SYNOPSIS
Exports one fully DPAPI-encrypted credential JSON per authorized audience group.

.DESCRIPTION
The account index schema is:
  {"schemaVersion":1,"source":{"batchId":"...","packageSha256":"...",
   "ciphertextSha256":"...","planSha256":"..."},"accounts":[
   {"kind":"platform|admin|user","id":"...","loginAccount":"...",
    "level":"platform-owner|authorized-platform|admin|user","platform":"...",
    "appId":null,"adminId":null,"status":"active|disabled"}]}
Admin entries use their own id as adminId. User entries reference exactly one matching
platform/admin/app. The index itself contains account identifiers, so callers must keep it
ephemeral and apply equivalent private ACL handling in the producing workflow.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$SourcePackagePath,

    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$AccountIndexPath,

    [string]$OutputRoot = (Join-Path $env:LOCALAPPDATA 'YiyunyingDeploy\account-packages'),

    [switch]$AllowNonDefaultOutputRootForTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security

function Get-Sha256Hex {
    param([Parameter(Mandatory = $true)][byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Get-FileSha256Hex {
    param([Parameter(Mandatory = $true)][string]$Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Require-String {
    param([object]$Object, [string]$Name, [string]$Context)
    if ($null -eq $Object -or -not ($Object.PSObject.Properties.Name -contains $Name)) {
        throw "$Context is missing $Name."
    }
    $value = $Object.$Name
    if ($value -isnot [string] -or [string]::IsNullOrWhiteSpace($value)) {
        throw "$Context.$Name must be a non-empty string."
    }
    return $value
}

function Assert-ExactProperties {
    param([object]$Object, [string[]]$Allowed, [string]$Context)
    $unexpected = @($Object.PSObject.Properties.Name | Where-Object { $_ -notin $Allowed })
    if ($unexpected.Count -ne 0) { throw "$Context has unexpected fields: $($unexpected -join ', ')." }
}

function ConvertTo-KeyString {
    param([object]$Value, [string]$Context)
    if ($Value -is [string]) {
        if ([string]::IsNullOrWhiteSpace($Value)) { throw "$Context must not be empty." }
        return $Value
    }
    if ($Value -is [byte] -or $Value -is [int16] -or $Value -is [int32] -or $Value -is [int64] -or
        $Value -is [uint16] -or $Value -is [uint32] -or $Value -is [uint64]) {
        return [Convert]::ToString($Value, [Globalization.CultureInfo]::InvariantCulture)
    }
    throw "$Context must be a string or integer."
}

function Assert-SafeLabel {
    param([string]$Value, [string]$Context)
    if ($Value.Length -gt 128 -or $Value -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*$') {
        throw "$Context contains unsupported characters."
    }
}

function Set-PrivateAcl {
    param([Parameter(Mandatory = $true)][string]$Path)
    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
    $propagation = [Security.AccessControl.PropagationFlags]::None
    foreach ($sid in @($currentSid, $systemSid)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            $propagation,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Assert-PrivateAcl {
    param([Parameter(Mandatory = $true)][string]$Path)
    $allowed = @(
        [Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
        'S-1-5-18'
    )
    $acl = Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected) { throw "ACL inheritance is enabled on $Path." }
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if ($rules.Count -ne 2) { throw "Unexpected ACL rule count on $Path." }
    foreach ($rule in $rules) {
        if ($rule.IdentityReference.Value -notin $allowed -or
            $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
            -not (($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl)) {
            throw "Unexpected ACL rule on $Path."
        }
    }
}

function Assert-PrivateFileAcl {
    param([Parameter(Mandatory = $true)][string]$Path)
    $allowed = @(
        [Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
        'S-1-5-18'
    )
    $rules = @((Get-Acl -LiteralPath $Path).GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if ($rules.Count -eq 0) { throw "No ACL rules found on $Path." }
    foreach ($rule in $rules) {
        if ($rule.IdentityReference.Value -notin $allowed -or
            $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
            -not (($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl)) {
            throw "Unexpected ACL rule on $Path."
        }
    }
}

function Read-JsonObject {
    param([Parameter(Mandatory = $true)][string]$Path, [string]$Context)
    $raw = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $Path).Path, [Text.Encoding]::UTF8)
    try { $value = $raw | ConvertFrom-Json }
    catch { throw "$Context is not valid JSON: $($_.Exception.Message)" }
    if ($null -eq $value -or $value -is [array]) { throw "$Context must be a JSON object." }
    return $value
}

function New-EncryptedWrapper {
    param(
        [Parameter(Mandatory = $true)][object]$Payload,
        [Parameter(Mandatory = $true)][string]$Platform,
        [Parameter(Mandatory = $true)][int]$Count,
        [Parameter(Mandatory = $true)][string]$BatchId
    )
    $payloadBytes = [Text.UTF8Encoding]::new($false).GetBytes(($Payload | ConvertTo-Json -Depth 12 -Compress))
    $entropy = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($entropy) }
    finally { $rng.Dispose() }
    try {
        $ciphertext = [Security.Cryptography.ProtectedData]::Protect(
            $payloadBytes,
            $entropy,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
        try {
            return [ordered]@{
                schemaVersion    = 1
                platform         = $Platform
                count            = $Count
                batchId          = $BatchId
                protection       = 'Windows-DPAPI-CurrentUser'
                payloadSha256    = Get-Sha256Hex -Bytes $payloadBytes
                entropyBase64    = [Convert]::ToBase64String($entropy)
                ciphertextBase64 = [Convert]::ToBase64String($ciphertext)
                ciphertextSha256 = Get-Sha256Hex -Bytes $ciphertext
            }
        }
        finally { [Array]::Clear($ciphertext, 0, $ciphertext.Length) }
    }
    finally {
        [Array]::Clear($payloadBytes, 0, $payloadBytes.Length)
        [Array]::Clear($entropy, 0, $entropy.Length)
    }
}

function Read-And-VerifyWrapper {
    param([Parameter(Mandatory = $true)][string]$Path)
    $wrapper = Read-JsonObject -Path $Path -Context 'exported package'
    Assert-ExactProperties -Object $wrapper -Allowed @(
        'schemaVersion', 'platform', 'count', 'batchId', 'protection',
        'payloadSha256', 'entropyBase64', 'ciphertextBase64', 'ciphertextSha256'
    ) -Context 'exported package'
    if ($wrapper.schemaVersion -ne 1 -or $wrapper.protection -ne 'Windows-DPAPI-CurrentUser') { throw 'Invalid exported package schema or protection.' }
    $ciphertext = [Convert]::FromBase64String((Require-String $wrapper 'ciphertextBase64' 'exported package'))
    $entropy = [Convert]::FromBase64String((Require-String $wrapper 'entropyBase64' 'exported package'))
    try {
        if ((Get-Sha256Hex $ciphertext) -ne (Require-String $wrapper 'ciphertextSha256' 'exported package')) {
            throw 'Exported package ciphertext hash mismatch.'
        }
        $plain = [Security.Cryptography.ProtectedData]::Unprotect($ciphertext, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        try {
            if ((Get-Sha256Hex $plain) -ne (Require-String $wrapper 'payloadSha256' 'exported package')) {
                throw 'Exported package payload hash mismatch.'
            }
            $payload = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json
            if ($payload.batchId -ne $wrapper.batchId -or $payload.platform -ne $wrapper.platform -or
                @($payload.accounts).Count -ne [int]$wrapper.count) {
                throw 'Exported package metadata does not match its encrypted payload.'
            }
            return $payload
        }
        finally { [Array]::Clear($plain, 0, $plain.Length) }
    }
    finally {
        [Array]::Clear($ciphertext, 0, $ciphertext.Length)
        [Array]::Clear($entropy, 0, $entropy.Length)
    }
}

$sourcePath = (Resolve-Path -LiteralPath $SourcePackagePath).Path
$indexPath = (Resolve-Path -LiteralPath $AccountIndexPath).Path
$sourceWrapper = Read-JsonObject -Path $sourcePath -Context 'source package'
Assert-ExactProperties -Object $sourceWrapper -Allowed @(
    'schemaVersion', 'batchId', 'createdAtUtc', 'protection', 'entropyBase64', 'ciphertextBase64',
    'ciphertextSha256', 'planSha256', 'counts', 'status'
) -Context 'source package'
if ($sourceWrapper.schemaVersion -ne 1 -or $sourceWrapper.protection -ne 'Windows-DPAPI-CurrentUser' -or $sourceWrapper.status -ne 'prepared') {
    throw 'Source package schema/protection is unsupported.'
}
$sourceBatch = Require-String $sourceWrapper 'batchId' 'source package'
Assert-SafeLabel $sourceBatch 'source package batchId'
$sourceCiphertext = [Convert]::FromBase64String((Require-String $sourceWrapper 'ciphertextBase64' 'source package'))
$sourceEntropy = [Convert]::FromBase64String((Require-String $sourceWrapper 'entropyBase64' 'source package'))
try {
    if ((Get-Sha256Hex $sourceCiphertext) -ne (Require-String $sourceWrapper 'ciphertextSha256' 'source package')) {
        throw 'Source package ciphertext hash mismatch.'
    }
    $sourcePlain = [Security.Cryptography.ProtectedData]::Unprotect(
        $sourceCiphertext,
        $sourceEntropy,
        [Security.Cryptography.DataProtectionScope]::CurrentUser
    )
    try { $source = [Text.Encoding]::UTF8.GetString($sourcePlain) | ConvertFrom-Json }
    finally { [Array]::Clear($sourcePlain, 0, $sourcePlain.Length) }
}
finally {
    [Array]::Clear($sourceCiphertext, 0, $sourceCiphertext.Length)
    [Array]::Clear($sourceEntropy, 0, $sourceEntropy.Length)
}
Assert-ExactProperties -Object $source -Allowed @('schemaVersion', 'batchId', 'createdAtUtc', 'planSha256', 'accounts', 'apps') -Context 'source payload'
if ($source.schemaVersion -ne 1 -or $source.batchId -ne $sourceBatch -or $source.planSha256 -ne $sourceWrapper.planSha256) {
    throw 'Source package wrapper/payload identity mismatch.'
}
Assert-ExactProperties -Object $sourceWrapper.counts -Allowed @('platforms', 'admins', 'users', 'apps') -Context 'source package counts'
$actualSourceCounts = @{
    platforms = @($source.accounts | Where-Object kind -eq 'platform').Count
    admins = @($source.accounts | Where-Object kind -eq 'admin').Count
    users = @($source.accounts | Where-Object kind -eq 'user').Count
    apps = @($source.apps).Count
}
foreach ($countName in @('platforms', 'admins', 'users', 'apps')) {
    if ($sourceWrapper.counts.$countName -isnot [int] -or [int]$sourceWrapper.counts.$countName -ne $actualSourceCounts[$countName]) {
        throw "Source package count mismatch for $countName."
    }
}

$index = Read-JsonObject -Path $indexPath -Context 'account index'
Assert-ExactProperties -Object $index -Allowed @('schemaVersion', 'source', 'accounts') -Context 'account index'
if ($index.schemaVersion -ne 1) { throw 'Unsupported account index schema.' }
Assert-ExactProperties -Object $index.source -Allowed @('batchId', 'packageSha256', 'ciphertextSha256', 'planSha256') -Context 'account index source'
if ($index.source.batchId -ne $sourceBatch -or
    $index.source.packageSha256 -ne (Get-FileSha256Hex $sourcePath) -or
    $index.source.ciphertextSha256 -ne $sourceWrapper.ciphertextSha256 -or
    $index.source.planSha256 -ne $sourceWrapper.planSha256) {
    throw 'Account index is not bound to this exact source package/batch/plan.'
}

$sourceAccounts = @($source.accounts)
$indexAccounts = @($index.accounts)
if ($sourceAccounts.Count -eq 0 -or $sourceAccounts.Count -ne $indexAccounts.Count) {
    throw 'Account index count does not exactly match the source package.'
}
$sourceByKey = @{}
$passwordSet = @{}
foreach ($account in $sourceAccounts) {
    Assert-ExactProperties -Object $account -Allowed @('kind', 'id', 'password', 'disabled') -Context 'source account'
    $kind = Require-String $account 'kind' 'source account'
    if ($kind -notin @('platform', 'admin', 'user')) { throw "Unsupported source account kind: $kind." }
    $id = ConvertTo-KeyString $account.id 'source account id'
    if ($account.disabled -isnot [bool]) { throw "Source account $kind/$id has a non-boolean disabled flag." }
    $password = Require-String $account 'password' "source account $kind/$id"
    $key = "$kind`n$id"
    if ($sourceByKey.ContainsKey($key)) { throw "Duplicate source account kind/id: $kind/$id." }
    if ($passwordSet.ContainsKey($password)) { throw 'Source account passwords are not globally unique.' }
    $sourceByKey[$key] = $account
    $passwordSet[$password] = $true
}

$mapped = @()
$indexKeys = @{}
$loginAccounts = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach ($entry in $indexAccounts) {
    Assert-ExactProperties -Object $entry -Allowed @('kind', 'id', 'loginAccount', 'level', 'platform', 'appId', 'adminId', 'status') -Context 'account index entry'
    $kind = Require-String $entry 'kind' 'account index entry'
    $id = ConvertTo-KeyString $entry.id 'account index id'
    $key = "$kind`n$id"
    if (-not $sourceByKey.ContainsKey($key) -or $indexKeys.ContainsKey($key)) {
        throw "Account index has an unknown or duplicate kind/id: $kind/$id."
    }
    $indexKeys[$key] = $true
    $sourceAccount = $sourceByKey[$key]
    $loginAccount = Require-String $entry 'loginAccount' "account index $kind/$id"
    if (-not $loginAccounts.Add($loginAccount)) { throw "Duplicate loginAccount in account index: $loginAccount." }
    $level = Require-String $entry 'level' "account index $kind/$id"
    $platform = Require-String $entry 'platform' "account index $kind/$id"
    Assert-SafeLabel $platform "account index $kind/$id platform"
    $status = Require-String $entry 'status' "account index $kind/$id"
    $expectedStatus = if ($sourceAccount.disabled) { 'disabled' } else { 'active' }
    if ($status -ne $expectedStatus) { throw "Account index status drift for $kind/$id." }
    $appId = if ($null -eq $entry.appId) { $null } else { ConvertTo-KeyString $entry.appId "account index $kind/$id appId" }
    $adminId = if ($null -eq $entry.adminId) { $null } else { ConvertTo-KeyString $entry.adminId "account index $kind/$id adminId" }
    $packageClass = switch ("$kind/$level") {
        'platform/platform-owner' { if ($null -ne $appId -or $null -ne $adminId) { throw 'Platform owner must not specify appId/adminId.' }; 'platform-owner' }
        'platform/authorized-platform' { if ($null -ne $appId -or $null -ne $adminId) { throw 'Authorized platform must not specify appId/adminId.' }; 'authorized-platform' }
        'admin/admin' { if ($null -eq $appId -or $null -eq $adminId -or $adminId -ne $id) { throw "Admin $id must specify its appId and use its own id as adminId." }; 'admin' }
        'user/user' { if ($null -eq $appId -or $null -eq $adminId) { throw "User $id must specify appId/adminId." }; 'user' }
        default { throw "Invalid kind/level combination for $kind/$id." }
    }
    $mapped += [pscustomobject][ordered]@{
        kind = $kind; id = $id; loginAccount = $loginAccount; level = $level; platform = $platform
        appId = $appId; adminId = $adminId; status = $status; packageClass = $packageClass
        password = $sourceAccount.password
    }
}
if ($indexKeys.Count -ne $sourceByKey.Count) { throw 'Account index does not cover every source account exactly once.' }

$appSecrets = @{}
foreach ($app in @($source.apps)) {
    Assert-ExactProperties -Object $app -Allowed @('id', 'secret') -Context 'source app'
    $appId = ConvertTo-KeyString $app.id 'source app id'
    if ($appSecrets.ContainsKey($appId)) { throw "Duplicate source app id: $appId." }
    $appSecrets[$appId] = Require-String $app 'secret' "source app $appId"
}
$admins = @($mapped | Where-Object packageClass -eq 'admin')
foreach ($admin in $admins) {
    if (-not $appSecrets.ContainsKey($admin.appId)) { throw "Admin $($admin.id) references an app absent from the source package." }
}
foreach ($user in @($mapped | Where-Object packageClass -eq 'user')) {
    $matchingAdmin = @($admins | Where-Object { $_.id -eq $user.adminId -and $_.platform -eq $user.platform })
    if ($matchingAdmin.Count -ne 1) { throw "User $($user.id) does not reference exactly one matching platform admin." }
}

$groups = @()
foreach ($class in @('platform-owner', 'authorized-platform')) {
    foreach ($group in @($mapped | Where-Object packageClass -eq $class | Group-Object platform)) {
        $groups += [pscustomobject]@{ Class = $class; Platform = $group.Name; AppId = $null; AdminId = $null; Accounts = @($group.Group) }
    }
}
foreach ($admin in $admins) {
    $groups += [pscustomobject]@{ Class = 'admin'; Platform = $admin.platform; AppId = $admin.appId; AdminId = $admin.id; Accounts = @($admin) }
}
foreach ($group in @($mapped | Where-Object packageClass -eq 'user' | Group-Object { "$($_.platform)`n$($_.adminId)`n$($_.appId)" })) {
    $first = $group.Group[0]
    $groups += [pscustomobject]@{ Class = 'user'; Platform = $first.platform; AppId = $first.appId; AdminId = $first.adminId; Accounts = @($group.Group) }
}
if ($groups.Count -eq 0) { throw 'No credential packages would be generated.' }

$outputRootFull = [IO.Path]::GetFullPath($OutputRoot)
$defaultOutputRoot = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'YiyunyingDeploy\account-packages'))
if (-not $AllowNonDefaultOutputRootForTest -and -not $outputRootFull.Equals($defaultOutputRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw "OutputRoot must be the protected LocalAppData account-packages directory. The override is test-only."
}
if (-not (Test-Path -LiteralPath $outputRootFull)) { [void](New-Item -ItemType Directory -Path $outputRootFull) }
$rootItem = Get-Item -LiteralPath $outputRootFull -Force
if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'OutputRoot must not be a reparse point.' }
$finalDirectory = Join-Path $outputRootFull $sourceBatch
if (Test-Path -LiteralPath $finalDirectory) { throw "Output batch directory already exists: $finalDirectory" }
$partialDirectory = Join-Path $outputRootFull (".{0}.{1}.partial" -f $sourceBatch, [Guid]::NewGuid().ToString('N'))
[void](New-Item -ItemType Directory -Path $partialDirectory)
Set-PrivateAcl $partialDirectory

try {
    $allVerifiedPasswords = @{}
    foreach ($group in $groups) {
        $accountPayload = @($group.Accounts | ForEach-Object {
            [ordered]@{
                kind = $_.kind; id = $_.id; loginAccount = $_.loginAccount; level = $_.level
                platform = $_.platform; appId = $_.appId; adminId = $_.adminId; status = $_.status; password = $_.password
            }
        })
        $payload = [ordered]@{
            schemaVersion = 1
            batchId = $sourceBatch
            platform = $group.Platform
            packageClass = $group.Class
            appId = $group.AppId
            adminId = $group.AdminId
            accounts = $accountPayload
            appSecret = if ($group.Class -eq 'admin') { $appSecrets[$group.AppId] } else { $null }
        }
        $wrapper = New-EncryptedWrapper -Payload $payload -Platform $group.Platform -Count $accountPayload.Count -BatchId $sourceBatch
        $groupKey = "$($group.Class)`n$($group.Platform)`n$($group.AdminId)`n$($group.AppId)"
        $fileHash = Get-Sha256Hex ([Text.Encoding]::UTF8.GetBytes($groupKey))
        $fileName = "$($group.Class)-$($fileHash.Substring(0, 20)).dpapi.json"
        $target = Join-Path $partialDirectory $fileName
        [IO.File]::WriteAllText($target, ($wrapper | ConvertTo-Json -Depth 6), [Text.UTF8Encoding]::new($false))
        Assert-PrivateFileAcl $target
        $verified = Read-And-VerifyWrapper $target
        foreach ($verifiedAccount in @($verified.accounts)) {
            if ($allVerifiedPasswords.ContainsKey($verifiedAccount.password)) { throw 'Exported package password uniqueness check failed.' }
            $allVerifiedPasswords[$verifiedAccount.password] = $true
        }
        if ($group.Class -eq 'admin') {
            if ($verified.appSecret -ne $appSecrets[$group.AppId]) { throw 'Admin package app secret read-back failed.' }
        }
        elseif ($null -ne $verified.appSecret) { throw 'A non-admin package contains an app secret.' }
    }
    if ($allVerifiedPasswords.Count -ne $sourceAccounts.Count) { throw 'Exported package account count read-back failed.' }
    Assert-PrivateAcl $partialDirectory
    $partialFiles = @(Get-ChildItem -LiteralPath $partialDirectory -Filter '*.dpapi.json' -File)
    if ($partialFiles.Count -ne $groups.Count) { throw 'Atomic output read-back file count failed.' }
    Move-Item -LiteralPath $partialDirectory -Destination $finalDirectory
    [pscustomobject]@{
        schemaVersion = 1
        batchId = $sourceBatch
        outputDirectory = $finalDirectory
        packageCount = $groups.Count
        accountCount = $sourceAccounts.Count
        status = 'verified-dpapi-current-user'
    } | ConvertTo-Json -Depth 3
}
catch {
    if (Test-Path -LiteralPath $partialDirectory) { Remove-Item -LiteralPath $partialDirectory -Recurse -Force }
    throw
}
