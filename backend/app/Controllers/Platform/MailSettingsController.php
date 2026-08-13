<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Throwable;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\MailConfigurationService;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\VerificationEmailDeliveryService;

final class MailSettingsController
{
    public static function show(Request $request): \Yiyunying\Core\ApiResponse
    {
        self::actor($request);
        return Response::success(['items' => [MailConfigurationService::status()]]);
    }

    public static function update(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request);
        self::assertCurrentPassword($request, $actor);
        $before = MailConfigurationService::status();
        $after = MailConfigurationService::update($request->all(), (int) $actor['id']);
        PlatformService::log(
            $request,
            $actor,
            'mail_settings',
            'configuration_update',
            'platform_mail_settings',
            1,
            $before,
            $after
        );
        return Response::success(['items' => [$after]], '邮件配置已安全保存；SMTP 密码不会回显');
    }

    public static function test(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request);
        self::assertCurrentPassword($request, $actor);
        $recipient = strtolower(trim((string) $request->input('recipient_email', '')));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || strlen($recipient) > 190) {
            throw new HttpException('请填写明确且有效的测试收件邮箱', 0, 422);
        }
        $audit = ['recipient_masked' => self::maskEmail($recipient)];
        Database::transaction(static function () use ($request, $actor, $audit): void {
            Database::one('SELECT id FROM platform_accounts WHERE id = ? FOR UPDATE', [(int) $actor['id']]);
            self::assertTestRateLimit((int) $actor['id']);
            PlatformService::log(
                $request,
                $actor,
                'mail_settings',
                'test_send_started',
                'platform_mail_settings',
                1,
                null,
                $audit
            );
        });

        try {
            $delivery = VerificationEmailDeliveryService::sendConfigurationTest($recipient);
        } catch (HttpException $exception) {
            PlatformService::log(
                $request,
                $actor,
                'mail_settings',
                'test_send_failed',
                'platform_mail_settings',
                1,
                null,
                $audit + ['http_status' => $exception->httpStatus]
            );
            throw $exception;
        } catch (Throwable $exception) {
            PlatformService::log(
                $request,
                $actor,
                'mail_settings',
                'test_send_failed',
                'platform_mail_settings',
                1,
                null,
                $audit + ['http_status' => 503]
            );
            throw new HttpException('测试邮件服务异常，请查看服务器日志', 503, 503);
        }

        $status = (string) ($delivery['delivery_status'] ?? '');
        PlatformService::log(
            $request,
            $actor,
            'mail_settings',
            'test_send_accepted',
            'platform_mail_settings',
            1,
            null,
            $audit + ['delivery_status' => $status]
        );
        $message = match ($status) {
            'delivered' => '测试邮件已确认送达',
            'accepted_unconfirmed' => '测试邮件已被邮件服务接收，最终送达尚未确认，请检查收件箱和垃圾邮件',
            'simulated' => '测试邮件仅写入本地开发日志，未实际发送',
            default => '测试邮件投递状态未确认',
        };
        return Response::success([
            'delivery_status' => $status,
            'recipient_masked' => $audit['recipient_masked'],
        ], $message, 202);
    }

    public static function reencrypt(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request);
        self::assertCurrentPassword($request, $actor);
        $expectedRevision = (int) $request->input('expected_revision', -1);
        $before = MailConfigurationService::status();
        $after = MailConfigurationService::reencryptPassword($expectedRevision, (int) $actor['id']);
        PlatformService::log(
            $request,
            $actor,
            'mail_settings',
            'password_reencrypted',
            'platform_mail_settings',
            1,
            ['revision' => $before['revision'], 'smtp_password_configured' => $before['smtp_password_configured']],
            ['revision' => $after['revision'], 'smtp_password_configured' => $after['smtp_password_configured']]
        );
        return Response::success(['items' => [$after]], 'SMTP 密码已使用当前活动密钥重新加密');
    }

    private static function actor(Request $request): array
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        return $actor;
    }

    private static function assertTestRateLimit(int $platformId): void
    {
        $last = Database::one(
            "SELECT MAX(created_at) AS last_at FROM platform_operation_logs
             WHERE platform_id = ? AND module = 'mail_settings' AND action = 'test_send_started'",
            [$platformId]
        );
        if (!empty($last['last_at']) && strtotime((string) $last['last_at']) > time() - 60) {
            throw new HttpException('测试邮件发送过于频繁，请 60 秒后再试', 429, 429);
        }
        $hourly = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM platform_operation_logs
             WHERE platform_id = ? AND module = 'mail_settings' AND action = 'test_send_started'
             AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$platformId]
        )['total'] ?? 0);
        if ($hourly >= 5) throw new HttpException('本小时测试邮件次数已达上限', 429, 429);
    }

    private static function assertCurrentPassword(Request $request, array $actor): void
    {
        if (!Password::verify((string) $request->input('current_password', ''), (string) $actor['password_hash'])) {
            throw new HttpException('当前 root 密码不正确', 403, 403);
        }
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($name, 0, 1) . str_repeat('*', max(2, mb_strlen($name) - 1)) . '@' . $domain;
    }
}
