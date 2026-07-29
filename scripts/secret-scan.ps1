[CmdletBinding()]
param(
    [string]$Root = ''
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($Root)) {
    $Root = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
}

$pathSeparators = [char[]]@([char]92, [char]47)
$rootPath = (Resolve-Path -LiteralPath $Root).Path.TrimEnd($pathSeparators)
$ignoredExactPaths = @(
    'backend/tools/smoke-maximum.ps1'
)
$ignoredDirectoryPattern = '(^|/)(\.git|\.gradle|\.vinext|build|dist|coverage|node_modules|vendor)(/|$)'
$ignoredModelPattern = '(^|/)backend/storage/stt/models(/|$)'
$textExtensions = @(
    '.java', '.kt', '.gradle', '.xml', '.php', '.json', '.js', '.mjs', '.ts', '.tsx',
    '.css', '.scss', '.html', '.md', '.txt', '.yml', '.yaml', '.properties', '.toml',
    '.ini', '.conf', '.env', '.example', '.sql', '.ps1', '.sh'
)
$patterns = @(
    '(?i)-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----',
    '(?i)(?<![A-Za-z0-9_])(password|passwd|db_password|secret|api_key|access_token|private_key)(?![A-Za-z0-9_])\s*["'']?\s*(?:=>|[:=])\s*["''](?!secure\.|local[-_]|change[-_]?me|your[-_]|example[-_])[^"''\s]{12,}["'']',
    '(?i)gh[pousr]_[A-Za-z0-9_]{30,}',
    '(?i)sk-[A-Za-z0-9_-]{20,}',
    '(?i)AIza[0-9A-Za-z_-]{30,}'
)

function Get-RepositoryRelativePath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ($Path.StartsWith($rootPath, [StringComparison]::OrdinalIgnoreCase)) {
        return $Path.Substring($rootPath.Length).TrimStart($pathSeparators).Replace('\', '/')
    }
    return $Path.Replace('\', '/')
}

function Test-IgnoredPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $relative = Get-RepositoryRelativePath -Path $Path
    return $relative -match $ignoredDirectoryPattern -or $relative -match $ignoredModelPattern
}

$findings = [System.Collections.Generic.List[string]]::new()
Get-ChildItem -LiteralPath $rootPath -Recurse -File | ForEach-Object {
    $fullName = $_.FullName
    if (Test-IgnoredPath -Path $fullName) { return }

    $relative = Get-RepositoryRelativePath -Path $fullName
    if ($ignoredExactPaths -contains $relative) { return }
    if ($_.Name -eq '.env.example' -or $_.Name -eq 'pnpm-lock.yaml') { return }
    if ($textExtensions -notcontains $_.Extension.ToLowerInvariant()) { return }
    if ($_.Length -gt 5MB) { return }

    $content = Get-Content -LiteralPath $fullName -Raw -ErrorAction SilentlyContinue
    foreach ($pattern in $patterns) {
        if ($content -match $pattern) {
            $findings.Add("$relative matches $pattern")
        }
    }
}

if ($findings.Count -gt 0) {
    $findings | ForEach-Object { Write-Error $_ }
    throw "Secret scan failed with $($findings.Count) finding(s)."
}

$oversized = Get-ChildItem -LiteralPath $rootPath -Recurse -File | Where-Object {
    -not (Test-IgnoredPath -Path $_.FullName) -and $_.Length -ge 95MB
}
if ($oversized) {
    $oversized | ForEach-Object { Write-Error "$($_.FullName) is $([math]::Round($_.Length / 1MB, 2)) MB" }
    throw 'Files at or above 95 MB are not allowed in the source repository.'
}

Write-Host 'Secret and large-file scan passed.' -ForegroundColor Green
