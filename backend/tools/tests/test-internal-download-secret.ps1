$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot '..\get-or-create-internal-download-secret.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('yy-secret-test-' + [Guid]::NewGuid().ToString('N'))
try {
    [void](New-Item -ItemType Directory -Path $root)
    $target = Join-Path $root 'secret.dpapi.json'
    $first = & powershell -NoProfile -ExecutionPolicy Bypass -File $script -Path $target -Reveal
    $second = & powershell -NoProfile -ExecutionPolicy Bypass -File $script -Path $target -Reveal
    if ($first -notmatch '^[0-9a-f]{64}$' -or $first -ne $second) { throw 'Secret create/read contract failed.' }
    $summary = (& powershell -NoProfile -ExecutionPolicy Bypass -File $script -Path $target) | ConvertFrom-Json
    if ($summary.status -ne 'verified' -or $summary.protection -ne 'DPAPI-CurrentUser') { throw 'Secret summary contract failed.' }
    if ((Get-Content -Raw -Encoding UTF8 $target).Contains($first)) { throw 'Plaintext secret leaked into wrapper.' }
    Write-Output 'PASS: internal download signing secret DPAPI contract'
}
finally {
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
