[CmdletBinding()]
param(
    [string]$Path = (Join-Path $env:LOCALAPPDATA 'YiyunyingDeploy\internal-download-signing-secret.dpapi.json'),
    [switch]$Reveal
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security

function Get-Sha256Hex([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Set-PrivateFileAcl([string]$Target) {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $system = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $acl = [Security.AccessControl.FileSecurity]::new()
    $acl.SetOwner($identity.User)
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in @($identity.User, $system)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    Set-Acl -LiteralPath $Target -AclObject $acl
}

$fullPath = [IO.Path]::GetFullPath($Path)
$parent = Split-Path -Parent $fullPath
if ([string]::IsNullOrWhiteSpace($parent)) { throw 'A parent directory is required.' }
if (-not (Test-Path -LiteralPath $parent)) { [void](New-Item -ItemType Directory -Path $parent) }
$parentItem = Get-Item -LiteralPath $parent -Force
if (($parentItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'The secret directory must not be a reparse point.' }

if (-not (Test-Path -LiteralPath $fullPath)) {
    $secretBytes = [byte[]]::new(32)
    $entropy = [byte[]]::new(32)
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($secretBytes)
        $rng.GetBytes($entropy)
        $secret = ([BitConverter]::ToString($secretBytes)).Replace('-', '').ToLowerInvariant()
        $plain = [Text.Encoding]::ASCII.GetBytes($secret)
        try {
            $ciphertext = [Security.Cryptography.ProtectedData]::Protect($plain, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
            try {
                $wrapper = [ordered]@{
                    schemaVersion = 1
                    createdAtUtc = [DateTimeOffset]::UtcNow.ToString('o')
                    protection = 'DPAPI-CurrentUser'
                    secretSha256 = Get-Sha256Hex $plain
                    entropyBase64 = [Convert]::ToBase64String($entropy)
                    ciphertextBase64 = [Convert]::ToBase64String($ciphertext)
                    ciphertextSha256 = Get-Sha256Hex $ciphertext
                }
                $partial = "$fullPath.$([Guid]::NewGuid().ToString('N')).partial"
                try {
                    [IO.File]::WriteAllText($partial, ($wrapper | ConvertTo-Json -Depth 3) + "`n", [Text.UTF8Encoding]::new($false))
                    Set-PrivateFileAcl $partial
                    if (Test-Path -LiteralPath $fullPath) { throw 'The secret appeared concurrently; refusing to overwrite it.' }
                    Move-Item -LiteralPath $partial -Destination $fullPath
                }
                finally {
                    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial -Force }
                }
            }
            finally { [Array]::Clear($ciphertext, 0, $ciphertext.Length) }
        }
        finally { [Array]::Clear($plain, 0, $plain.Length) }
    }
    finally {
        $rng.Dispose()
        [Array]::Clear($secretBytes, 0, $secretBytes.Length)
        [Array]::Clear($entropy, 0, $entropy.Length)
    }
}

$secretItem = Get-Item -LiteralPath $fullPath -Force
if (($secretItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'The signing-secret package must not be a reparse point.' }
$raw = [IO.File]::ReadAllText($fullPath, [Text.Encoding]::UTF8)
try { $stored = $raw | ConvertFrom-Json }
catch { throw 'The signing-secret package is not valid JSON.' }
$allowed = @('schemaVersion', 'createdAtUtc', 'protection', 'secretSha256', 'entropyBase64', 'ciphertextBase64', 'ciphertextSha256')
if (@($stored.PSObject.Properties.Name | Where-Object { $_ -notin $allowed }).Count -ne 0 -or $stored.schemaVersion -ne 1 -or $stored.protection -ne 'DPAPI-CurrentUser') {
    throw 'The signing-secret package schema is unsupported.'
}
$cipher = [Convert]::FromBase64String($stored.ciphertextBase64)
$entropyRead = [Convert]::FromBase64String($stored.entropyBase64)
try {
    if ((Get-Sha256Hex $cipher) -ne $stored.ciphertextSha256) { throw 'Signing-secret ciphertext hash mismatch.' }
    $plainRead = [Security.Cryptography.ProtectedData]::Unprotect($cipher, $entropyRead, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    try {
        $value = [Text.Encoding]::ASCII.GetString($plainRead)
        if ($value -notmatch '^[0-9a-f]{64}$' -or (Get-Sha256Hex $plainRead) -ne $stored.secretSha256) { throw 'Signing-secret payload validation failed.' }
        $acl = Get-Acl -LiteralPath $fullPath
        $expectedSids = @(
            [Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
            'S-1-5-18'
        ) | Sort-Object -Unique
        $actualRules = @($acl.Access | Where-Object {
            $_.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow -and
            ($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl
        })
        $actualSids = @($actualRules | ForEach-Object {
            $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
        } | Sort-Object -Unique)
        if (-not $acl.AreAccessRulesProtected -or @($acl.Access).Count -ne 2 -or
            @(Compare-Object -ReferenceObject $expectedSids -DifferenceObject $actualSids).Count -ne 0) {
            throw 'Signing-secret ACL is not private.'
        }
        if ($Reveal) { Write-Output $value }
        else { [ordered]@{ schemaVersion = 1; protection = 'DPAPI-CurrentUser'; secretSha256 = $stored.secretSha256; status = 'verified' } | ConvertTo-Json }
    }
    finally { [Array]::Clear($plainRead, 0, $plainRead.Length) }
}
finally {
    [Array]::Clear($cipher, 0, $cipher.Length)
    [Array]::Clear($entropyRead, 0, $entropyRead.Length)
}
