<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class LifecycleService
{
    private const EDITIONS = ['all', 'platform_owner', 'authorized_platform', 'admin', 'user'];
    private const TARGETS = ['global', 'level', 'platform', 'admin', 'app'];

    public static function context(string $edition, string $platformKey = '', string $appKey = '', ?int $adminId = null): array
    {
        if (!in_array($edition, array_slice(self::EDITIONS, 1), true)) throw new HttpException('edition_code 不支持', 0, 422);
        if ($edition === 'user') {
            $row = Database::one(
                'SELECT ap.id AS app_id, ap.app_key, ap.admin_id, a.platform_id, p.level AS platform_level,
                        CASE WHEN p.level = 1 THEN p.id ELSE p.parent_id END AS root_platform_id
                 FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
                 INNER JOIN platform_accounts p ON p.id = a.platform_id
                 WHERE ap.app_key = ? AND ap.deleted_at IS NULL',
                [$appKey]
            );
            if ($row === null) throw new HttpException('应用不存在', 404, 404);
            return [
                'edition_code' => $edition, 'target_level' => 4,
                'root_platform_id' => (int) $row['root_platform_id'],
                'platform_id' => (int) $row['platform_id'], 'admin_id' => (int) $row['admin_id'],
                'app_id' => (int) $row['app_id'], 'app_key' => (string) $row['app_key'],
            ];
        }
        $platform = Database::one('SELECT * FROM platform_accounts WHERE platform_key = ? AND deleted_at IS NULL', [$platformKey]);
        if ($platform === null) throw new HttpException('平台入口不存在', 404, 404);
        $level = match ($edition) { 'platform_owner' => 1, 'authorized_platform' => 2, default => 3 };
        if ($edition === 'platform_owner' && (int) $platform['level'] !== 1) throw new HttpException('当前入口不是 1 级平台', 0, 422);
        if ($edition === 'authorized_platform' && (int) $platform['level'] !== 2) throw new HttpException('当前入口不是 2 级授权平台', 0, 422);
        if ($adminId !== null && $edition === 'admin') PlatformService::ownedAdmin($platform, $adminId);
        return [
            'edition_code' => $edition, 'target_level' => $level,
            'root_platform_id' => (int) $platform['level'] === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'platform_id' => (int) $platform['id'], 'admin_id' => $adminId, 'app_id' => null,
        ];
    }

    public static function check(array $context, int $currentVersionCode, string $clientIp): array
    {
        $edition = (string) $context['edition_code'];
        $issuerIds = [(int) $context['root_platform_id']];
        if ((int) $context['platform_id'] !== (int) $context['root_platform_id']) $issuerIds[] = (int) $context['platform_id'];
        $issuerPlaceholders = implode(',', array_fill(0, count($issuerIds), '?'));
        $matchSql = self::targetMatchSql($context);
        $matchParams = self::targetMatchParams($context);

        $updates = Database::all(
            "SELECT u.* FROM software_update_policies u
             WHERE u.issuer_type = 'platform' AND u.issuer_id IN ({$issuerPlaceholders})
               AND u.edition_code IN ('all', ?) AND u.status = 1
               AND (u.starts_at IS NULL OR u.starts_at <= NOW()) AND (u.ends_at IS NULL OR u.ends_at > NOW())
               AND ({$matchSql})
             ORDER BY u.issuer_level ASC, u.priority DESC, u.version_code DESC, u.id DESC",
            array_merge($issuerIds, [$edition], $matchParams)
        );
        if ($edition === 'user' && $context['app_id'] !== null) {
            $appVersion = Database::one(
                'SELECT id, admin_id AS issuer_id, 3 AS issuer_level, ? AS issuer_type, ? AS edition_code,
                        ? AS target_type, app_id AS target_id, NULL AS target_level, version_name, version_code,
                        min_supported_version_code, apk_url AS download_url, package_name, sha256, size_bytes,
                        update_content AS release_notes,
                        force_update, 0 AS priority, status, NULL AS starts_at, NULL AS ends_at, created_at, updated_at
                 FROM app_versions WHERE app_id = ? AND status = 1 AND deleted_at IS NULL
                 ORDER BY version_code DESC, id DESC LIMIT 1',
                ['admin', 'user', 'app', (int) $context['app_id']]
            );
            if ($appVersion !== null) $updates[] = $appVersion;
        }
        $update = self::selectUpdate($updates, $currentVersionCode);

        $maintenance = Database::all(
            "SELECT m.* FROM maintenance_policies m
             WHERE m.edition_code IN ('all', ?) AND m.status = 1
               AND (m.starts_at IS NULL OR m.starts_at <= NOW()) AND (m.ends_at IS NULL OR m.ends_at > NOW())
               AND ((m.issuer_type = 'platform' AND m.issuer_id IN ({$issuerPlaceholders}))
                    OR (m.issuer_type = 'admin' AND m.issuer_id = ?))
               AND ({$matchSql})
             ORDER BY m.forced DESC, m.issuer_level ASC, m.priority DESC, m.id DESC",
            array_merge([$edition], $issuerIds, [(int) ($context['admin_id'] ?? 0)], $matchParams)
        );
        $activeMaintenance = null;
        foreach ($maintenance as $candidate) {
            $allowlist = json_decode((string) ($candidate['allowlist_json'] ?? ''), true);
            if (is_array($allowlist) && in_array($clientIp, array_map('strval', $allowlist), true)) continue;
            $activeMaintenance = $candidate;
            break;
        }

        $themes = Database::all(
            "SELECT f.* FROM festival_theme_policies f
             WHERE f.edition_code IN ('all', ?) AND f.status = 1
               AND f.starts_at <= NOW() AND f.ends_at > NOW()
               AND ((f.issuer_type = 'platform' AND f.issuer_id IN ({$issuerPlaceholders}))
                    OR (f.issuer_type = 'admin' AND f.issuer_id = ?))
               AND ({$matchSql})
             ORDER BY f.issuer_level ASC, f.priority DESC, f.starts_at DESC, f.id DESC",
            array_merge([$edition], $issuerIds, [(int) ($context['admin_id'] ?? 0)], $matchParams)
        );
        $activeTheme = $themes[0] ?? null;

        return [
            'edition_code' => $edition,
            'current_version_code' => $currentVersionCode,
            'update' => $update,
            'maintenance' => $activeMaintenance === null ? [
                'active' => false, 'forced' => false, 'title' => '', 'message' => '',
            ] : [
                'active' => true, 'forced' => (bool) $activeMaintenance['forced'],
                'policy_id' => (int) $activeMaintenance['id'], 'title' => $activeMaintenance['title'],
                'message' => $activeMaintenance['message'], 'starts_at' => $activeMaintenance['starts_at'],
                'ends_at' => $activeMaintenance['ends_at'], 'issuer_type' => $activeMaintenance['issuer_type'],
                'issuer_level' => (int) $activeMaintenance['issuer_level'],
            ],
            'festival_theme' => $activeTheme === null ? [
                'active' => false,
            ] : [
                'active' => true,
                'policy_id' => (int) $activeTheme['id'],
                'theme_code' => (string) $activeTheme['theme_code'],
                'title' => (string) $activeTheme['title'],
                'greeting' => (string) $activeTheme['greeting'],
                'background_url' => (string) $activeTheme['background_url'],
                'accent_color' => (string) $activeTheme['accent_color'],
                'action_text' => (string) $activeTheme['action_text'],
                'action_url' => (string) $activeTheme['action_url'],
                'config' => self::jsonObject($activeTheme['config_json'] ?? null),
                'starts_at' => (string) $activeTheme['starts_at'],
                'ends_at' => (string) $activeTheme['ends_at'],
                'issuer_type' => (string) $activeTheme['issuer_type'],
                'issuer_level' => (int) $activeTheme['issuer_level'],
            ],
            'server_time' => date('Y-m-d H:i:s'),
        ];
    }

    public static function createPlatformUpdate(array $actor, array $data): int
    {
        PlatformService::requireCapability($actor, 'software.manage');
        [$edition, $targetType, $targetId, $targetLevel] = self::validatePlatformTarget($actor, $data);
        $versionCode = max(1, (int) ($data['version_code'] ?? 0));
        $minCode = max(0, (int) ($data['min_supported_version_code'] ?? 0));
        if ($minCode > $versionCode) throw new HttpException('最低支持版本不能大于发布版本', 0, 422);
        return Database::insert(
            'INSERT INTO software_update_policies
             (issuer_type, issuer_id, issuer_level, edition_code, target_type, target_id, target_level,
               version_name, version_code, min_supported_version_code, download_url, package_name, sha256,
               size_bytes, release_notes, force_update, priority, status, starts_at, ends_at, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [
                'platform', (int) $actor['id'], (int) $actor['level'], $edition, $targetType, $targetId, $targetLevel,
                mb_substr(trim((string) ($data['version_name'] ?? '')), 0, 40), $versionCode, $minCode,
                mb_substr(trim((string) ($data['download_url'] ?? '')), 0, 1000),
                self::packageName($data['package_name'] ?? ''), self::sha256($data['sha256'] ?? ''),
                max(0, (int) ($data['size_bytes'] ?? 0)), (string) ($data['release_notes'] ?? ''),
                self::boolValue($data['force_update'] ?? false) ? 1 : 0, (int) ($data['priority'] ?? 0),
                self::dateValue($data['starts_at'] ?? null), self::dateValue($data['ends_at'] ?? null),
            ]
        );
    }

    public static function createPlatformMaintenance(array $actor, array $data): int
    {
        PlatformService::requireCapability($actor, 'software.manage');
        [$edition, $targetType, $targetId, $targetLevel] = self::validatePlatformTarget($actor, $data);
        return self::insertMaintenance('platform', (int) $actor['id'], (int) $actor['level'], $edition, $targetType, $targetId, $targetLevel, $data);
    }

    public static function createPlatformFestival(array $actor, array $data): int
    {
        PlatformService::requireCapability($actor, 'software.manage');
        [$edition, $targetType, $targetId, $targetLevel] = self::validatePlatformTarget($actor, $data);
        return self::insertFestival('platform', (int) $actor['id'], (int) $actor['level'], $edition, $targetType, $targetId, $targetLevel, $data);
    }

    public static function createAdminMaintenance(array $admin, int $appId, array $data): int
    {
        AppService::owned((int) $admin['id'], $appId);
        return self::insertMaintenance('admin', (int) $admin['id'], 3, 'user', 'app', $appId, 4, $data);
    }

    public static function createAdminFestival(array $admin, int $appId, array $data): int
    {
        AppService::owned((int) $admin['id'], $appId);
        return self::insertFestival('admin', (int) $admin['id'], 3, 'user', 'app', $appId, 4, $data);
    }

    public static function manageablePolicy(array $actor, string $table, int $id): array
    {
        if (!in_array($table, ['software_update_policies', 'maintenance_policies', 'festival_theme_policies'], true)) throw new \InvalidArgumentException('Invalid lifecycle table');
        $row = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($row === null) throw new HttpException('生命周期策略不存在', 404, 404);
        $allowed = (string) $row['issuer_type'] === 'platform' && (int) $row['issuer_id'] === (int) $actor['id'];
        if ((int) $actor['level'] === 1 && (string) $row['issuer_type'] === 'platform') {
            $issuer = Database::one('SELECT parent_id FROM platform_accounts WHERE id = ?', [(int) $row['issuer_id']]);
            if ((int) ($issuer['parent_id'] ?? 0) === (int) $actor['id']) $allowed = true;
        }
        if (!$allowed) throw new HttpException('无权管理该生命周期策略', 403, 403);
        return $row;
    }

    public static function adminPolicy(array $admin, int $appId, int $id): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $row = Database::one(
            "SELECT * FROM maintenance_policies WHERE id = ? AND issuer_type = 'admin' AND issuer_id = ? AND target_type = 'app' AND target_id = ?",
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) throw new HttpException('应用维护策略不存在', 404, 404);
        return $row;
    }

    public static function adminFestivalPolicy(array $admin, int $appId, int $id): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $row = Database::one(
            "SELECT * FROM festival_theme_policies WHERE id = ? AND issuer_type = 'admin' AND issuer_id = ? AND target_type = 'app' AND target_id = ?",
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) throw new HttpException('节日界面策略不存在', 404, 404);
        return $row;
    }

    private static function insertMaintenance(string $issuerType, int $issuerId, int $issuerLevel, string $edition, string $targetType, ?int $targetId, ?int $targetLevel, array $data): int
    {
        $title = trim((string) ($data['title'] ?? '系统维护'));
        $message = trim((string) ($data['message'] ?? '系统维护中，请稍后再试'));
        if ($title === '' || $message === '') throw new HttpException('维护标题和说明不能为空', 0, 422);
        $allowlist = $data['allowlist'] ?? [];
        if (!is_array($allowlist)) throw new HttpException('allowlist 必须是数组', 0, 422);
        return Database::insert(
            'INSERT INTO maintenance_policies
             (issuer_type, issuer_id, issuer_level, edition_code, target_type, target_id, target_level,
              title, message, forced, allowlist_json, priority, status, starts_at, ends_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [
                $issuerType, $issuerId, $issuerLevel, $edition, $targetType, $targetId, $targetLevel,
                mb_substr($title, 0, 200), $message, self::boolValue($data['forced'] ?? true) ? 1 : 0,
                json_encode(array_values(array_unique(array_map('strval', $allowlist))), JSON_UNESCAPED_UNICODE),
                (int) ($data['priority'] ?? 0), self::dateValue($data['starts_at'] ?? null), self::dateValue($data['ends_at'] ?? null),
            ]
        );
    }

    private static function insertFestival(string $issuerType, int $issuerId, int $issuerLevel, string $edition, string $targetType, ?int $targetId, ?int $targetLevel, array $data): int
    {
        $themeCode = strtolower(trim((string) ($data['theme_code'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $themeCode) !== 1) {
            throw new HttpException('theme_code 只能使用 2-80 位小写字母、数字、下划线或短横线', 0, 422);
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') throw new HttpException('节日界面标题不能为空', 0, 422);
        $startsAt = self::requiredDate($data['starts_at'] ?? null, '开始时间');
        $endsAt = self::requiredDate($data['ends_at'] ?? null, '结束时间');
        if (strtotime($endsAt) <= strtotime($startsAt)) throw new HttpException('结束时间必须晚于开始时间', 0, 422);
        $accentColor = strtoupper(trim((string) ($data['accent_color'] ?? '#1677FF')));
        if (preg_match('/^#[0-9A-F]{6}$/', $accentColor) !== 1) throw new HttpException('accent_color 必须是 #RRGGBB 格式', 0, 422);
        $config = $data['config'] ?? [];
        if (!is_array($config)) throw new HttpException('config 必须是对象', 0, 422);
        return Database::insert(
            'INSERT INTO festival_theme_policies
             (issuer_type, issuer_id, issuer_level, edition_code, target_type, target_id, target_level,
              theme_code, title, greeting, background_url, accent_color, action_text, action_url, config_json,
              priority, status, starts_at, ends_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [
                $issuerType, $issuerId, $issuerLevel, $edition, $targetType, $targetId, $targetLevel,
                $themeCode, mb_substr($title, 0, 160), mb_substr(trim((string) ($data['greeting'] ?? '')), 0, 500),
                mb_substr(trim((string) ($data['background_url'] ?? '')), 0, 1000), $accentColor,
                mb_substr(trim((string) ($data['action_text'] ?? '')), 0, 80),
                mb_substr(trim((string) ($data['action_url'] ?? '')), 0, 1000),
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) ($data['priority'] ?? 0), $startsAt, $endsAt,
            ]
        );
    }

    private static function validatePlatformTarget(array $actor, array $data): array
    {
        $edition = trim((string) ($data['edition_code'] ?? ''));
        $targetType = trim((string) ($data['target_type'] ?? ''));
        $targetId = isset($data['target_id']) && $data['target_id'] !== '' ? (int) $data['target_id'] : null;
        $targetLevel = isset($data['target_level']) && $data['target_level'] !== '' ? (int) $data['target_level'] : null;
        if (!in_array($edition, self::EDITIONS, true)) throw new HttpException('edition_code 不支持', 0, 422);
        if (!in_array($targetType, self::TARGETS, true)) throw new HttpException('target_type 不支持', 0, 422);
        if ((int) $actor['level'] === 2 && $edition === 'platform_owner') throw new HttpException('2 级平台不能管理 1 级平台版', 403, 403);
        GovernanceService::assertTarget($actor, $targetType, $targetId, $targetLevel);
        return [$edition, $targetType, $targetId, $targetLevel];
    }

    private static function selectUpdate(array $updates, int $current): array
    {
        if ($updates === []) return ['available' => false, 'force_update' => false];
        usort($updates, static function (array $a, array $b) use ($current): int {
            $aForced = (int) $a['force_update'] === 1 || $current < (int) $a['min_supported_version_code'];
            $bForced = (int) $b['force_update'] === 1 || $current < (int) $b['min_supported_version_code'];
            if ($aForced !== $bForced) return $aForced ? -1 : 1;
            if ($aForced && (int) $a['issuer_level'] !== (int) $b['issuer_level']) return (int) $a['issuer_level'] <=> (int) $b['issuer_level'];
            if ((int) $a['version_code'] !== (int) $b['version_code']) return (int) $b['version_code'] <=> (int) $a['version_code'];
            return (int) ($a['issuer_level'] ?? 9) <=> (int) ($b['issuer_level'] ?? 9);
        });
        $latest = $updates[0];
        $available = (int) $latest['version_code'] > $current;
        return [
            'available' => $available,
            'force_update' => $available && ((int) $latest['force_update'] === 1 || $current < (int) $latest['min_supported_version_code']),
            'policy_id' => (int) $latest['id'], 'version_name' => $latest['version_name'],
            'version_code' => (int) $latest['version_code'], 'min_supported_version_code' => (int) $latest['min_supported_version_code'],
            'download_url' => $latest['download_url'], 'release_notes' => $latest['release_notes'],
            'package_name' => (string) ($latest['package_name'] ?? ''),
            'sha256' => (string) ($latest['sha256'] ?? ''),
            'size_bytes' => (int) ($latest['size_bytes'] ?? 0),
            'issuer_type' => $latest['issuer_type'], 'issuer_level' => (int) $latest['issuer_level'],
        ];
    }

    private static function targetMatchSql(array $context): string
    {
        return "target_type = 'global'
            OR (target_type = 'level' AND target_level = ?)
            OR (target_type = 'platform' AND target_id = ?)
            OR (target_type = 'admin' AND target_id = ?)
            OR (target_type = 'app' AND target_id = ?)";
    }

    private static function targetMatchParams(array $context): array
    {
        return [(int) $context['target_level'], (int) $context['platform_id'], (int) ($context['admin_id'] ?? 0), (int) ($context['app_id'] ?? 0)];
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function dateValue($value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        $time = strtotime($value); if ($time === false) throw new HttpException('时间格式错误', 0, 422);
        return date('Y-m-d H:i:s', $time);
    }

    private static function requiredDate($value, string $label): string
    {
        $date = self::dateValue($value);
        if ($date === null) throw new HttpException($label . '不能为空', 0, 422);
        return $date;
    }

    private static function packageName($value): string
    {
        $name = trim((string) $value);
        if ($name !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/', $name) !== 1) {
            throw new HttpException('package_name 不是有效的 Android 包名', 0, 422);
        }
        return mb_substr($name, 0, 190);
    }

    private static function sha256($value): string
    {
        $hash = strtolower(trim((string) $value));
        if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) throw new HttpException('sha256 必须是 64 位十六进制摘要', 0, 422);
        return $hash;
    }

    private static function jsonObject($value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
