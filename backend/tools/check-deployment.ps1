param(
    [string]$BaseUrl = 'http://appht.jjmxg.xyz',
    [string]$PlatformAccount = 'root',
    [string]$PlatformPassword = '123456',
    [string]$AdminAccount = 'admin',
    [string]$AdminPassword = '123456',
    [string]$AppKey = 'yiyunying-demo',
    [string]$UserAccount = 'user',
    [string]$UserPassword = '123456'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0

function Invoke-JsonCheck {
    param([string]$Method, [string]$Path, [object]$Body = $null, [hashtable]$Headers = @{})

    $request = [System.Net.HttpWebRequest]::Create("$BaseUrl$Path")
    $request.Method = $Method
    $request.Accept = 'application/json'
    $request.AllowAutoRedirect = $false
    $request.Timeout = 15000
    foreach ($entry in $Headers.GetEnumerator()) { $request.Headers[$entry.Key] = [string]$entry.Value }
    if ($null -ne $Body) {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 20 -Compress))
        $request.ContentType = 'application/json; charset=utf-8'
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Dispose() }
    }

    $response = $null
    try { $response = $request.GetResponse() } catch [System.Net.WebException] { $response = $_.Exception.Response }
    if ($null -eq $response) { throw "$Method $Path：服务器没有返回 HTTP 响应" }
    try {
        $status = [int]$response.StatusCode
        $contentType = [string]$response.ContentType
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream(), [System.Text.Encoding]::UTF8)
        try { $raw = $reader.ReadToEnd() } finally { $reader.Dispose() }
        try { $json = $raw | ConvertFrom-Json } catch {
            $preview = if ($raw.Length -gt 180) { $raw.Substring(0, 180) } else { $raw }
            throw ("{0} {1} 返回的不是 JSON：HTTP {2}，Content-Type={3}，内容={4}" -f $Method, $Path, $status, $contentType, $preview)
        }
        if ($status -lt 200 -or $status -ge 300 -or [int]$json.code -ne 1) {
            throw ("{0} {1} 失败：HTTP {2}，code={3}，msg={4}" -f $Method, $Path, $status, $json.code, $json.msg)
        }
        $script:Checks++
        Write-Output ("[OK] {0} {1} -> HTTP {2}, JSON" -f $Method, $Path, $status)
        return $json.data
    } finally {
        $response.Dispose()
    }
}

Invoke-JsonCheck GET '/api/health' | Out-Null
Invoke-JsonCheck POST '/api/platform/login' @{ account = $PlatformAccount; password = $PlatformPassword; platform_key = 'root'; device = 'deployment-check' } | Out-Null
Invoke-JsonCheck POST '/api/admin/login' @{ account = $AdminAccount; password = $AdminPassword; device = 'deployment-check' } | Out-Null
Invoke-JsonCheck POST '/api/user/login' @{ app_key = $AppKey; account = $UserAccount; password = $UserPassword; device = 'deployment-check' } @{ 'X-App-Key' = $AppKey } | Out-Null

Write-Output "Deployment JSON checks passed: $script:Checks/4"
