<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Password
{
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
}
