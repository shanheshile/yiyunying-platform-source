<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class IdentityService
{
    private const TYPES = ['email', 'phone'];

    public static function registrationPolicy(int $appId): array
    {
        $nicknameEnabled = (bool) AppService::setting($appId, 'registration_nickname_enabled', true);
        $emailEnabled = (bool) AppService::setting($appId, 'registration_email_enabled', false);
        $phoneEnabled = (bool) AppService::setting($appId, 'registration_phone_enabled', false);
        return [
            'account' => ['enabled' => true, 'required' => true, 'label' => '账号'],
            'nickname' => [
                'enabled' => $nicknameEnabled,
                'required' => $nicknameEnabled && (bool) AppService::setting($appId, 'registration_nickname_required', true),
                'label' => '昵称',
            ],
            'email' => [
                'enabled' => $emailEnabled,
                'required' => $emailEnabled && (bool) AppService::setting($appId, 'registration_email_required', false),
                'verification_required' => $emailEnabled,
                'label' => '邮箱',
            ],
            'phone' => [
                'enabled' => $phoneEnabled,
                'required' => $phoneEnabled && (bool) AppService::setting($appId, 'registration_phone_required', false),
                'verification_required' => false,
                'label' => '手机号',
            ],
            'password_confirmation_required' => true,
            'uid' => [
                'label' => 'UID',
                'generated_by_server' => true,
                'fixed_length' => false,
                'description' => '系统生成的统一搜索码，与用户自定义账号分离。',
            ],
        ];
    }

    public static function validateRegistrationContacts(int $appId, array $data): array
    {
        $policy = self::registrationPolicy($appId);
        $nickname = trim((string) ($data['nickname'] ?? ''));
        if (!$policy['nickname']['enabled']) {
            $nickname = '';
        } elseif ($policy['nickname']['required'] && $nickname === '') {
            throw new HttpException('昵称不能为空', 0, 422);
        }

        $email = self::normalize('email', (string) ($data['email'] ?? ''));
        if (!$policy['email']['enabled']) {
            $email = '';
        } elseif ($policy['email']['required'] && $email === '') {
            throw new HttpException('邮箱不能为空', 0, 422);
        }
        if ($email !== '') {
            self::assertFormat('email', $email);
            self::assertAvailable('email', $email);
        }

        $phone = self::normalize('phone', (string) ($data['phone'] ?? ''));
        if (!$policy['phone']['enabled']) {
            $phone = '';
        } elseif ($policy['phone']['required'] && $phone === '') {
            throw new HttpException('手机号不能为空', 0, 422);
        }
        if ($phone !== '') {
            self::assertFormat('phone', $phone);
            self::assertAvailable('phone', $phone);
        }

        return ['nickname' => $nickname, 'email' => $email, 'phone' => $phone, 'policy' => $policy];
    }

    public static function generateUid(): string
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $length = random_int(10, 16);
            $uid = (string) random_int(1, 9);
            while (strlen($uid) < $length) $uid .= (string) random_int(0, 9);
            if (Database::one('SELECT id FROM users WHERE uid = ? LIMIT 1', [$uid]) === null) return $uid;
        }
        throw new HttpException('暂时无法生成 UID，请稍后重试', 503, 503);
    }

    public static function resolveUserReference(int $appId, $reference): int
    {
        $value = trim((string) $reference);
        if ($value === '') throw new HttpException('UID 或用户 ID 不能为空', 0, 422);
        $row = Database::one(
            'SELECT id FROM users WHERE app_id = ? AND uid = ? AND status = 1 AND deleted_at IS NULL LIMIT 1',
            [$appId, $value]
        );
        if ($row !== null) return (int) $row['id'];
        if (ctype_digit($value)) {
            $row = Database::one(
                'SELECT id FROM users WHERE app_id = ? AND id = ? AND status = 1 AND deleted_at IS NULL LIMIT 1',
                [$appId, (int) $value]
            );
            if ($row !== null) return (int) $row['id'];
        }
        throw new HttpException('用户不存在', 404, 404);
    }

    public static function bind(
        string $subjectType,
        int $subjectId,
        string $identityType,
        string $value,
        ?int $platformId,
        ?int $adminId,
        ?int $appId,
        bool $verified
    ): void {
        $normalized = self::normalize($identityType, $value);
        if ($normalized === '') return;
        self::assertFormat($identityType, $normalized);
        self::assertAvailable($identityType, $normalized, $subjectType, $subjectId);
        $existing = Database::one(
            'SELECT id, identity_hash FROM identity_bindings WHERE subject_type = ? AND subject_id = ? AND identity_type = ?',
            [$subjectType, $subjectId, $identityType]
        );
        $hash = self::hash($identityType, $normalized);
        if ($existing !== null) {
            Database::execute(
                'UPDATE identity_bindings SET identity_value = ?, identity_hash = ?, platform_id = ?, admin_id = ?, app_id = ?,
                        verified_at = ?, updated_at = NOW() WHERE id = ?',
                [$normalized, $hash, $platformId, $adminId, $appId, $verified ? date('Y-m-d H:i:s') : null, (int) $existing['id']]
            );
            return;
        }
        Database::execute(
            'INSERT INTO identity_bindings
             (subject_type, subject_id, platform_id, admin_id, app_id, identity_type, identity_value, identity_hash, verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$subjectType, $subjectId, $platformId, $adminId, $appId, $identityType, $normalized, $hash,
             $verified ? date('Y-m-d H:i:s') : null]
        );
    }

    public static function normalize(string $type, string $value): string
    {
        self::assertType($type);
        $value = trim($value);
        if ($type === 'email') return mb_strtolower($value);
        return preg_replace('/[\s\-()]/u', '', $value) ?? '';
    }

    public static function assertAvailable(
        string $type,
        string $value,
        ?string $allowedSubjectType = null,
        ?int $allowedSubjectId = null
    ): void {
        $normalized = self::normalize($type, $value);
        if ($normalized === '') return;
        $binding = Database::one(
            'SELECT subject_type, subject_id FROM identity_bindings WHERE identity_type = ? AND identity_hash = ? LIMIT 1',
            [$type, self::hash($type, $normalized)]
        );
        if ($binding !== null
            && ((string) $binding['subject_type'] !== (string) $allowedSubjectType
                || (int) $binding['subject_id'] !== (int) $allowedSubjectId)) {
            throw new HttpException(($type === 'email' ? '该邮箱' : '该手机号') . '已绑定其他 UID，请先申请解绑', 0, 409);
        }

        $column = $type === 'email' ? 'email' : 'phone';
        $queries = [
            ['user', "SELECT id FROM users WHERE {$column} = ? AND deleted_at IS NULL LIMIT 1"],
            ['admin', "SELECT id FROM admins WHERE {$column} = ? LIMIT 1"],
            ['platform', "SELECT id FROM platform_accounts WHERE {$column} = ? AND deleted_at IS NULL LIMIT 1"],
        ];
        foreach ($queries as [$subjectType, $sql]) {
            $row = Database::one($sql, [$normalized]);
            if ($row !== null && ($subjectType !== $allowedSubjectType || (int) $row['id'] !== (int) $allowedSubjectId)) {
                throw new HttpException(($type === 'email' ? '该邮箱' : '该手机号') . '已被其他账号使用，请先申请解绑', 0, 409);
            }
        }
    }

    public static function requestUnbind(string $subjectType, array $subject, string $identityType, string $reason): array
    {
        self::assertType($identityType);
        if ($subjectType === 'user' && !AppService::setting((int) $subject['app_id'], 'identity_unbind_enabled', true)) {
            throw new HttpException('当前应用已关闭联系方式解绑申请', 403, 403);
        }
        $binding = Database::one(
            'SELECT * FROM identity_bindings WHERE subject_type = ? AND subject_id = ? AND identity_type = ?',
            [$subjectType, (int) $subject['id'], $identityType]
        );
        if ($binding === null) throw new HttpException('当前账号没有绑定该联系方式', 404, 404);
        if (Database::one(
            "SELECT id FROM identity_unbind_requests WHERE subject_type = ? AND subject_id = ? AND identity_type = ? AND status = 'pending'",
            [$subjectType, (int) $subject['id'], $identityType]
        )) throw new HttpException('已有待审核的解绑申请', 0, 409);

        [$reviewerType, $reviewerId] = self::reviewer($subjectType, $subject);
        $id = Database::insert(
            'INSERT INTO identity_unbind_requests
             (subject_type, subject_id, platform_id, admin_id, app_id, identity_type, identity_value,
              reviewer_type, reviewer_id, status, reason, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$subjectType, (int) $subject['id'], $binding['platform_id'], $binding['admin_id'], $binding['app_id'],
             $identityType, $binding['identity_value'], $reviewerType, $reviewerId, 'pending', mb_substr(trim($reason), 0, 500)]
        );
        return self::requestById($id);
    }

    public static function requestsForSubject(string $subjectType, int $subjectId): array
    {
        return Database::all(
            'SELECT * FROM identity_unbind_requests WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 200',
            [$subjectType, $subjectId]
        );
    }

    public static function requestsForReviewer(
        string $reviewerType,
        array $reviewer,
        string $status = 'pending',
        bool $includeAll = false
    ): array
    {
        $params = [];
        $rootScope = $includeAll
            && $reviewerType === 'platform'
            && (int) ($reviewer['level'] ?? 0) === 1;
        if ($rootScope) {
            $where = '1 = 1';
        } else {
            $where = 'reviewer_type = ? AND reviewer_id = ?';
            $params = [$reviewerType, (int) $reviewer['id']];
        }
        if ($status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        return Database::all(
            'SELECT * FROM identity_unbind_requests WHERE ' . $where . ' ORDER BY id DESC LIMIT 500',
            $params
        );
    }

    public static function review(
        string $reviewerType,
        array $reviewer,
        int $requestId,
        bool $approved,
        string $remark,
        bool $force = false
    ): array {
        return Database::transaction(static function () use ($reviewerType, $reviewer, $requestId, $approved, $remark, $force): array {
            $row = Database::one('SELECT * FROM identity_unbind_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($row === null) throw new HttpException('解绑申请不存在', 404, 404);
            if ((string) $row['status'] !== 'pending') throw new HttpException('该申请已处理', 0, 409);
            $rootOverride = $force
                && $reviewerType === 'platform'
                && (int) ($reviewer['level'] ?? 0) === 1;
            if (!$rootOverride
                && ((string) $row['reviewer_type'] !== $reviewerType || (int) $row['reviewer_id'] !== (int) $reviewer['id'])) {
                throw new HttpException('无权审核该申请', 403, 403);
            }
            if ($approved) self::performUnbind($row);
            Database::execute(
                'UPDATE identity_unbind_requests SET status = ?, review_remark = ?, reviewed_by_type = ?,
                        reviewed_by_id = ?, review_mode = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$approved ? 'approved' : 'rejected', mb_substr(trim($remark), 0, 500), $reviewerType,
                 (int) $reviewer['id'], $rootOverride ? 'root_force' : 'direct', $requestId]
            );
            return self::requestById($requestId);
        });
    }

    private static function performUnbind(array $request): void
    {
        $column = (string) $request['identity_type'] === 'email' ? 'email' : 'phone';
        $table = match ((string) $request['subject_type']) {
            'user' => 'users',
            'admin' => 'admins',
            'platform' => 'platform_accounts',
            default => throw new HttpException('不支持的账号类型', 0, 422),
        };
        Database::execute("UPDATE {$table} SET {$column} = NULL, updated_at = NOW() WHERE id = ?", [(int) $request['subject_id']]);
        Database::execute(
            'DELETE FROM identity_bindings WHERE subject_type = ? AND subject_id = ? AND identity_type = ?',
            [(string) $request['subject_type'], (int) $request['subject_id'], (string) $request['identity_type']]
        );
    }

    private static function reviewer(string $subjectType, array $subject): array
    {
        if ($subjectType === 'user') return ['admin', (int) $subject['admin_id']];
        if ($subjectType === 'admin') return ['platform', (int) $subject['platform_id']];
        if ($subjectType === 'platform') {
            $parentId = (int) ($subject['parent_id'] ?? 0);
            if ($parentId <= 0) throw new HttpException('一级平台总控不受解绑审核限制', 0, 422);
            return ['platform', $parentId];
        }
        throw new HttpException('不支持的账号类型', 0, 422);
    }

    private static function requestById(int $id): array
    {
        $row = Database::one('SELECT * FROM identity_unbind_requests WHERE id = ?', [$id]);
        if ($row === null) throw new HttpException('解绑申请不存在', 404, 404);
        return $row;
    }

    private static function hash(string $type, string $normalized): string
    {
        return hash('sha256', $type . ':' . $normalized);
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new HttpException('联系方式类型只支持 email 或 phone', 0, 422);
    }

    private static function assertFormat(string $type, string $value): void
    {
        self::assertType($type);
        if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException('邮箱格式错误', 0, 422);
        }
        if ($type === 'phone' && preg_match('/^\+?[0-9]{6,20}$/', $value) !== 1) {
            throw new HttpException('手机号格式错误', 0, 422);
        }
    }
}
