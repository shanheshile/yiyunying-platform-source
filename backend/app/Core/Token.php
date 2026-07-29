<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Token
{
    public static function issue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
