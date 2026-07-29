<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class FriendQrService
{
    public static function encode(array $user): string
    {
        $payload = self::base64Url(json_encode([
            'v' => 1,
            'type' => 'friend',
            'app_id' => (int) $user['app_id'],
            'uid' => (string) $user['uid'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 'yyyfriend:' . $payload . '.' . self::signature($payload);
    }

    public static function decode(string $value, int $expectedAppId): string
    {
        $value = trim($value);
        if (!str_starts_with($value, 'yyyfriend:')) throw new HttpException('该二维码不是易运盈好友码', 0, 422);
        $parts = explode('.', substr($value, 10), 2);
        if (count($parts) !== 2 || !hash_equals(self::signature($parts[0]), $parts[1])) {
            throw new HttpException('好友二维码签名无效', 0, 422);
        }
        $decoded = json_decode(self::base64UrlDecode($parts[0]), true);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'friend' || (int) ($decoded['app_id'] ?? 0) !== $expectedAppId) {
            throw new HttpException('好友二维码与当前应用不匹配', 0, 422);
        }
        $uid = trim((string) ($decoded['uid'] ?? ''));
        if ($uid === '') throw new HttpException('好友二维码缺少 UID', 0, 422);
        return $uid;
    }

    private static function signature(string $payload): string
    {
        return self::base64Url(hash_hmac('sha256', $payload, (string) config('security.qr_signing_key'), true));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new HttpException('好友二维码数据损坏', 0, 422);
        return $decoded;
    }
}
