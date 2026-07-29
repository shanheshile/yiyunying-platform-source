<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;

final class CaptchaController
{
    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $appKey = trim((string) $request->input('app_key', $request->header('x-app-key', '')));
        $scene = trim((string) $request->input('scene', 'password_reset'));
        $target = trim((string) $request->input('account', ''));
        if (!in_array($scene, ['password_reset', 'register', 'login'], true)) {
            throw new HttpException('不支持的验证码场景', 0, 422);
        }
        if ($target === '' || mb_strlen($target) > 190) {
            throw new HttpException('account 不能为空且不能超过 190 个字符', 0, 422);
        }
        $app = AppService::byKey($appKey);
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        $recent = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM verification_codes
             WHERE app_id = ? AND scene = ? AND target = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            [(int) $app['id'], $scene, $target]
        )['total'] ?? 0);
        if ($recent >= 5) {
            throw new HttpException('验证码请求过于频繁', 429, 429);
        }
        $a = random_int(1, 30);
        $b = random_int(1, 20);
        $operator = random_int(0, 1) === 1 ? '+' : '-';
        if ($operator === '-' && $a < $b) {
            [$a, $b] = [$b, $a];
        }
        $answer = $operator === '+' ? $a + $b : $a - $b;
        $expiredAt = date('Y-m-d H:i:s', time() + 300);
        $id = Database::insert(
            'INSERT INTO verification_codes
             (admin_id, app_id, scene, target, code_hash, payload_json, attempts, expired_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())',
            [
                (int) $app['admin_id'], (int) $app['id'], $scene, $target, hash('sha256', (string) $answer),
                json_encode(['question' => "{$a} {$operator} {$b} = ?", 'ip' => $request->clientIp()], JSON_UNESCAPED_UNICODE),
                $expiredAt,
            ]
        );
        return Response::success([
            'captcha_id' => $id,
            'scene' => $scene,
            'question' => "{$a} {$operator} {$b} = ?",
            'expired_at' => $expiredAt,
        ], '验证码已生成', 201);
    }
}
