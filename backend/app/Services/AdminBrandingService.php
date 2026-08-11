<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class AdminBrandingService
{
    private const URL_FIELDS = ['official_url', 'download_url', 'official_qq_group_link', 'alipay_qr_url', 'wechat_qr_url'];
    private const TEXT_LIMITS = [
        'official_qq_group' => 100,
        'software_intro' => 5000,
        'about_us' => 5000,
    ];

    public static function get(int $adminId): array
    {
        $row = Database::one('SELECT * FROM admin_public_profiles WHERE admin_id = ?', [$adminId]);
        if ($row === null) {
            return self::present([
                'admin_id' => $adminId,
                'official_url' => '',
                'download_url' => '',
                'official_qq_group' => '',
                'official_qq_group_link' => '',
                'alipay_qr_url' => '',
                'wechat_qr_url' => '',
                'software_intro' => '',
                'about_us' => '',
                'revision' => 0,
                'updated_at' => null,
            ]);
        }
        return self::present($row);
    }

    public static function save(int $adminId, array $data): array
    {
        $current = self::get($adminId);
        $values = [];
        foreach (self::URL_FIELDS as $field) {
            $value = array_key_exists($field, $data) ? trim((string) $data[$field]) : (string) ($current[$field] ?? '');
            if ($value !== '') self::assertUrl($field, $value);
            $values[$field] = mb_substr($value, 0, 1000);
        }
        foreach (self::TEXT_LIMITS as $field => $limit) {
            $value = array_key_exists($field, $data) ? trim((string) $data[$field]) : (string) ($current[$field] ?? '');
            $values[$field] = mb_substr($value, 0, $limit);
        }
        Database::execute(
            'INSERT INTO admin_public_profiles
             (admin_id, official_url, download_url, official_qq_group, official_qq_group_link,
              alipay_qr_url, wechat_qr_url, software_intro, about_us, revision, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE official_url = VALUES(official_url), download_url = VALUES(download_url),
               official_qq_group = VALUES(official_qq_group), official_qq_group_link = VALUES(official_qq_group_link),
               alipay_qr_url = VALUES(alipay_qr_url), wechat_qr_url = VALUES(wechat_qr_url),
               software_intro = VALUES(software_intro), about_us = VALUES(about_us),
               revision = revision + 1, updated_at = NOW()',
            [
                $adminId, $values['official_url'], $values['download_url'], $values['official_qq_group'],
                $values['official_qq_group_link'], $values['alipay_qr_url'], $values['wechat_qr_url'],
                $values['software_intro'], $values['about_us'],
            ]
        );
        return self::get($adminId);
    }

    private static function assertUrl(string $field, string $value): void
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $allowed = $field === 'official_qq_group_link' ? ['https', 'http', 'mqqapi'] : ['https', 'http'];
        if (!in_array($scheme, $allowed, true) || preg_match('/[\x00-\x20]/u', $value) === 1) {
            throw new HttpException($field . ' 不是有效链接', 0, 422);
        }
    }

    private static function present(array $row): array
    {
        $payload = [
            'official_url' => (string) ($row['official_url'] ?? ''),
            'download_url' => (string) ($row['download_url'] ?? ''),
            'official_qq_group' => (string) ($row['official_qq_group'] ?? ''),
            'official_qq_group_link' => (string) ($row['official_qq_group_link'] ?? ''),
            'alipay_qr_url' => (string) ($row['alipay_qr_url'] ?? ''),
            'wechat_qr_url' => (string) ($row['wechat_qr_url'] ?? ''),
            'software_intro' => (string) ($row['software_intro'] ?? ''),
            'about_us' => (string) ($row['about_us'] ?? ''),
            'revision' => (int) ($row['revision'] ?? 0),
            'updated_at' => $row['updated_at'] ?? null,
        ];
        $payload['settings_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payload;
    }
}
