<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Core\HttpException;
use Yiyunying\Services\ContactVerificationService;
use Yiyunying\Services\VerificationEmailDeliveryService;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectHttpException(callable $callback, string $message): HttpException
{
    try {
        $callback();
    } catch (HttpException $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

$originalConfig = $GLOBALS['yiyunying_config'];
try {
    $GLOBALS['yiyunying_config']['app']['env'] = 'production';
    $GLOBALS['yiyunying_config']['app']['debug'] = false;
    $GLOBALS['yiyunying_config']['mail']['database_config_enabled'] = false;
    $GLOBALS['yiyunying_config']['mail']['transport'] = 'disabled';
    $disabled = expectHttpException(
        static fn() => VerificationEmailDeliveryService::deliver(
            'recipient@example.com', '测试应用', '123456', '注册'
        ),
        'disabled transport must fail closed'
    );
    assertTrue($disabled->httpStatus === 503, 'disabled transport must return 503');

    $GLOBALS['yiyunying_config']['mail']['transport'] = 'native';
    $GLOBALS['yiyunying_config']['mail']['from_address'] = 'no-reply@example.test';
    $placeholder = expectHttpException(
        static fn() => VerificationEmailDeliveryService::deliver(
            'recipient@example.com', '测试应用', '123456', '注册'
        ),
        'placeholder production sender must fail closed'
    );
    assertTrue($placeholder->httpStatus === 503, 'placeholder sender must return 503');
    assertTrue(
        str_contains($placeholder->getMessage(), '发件邮箱')
            && str_contains($placeholder->getMessage(), '正式域名'),
        'placeholder error must name the production sender-domain gate'
    );

    assertTrue(
        ContactVerificationService::deliveryResponseMessage(['delivery_status' => 'accepted_unconfirmed'])
            === '邮件服务已接收投递请求，最终送达尚未确认，请检查收件箱和垃圾邮件',
        'accepted handoff must not claim inbox delivery'
    );
    assertTrue(
        str_contains(
            ContactVerificationService::deliveryResponseMessage(['delivery_status' => 'simulated']),
            '未实际发送'
        ),
        'log transport must be labelled simulated'
    );

    $controller = file_get_contents(dirname(__DIR__) . '/app/Controllers/PublicApi/VerificationController.php');
    $userAuth = file_get_contents(dirname(__DIR__) . '/app/Controllers/User/AuthController.php');
    assertTrue(is_string($controller) && !str_contains($controller, '验证码已发送'), 'public response must not claim sent');
    assertTrue(is_string($userAuth) && !str_contains($userAuth, "'验证码已发送'"), 'reset response must not claim sent');
} finally {
    $GLOBALS['yiyunying_config'] = $originalConfig;
}

echo "Verification email delivery contract passed.\n";
