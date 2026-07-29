<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;

final class ContactVerificationService
{
    public static function issuePasswordReset(array $app, string $account, string $contact, Request $request): array
    {
        $account = trim($account);
        $contact = trim($contact);
        if ($account === '' || $contact === '') throw new HttpException('账号和邮箱或手机号不能为空', 0, 422);
        $user = Database::one(
            'SELECT id, account, email, phone FROM users
             WHERE admin_id = ? AND app_id = ? AND account = ? AND deleted_at IS NULL AND status = 1',
            [(int) $app['admin_id'], (int) $app['id'], $account]
        );
        if ($user === null) throw new HttpException('用户不存在', 404, 404);
        $channel = '';
        if ($user['email'] !== null && hash_equals((string) $user['email'], $contact)) $channel = 'email';
        elseif ($user['phone'] !== null && hash_equals((string) $user['phone'], $contact)) $channel = 'phone';
        if ($channel === '') throw new HttpException('邮箱或手机号与账号不匹配', 0, 422);

        $recent = Database::one(
            "SELECT created_at FROM verification_codes WHERE app_id = ? AND scene = 'password_reset' AND target = ?
             ORDER BY id DESC LIMIT 1",
            [(int) $app['id'], $account]
        );
        if ($recent !== null && strtotime((string) $recent['created_at']) > time() - 60) {
            throw new HttpException('验证码发送过于频繁，请 60 秒后再试', 429, 429);
        }
        $hourly = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM verification_codes
             WHERE app_id = ? AND scene = 'password_reset' AND target = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [(int) $app['id'], $account]
        )['total'] ?? 0);
        if ($hourly >= 5) throw new HttpException('该账号本小时验证码发送次数已达上限', 429, 429);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiredAt = date('Y-m-d H:i:s', time() + 600);
        $id = Database::insert(
            'INSERT INTO verification_codes
             (admin_id, app_id, scene, target, code_hash, payload_json, attempts, expired_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())',
            [(int) $app['admin_id'], (int) $app['id'], 'password_reset', $account, hash('sha256', $code),
             json_encode(['channel' => $channel, 'contact' => $contact, 'ip' => $request->clientIp()], JSON_UNESCAPED_UNICODE), $expiredAt]
        );
        try {
            if ($channel === 'email') self::deliverEmail($contact, (string) $app['name'], $code, '找回密码');
            else self::deliverPhoneCode($contact, $code);
        } catch (\Throwable $exception) {
            Database::execute('UPDATE verification_codes SET used_at = NOW() WHERE id = ?', [$id]);
            throw $exception;
        }
        $masked = $channel === 'email' ? self::maskEmail($contact) : self::maskPhone($contact);
        $result = [
            'verification_id' => $id, 'scene' => 'password_reset', 'channel' => $channel,
            'target_masked' => $masked, 'expired_at' => $expiredAt,
        ];
        if ((bool) config('app.debug', false)) $result['debug_code'] = $code;
        return $result;
    }

    public static function issueEmail(array $app, string $email, string $scene, Request $request): array
    {
        $email = IdentityService::normalize('email', $email);
        if (!in_array($scene, ['register', 'profile_email'], true)) {
            throw new HttpException('不支持的验证码场景', 0, 422);
        }
        $policy = IdentityService::registrationPolicy((int) $app['id']);
        if ($scene === 'register' && !$policy['email']['enabled']) {
            throw new HttpException('当前应用未启用邮箱注册', 403, 403);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new HttpException('邮箱格式错误', 0, 422);
        IdentityService::assertAvailable('email', $email);
        $codeScene = $scene === 'register' ? 'register_email' : 'profile_email';
        $recent = Database::one(
            'SELECT created_at FROM verification_codes WHERE app_id = ? AND scene = ? AND target = ? ORDER BY id DESC LIMIT 1',
            [(int) $app['id'], $codeScene, $email]
        );
        if ($recent !== null && strtotime((string) $recent['created_at']) > time() - 60) {
            throw new HttpException('验证码发送过于频繁，请 60 秒后再试', 429, 429);
        }
        $hourly = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM verification_codes WHERE app_id = ? AND scene = ? AND target = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [(int) $app['id'], $codeScene, $email]
        )['total'] ?? 0);
        if ($hourly >= 5) throw new HttpException('该邮箱本小时验证码发送次数已达上限', 429, 429);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiredAt = date('Y-m-d H:i:s', time() + 600);
        $id = Database::insert(
            'INSERT INTO verification_codes
             (admin_id, app_id, scene, target, code_hash, payload_json, attempts, expired_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())',
            [(int) $app['admin_id'], (int) $app['id'], $codeScene, $email, hash('sha256', $code),
             json_encode(['channel' => 'email', 'ip' => $request->clientIp()], JSON_UNESCAPED_UNICODE), $expiredAt]
        );
        try {
            self::deliverEmail($email, (string) $app['name'], $code, '注册');
        } catch (\Throwable $exception) {
            Database::execute('UPDATE verification_codes SET used_at = NOW() WHERE id = ?', [$id]);
            throw $exception;
        }
        $result = ['verification_id' => $id, 'scene' => $scene, 'target_masked' => self::maskEmail($email), 'expired_at' => $expiredAt];
        if ((bool) config('app.debug', false)) $result['debug_code'] = $code;
        return $result;
    }

    public static function assertEmailCode(int $appId, string $scene, string $email, string $code): array
    {
        $email = IdentityService::normalize('email', $email);
        $codeScene = $scene === 'register' ? 'register_email' : 'profile_email';
        $row = Database::one(
            'SELECT * FROM verification_codes WHERE app_id = ? AND scene = ? AND target = ?
             AND used_at IS NULL AND expired_at > NOW() ORDER BY id DESC LIMIT 1',
            [$appId, $codeScene, $email]
        );
        if ($row === null || (int) $row['attempts'] >= 5 || !hash_equals((string) $row['code_hash'], hash('sha256', trim($code)))) {
            if ($row !== null) Database::execute('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);
            throw new HttpException('邮箱验证码错误或已过期', 0, 422);
        }
        return $row;
    }

    public static function consume(array $code): void
    {
        Database::execute('UPDATE verification_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [(int) $code['id']]);
    }

    private static function deliverEmail(string $email, string $appName, string $code, string $purpose): void
    {
        $transport = strtolower((string) config('mail.transport', 'native'));
        $subject = $appName . $purpose . '验证码';
        $message = "您正在进行{$purpose}操作，验证码是：{$code}\n\n10 分钟内有效。如非本人操作，请忽略本邮件。";
        if ($transport === 'log') {
            $directory = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($directory)) @mkdir($directory, 0775, true);
            file_put_contents($directory . '/verification-mail.log', date('c') . "\t{$email}\t{$code}\n", FILE_APPEND | LOCK_EX);
            return;
        }
        if ($transport !== 'native') throw new HttpException('邮件发送通道未配置', 503, 503);
        $from = (string) config('mail.from_address', 'no-reply@appht.jjmxg.xyz');
        $fromName = (string) config('mail.from_name', '易运盈后台');
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
        ];
        if (!@mail($email, mb_encode_mimeheader($subject, 'UTF-8'), $message, implode("\r\n", $headers))) {
            throw new HttpException('验证码邮件发送失败，请联系管理员检查邮件通道', 503, 503);
        }
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($name, 0, 1) . str_repeat('*', max(2, mb_strlen($name) - 1)) . '@' . $domain;
    }

    private static function deliverPhoneCode(string $phone, string $code): void
    {
        $transport = strtolower((string) config('sms.transport', 'disabled'));
        if ($transport === 'log' || (bool) config('app.debug', false)) {
            $directory = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($directory)) @mkdir($directory, 0775, true);
            file_put_contents($directory . '/verification-sms.log', date('c') . "\t{$phone}\t{$code}\n", FILE_APPEND | LOCK_EX);
            return;
        }
        throw new HttpException('短信验证码通道未配置，请使用绑定邮箱找回或联系管理员', 503, 503);
    }

    private static function maskPhone(string $phone): string
    {
        $length = mb_strlen($phone);
        if ($length <= 7) return mb_substr($phone, 0, 2) . '***' . mb_substr($phone, -2);
        return mb_substr($phone, 0, 3) . str_repeat('*', max(4, $length - 7)) . mb_substr($phone, -4);
    }
}
