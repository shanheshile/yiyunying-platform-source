param(
    [string]$BaseUrl = 'http://appht.jjmxg.xyz',
    [string]$AudioFile = ''
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$script:Checks = 0
$operatorId = 0
$rootHeaders = @{}
$generatedAudio = $false

function Invoke-Api {
    param([string]$Method, [string]$Path, [hashtable]$Headers = @{}, [object]$Body = $null)
    $params = @{ Method = $Method; Uri = "$BaseUrl$Path"; Headers = $Headers; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    try { $response = Invoke-RestMethod @params }
    catch {
        $detail = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($detail) -and $null -ne $_.Exception.Response) {
            try {
                $stream = $_.Exception.Response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = [System.IO.StreamReader]::new($stream)
                    try { $detail = $reader.ReadToEnd() }
                    finally { $reader.Dispose() }
                }
            }
            catch { }
        }
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
    if ($response.code -ne 1) { throw "$Method $Path returned code=$($response.code): $($response.msg)" }
    $script:Checks++
    return $response.data
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Assertion failed: $Message" }
    $script:Checks++
}

function New-TestAudio {
    $path = Join-Path $env:TEMP 'yiyunying-stt-api-smoke.wav'
    Add-Type -AssemblyName System.Speech
    $speech = New-Object System.Speech.Synthesis.SpeechSynthesizer
    try {
        $chinese = @($speech.GetInstalledVoices() | Where-Object { $_.VoiceInfo.Culture.Name -eq 'zh-CN' })
        if ($chinese.Count -eq 0) { throw 'No zh-CN speech synthesis voice is installed.' }
        $speech.SelectVoice($chinese[0].VoiceInfo.Name)
        $speech.Rate = -1
        $speech.SetOutputToWaveFile($path)
        $phrase = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(
            '5piT6L+Q55uI5ZCO5Y+w6K+t6Z+z6L2s5paH5a2X5rWL6K+V5oiQ5Yqf44CC6L+e57ut5Y+R6YCB5raI5oGv5pe277yM6L6T5YWl5rOV5LiN5Lya6Ieq5Yqo5pS26LW344CC'
        ))
        $speech.Speak($phrase)
    }
    finally { $speech.Dispose() }
    return $path
}

function Invoke-AudioUpload {
    param([hashtable]$Headers, [string]$Path)
    Add-Type -AssemblyName System.Net.Http
    $client = [System.Net.Http.HttpClient]::new()
    $content = [System.Net.Http.MultipartFormDataContent]::new()
    $stream = $null
    try {
        foreach ($key in $Headers.Keys) {
            [void]$client.DefaultRequestHeaders.TryAddWithoutValidation([string]$key, [string]$Headers[$key])
        }
        $stream = [System.IO.File]::OpenRead($Path)
        $file = [System.Net.Http.StreamContent]::new($stream)
        $file.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('audio/wav')
        $content.Add($file, 'file', [System.IO.Path]::GetFileName($Path))
        $content.Add([System.Net.Http.StringContent]::new('chat_voice'), 'scene')
        $content.Add([System.Net.Http.StringContent]::new('1'), 'original_upload')
        $response = $client.PostAsync("$BaseUrl/api/user/uploads", $content).GetAwaiter().GetResult()
        $raw = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if (-not $response.IsSuccessStatusCode) {
            throw "POST /api/user/uploads failed: HTTP $([int]$response.StatusCode) $raw"
        }
        $json = $raw | ConvertFrom-Json
        if ($json.code -ne 1) { throw "POST /api/user/uploads returned code=$($json.code): $($json.msg)" }
        $script:Checks++
        return $json.data
    }
    finally {
        if ($null -ne $content) { $content.Dispose() }
        if ($null -ne $stream) { $stream.Dispose() }
        $client.Dispose()
    }
}

