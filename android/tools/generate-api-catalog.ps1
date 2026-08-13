param(
    [string]$BackendRoutes = "..\backend\routes\api.php",
    [string]$Output = "app\src\main\assets\api_catalog.json"
)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$routesPath = if ([System.IO.Path]::IsPathRooted($BackendRoutes)) {
    [System.IO.Path]::GetFullPath($BackendRoutes)
} else {
    [System.IO.Path]::GetFullPath((Join-Path $project $BackendRoutes))
}
$outputPath = if ([System.IO.Path]::IsPathRooted($Output)) {
    [System.IO.Path]::GetFullPath($Output)
} else {
    [System.IO.Path]::GetFullPath((Join-Path $project $Output))
}
$items = [System.Collections.Generic.List[object]]::new()
$pattern = '^\s*\$router->(get|post|put|delete)\(''([^'']+)''\s*,\s*\[([^:]+)::class,\s*''([^'']+)''\]\)'

foreach ($line in [System.IO.File]::ReadAllLines($routesPath, [System.Text.Encoding]::UTF8)) {
    if ($line -match $pattern) {
        $method = $matches[1].ToUpperInvariant()
        $path = $matches[2]
        $scope = if ($path.StartsWith('/api/platform/')) {
            'platform'
        } elseif ($path.StartsWith('/api/admin/')) {
            'admin'
        } elseif ($path.StartsWith('/api/user/')) {
            'user'
        } else {
            'public'
        }
        $items.Add([ordered]@{
            method = $method
            path = $path
            scope = $scope
            handler = ($matches[3] + '::' + $matches[4])
        })
    }
}

$directory = Split-Path -Parent $outputPath
[System.IO.Directory]::CreateDirectory($directory) | Out-Null
$json = (($items | ConvertTo-Json -Depth 4 | Out-String) -replace "`r`n?", "`n").TrimEnd([char[]] "`n")
[System.IO.File]::WriteAllText($outputPath, $json + "`n", [System.Text.UTF8Encoding]::new($false))
Write-Output "Generated $($items.Count) routes: $outputPath"
