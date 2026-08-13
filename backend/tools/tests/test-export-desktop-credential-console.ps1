$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.Security

$tools = Split-Path -Parent $PSScriptRoot
$exporter = Join-Path $tools 'export-desktop-credential-console.ps1'
$temp = Join-Path ([IO.Path]::GetTempPath()) ('desktop-credential-console-' + [Guid]::NewGuid().ToString('N'))
[void](New-Item -ItemType Directory -Path $temp)

function Sha([byte[]]$Bytes) {
    $hash = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($hash.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $hash.Dispose() }
}
function Assert($Condition, [string]$Message) { if (-not $Condition) { throw $Message } }
function Write-Package([string]$Path, [object]$Payload) {
    $plain = [Text.UTF8Encoding]::new($false).GetBytes(($Payload | ConvertTo-Json -Depth 10 -Compress))
    $entropy = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($entropy) } finally { $rng.Dispose() }
    try {
        $cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        try {
            $wrapper = [ordered]@{
                schemaVersion=1;platform=$Payload.platform;count=@($Payload.accounts).Count;batchId=$Payload.batchId
                protection='Windows-DPAPI-CurrentUser';payloadSha256=(Sha $plain);entropyBase64=[Convert]::ToBase64String($entropy)
                ciphertextBase64=[Convert]::ToBase64String($cipher);ciphertextSha256=(Sha $cipher)
            }
            [IO.File]::WriteAllText($Path, ($wrapper | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
        } finally { [Array]::Clear($cipher,0,$cipher.Length) }
    } finally { [Array]::Clear($plain,0,$plain.Length);[Array]::Clear($entropy,0,$entropy.Length) }
}

try {
    $packages = Join-Path $temp 'packages'
    $output = Join-Path $temp 'output'
    [void](New-Item -ItemType Directory -Path $packages)
    $batch = 'example-batch-' + [Guid]::NewGuid().ToString('N')
    Write-Package (Join-Path $packages 'admin.json') ([ordered]@{
        schemaVersion=1;batchId=$batch;platform='example-platform';packageClass='admin';appId='example-app';adminId='example-admin'
        accounts=@([ordered]@{kind='admin';id='example-admin';loginAccount='example-admin-account';level='admin';platform='example-platform';appId='example-app';adminId='example-admin';status='active';password='example-admin-password-41'})
        appSecret='example-app-secret-53'
    })
    Write-Package (Join-Path $packages 'users.json') ([ordered]@{
        schemaVersion=1;batchId=$batch;platform='example-platform';packageClass='user';appId='example-app';adminId='example-admin'
        accounts=@(
            [ordered]@{kind='user';id='example-user-active';loginAccount='example-user-active-account';level='user';platform='example-platform';appId='example-app';adminId='example-admin';status='active';password='example-user-password-67'},
            [ordered]@{kind='user';id='example-user-disabled';loginAccount='example-user-disabled-account';level='user';platform='example-platform';appId='example-app';adminId='example-admin';status='disabled';password='example-disabled-password-79'}
        );appSecret=$null
    })
    $summaryText = & $exporter -PackageDirectory $packages -OutputDirectory $output -AllowNonDefaultOutputForTest
    foreach ($secret in @('example-admin-account','example-admin-password-41','example-app-secret-53','example-user-password-67')) {
        Assert (-not (($summaryText -join "`n").Contains($secret))) 'Exporter stdout leaked fixture credentials.'
    }
    $summary = $summaryText | ConvertFrom-Json
    Assert ($summary.accountCount -eq 3) 'Unexpected exported account count.'
    Assert ($summary.activeSourceStatusCount -eq 2) 'Unexpected active count.'
    Assert ($summary.disabledOrInactiveCount -eq 1) 'Unexpected disabled count.'
    Assert ($summary.initiallyMarkedTestCount -eq 0) 'Source accounts must not be guessed as test.'
    Assert ($summary.initiallyUnknownEnvironmentCount -eq 2) 'Active source accounts must start unknown.'
    Assert ($summary.secretsPrinted -eq $false) 'Summary must declare that secrets were not printed.'
    $stateName = (-join @([char]0x8d26,[char]0x53f7,[char]0x603b,[char]0x8868)) + '.json'
    $state = Get-Content -LiteralPath (Join-Path $output $stateName) -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert (@($state.accounts).Count -eq 3) 'State account count mismatch.'
    Assert (@($state.accounts | Where-Object environment -eq 'unknown').Count -eq 3) 'All fixture environments must remain unknown.'
    Assert (@($state.accounts | Where-Object status -eq 'disabled').Count -eq 1) 'Disabled account was not isolated.'
    $disabledName = (-join @([char]0x5df2,[char]0x505c,[char]0x7528,[char]0x005f,[char]0x4e0d,[char]0x53ef,[char]0x767b,[char]0x5f55)) + '.json'
    $disabledDocument = Get-Content -LiteralPath (Join-Path $output ('JSON\' + $disabledName)) -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert ($disabledDocument.count -eq 1) 'Disabled derived JSON mismatch.'
    $acl = Get-Acl -LiteralPath $output
    Assert $acl.AreAccessRulesProtected 'Output ACL inheritance is enabled.'
    $allowed = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18')
    $rules = @($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
    Assert ($rules.Count -eq 2) 'Output ACL rule count mismatch.'
    Assert (@($rules | Where-Object { $_.IdentityReference.Value -notin $allowed }).Count -eq 0) 'Output ACL includes another principal.'
    $replaceFailed = $false
    try { & $exporter -PackageDirectory $packages -OutputDirectory $output -AllowNonDefaultOutputForTest | Out-Null }
    catch { $replaceFailed = $true }
    Assert $replaceFailed 'Existing output was overwritten without explicit opt-in.'
    'PASS: private desktop credential export fixture contract'
}
finally {
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
