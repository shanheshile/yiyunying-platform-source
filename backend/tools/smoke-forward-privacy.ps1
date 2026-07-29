param([string]$BaseUrl = 'http://127.0.0.1:8789')

$ErrorActionPreference = 'Stop'
$suite = Join-Path $PSScriptRoot 'smoke-chat-media-forward-search.ps1'
if (-not (Test-Path -LiteralPath $suite)) {
    throw 'Missing chat media and forward smoke suite'
}

Write-Host 'Testing selective/full anonymity, stable aliases, and nested snapshots' -ForegroundColor Cyan
& $suite -BaseUrl $BaseUrl
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Host 'Forward privacy smoke passed' -ForegroundColor Green
