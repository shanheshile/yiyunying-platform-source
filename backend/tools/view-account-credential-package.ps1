[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$PackagePath,

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

$raw = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $PackagePath).Path, [Text.Encoding]::UTF8)
try { $wrapper = $raw | ConvertFrom-Json }
catch { throw "Package is not valid JSON: $($_.Exception.Message)" }
$allowed = @('schemaVersion', 'platform', 'count', 'batchId', 'protection', 'payloadSha256', 'entropyBase64', 'ciphertextBase64', 'ciphertextSha256')
$unexpected = @($wrapper.PSObject.Properties.Name | Where-Object { $_ -notin $allowed })
if ($unexpected.Count -ne 0 -or $wrapper.schemaVersion -ne 1 -or $wrapper.protection -ne 'Windows-DPAPI-CurrentUser') {
    throw 'Unsupported credential package wrapper.'
}
$ciphertext = [Convert]::FromBase64String($wrapper.ciphertextBase64)
$entropy = [Convert]::FromBase64String($wrapper.entropyBase64)
try {
    if ((Get-Sha256Hex $ciphertext) -ne $wrapper.ciphertextSha256) { throw 'Ciphertext hash mismatch.' }
    $plain = [Security.Cryptography.ProtectedData]::Unprotect($ciphertext, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    try {
        if ((Get-Sha256Hex $plain) -ne $wrapper.payloadSha256) { throw 'Payload hash mismatch.' }
        $payloadJson = [Text.Encoding]::UTF8.GetString($plain)
        $payload = $payloadJson | ConvertFrom-Json
        if ($payload.batchId -ne $wrapper.batchId -or $payload.platform -ne $wrapper.platform -or
            @($payload.accounts).Count -ne [int]$wrapper.count) {
            throw 'Wrapper metadata does not match the encrypted payload.'
        }
        if ($Reveal) {
            # Deliberately the only mode that emits secrets; the caller controls stdout.
            Write-Output ($payload | ConvertTo-Json -Depth 12)
        }
        else {
            [pscustomobject][ordered]@{
                schemaVersion = $wrapper.schemaVersion
                platform = $wrapper.platform
                packageClass = $payload.packageClass
                count = [int]$wrapper.count
                batchId = $wrapper.batchId
                payloadSha256 = $wrapper.payloadSha256
                status = 'verified-dpapi-current-user'
            } | ConvertTo-Json -Depth 3
        }
    }
    finally { [Array]::Clear($plain, 0, $plain.Length) }
}
finally {
    [Array]::Clear($ciphertext, 0, $ciphertext.Length)
    [Array]::Clear($entropy, 0, $entropy.Length)
}
