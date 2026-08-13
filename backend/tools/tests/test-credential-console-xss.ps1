$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$scriptPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'credential-console.js'
$script = [IO.File]::ReadAllText($scriptPath, [Text.Encoding]::UTF8)

function Assert($Condition, [string]$Message) { if (-not $Condition) { throw $Message } }

Assert ($script.Contains('history.replaceState')) 'Bearer token must be removed from browser history after load.'
Assert (-not $script.Contains("$('tbody').innerHTML")) 'Credential table must not use innerHTML.'
Assert ($script.Contains("document.createElement('input')") -and $script.Contains('input.value = String(value')) 'Credential values must be assigned through DOM value properties.'
Assert ($script.Contains("document.createElement('option')") -and $script.Contains('option.textContent = label')) 'Option labels must be assigned with textContent.'
Assert ($script.Contains("document.createElement('button')") -and $script.Contains('element.textContent = label')) 'Action labels must be assigned with textContent.'
Assert ($script.Contains('tbody.replaceChildren()') -and $script.Contains('tbody.appendChild(fragment)')) 'Credential rows must be rebuilt through DOM nodes.'
Assert (-not $script.Contains('location.href = `/tests?token=')) 'Test-page navigation must not put the token back in the URL.'
Assert (-not $script.Contains('location.href = `/?token=')) 'All-account navigation must not put the token back in the URL.'
'PASS: credential console persistent DOM XSS hardening contract'
