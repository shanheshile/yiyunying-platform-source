[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$CredentialFile,
    [Parameter(Mandatory = $true)][string]$KnownHosts,
    [Parameter(Mandatory = $true)][string]$Bundle,
    [Parameter(Mandatory = $true)][string]$BundleSha256,
    [Parameter(Mandatory = $true)][string]$Python,
    [string]$ReleaseToolsPath = (Join-Path (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))) '.tools_deps'),
    [switch]$Execute,
    [string]$Confirm = '',
    [string]$MaintenanceConfirmed = '',
    [string]$ConfirmManifestSha = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-Sha256Hex([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $sha.Dispose()
    }
}

function Resolve-UniqueRegularFile([string]$Path, [string]$Label) {
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.Length -lt 1) {
        throw "$Label must be a non-empty regular non-reparse file."
    }
    return $item.FullName
}

function Resolve-RealDirectory([string]$Path, [string]$Label) {
    $item = Get-Item -LiteralPath $Path -Force
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "$Label must be a real non-reparse directory."
    }
    return $item.FullName
}

if ($BundleSha256 -cnotmatch '^[0-9a-f]{64}$') {
    throw 'BundleSha256 must be exactly 64 lowercase hexadecimal characters.'
}
if ($Execute) {
    if ($Confirm -cne 'install-offline-stt-cpython-3.11.15-faster-whisper-1.2.1' -or
        $MaintenanceConfirmed -cne 'stt-current-switch-and-rollback-reviewed' -or
        $ConfirmManifestSha -cnotmatch '^[0-9a-f]{64}$') {
        throw 'Execute requires both exact acknowledgements and the verified source manifest SHA-256.'
    }
}
elseif ($Confirm -ne '' -or $MaintenanceConfirmed -ne '' -or $ConfirmManifestSha -ne '') {
    throw 'Confirmation values are only accepted together with -Execute.'
}

$credentialPath = Resolve-UniqueRegularFile $CredentialFile 'CredentialFile'
$knownHostsPath = Resolve-UniqueRegularFile $KnownHosts 'KnownHosts'
$bundlePath = Resolve-UniqueRegularFile $Bundle 'Bundle'
$pythonPath = Resolve-UniqueRegularFile $Python 'Python'
$releaseTools = Resolve-RealDirectory $ReleaseToolsPath 'ReleaseToolsPath'
$installerPath = Resolve-UniqueRegularFile (Join-Path $PSScriptRoot 'install-production-stt-runtime.py') 'STT installer'

$entropy = $null
$ciphertext = $null
$plain = $null
$password = $null
try {
    $env:PYTHONPATH = $releaseTools
    $paramikoVersion = & $pythonPath -c "import importlib.metadata as m,paramiko; print(m.version('paramiko'))"
    if ($LASTEXITCODE -ne 0 -or [string]$paramikoVersion -cne '5.0.0') {
        throw 'ReleaseToolsPath must expose the reviewed Paramiko 5.0.0 environment.'
    }
    $wrapper = Get-Content -LiteralPath $credentialPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $allowedWrapper = @('schemaVersion', 'purpose', 'protection', 'entropyBase64', 'ciphertextBase64', 'ciphertextSha256', 'payloadSha256')
    if (@($wrapper.PSObject.Properties.Name | Where-Object { $_ -notin $allowedWrapper }).Count -ne 0 -or
        $wrapper.schemaVersion -ne 1 -or
        $wrapper.purpose -cne 'yiyunying-production-ssh' -or
        $wrapper.protection -cne 'Windows-DPAPI-CurrentUser' -or
        [string]$wrapper.ciphertextSha256 -cnotmatch '^[0-9a-f]{64}$' -or
        [string]$wrapper.payloadSha256 -cnotmatch '^[0-9a-f]{64}$') {
        throw 'CredentialFile does not match the reviewed production SSH DPAPI schema.'
    }
    try {
        $entropy = [Convert]::FromBase64String([string]$wrapper.entropyBase64)
        $ciphertext = [Convert]::FromBase64String([string]$wrapper.ciphertextBase64)
    }
    catch {
        throw 'CredentialFile contains invalid Base64 payloads.'
    }
    if ((Get-Sha256Hex $ciphertext) -cne [string]$wrapper.ciphertextSha256) {
        throw 'CredentialFile ciphertext digest is invalid.'
    }
    try {
        $plain = [Security.Cryptography.ProtectedData]::Unprotect(
            $ciphertext,
            $entropy,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
    }
    catch {
        throw 'CredentialFile cannot be unsealed by the current Windows user.'
    }
    if ((Get-Sha256Hex $plain) -cne [string]$wrapper.payloadSha256) {
        throw 'CredentialFile plaintext digest is invalid.'
    }
    try {
        $credential = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json
    }
    catch {
        throw 'CredentialFile plaintext is not valid UTF-8 JSON.'
    }
    $allowedPayload = @('host', 'port', 'username', 'password')
    if (@($credential.PSObject.Properties.Name | Where-Object { $_ -notin $allowedPayload }).Count -ne 0 -or
        [string]::IsNullOrWhiteSpace([string]$credential.host) -or
        [string]$credential.host -match '[\s/@:]' -or
        [int]$credential.port -lt 1 -or [int]$credential.port -gt 65535 -or
        [string]$credential.username -cne 'root' -or
        [string]::IsNullOrEmpty([string]$credential.password)) {
        throw 'CredentialFile plaintext identity is outside the pinned root SSH contract.'
    }
    $password = [string]$credential.password
    $arguments = @(
        $installerPath,
        '--host', [string]$credential.host,
        '--port', [string]([int]$credential.port),
        '--user', 'root',
        '--known-hosts', $knownHostsPath,
        '--bundle', $bundlePath,
        '--bundle-sha256', $BundleSha256
    )
    if ($Execute) {
        $arguments += @(
            '--execute',
            '--confirm', $Confirm,
            '--maintenance-confirmed', $MaintenanceConfirmed,
            '--confirm-manifest-sha', $ConfirmManifestSha
        )
    }
    $env:YY_SSH_PASSWORD = $password
    $env:YY_SSH_EXPECTED_HOST = [string]$credential.host
    & $pythonPath @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Offline STT installer failed with exit code $LASTEXITCODE."
    }
}
finally {
    Remove-Item Env:YY_SSH_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:YY_SSH_EXPECTED_HOST -ErrorAction SilentlyContinue
    Remove-Item Env:PYTHONPATH -ErrorAction SilentlyContinue
    if ($null -ne $plain) { [Array]::Clear($plain, 0, $plain.Length) }
    if ($null -ne $ciphertext) { [Array]::Clear($ciphertext, 0, $ciphertext.Length) }
    if ($null -ne $entropy) { [Array]::Clear($entropy, 0, $entropy.Length) }
    $password = $null
    $credential = $null
}
