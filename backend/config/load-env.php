<?php
declare(strict_types=1);

/**
 * Load a small, dependency-free dotenv file without overriding process-level
 * environment variables. Production PHP-FPM values therefore keep priority.
 */
return static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }
        $processValue = getenv($name);
        if ($processValue !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
            continue;
        }

        $value = trim(substr($line, $separator + 1));
        $length = strlen($value);
        if ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") {
            $value = substr($value, 1, -1);
            $value = str_replace(["\\'", "\\\\"], ["'", "\\"], $value);
        } elseif ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            $value = stripcslashes(substr($value, 1, -1));
        } else {
            $value = preg_replace('/\s+[;#].*$/', '', $value) ?? $value;
            $value = rtrim($value);
        }

        if (function_exists('putenv')) {
            @putenv($name . '=' . $value);
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
};
