<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Password
{
    private const KNOWN_WEAK_PASSWORDS = [
        '000000', '111111', '123456', '1234567', '12345678', '123456789', '1234567890',
        '654321', '888888', 'abc123', 'admin', 'admin123', 'password', 'password1',
        'qwerty', 'qwerty123', 'root', 'root123', 'user', 'user123',
    ];

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $storedHash): bool
    {
        if (strncmp($storedHash, 'pbkdf2_sha256$', 14) === 0) {
            $parts = explode('$', $storedHash, 4);
            if (count($parts) !== 4 || !ctype_digit($parts[1])) {
                return false;
            }
            $actual = base64_encode(hash_pbkdf2(
                'sha256',
                $password,
                $parts[2],
                (int) $parts[1],
                32,
                true
            ));
            return hash_equals($parts[3], $actual);
        }
        return password_verify($password, $storedHash);
    }

    public static function needsRehash(string $storedHash): bool
    {
        return strncmp($storedHash, 'pbkdf2_sha256$', 14) === 0
            || password_needs_rehash($storedHash, PASSWORD_DEFAULT);
    }

    public static function isKnownWeak(string $password): bool
    {
        $normalized = trim($password);
        if (function_exists('mb_strtolower')) {
            $normalized = mb_strtolower($normalized, 'UTF-8');
        } else {
            $normalized = strtolower($normalized);
        }
        if (in_array($normalized, self::KNOWN_WEAK_PASSWORDS, true)) {
            return true;
        }
        return preg_match('/^(.)\\1{5,}$/us', $normalized) === 1;
    }

    public static function isAcceptable(string $password): bool
    {
        $minimum = max(8, (int) config('security.password_min_length', 8));
        $length = strlen($password);
        return $length >= $minimum && $length <= 72 && !self::isKnownWeak($password);
    }

    public static function assertAcceptable(string $password, string $field = 'password'): string
    {
        $minimum = max(8, (int) config('security.password_min_length', 8));
        $length = strlen($password);
        if ($length < $minimum || $length > 72) {
            throw new HttpException("{$field} 长度必须在 {$minimum}-72 个字节之间", 0, 422);
        }
        if (self::isKnownWeak($password)) {
            throw new HttpException("{$field} 不能使用已知弱密码，请改用独立随机密码", 0, 422);
        }
        return $password;
    }
}
