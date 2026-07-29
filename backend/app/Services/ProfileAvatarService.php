<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class ProfileAvatarService
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function upload(string $scope, int $actorId): array
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException('请选择本地头像图片', 0, 422);
        }
        $file = $_FILES['file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException('头像上传失败', 0, 422, ['upload_error' => (int) ($file['error'] ?? -1)]);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new HttpException('头像图片必须小于 10 MB', 0, 422);
        $original = basename((string) ($file['name'] ?? 'avatar.jpg'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED, true)) throw new HttpException('头像仅支持 JPG、PNG、GIF 或 WebP', 0, 422);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('头像临时文件无效', 0, 422);
        $relativeDir = 'uploads/avatars/' . preg_replace('/[^a-z_]/', '', strtolower($scope)) . '/' . $actorId;
        $directory = YIYUNYING_ROOT . '/public/' . $relativeDir;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException('创建头像目录失败', -1, 500);
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $extension;
        $path = $directory . '/' . $stored;
        if (!move_uploaded_file($tmp, $path)) throw new HttpException('保存头像失败', -1, 500);
        $url = rtrim((string) config('app.url'), '/') . '/' . $relativeDir . '/' . $stored;
        return [
            'avatar' => $url, 'file_url' => $url, 'size_bytes' => $size, 'unit' => '字节',
            'sha256' => hash_file('sha256', $path), 'cache_version' => time(),
        ];
    }
}
