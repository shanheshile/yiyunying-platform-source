param(
    [string]$SourceRoot = (Join-Path $PSScriptRoot "..\app\src\main\java")
)

$ErrorActionPreference = "Stop"
$resolvedRoot = (Resolve-Path -LiteralPath $SourceRoot).Path
$files = Get-ChildItem -LiteralPath $resolvedRoot -Recurse -Filter *.java -File
$violations = New-Object System.Collections.Generic.List[string]

$directSerialization = [regex]::new(
    '(?s)(setText|setMessage|Toast\.makeText|Snackbar\.make)\s*\([^;]{0,500}?' +
    '(Jsons\.(GSON|PRETTY)\.toJson|\.data\(\)\.toString\(\)|' +
    '\.dataObject\(\)\.toString\(\)|\.getAsJson(Object|Array)\(\)\.toString\(\))',
    [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
)
$indirectSerialization = [regex]::new(
    '(?s)(setText|setMessage|Toast\.makeText|Snackbar\.make)\s*\([^;]{0,500}?' +
    '\b(payload|response|json|dataObject|record)\s*\.toString\(\)',
    [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
)
$nativeDialog = [regex]::new(
    'new\s+(android\.app\.)?AlertDialog\.Builder|new\s+MaterialAlertDialogBuilder',
    [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
)
$technicalCopy = [regex]::new(
    '(?is)(setText|setMessage|Toast\.makeText|Snackbar\.make)\s*\([^;]{0,500}?' +
    '(PHP\s*(Warning|Notice|Fatal)|proc_open\s*\(|/www/wwwroot|' +
    'SQLSTATE\[|stack trace|Caused by:)',
    [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
)

foreach ($file in $files) {
    $content = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
    $relative = $file.FullName.Substring($resolvedRoot.Length).TrimStart('\')

    foreach ($match in $directSerialization.Matches($content)) {
        $line = ($content.Substring(0, $match.Index) -split "`n").Count
        $violations.Add("$relative`:$line direct serialized payload in UI")
    }

    foreach ($match in $indirectSerialization.Matches($content)) {
        $line = ($content.Substring(0, $match.Index) -split "`n").Count
        $violations.Add("$relative`:$line indirect serialized payload in UI")
    }

    if ($relative -notlike '*\ui\common\YiyunyingDialogBuilder.java') {
        foreach ($match in $nativeDialog.Matches($content)) {
            $line = ($content.Substring(0, $match.Index) -split "`n").Count
            $violations.Add("$relative`:$line bypasses shared dialog surface")
        }
    }

    if ($relative -like '*\ui\*') {
        foreach ($match in $technicalCopy.Matches($content)) {
            $line = ($content.Substring(0, $match.Index) -split "`n").Count
            $violations.Add("$relative`:$line user-visible technical diagnostic")
        }
    }
}

$moduleRegistryPath = Join-Path $resolvedRoot 'xyz\jjmxg\yiyunying\domain\module\ModuleRegistry.java'
if (Test-Path -LiteralPath $moduleRegistryPath) {
    $registry = Get-Content -LiteralPath $moduleRegistryPath -Raw -Encoding UTF8
    if ($registry -match 'special\s*\(\s*"api_console"') {
        $line = ($registry.Substring(0, $registry.IndexOf('api_console')) -split "`n").Count
        $violations.Add("xyz\jjmxg\yiyunying\domain\module\ModuleRegistry.java`:$line exposes technical API console")
    }
}

if ($violations.Count -gt 0) {
    Write-Host "Unsafe user-visible data paths found:" -ForegroundColor Red
    $violations | Sort-Object -Unique | ForEach-Object { Write-Host " - $_" }
    exit 1
}

Write-Host "Raw JSON and diagnostic UI gate passed: $($files.Count) Java files." -ForegroundColor Green
