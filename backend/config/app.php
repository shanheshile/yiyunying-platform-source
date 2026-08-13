<?php
declare(strict_types=1);

$env = static function (string $name, $default = null) {
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }
    if (array_key_exists($name, $_ENV)) {
        return $_ENV[$name];
    }
    if (array_key_exists($name, $_SERVER)) {
        return $_SERVER[$name];
    }
    return $default;
};

$envBool = static function (string $name, bool $default) use ($env): bool {
    $value = $env($name, $default ? 'true' : 'false');
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

$root = dirname(__DIR__);
$bundledSttVenvPython = PHP_OS_FAMILY === 'Windows'
    ? $root . '/storage/stt/venv/Scripts/python.exe'
    : $root . '/storage/stt/venv/bin/python3';
$bundledSttScript = $root . '/tools/stt/transcribe.py';
$systemPythonCandidates = PHP_OS_FAMILY === 'Windows'
    ? ['python.exe', 'python']
    : ['/usr/bin/python3', '/usr/local/bin/python3', 'python3'];
$bundledSttPython = is_file($bundledSttVenvPython)
    && (PHP_OS_FAMILY === 'Windows' || is_executable($bundledSttVenvPython))
    ? $bundledSttVenvPython
    : '';
if ($bundledSttPython === '') {
    foreach ($systemPythonCandidates as $candidate) {
        if (!str_contains($candidate, '/') || (is_file($candidate) && is_executable($candidate))) {
            $bundledSttPython = $candidate;
            break;
        }
    }
}
$bundledSttAvailable = is_file($bundledSttScript) && $bundledSttPython !== '';
$speechProvider = strtolower(trim((string) $env(
    'STT_PROVIDER',
    $bundledSttAvailable ? 'local-command' : 'openai-compatible'
)));
$localSpeech = in_array($speechProvider, ['local', 'local-command', 'whisper-local'], true);
$bundledSttArgs = json_encode([
    $bundledSttScript,
    '--input', '{input}',
    '--output', '{output}',
    '--language', '{language}',
    '--model', '{model}',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$voiceCallIceServers = json_decode((string) $env('VOICE_CALL_ICE_SERVERS', ''), true);
if (!is_array($voiceCallIceServers) || $voiceCallIceServers === []) {
    $iceServerFile = $root . '/storage/voice-call-ice-servers.json';
    if (is_readable($iceServerFile)) {
        $fileValue = file_get_contents($iceServerFile);
        $fileServers = is_string($fileValue) ? json_decode($fileValue, true) : null;
        if (is_array($fileServers) && $fileServers !== []) {
            $voiceCallIceServers = $fileServers;
        }
    }
}
if (!is_array($voiceCallIceServers) || $voiceCallIceServers === []) {
    $voiceCallIceServers = [['urls' => ['stun:stun.l.google.com:19302']]];
}

return [
    'app' => [
        'name' => '易运盈后台',
        'env' => (string) $env('APP_ENV', 'local'),
        'debug' => $envBool('APP_DEBUG', false),
        'url' => rtrim((string) $env('APP_URL', 'http://127.0.0.1:8788'), '/'),
        'base_path' => rtrim((string) $env('APP_BASE_PATH', ''), '/'),
        'timezone' => (string) $env('APP_TIMEZONE', 'Asia/Shanghai'),
        'cors_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $env('CORS_ORIGINS', '*'))
        ))),
    ],
    'database' => [
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'name' => (string) $env('DB_NAME', 'yiyunying'),
        'user' => (string) $env('DB_USER', 'root'),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'platform_token_ttl' => (int) $env('PLATFORM_TOKEN_TTL', 86400),
        'admin_token_ttl' => (int) $env('ADMIN_TOKEN_TTL', 86400),
        'user_token_ttl' => (int) $env('USER_TOKEN_TTL', 2592000),
        'user_refresh_token_ttl' => (int) $env('USER_REFRESH_TOKEN_TTL', 7776000),
        'password_min_length' => (int) $env('PASSWORD_MIN_LENGTH', 8),
        'login_failure_window_seconds' => (int) $env('LOGIN_FAILURE_WINDOW_SECONDS', 900),
        'login_failure_identity_ip_limit' => (int) $env('LOGIN_FAILURE_IDENTITY_IP_LIMIT', 5),
        'login_failure_identity_limit' => (int) $env('LOGIN_FAILURE_IDENTITY_LIMIT', 15),
        'login_failure_ip_limit' => (int) $env('LOGIN_FAILURE_IP_LIMIT', 30),
        'data_console_enabled' => $envBool('DATA_CONSOLE_ENABLED', false),
        'qr_signing_key' => (string) $env('QR_SIGNING_KEY', 'local-development-only-change-me'),
        'media_signing_key' => (string) $env('MEDIA_SIGNING_KEY', ''),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $env('TRUSTED_PROXIES', ''))
        ), static fn(string $value): bool => $value !== '')),
    ],
    'mail' => [
        'transport' => (string) $env('MAIL_TRANSPORT', 'disabled'),
        'from_address' => (string) $env('MAIL_FROM_ADDRESS', 'no-reply@example.test'),
        'from_name' => (string) $env('MAIL_FROM_NAME', '易运盈后台'),
        'database_config_enabled' => $envBool('MAIL_DATABASE_CONFIG_ENABLED', true),
        'settings_master_key' => (string) $env('MAIL_SETTINGS_MASTER_KEY', ''),
        'settings_active_key_id' => (string) $env('MAIL_SETTINGS_ACTIVE_KEY_ID', 'v1'),
        'settings_keyring_json' => (string) $env('MAIL_SETTINGS_KEYRING_JSON', ''),
        'smtp' => [
            'host' => (string) $env('MAIL_SMTP_HOST', ''),
            'port' => (int) $env('MAIL_SMTP_PORT', 587),
            'encryption' => (string) $env('MAIL_SMTP_ENCRYPTION', 'tls'),
            'username' => (string) $env('MAIL_SMTP_USERNAME', ''),
            'password' => (string) $env('MAIL_SMTP_PASSWORD', ''),
            'timeout' => (int) $env('MAIL_SMTP_TIMEOUT', 10),
            'helo' => (string) $env('MAIL_SMTP_HELO', ''),
        ],
    ],
    'speech' => [
        'provider' => $speechProvider,
        'endpoint' => (string) $env('STT_API_URL', ''),
        'api_key' => (string) $env('STT_API_KEY', ''),
        'model' => (string) $env('STT_MODEL', $localSpeech ? 'base' : 'whisper-1'),
        'timeout' => (int) $env('STT_TIMEOUT', 120),
        'max_bytes' => (int) $env('STT_MAX_BYTES', 104857600),
        'command' => (string) $env('STT_COMMAND', $bundledSttAvailable ? $bundledSttPython : ''),
        'command_args' => (string) $env('STT_COMMAND_ARGS', $bundledSttAvailable ? $bundledSttArgs : ''),
    ],
    'ai' => [
        'enabled' => $envBool('AI_ENABLED', true),
        'provider' => strtolower((string) $env('AI_PROVIDER', 'ollama')),
        'endpoint' => rtrim((string) $env('AI_API_URL', 'http://127.0.0.1:11434'), '/'),
        'api_key' => (string) $env('AI_API_KEY', ''),
        'model' => (string) $env('AI_MODEL', 'qwen2.5:3b'),
        'connect_timeout' => (int) $env('AI_CONNECT_TIMEOUT', 2),
        'timeout' => (int) $env('AI_TIMEOUT', 45),
        'max_tokens' => (int) $env('AI_MAX_TOKENS', 180),
        'temperature' => (float) $env('AI_TEMPERATURE', 0.35),
        'history_limit' => (int) $env('AI_HISTORY_LIMIT', 4),
        'knowledge_limit' => (int) $env('AI_KNOWLEDGE_LIMIT', 8),
        'context_document_limit' => (int) $env('AI_CONTEXT_DOCUMENT_LIMIT', 2),
        'context_chars_per_document' => (int) $env('AI_CONTEXT_CHARS_PER_DOCUMENT', 450),
        'history_message_chars' => (int) $env('AI_HISTORY_MESSAGE_CHARS', 600),
        'retry_after_seconds' => (int) $env('AI_RETRY_AFTER_SECONDS', 20),
        'fallback_enabled' => $envBool('AI_FALLBACK_ENABLED', true),
        'public_knowledge_enabled' => $envBool('AI_PUBLIC_KNOWLEDGE_ENABLED', true),
        'public_knowledge_timeout' => (int) $env('AI_PUBLIC_KNOWLEDGE_TIMEOUT', 6),
        'public_knowledge_cache_seconds' => (int) $env('AI_PUBLIC_KNOWLEDGE_CACHE_SECONDS', 604800),
        'public_knowledge_limit' => (int) $env('AI_PUBLIC_KNOWLEDGE_LIMIT', 3),
    ],
    'weather' => [
        'endpoint' => (string) $env('WEATHER_API_URL', 'https://api.open-meteo.com/v1/forecast'),
        'geocoding_endpoint' => (string) $env('WEATHER_GEOCODING_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
        'secondary_geocoding_endpoint' => (string) $env('WEATHER_SECONDARY_GEOCODING_URL', 'https://nominatim.openstreetmap.org/search'),
        'connect_timeout' => (int) $env('WEATHER_CONNECT_TIMEOUT', 4),
        'timeout' => (int) $env('WEATHER_TIMEOUT', 8),
        'cache_seconds' => (int) $env('WEATHER_CACHE_SECONDS', 600),
        'stale_cache_seconds' => (int) $env('WEATHER_STALE_CACHE_SECONDS', 21600),
        'geocoding_cache_seconds' => (int) $env('WEATHER_GEOCODING_CACHE_SECONDS', 2592000),
    ],
    'news' => [
        'top_endpoint' => (string) $env('NEWS_TOP_RSS_URL', 'https://news.google.com/rss'),
        'search_endpoint' => (string) $env('NEWS_SEARCH_RSS_URL', 'https://news.google.com/rss/search'),
        'connect_timeout' => (int) $env('NEWS_CONNECT_TIMEOUT', 4),
        'timeout' => (int) $env('NEWS_TIMEOUT', 12),
        'cache_seconds' => (int) $env('NEWS_CACHE_SECONDS', 300),
        'stale_cache_seconds' => (int) $env('NEWS_STALE_CACHE_SECONDS', 21600),
        'limit' => (int) $env('NEWS_LIMIT', 10),
    ],
    'maintenance' => [
        'batch_size' => (int) $env('MAINTENANCE_BATCH_SIZE', 5000),
        'token_grace_days' => (int) $env('MAINTENANCE_TOKEN_GRACE_DAYS', 7),
        'verification_days' => (int) $env('MAINTENANCE_VERIFICATION_DAYS', 2),
        'voice_signal_days' => (int) $env('MAINTENANCE_VOICE_SIGNAL_DAYS', 7),
        'request_log_days' => (int) $env('MAINTENANCE_REQUEST_LOG_DAYS', 30),
        'error_log_days' => (int) $env('MAINTENANCE_ERROR_LOG_DAYS', 90),
        'login_log_days' => (int) $env('MAINTENANCE_LOGIN_LOG_DAYS', 180),
        'operation_log_days' => (int) $env('MAINTENANCE_OPERATION_LOG_DAYS', 365),
        'read_notification_days' => (int) $env('MAINTENANCE_READ_NOTIFICATION_DAYS', 180),
    ],
    'voice_call' => [
        'ice_servers' => $voiceCallIceServers,
        'ring_timeout_seconds' => (int) $env('VOICE_CALL_RING_TIMEOUT_SECONDS', 60),
        'active_timeout_seconds' => (int) $env('VOICE_CALL_ACTIVE_TIMEOUT_SECONDS', 45),
        'signal_poll_ms' => (int) $env('VOICE_CALL_SIGNAL_POLL_MS', 100),
    ],
    'pagination' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],
];
