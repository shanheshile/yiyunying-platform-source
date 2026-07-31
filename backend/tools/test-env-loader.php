<?php
declare(strict_types=1);

$loadEnvironment = require dirname(__DIR__) . '/config/load-env.php';
$path = tempnam(sys_get_temp_dir(), 'yiyunying-env-');
if ($path === false) {
    throw new RuntimeException('Unable to create temporary dotenv file.');
}

$loadedKey = 'YIYUNYING_ENV_TEST_LOADED';
$priorityKey = 'YIYUNYING_ENV_TEST_PRIORITY';
if (function_exists('putenv')) {
    @putenv($loadedKey);
    @putenv($priorityKey . '=process-value');
} else {
    unset($_ENV[$loadedKey], $_SERVER[$loadedKey]);
    $_SERVER[$priorityKey] = 'process-value';
}

try {
    file_put_contents($path, implode("\n", [
        "\xEF\xBB\xBF# dotenv parser regression test",
        $loadedKey . "='quoted value with # marker'",
        $priorityKey . '=file-value',
        'export YIYUNYING_ENV_TEST_JSON="[{\\"urls\\":[\\"stun:test\\"]}]"',
        'INVALID NAME=ignored',
    ]));
    $loadEnvironment($path);

    $loadedValue = getenv($loadedKey);
    if ($loadedValue === false) {
        $loadedValue = $_ENV[$loadedKey] ?? $_SERVER[$loadedKey] ?? false;
    }
    if ($loadedValue !== 'quoted value with # marker') {
        throw new RuntimeException('Quoted dotenv value was not loaded.');
    }
    $priorityValue = getenv($priorityKey);
    if ($priorityValue === false) {
        $priorityValue = $_ENV[$priorityKey] ?? $_SERVER[$priorityKey] ?? false;
    }
    if ($priorityValue !== 'process-value') {
        throw new RuntimeException('Process environment must override dotenv values.');
    }
    $jsonValue = getenv('YIYUNYING_ENV_TEST_JSON');
    if ($jsonValue === false) {
        $jsonValue = $_ENV['YIYUNYING_ENV_TEST_JSON'] ?? $_SERVER['YIYUNYING_ENV_TEST_JSON'] ?? false;
    }
    if ($jsonValue !== '[{"urls":["stun:test"]}]') {
        throw new RuntimeException('Escaped dotenv value was not decoded.');
    }
    fwrite(STDOUT, "dotenv loader tests passed.\n");
} finally {
    @unlink($path);
    if (function_exists('putenv')) {
        @putenv($loadedKey);
        @putenv($priorityKey);
        @putenv('YIYUNYING_ENV_TEST_JSON');
    }
    unset($_ENV[$loadedKey], $_SERVER[$loadedKey]);
    unset($_ENV[$priorityKey], $_SERVER[$priorityKey]);
    unset($_ENV['YIYUNYING_ENV_TEST_JSON'], $_SERVER['YIYUNYING_ENV_TEST_JSON']);
}