try {
    if ([string]::IsNullOrWhiteSpace($AudioFile)) {
        $AudioFile = New-TestAudio
        $generatedAudio = $true
    }
    $AudioFile = (Resolve-Path $AudioFile).Path
    Assert-True ((Get-Item $AudioFile).Length -gt 10000) 'test WAV contains real audio data'

    $suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $health = Invoke-Api GET '/api/health'
    Assert-True ($health.database -eq 'connected') 'database health'

    $root = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = 'yiyunying-root'; account = 'root'; password = '123456'
    }
    $rootHeaders = @{ Authorization = "Bearer $($root.access_token)" }
    $operatorAccount = "stt_l2_$suffix"
    $operator = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
        account = $operatorAccount; password = '123456'; nickname = 'Speech Test Platform'
        membership_days = 2; admin_quota = 1; balance = 1; allowed_weekdays = @(1,2,3,4,5,6,7)
    }
    $operatorId = [int]$operator.operator.id
    $platformKey = [string]$operator.operator.platform_key
    $operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
        platform_key = $platformKey; account = $operatorAccount; password = '123456'
    }
    $operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

    $adminAccount = "stt_l3_$suffix"
    Invoke-Api POST '/api/platform/admins' $operatorHeaders @{
        account = $adminAccount; password = '123456'; nickname = 'Speech Test Admin'
        vip_days = 2; app_quota = 1; remote_document_quota = 1; balance = 1
    } | Out-Null
    $admin = Invoke-Api POST '/api/admin/login' @{} @{
        platform_key = $platformKey; account = $adminAccount; password = '123456'
    }
    $adminHeaders = @{ Authorization = "Bearer $($admin.access_token)" }
    $created = Invoke-Api POST '/api/admin/apps' $adminHeaders @{
        name = "Speech API $suffix"; app_key = "stt_$suffix"; description = 'Speech transcription smoke test'
    }
    $appId = [int]$created.app.id
    $appKey = [string]$created.app.app_key
    $account = "stt_user_$suffix"
    Invoke-Api POST "/api/admin/apps/$appId/users" $adminHeaders @{
        account = $account; password = '123456'; nickname = 'Speech Test User'
    } | Out-Null
    $login = Invoke-Api POST '/api/user/login' @{} @{
        app_key = $appKey; account = $account; password = '123456'
    }
    $userHeaders = @{ Authorization = "Bearer $($login.access_token)"; 'X-App-Key' = $appKey }

    $upload = Invoke-AudioUpload $userHeaders $AudioFile
    Assert-True ([int]$upload.upload_id -gt 0) 'audio upload receives an id'
    Assert-True ([string]$upload.mime_type -like 'audio/*') 'server recognizes the WAV as audio'

    $first = Invoke-Api POST '/api/user/audio/transcriptions' $userHeaders @{
        upload_id = [int]$upload.upload_id; language = 'zh'
    }
    $backendKeywords = @('5ZCO5Y+w', '5b6M5Y+w') | ForEach-Object {
        [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($_))
    }
    $inputMethodKeywords = @('6L6T5YWl5rOV', '6Ly45YWl5rOV') | ForEach-Object {
        [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($_))
    }
    $hasBackendKeyword = @($backendKeywords | Where-Object { [string]$first.transcript -like "*$_*" }).Count -gt 0
    $hasInputMethodKeyword = @($inputMethodKeywords | Where-Object { [string]$first.transcript -like "*$_*" }).Count -gt 0
    Write-Host "transcript=$($first.transcript)"
    Assert-True (-not [bool]$first.cached) 'first request runs local inference'
    Assert-True $hasBackendKeyword 'transcript contains the spoken backend keyword'
    Assert-True $hasInputMethodKeyword 'transcript contains the spoken input-method keyword'

    $second = Invoke-Api POST '/api/user/audio/transcriptions' $userHeaders @{
        upload_id = [int]$upload.upload_id; language = 'zh'
    }
    Assert-True ([bool]$second.cached) 'second request uses the database cache'
    Assert-True ([string]$second.transcript -eq [string]$first.transcript) 'cached transcript is identical'

    Write-Host 'Speech transcription API smoke test passed.' -ForegroundColor Green
    Write-Host "checks=$script:Checks upload_id=$($upload.upload_id) transcript=$($first.transcript)"
}
finally {
    if ($operatorId -gt 0 -and $rootHeaders.Count -gt 0) {
        try { Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null }
        catch { Write-Warning "Temporary speech branch cleanup failed: $($_.Exception.Message)" }
    }
    if ($generatedAudio -and -not [string]::IsNullOrWhiteSpace($AudioFile)) {
        Remove-Item -LiteralPath $AudioFile -Force -ErrorAction SilentlyContinue
    }
}
