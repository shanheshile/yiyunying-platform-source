<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class GroupQrService
{
    private const PREFIX = 'yyygroup:';
    private const LEGACY_PREFIX = 'yiyunying://group/';

    public static function encode(array $room, int $issuerUserId): string
    {
        $payload = self::base64Url(json_encode([
            'v' => 1,
            'type' => 'group',
            'admin_id' => (int) $room['admin_id'],
            'app_id' => (int) $room['app_id'],
            'room_id' => (int) $room['id'],
            'issuer_user_id' => $issuerUserId,
            'issued_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::PREFIX . $payload . '.' . self::signature($payload);
    }

    public static function decode(string $value, int $expectedAdminId, int $expectedAppId): array
    {
        $value = trim($value);
        if (str_starts_with($value, self::PREFIX)) {
            return self::decodeSigned($value, $expectedAdminId, $expectedAppId);
        }
        if (str_starts_with($value, self::LEGACY_PREFIX)) {
            return self::decodeLegacy($value);
        }
        throw new HttpException('该二维码不是易运盈群聊码', 0, 422);
    }

    private static function decodeSigned(string $value, int $expectedAdminId, int $expectedAppId): array
    {
        $parts = explode('.', substr($value, strlen(self::PREFIX)), 2);
        if (count($parts) !== 2 || !hash_equals(self::signature($parts[0]), $parts[1])) {
            throw new HttpException('群二维码签名无效', 0, 422);
        }
        $decoded = json_decode(self::base64UrlDecode($parts[0]), true);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'group') {
            throw new HttpException('群二维码数据损坏', 0, 422);
        }
        if ((int) ($decoded['admin_id'] ?? 0) !== $expectedAdminId
            || (int) ($decoded['app_id'] ?? 0) !== $expectedAppId) {
            throw new HttpException('群二维码与当前应用不匹配', 0, 422);
        }
        $roomId = (int) ($decoded['room_id'] ?? 0);
        $issuerUserId = (int) ($decoded['issuer_user_id'] ?? 0);
        if ($roomId <= 0 || $issuerUserId <= 0) {
            throw new HttpException('群二维码缺少必要信息', 0, 422);
        }
        return [
            'room_id' => $roomId,
            'issuer_user_id' => $issuerUserId,
            'signed' => true,
        ];
    }

    private static function decodeLegacy(string $value): array
    {
        $path = parse_url($value, PHP_URL_PATH);
        $roomId = (int) trim((string) $path, '/');
        if ($roomId <= 0) throw new HttpException('旧版群二维码数据无效', 0, 422);
        return [
            'room_id' => $roomId,
            'issuer_user_id' => 0,
            'signed' => false,
        ];
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
        if ($decoded === false) throw new HttpException('群二维码数据损坏', 0, 422);
        return $decoded;
    }
}
