<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Core\HttpException;
use Yiyunying\Services\MailConfigurationService;

function mailAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function mailExpectFailure(callable $callback, string $message): HttpException
{
    try {
        $callback();
    } catch (HttpException $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$original = $GLOBALS['yiyunying_config'];
$secret = 'example-smtp-password-that-must-never-appear';
$oldKey = str_repeat('1a', 32);
$newKey = str_repeat('2b', 32);

try {
    $GLOBALS['yiyunying_config']['mail']['database_config_enabled'] = false;
    $GLOBALS['yiyunying_config']['mail']['settings_master_key'] = $oldKey;
    $GLOBALS['yiyunying_config']['mail']['settings_active_key_id'] = 'old';
    $GLOBALS['yiyunying_config']['mail']['settings_keyring_json'] = '';
    $aesGcmAvailable = function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt')
        && function_exists('openssl_get_cipher_methods')
        && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    if ($aesGcmAvailable) {
        $envelope = MailConfigurationService::encryptPassword($secret);
        mailAssert(!str_contains($envelope, $secret), 'authenticated envelope leaked plaintext');
        mailAssert(MailConfigurationService::decryptPassword($envelope) === $secret, 'AES-GCM roundtrip failed');
        $decoded = json_decode($envelope, true, 512, JSON_THROW_ON_ERROR);
        mailAssert(($decoded['key_id'] ?? null) === 'old', 'envelope must bind the encryption key id');

        $tampered = $decoded;
        $tag = base64_decode((string) $tampered['tag'], true);
        $tag[0] = chr(ord($tag[0]) ^ 1);
        $tampered['tag'] = base64_encode($tag);
        $failure = mailExpectFailure(
            static fn() => MailConfigurationService::decryptPassword(json_encode($tampered, JSON_THROW_ON_ERROR)),
            'tampered AES-GCM tag must fail closed'
        );
        mailAssert(!str_contains($failure->getMessage(), $secret), 'decryption error leaked plaintext');

        // Rotation gate: retain the old key in the server-only keyring, switch the
        // active id, re-read old data, then write a new envelope with the active key.
        $GLOBALS['yiyunying_config']['mail']['settings_master_key'] = '';
        $GLOBALS['yiyunying_config']['mail']['settings_active_key_id'] = 'new';
        $GLOBALS['yiyunying_config']['mail']['settings_keyring_json'] = json_encode([
            'old' => $oldKey,
            'new' => $newKey,
        ], JSON_THROW_ON_ERROR);
        mailAssert(MailConfigurationService::decryptPassword($envelope) === $secret, 'old keyring entry must decrypt during rotation');
        $rotated = MailConfigurationService::encryptPassword($secret);
        $rotatedData = json_decode($rotated, true, 512, JSON_THROW_ON_ERROR);
        mailAssert(($rotatedData['key_id'] ?? null) === 'new', 'new writes must use active key id');

        $GLOBALS['yiyunying_config']['mail']['settings_keyring_json'] = json_encode(['new' => $newKey], JSON_THROW_ON_ERROR);
        mailExpectFailure(
            static fn() => MailConfigurationService::decryptPassword($envelope),
            'removing old key before re-encryption must fail closed'
        );
        mailAssert(MailConfigurationService::decryptPassword($rotated) === $secret, 'rotated envelope must survive old key removal');
    } else {
        $failure = mailExpectFailure(
            static fn() => MailConfigurationService::encryptPassword($secret),
            'missing AES-GCM runtime must reject encrypted configuration writes'
        );
        mailAssert($failure->httpStatus === 503, 'missing AES-GCM runtime must fail with 503');
        mailAssert(!str_contains($failure->getMessage(), $secret), 'missing-runtime error leaked plaintext');
    }

    // Environment-only native/SMTP deployments continue operating without a DB
    // encryption key. The key is mandatory only for writes and DB ciphertext.
    $GLOBALS['yiyunying_config']['app']['env'] = 'production';
    $GLOBALS['yiyunying_config']['mail']['settings_master_key'] = '';
    $GLOBALS['yiyunying_config']['mail']['settings_keyring_json'] = '';
    $GLOBALS['yiyunying_config']['mail']['transport'] = 'native';
    $GLOBALS['yiyunying_config']['mail']['from_address'] = 'no-reply@mail.example.org';
    $GLOBALS['yiyunying_config']['mail']['from_name'] = 'Mail Contract';
    $effective = MailConfigurationService::effective();
    mailAssert(($effective['source'] ?? '') === 'environment', 'missing DB row must use explicit environment fallback');
    mailAssert(($effective['transport'] ?? '') === 'native', 'environment native transport must not require DB encryption key');
    mailExpectFailure(
        static fn() => MailConfigurationService::encryptPassword('new-password'),
        'configuration writes must fail closed without active key'
    );

    $controller = (string) file_get_contents($root . '/app/Controllers/Platform/MailSettingsController.php');
    $service = (string) file_get_contents($root . '/app/Services/MailConfigurationService.php');
    $routes = (string) file_get_contents($root . '/routes/api.php');
    $install = (string) file_get_contents($root . '/database/install.sql');
    $migration = (string) file_get_contents($root . '/database/migrations/upgrade_20260814_secure_mail_settings.sql');
    $dataConsole = (string) file_get_contents($root . '/app/Controllers/Platform/DataConsoleController.php');
    $androidRegistry = (string) file_get_contents(dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java');
    $androidEdition = (string) file_get_contents(dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/AppEdition.java');
    $check = (string) file_get_contents($root . '/tools/check.ps1');

    foreach (['show', 'update', 'test', 'reencrypt'] as $method) {
        mailAssert(str_contains($controller, "function {$method}"), "mail controller missing {$method}");
    }
    mailAssert(str_contains($controller, 'PlatformService::requireLevelOne($actor)'), 'mail API must be level-1 only');
    mailAssert(substr_count($controller, 'self::assertCurrentPassword($request, $actor)') >= 3, 'all mail mutations must require current root password');
    mailAssert(str_contains($controller, "action = 'test_send_started'"), 'test mail must use durable audit rate limit');
    mailAssert(str_contains($service, 'FOR UPDATE'), 'mail update must lock singleton row');
    mailAssert(str_contains($service, 'expected_revision'), 'mail update must use optimistic revision');
    mailAssert(str_contains($service, '不能同时清除并设置 SMTP 密码'), 'password clear/set conflict must be rejected');
    mailAssert(!str_contains($controller, 'smtp_password_ciphertext'), 'controller responses/audit must not expose ciphertext');
    mailAssert(str_contains($routes, '/api/platform/mail-settings/reencrypt'), 'key rotation re-encryption route missing');
    mailAssert(str_contains($install, 'CREATE TABLE IF NOT EXISTS `platform_mail_settings`'), 'fresh install missing secure mail table');
    mailAssert(str_contains($migration, "'2026.08.14-secure-mail-settings'"), 'migration record missing');
    mailAssert(str_contains($dataConsole, "'platform_mail_settings'"), 'data console must hide secure mail table');
    mailAssert(str_contains($androidRegistry, '"mail_settings", "邮件服务配置"'), 'Android root mail module missing');
    mailAssert(str_contains($androidEdition, '!"mail_settings".equals(moduleId)'), 'authorized-platform edition must hide root mail module');
    mailAssert(substr_count($check, 'test-secure-mail-settings-contract.php') >= 2, 'secure mail contract must be required and executed by check.ps1');
} finally {
    $GLOBALS['yiyunying_config'] = $original;
}

echo "Secure root mail settings contract passed.\n";
