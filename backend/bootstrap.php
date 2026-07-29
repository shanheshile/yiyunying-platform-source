<?php
declare(strict_types=1);

define('YIYUNYING_ROOT', __DIR__);

// Keep UTF-8 routing usable on small PHP installations that omit mbstring.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string
    {
        return strtolower($value);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int
    {
        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($value, $encoding ?: 'UTF-8');
            if ($length !== false) return $length;
        }
        return preg_match_all('/./us', $value, $matches) ?: 0;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string
    {
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($value, $offset, $length, $encoding ?: 'UTF-8');
            if ($result !== false) return $result;
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($characters, $offset, $length));
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        if (function_exists('iconv_strpos')) {
            return iconv_strpos($haystack, $needle, $offset, $encoding ?: 'UTF-8');
        }
        $byteOffset = strlen(mb_substr($haystack, 0, $offset, $encoding));
        $byteIndex = strpos($haystack, $needle, $byteOffset);
        return $byteIndex === false ? false : mb_strlen(substr($haystack, 0, $byteIndex), $encoding);
    }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return mb_strpos(mb_strtolower($haystack, $encoding), mb_strtolower($needle, $encoding), $offset, $encoding);
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Yiyunying\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = YIYUNYING_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$GLOBALS['yiyunying_config'] = require YIYUNYING_ROOT . '/config/app.php';

function config(string $key = '', $default = null)
{
    $value = $GLOBALS['yiyunying_config'] ?? [];
    if ($key === '') {
        return $value;
    }

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

date_default_timezone_set((string) config('app.timezone', 'Asia/Shanghai'));
