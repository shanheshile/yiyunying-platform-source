<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\ApiResponse;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;

final class CatalogDownloadService
{
    public static function userUrl(string $kind, array $item, array $user, bool $entitled): string
    {
        if (!$entitled || !self::ready($kind, $item)) return '';
        $id = (int) ($item['id'] ?? 0);
        return $kind === 'store_app'
            ? '/api/user/store-apps/' . $id . '/download'
            : '/api/user/resources/' . $id . '/download';
    }

    public static function adminUrl(string $kind, array $item): string
    {
        if (!self::ready($kind, $item)) return '';
        $id = (int) ($item['id'] ?? 0);
        $appId = (int) ($item['app_id'] ?? 0);
        return $kind === 'store_app'
            ? '/api/admin/apps/' . $appId . '/store-apps/' . $id . '/download'
            : '/api/admin/apps/' . $appId . '/resources/' . $id . '/download';
    }

    public static function downloadForUser(
        Request $request,
        array $user,
        string $kind,
        int $itemId
    ): ApiResponse {
        [$table, $purchaseTable, $itemColumn] = self::definition($kind);
        $item = Database::one(
            "SELECT * FROM {$table} WHERE id = ? AND admin_id = ? AND app_id = ?",
            [$itemId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($item === null) throw new HttpException('资源不存在', 404, 404);
        $deleted = ($item['deleted_at'] ?? null) !== null;
        $owner = !$deleted && (int) ($item['user_id'] ?? 0) === (int) $user['id'];
        $active = !$deleted
            && (string) ($item['audit_status'] ?? '') === 'approved'
            && (int) ($item['status'] ?? 0) === 1;
        $free = $active && (int) ($item['price_integral'] ?? 0) === 0;
        $hasPurchase = Database::one(
            "SELECT id FROM {$purchaseTable} WHERE {$itemColumn} = ? AND buyer_user_id = ?",
            [$itemId, (int) $user['id']]
        ) !== null;
        if ($deleted && !$hasPurchase) {
            throw new HttpException('该历史条目仅向已购买用户保留下载凭据', 404, 404);
        }
        if (!$active && !$owner && !$hasPurchase) {
            throw new HttpException('资源当前已停止公开，只有发布者或历史购买者可以下载', 404, 404);
        }
        if (!$owner && !$hasPurchase && !$free) {
            throw new HttpException('请先完成购买后再下载', 403, 403);
        }
        $upload = self::privateUpload($kind, $item, true);
        if (trim((string) ($request->header('range') ?? '')) === '') {
            Database::execute("UPDATE {$table} SET download_count = download_count + 1 WHERE id = ?", [$itemId]);
        }
        $path = UploadStorageService::privatePhysicalPath((string) $upload['file_path']);
        if ($path === null) throw new HttpException('资源文件不存在，请联系发布者重新上传', 404, 404);
        return Response::file(
            $path,
            'application/octet-stream',
            'attachment',
            (string) ($upload['original_name'] ?? ($kind === 'store_app' ? 'application.apk' : 'resource.bin')),
            (string) ($upload['sha256'] ?? '')
        );
    }

    public static function downloadForAdmin(
        array $admin,
        int $appId,
        string $kind,
        int $itemId
    ): ApiResponse {
        [$table] = self::definition($kind);
        $item = Database::one(
            "SELECT * FROM {$table} WHERE id = ? AND admin_id = ? AND app_id = ?",
            [$itemId, (int) $admin['id'], $appId]
        );
        if ($item === null) throw new HttpException('资源不存在', 404, 404);
        $upload = self::privateUpload($kind, $item, true);
        $path = UploadStorageService::privatePhysicalPath((string) $upload['file_path']);
        if ($path === null) throw new HttpException('资源文件不存在，请要求发布者重新上传', 404, 404);
        return Response::file(
            $path,
            'application/octet-stream',
            'attachment',
            (string) ($upload['original_name'] ?? ($kind === 'store_app' ? 'application.apk' : 'resource.bin')),
            (string) ($upload['sha256'] ?? '')
        );
    }

    public static function assertReady(string $kind, array $item): void
    {
        self::privateUpload($kind, $item, true);
    }

    private static function ready(string $kind, array $item): bool
    {
        try {
            self::privateUpload($kind, $item, false);
            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private static function privateUpload(string $kind, array $item, bool $fullIntegrity = false): array
    {
        $uploadId = max(0, (int) ($item['source_upload_id'] ?? 0));
        if ($uploadId <= 0) {
            throw new HttpException('该条目使用旧式公开地址，必须重新上传文件后才能下载', 0, 409);
        }
        return UploadStorageService::verifiedPrivateCatalogUpload(
            $uploadId,
            (int) ($item['admin_id'] ?? 0),
            (int) ($item['app_id'] ?? 0),
            self::scene($kind, $item),
            $fullIntegrity
        );
    }

    private static function definition(string $kind): array
    {
        if ($kind === 'resource') {
            return ['resources', 'resource_purchases', 'resource_id'];
        }
        if ($kind === 'store_app') {
            return ['store_apps', 'store_app_purchases', 'store_app_id'];
        }
        throw new \InvalidArgumentException('Unsupported catalog kind');
    }

    private static function scene(string $kind, array $item): string
    {
        if ($kind === 'store_app') return 'store_app_package';
        if ($kind === 'resource') {
            return SubmissionInspectionService::catalogScene((string) ($item['resource_type'] ?? 'source_market'));
        }
        throw new \InvalidArgumentException('Unsupported catalog kind');
    }
}
