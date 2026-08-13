<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use PDOException;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

/**
 * Global mail transport configuration owned exclusively by the level-1 platform.
 *
 * Non-secret fields are stored in their own singleton table. The SMTP password is
 * stored only as an authenticated, versioned AES-256-GCM envelope. Environment
 * configuration is used only when no database row exists (or DB overrides are
 * explicitly disabled by the server operator); a stored disabled row therefore
 * cannot be bypassed by environment fallback.
 */
final class MailConfigurationService
{
    private const ROW_ID = 1;
    private const CIPHER_VERSION = 1;
    private const CIPHER_ALGORITHM = 'aes-256-gcm';

    public static function status(): array
    {
        $row = self::databaseConfigurationEnabled() ? self::storedRow() : null;
        $source = $row === null ? 'environment' : 'database';
        $configuration = $row === null ? self::environmentConfiguration() : self::rowConfiguration($row, false);
        $issues = self::configurationIssues($configuration, $source, false);
        $passwordCiphertext = $row === null ? '' : trim((string) ($row['smtp_password_ciphertext'] ?? ''));
        if ($passwordCiphertext !== '' && !self::masterKeyConfigured()) {
            $issues[] = '缺少可解密当前 SMTP 密码的邮件配置密钥，数据库邮件发送已关闭';
        } elseif ($passwordCiphertext !== '') {
            try {
                self::decryptPassword((string) $row['smtp_password_ciphertext']);
            } catch (HttpException $exception) {
                $issues[] = '已保存的 SMTP 密码无法通过认证解密';
            }
        }
        $issues = array_values(array_unique($issues));
        $transport = (string) ($configuration['transport'] ?? 'disabled');
        return [
            'id' => self::ROW_ID,
            'source' => $source,
            'transport' => $transport,
            'transport_label' => match ($transport) {
                'native' => '服务器本机邮件服务',
                'smtp' => 'SMTP（TLS）',
                'log' => '仅本地测试日志',
                default => '已禁用',
            },
            'from_address' => (string) ($configuration['from_address'] ?? ''),
            'from_name' => (string) ($configuration['from_name'] ?? ''),
            'smtp_host' => (string) ($configuration['smtp']['host'] ?? ''),
            'smtp_port' => (int) ($configuration['smtp']['port'] ?? 0),
            'smtp_encryption' => (string) ($configuration['smtp']['encryption'] ?? ''),
            'smtp_username' => (string) ($configuration['smtp']['username'] ?? ''),
            'smtp_password_configured' => self::passwordConfigured($configuration, $row),
            'master_key_configured' => self::masterKeyConfigured(),
            'configuration_write_ready' => self::masterKeyConfigured() && self::databaseConfigurationEnabled(),
            'database_override_configured' => $row !== null,
            'revision' => $row === null ? 0 : (int) ($row['revision'] ?? 1),
            'expected_revision' => $row === null ? 0 : (int) ($row['revision'] ?? 1),
            'configuration_valid' => $issues === [],
            'send_ready' => $transport !== 'disabled' && $issues === [],
            'issues' => $issues,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function update(array $input, int $actorId): array
    {
        self::activeKey();
        if (!self::databaseConfigurationEnabled()) {
            throw new HttpException('服务器已关闭数据库邮件配置覆盖，不能从总控端保存', 503, 503);
        }
        $expectedRevision = (int) ($input['expected_revision'] ?? -1);
        Database::transaction(static function () use ($input, $actorId, $expectedRevision): void {
            $existing = self::storedRow(true);
            $actualRevision = $existing === null ? 0 : (int) ($existing['revision'] ?? 1);
            if ($expectedRevision < 0 || $expectedRevision !== $actualRevision) {
                throw new HttpException('邮件配置已被其他会话修改，请刷新后重试', 0, 409, [
                    'current_revision' => $actualRevision,
                ]);
            }
            $normalized = self::normalizeInput($input, $existing);
            if ($existing === null) {
                Database::execute(
                    'INSERT INTO platform_mail_settings
                     (id, transport, from_address, from_name, smtp_host, smtp_port, smtp_encryption,
                      smtp_username, smtp_password_ciphertext, revision, updated_by_platform_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                    [
                        self::ROW_ID, $normalized['transport'], $normalized['from_address'],
                        $normalized['from_name'], $normalized['smtp_host'], $normalized['smtp_port'],
                        $normalized['smtp_encryption'], $normalized['smtp_username'],
                        $normalized['smtp_password_ciphertext'], $actorId,
                    ]
                );
                return;
            }
            Database::execute(
                'UPDATE platform_mail_settings SET transport = ?, from_address = ?, from_name = ?,
                 smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?,
                 smtp_password_ciphertext = ?, revision = revision + 1, updated_by_platform_id = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $normalized['transport'], $normalized['from_address'], $normalized['from_name'],
                    $normalized['smtp_host'], $normalized['smtp_port'], $normalized['smtp_encryption'],
                    $normalized['smtp_username'], $normalized['smtp_password_ciphertext'], $actorId,
                    self::ROW_ID,
                ]
            );
        });
        return self::status();
    }

    public static function reencryptPassword(int $expectedRevision, int $actorId): array
    {
        self::activeKey();
        if (!self::databaseConfigurationEnabled()) {
            throw new HttpException('服务器已关闭数据库邮件配置覆盖', 503, 503);
        }
        Database::transaction(static function () use ($expectedRevision, $actorId): void {
            $row = self::storedRow(true);
            if ($row === null) throw new HttpException('尚未保存数据库邮件配置', 0, 409);
            $actualRevision = (int) ($row['revision'] ?? 1);
            if ($expectedRevision !== $actualRevision) {
                throw new HttpException('邮件配置已被其他会话修改，请刷新后重试', 0, 409, [
                    'current_revision' => $actualRevision,
                ]);
            }
            $ciphertext = trim((string) ($row['smtp_password_ciphertext'] ?? ''));
            if ($ciphertext === '') throw new HttpException('当前没有可重加密的 SMTP 密码', 0, 409);
            $plaintext = self::decryptPassword($ciphertext);
            $nextCiphertext = self::encryptPassword($plaintext);
            unset($plaintext);
            Database::execute(
                'UPDATE platform_mail_settings SET smtp_password_ciphertext = ?, revision = revision + 1,
                 updated_by_platform_id = ?, updated_at = NOW() WHERE id = ?',
                [$nextCiphertext, $actorId, self::ROW_ID]
            );
        });
        return self::status();
    }

    /** Returns the only configuration that mail delivery is allowed to use. */
    public static function effective(): array
    {
        $row = self::databaseConfigurationEnabled() ? self::storedRow() : null;
        $source = $row === null ? 'environment' : 'database';
        $configuration = $row === null
            ? self::environmentConfiguration()
            : self::rowConfiguration($row, true);
        $issues = self::configurationIssues($configuration, $source, true);
        if ($issues !== []) {
            throw new HttpException('邮件配置不可用：' . $issues[0], 503, 503);
        }
        $configuration['source'] = $source;
        return $configuration;
    }

    public static function encryptPassword(string $password): string
    {
        if ($password === '' || strlen($password) > 4096) {
            throw new HttpException('SMTP 密码长度无效', 0, 422);
        }
        if (!function_exists('openssl_encrypt')) {
            throw new HttpException('服务器缺少认证加密支持', 503, 503);
        }
        [$keyId, $key] = self::activeKey();
        $aad = self::aad($keyId);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $password,
            self::CIPHER_ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new HttpException('SMTP 密码加密失败', 503, 503);
        }
        $envelope = json_encode([
            'version' => self::CIPHER_VERSION,
            'algorithm' => self::CIPHER_ALGORITHM,
            'key_id' => $keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($envelope)) throw new HttpException('SMTP 密码加密结果无效', 503, 503);
        return $envelope;
    }

    public static function decryptPassword(string $envelope): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new HttpException('服务器缺少认证解密支持', 503, 503);
        }
        $decoded = json_decode($envelope, true);
        if (!is_array($decoded)
            || (int) ($decoded['version'] ?? 0) !== self::CIPHER_VERSION
            || (string) ($decoded['algorithm'] ?? '') !== self::CIPHER_ALGORITHM) {
            throw new HttpException('SMTP 密码密文版本无效', 503, 503);
        }
        $keyId = (string) ($decoded['key_id'] ?? '');
        $key = self::keyForId($keyId);
        $nonce = self::strictBase64((string) ($decoded['nonce'] ?? ''), 12);
        $ciphertext = self::strictBase64((string) ($decoded['ciphertext'] ?? ''), null);
        $tag = self::strictBase64((string) ($decoded['tag'] ?? ''), 16);
        if ($ciphertext === '') throw new HttpException('SMTP 密码密文无效', 503, 503);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($keyId)
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new HttpException('SMTP 密码认证解密失败', 503, 503);
        }
        return $plaintext;
    }

    public static function masterKeyConfigured(): bool
    {
        try {
            self::activeKey();
            return true;
        } catch (HttpException $exception) {
            return false;
        }
    }

    private static function normalizeInput(array $input, ?array $existing): array
    {
        $transport = strtolower(trim((string) ($input['transport'] ?? '')));
        if (!in_array($transport, ['disabled', 'native', 'smtp'], true)) {
            throw new HttpException('邮件通道只能选择 disabled、native 或 smtp', 0, 422);
        }
        $fromAddress = strtolower(trim((string) ($input['from_address'] ?? '')));
        $fromName = self::singleLine((string) ($input['from_name'] ?? ''));
        $smtpHost = strtolower(trim((string) ($input['smtp_host'] ?? '')));
        $smtpPort = (int) ($input['smtp_port'] ?? 587);
        $smtpEncryption = strtolower(trim((string) ($input['smtp_encryption'] ?? 'tls')));
        $smtpUsername = self::singleLine((string) ($input['smtp_username'] ?? ''));
        $clearPassword = filter_var($input['clear_smtp_password'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $providedPassword = array_key_exists('smtp_password', $input) ? (string) $input['smtp_password'] : '';
        if ($clearPassword && $providedPassword !== '') {
            throw new HttpException('不能同时清除并设置 SMTP 密码', 0, 422);
        }
        $ciphertext = $clearPassword ? null : ($existing['smtp_password_ciphertext'] ?? null);
        if ($providedPassword !== '') $ciphertext = self::encryptPassword($providedPassword);

        $configuration = [
            'transport' => $transport,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'smtp' => [
                'host' => $smtpHost,
                'port' => $smtpPort,
                'encryption' => $smtpEncryption,
                'username' => $smtpUsername,
                'password' => $ciphertext === null ? '' : '__configured__',
                'timeout' => 10,
                'helo' => '',
            ],
        ];
        $issues = self::configurationIssues($configuration, 'database', false);
        if ($issues !== []) throw new HttpException($issues[0], 0, 422);
        return [
            'transport' => $transport,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_password_ciphertext' => $ciphertext,
        ];
    }

    private static function configurationIssues(array $configuration, string $source, bool $forSending): array
    {
        $issues = [];
        $transport = strtolower(trim((string) ($configuration['transport'] ?? 'disabled')));
        $allowed = $source === 'environment' && (string) config('app.env', 'production') !== 'production'
            ? ['disabled', 'log', 'native', 'smtp']
            : ['disabled', 'native', 'smtp'];
        if (!in_array($transport, $allowed, true)) $issues[] = '邮件通道类型无效';
        if ($forSending && $transport === 'disabled') $issues[] = '邮件通道已禁用';
        if ($transport === 'disabled' || $transport === 'log') return $issues;

        $from = strtolower(trim((string) ($configuration['from_address'] ?? '')));
        $name = self::singleLine((string) ($configuration['from_name'] ?? ''));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || strlen($from) > 190) {
            $issues[] = '发件邮箱格式无效';
        }
        if ($name === '' || mb_strlen($name) > 100) $issues[] = '发件名称长度无效';
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
        if ((string) config('app.env', 'production') === 'production'
            && ($domain === ''
                || !str_contains($domain, '.')
                || preg_match('/(?:^|\.)(?:example|invalid|test|localhost)$/', $domain) === 1)) {
            $issues[] = '生产发件邮箱必须使用可验证的正式域名';
        }
        if ($transport !== 'smtp') return $issues;

        $smtp = is_array($configuration['smtp'] ?? null) ? $configuration['smtp'] : [];
        $host = strtolower(trim((string) ($smtp['host'] ?? '')));
        $port = (int) ($smtp['port'] ?? 0);
        $encryption = strtolower(trim((string) ($smtp['encryption'] ?? '')));
        $username = (string) ($smtp['username'] ?? '');
        $password = (string) ($smtp['password'] ?? '');
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            $issues[] = 'SMTP 主机名格式无效';
        }
        if ($port < 1 || $port > 65535) $issues[] = 'SMTP 端口无效';
        $localUnencrypted = $source === 'environment'
            && (string) config('app.env', 'production') !== 'production'
            && in_array($host, ['127.0.0.1', 'localhost'], true)
            && $encryption === 'none';
        if (!in_array($encryption, ['tls', 'ssl'], true) && !$localUnencrypted) {
            $issues[] = 'SMTP 必须启用 TLS 或 SSL';
        }
        if (($username === '') !== ($password === '')) $issues[] = 'SMTP 用户名和密码必须同时配置或同时留空';
        if (in_array($encryption, ['tls', 'ssl'], true) && !extension_loaded('openssl')) {
            $issues[] = '服务器缺少 OpenSSL TLS 支持';
        }
        return $issues;
    }

    private static function rowConfiguration(array $row, bool $includePassword): array
    {
        $ciphertext = trim((string) ($row['smtp_password_ciphertext'] ?? ''));
        return [
            'transport' => strtolower((string) ($row['transport'] ?? 'disabled')),
            'from_address' => (string) ($row['from_address'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
            'smtp' => [
                'host' => (string) ($row['smtp_host'] ?? ''),
                'port' => (int) ($row['smtp_port'] ?? 587),
                'encryption' => (string) ($row['smtp_encryption'] ?? 'tls'),
                'username' => (string) ($row['smtp_username'] ?? ''),
                'password' => $includePassword && $ciphertext !== '' ? self::decryptPassword($ciphertext) : ($ciphertext === '' ? '' : '__configured__'),
                'timeout' => max(3, min(30, (int) config('mail.smtp.timeout', 10))),
                'helo' => (string) config('mail.smtp.helo', ''),
            ],
        ];
    }

    private static function environmentConfiguration(): array
    {
        $mail = config('mail', []);
        if (!is_array($mail)) $mail = [];
        $smtp = is_array($mail['smtp'] ?? null) ? $mail['smtp'] : [];
        return [
            'transport' => strtolower(trim((string) ($mail['transport'] ?? 'disabled'))),
            'from_address' => trim((string) ($mail['from_address'] ?? '')),
            'from_name' => trim((string) ($mail['from_name'] ?? '')),
            'smtp' => [
                'host' => trim((string) ($smtp['host'] ?? '')),
                'port' => (int) ($smtp['port'] ?? 587),
                'encryption' => strtolower(trim((string) ($smtp['encryption'] ?? 'tls'))),
                'username' => (string) ($smtp['username'] ?? ''),
                'password' => (string) ($smtp['password'] ?? ''),
                'timeout' => max(3, min(30, (int) ($smtp['timeout'] ?? 10))),
                'helo' => trim((string) ($smtp['helo'] ?? '')),
            ],
        ];
    }

    private static function storedRow(bool $forUpdate = false): ?array
    {
        try {
            return Database::one(
                'SELECT * FROM platform_mail_settings WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
                [self::ROW_ID]
            );
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ((string) $exception->getCode() === '42S02' || $driverCode === 1146) return null;
            throw $exception;
        }
    }

    private static function passwordConfigured(array $configuration, ?array $row): bool
    {
        if ($row !== null) return trim((string) ($row['smtp_password_ciphertext'] ?? '')) !== '';
        return (string) ($configuration['smtp']['password'] ?? '') !== '';
    }

    private static function activeKey(): array
    {
        if (!function_exists('openssl_get_cipher_methods')
            || !in_array(self::CIPHER_ALGORITHM, openssl_get_cipher_methods(), true)) {
            throw new HttpException('服务器缺少邮件配置认证加密支持', 503, 503);
        }
        $keyId = trim((string) config('mail.settings_active_key_id', 'v1'));
        return [$keyId, self::keyForId($keyId)];
    }

    private static function keyForId(string $keyId): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $keyId) !== 1) {
            throw new HttpException('邮件配置密钥标识无效', 503, 503);
        }
        $keys = self::keyring();
        $hex = (string) ($keys[$keyId] ?? '');
        $key = preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? hex2bin($hex) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new HttpException('邮件配置密钥缺失，已禁止读取密文和写入配置', 503, 503);
        }
        return $key;
    }

    private static function keyring(): array
    {
        $result = [];
        $json = trim((string) config('mail.settings_keyring_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) throw new HttpException('邮件配置密钥环格式无效', 503, 503);
            foreach ($decoded as $keyId => $hex) {
                if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', (string) $keyId) !== 1
                    || preg_match('/^[0-9a-f]{64}$/', (string) $hex) !== 1) {
                    throw new HttpException('邮件配置密钥环格式无效', 503, 503);
                }
                $result[(string) $keyId] = (string) $hex;
            }
        }
        // Single-key deployments remain supported. During rotation, place both old
        // and new keys in MAIL_SETTINGS_KEYRING_JSON before changing the active ID.
        $legacy = trim((string) config('mail.settings_master_key', ''));
        $active = trim((string) config('mail.settings_active_key_id', 'v1'));
        if ($legacy !== '' && preg_match('/^[0-9a-f]{64}$/', $legacy) === 1
            && !array_key_exists($active, $result)) {
            $result[$active] = $legacy;
        }
        return $result;
    }

    private static function aad(string $keyId): string
    {
        return 'yiyunying|platform_mail_settings|1|smtp_password_ciphertext|envelope:'
            . self::CIPHER_VERSION . '|key:' . $keyId;
    }

    private static function databaseConfigurationEnabled(): bool
    {
        return (bool) config('mail.database_config_enabled', true);
    }

    private static function strictBase64(string $value, ?int $expectedLength): string
    {
        if ($value === '' || base64_encode(base64_decode($value, true) ?: '') !== $value) {
            throw new HttpException('SMTP 密码密文编码无效', 503, 503);
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || ($expectedLength !== null && strlen($decoded) !== $expectedLength)) {
            throw new HttpException('SMTP 密码密文长度无效', 503, 503);
        }
        return $decoded;
    }

    private static function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }
}
