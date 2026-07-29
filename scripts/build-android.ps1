[CmdletBinding()]
param(
    [switch]$Release
)

$ErrorActionPreference = 'Stop'
$androidRoot = Join-Path (Split-Path -Parent $PSScriptRoot) 'android'
Push-Location $androidRoot
try {
    $suffix = if ($Release) { 'Release' } else { 'Debug' }
    $tasks = @(
        "assemblePlatformOwner$suffix",
        "assembleAuthorizedPlatform$suffix",
        "assembleAdmin$suffix",
        "assembleUser$suffix"
    )
    & .\gradlew.bat @tasks --stacktrace
    if ($LASTEXITCODE -ne 0) { throw "Android build failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}
