$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.Security

$tools = Split-Path -Parent $PSScriptRoot
$exporter = Join-Path $tools 'export-account-credential-packages.ps1'
$viewer = Join-Path $tools 'view-account-credential-package.ps1'
$temp = Join-Path ([IO.Path]::GetTempPath()) ('credential-package-test-' + [Guid]::NewGuid().ToString('N'))
[void](New-Item -ItemType Directory -Path $temp)

function Sha([byte[]]$Bytes) {
    $h = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($h.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $h.Dispose() }
}

function Assert($Condition, [string]$Message) { if (-not $Condition) { throw $Message } }

try {
    $batch = 'credential-test-' + [Guid]::NewGuid().ToString('N')
    $accounts = @(
        [ordered]@{ kind = 'platform'; id = 'p-owner'; password = 'example-owner-password-7b2'; disabled = $false },
        [ordered]@{ kind = 'platform'; id = 'p-auth'; password = 'example-authorized-password-9c4'; disabled = $false },
        [ordered]@{ kind = 'admin'; id = 'a-1'; password = 'example-admin-password-3d6'; disabled = $false },
        [ordered]@{ kind = 'user'; id = 'u-1'; password = 'example-user1-password-4e8'; disabled = $false },
        [ordered]@{ kind = 'user'; id = 'u-2'; password = 'example-user2-password-5f0'; disabled = $true }
    )
    $inner = [ordered]@{
        schemaVersion = 1; batchId = $batch; createdAtUtc = '2026-08-14T00:00:00Z'; planSha256 = ('a' * 64)
        accounts = $accounts; apps = @([ordered]@{ id = 'app-1'; secret = 'example-app-secret-2a1' })
    }
    $plain = [Text.UTF8Encoding]::new($false).GetBytes(($inner | ConvertTo-Json -Depth 8 -Compress))
    $entropy = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($entropy) }
    finally { $rng.Dispose() }
    $cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    $source = [ordered]@{
        schemaVersion = 1; batchId = $batch; createdAtUtc = '2026-08-14T00:00:00Z'; protection = 'Windows-DPAPI-CurrentUser'
        entropyBase64 = [Convert]::ToBase64String($entropy); ciphertextBase64 = [Convert]::ToBase64String($cipher)
        ciphertextSha256 = Sha $cipher; planSha256 = ('a' * 64)
        counts = [ordered]@{ platforms = 2; admins = 1; users = 2; apps = 1 }; status = 'prepared'
    }
    $sourcePath = Join-Path $temp 'source.dpapi.json'
    [IO.File]::WriteAllText($sourcePath, ($source | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
    [Array]::Clear($plain, 0, $plain.Length); [Array]::Clear($cipher, 0, $cipher.Length); [Array]::Clear($entropy, 0, $entropy.Length)

    $index = [ordered]@{
        schemaVersion = 1
        source = [ordered]@{
            batchId = $batch; packageSha256 = (Get-FileHash $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
            ciphertextSha256 = $source.ciphertextSha256; planSha256 = $source.planSha256
        }
        accounts = @(
            [ordered]@{ kind='platform';id='p-owner';loginAccount='owner@example.test';level='platform-owner';platform='test-platform';appId=$null;adminId=$null;status='active' },
            [ordered]@{ kind='platform';id='p-auth';loginAccount='authorized@example.test';level='authorized-platform';platform='test-platform';appId=$null;adminId=$null;status='active' },
            [ordered]@{ kind='admin';id='a-1';loginAccount='admin@example.test';level='admin';platform='test-platform';appId='app-1';adminId='a-1';status='active' },
            [ordered]@{ kind='user';id='u-1';loginAccount='user1@example.test';level='user';platform='test-platform';appId='app-1';adminId='a-1';status='active' },
            [ordered]@{ kind='user';id='u-2';loginAccount='user2@example.test';level='user';platform='test-platform';appId='app-2';adminId='a-1';status='disabled' }
        )
    }
    $indexPath = Join-Path $temp 'index.json'
    [IO.File]::WriteAllText($indexPath, ($index | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
    $outputRoot = Join-Path $temp 'output'
    $result = & $exporter -SourcePackagePath $sourcePath -AccountIndexPath $indexPath -OutputRoot $outputRoot -AllowNonDefaultOutputRootForTest | ConvertFrom-Json
    Assert ($result.packageCount -eq 5) 'Expected owner, authorized, admin/app and two user/app packages.'
    Assert ($result.accountCount -eq 5) 'Expected all fixture accounts.'
    $files = @(Get-ChildItem -LiteralPath $result.outputDirectory -Filter '*.dpapi.json' -File)
    Assert ($files.Count -eq 5) 'Output file count mismatch.'
    $diskText = ($files | ForEach-Object { [IO.File]::ReadAllText($_.FullName) }) -join "`n"
    foreach ($secret in @('owner@example.test','admin@example.test','example-owner-password-7b2','example-app-secret-2a1','app-1','a-1')) {
        Assert (-not $diskText.Contains($secret)) "Plaintext leaked to wrappers: $secret"
    }
    $adminFile = $null
    $userFiles = @()
    foreach ($file in $files) {
        $statsText = & $viewer -PackagePath $file.FullName
        foreach ($secret in @('owner@example.test','admin@example.test','example-owner-password-7b2','example-app-secret-2a1')) {
            Assert (-not $statsText.Contains($secret)) 'Default viewer output leaked plaintext.'
        }
        $stats = $statsText | ConvertFrom-Json
        if ($stats.packageClass -eq 'admin') { $adminFile = $file }
        if ($stats.packageClass -eq 'user') { $userFiles += $file }
    }
    Assert ($null -ne $adminFile) 'Admin package missing.'
    Assert ($userFiles.Count -eq 2) 'Users from two apps were not split into two packages.'
    $revealed = & $viewer -PackagePath $adminFile.FullName -Reveal | ConvertFrom-Json
    Assert ($revealed.appSecret -eq 'example-app-secret-2a1') 'Explicit reveal did not return the admin app secret.'
    Assert ($revealed.accounts[0].password -eq 'example-admin-password-3d6') 'Explicit reveal did not return the admin password.'
    $userApps = @($userFiles | ForEach-Object {
        $userPayload = & $viewer -PackagePath $_.FullName -Reveal | ConvertFrom-Json
        Assert ($null -eq $userPayload.appSecret) 'A user package received an app secret.'
        $userPayload.appId
    } | Sort-Object)
    Assert (($userApps -join ',') -eq 'app-1,app-2') 'Cross-app user package grouping failed.'

    $existingFailed = $false
    try { & $exporter -SourcePackagePath $sourcePath -AccountIndexPath $indexPath -OutputRoot $outputRoot -AllowNonDefaultOutputRootForTest | Out-Null }
    catch { $existingFailed = $true }
    Assert $existingFailed 'Existing output batch was not rejected.'

    $rootFailed = $false
    try { & $exporter -SourcePackagePath $sourcePath -AccountIndexPath $indexPath -OutputRoot (Join-Path $temp 'unsafe-without-opt-in') | Out-Null }
    catch { $rootFailed = $true }
    Assert $rootFailed 'Non-default output root was not rejected without the test-only override.'

    $drift = $index | ConvertTo-Json -Depth 8 | ConvertFrom-Json
    $drift.accounts[4].status = 'active'
    $driftPath = Join-Path $temp 'drift.json'
    [IO.File]::WriteAllText($driftPath, ($drift | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
    $driftFailed = $false
    try { & $exporter -SourcePackagePath $sourcePath -AccountIndexPath $driftPath -OutputRoot (Join-Path $temp 'drift-output') -AllowNonDefaultOutputRootForTest | Out-Null }
    catch { $driftFailed = $true }
    Assert $driftFailed 'Index status drift was not rejected.'

    $tamperedPath = Join-Path $temp 'tampered.dpapi.json'
    $tampered = [IO.File]::ReadAllText($adminFile.FullName) | ConvertFrom-Json
    $tampered.ciphertextBase64 = $tampered.ciphertextBase64.Substring(0, $tampered.ciphertextBase64.Length - 4) + 'AAAA'
    [IO.File]::WriteAllText($tamperedPath, ($tampered | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
    $tamperFailed = $false
    try { & $viewer -PackagePath $tamperedPath | Out-Null }
    catch { $tamperFailed = $true }
    Assert $tamperFailed 'Ciphertext tampering was not rejected.'

    'PASS: DPAPI account package export/view offline contract'
}
finally {
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
