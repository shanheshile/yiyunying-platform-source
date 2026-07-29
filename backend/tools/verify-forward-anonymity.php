<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Services\MessageForwardService;

$item = [
    'sender_id' => 17,
    'sender_name' => '当前层发送者',
    'source_context' => [
        'type' => 'private',
        'name' => '当前层会话',
    ],
    'attachments' => [[
        'type' => 'voice',
        'file_name' => 'voice.m4a',
        'duration_seconds' => 8,
    ]],
    'forward_bundle' => [
        'anonymity_mode' => 'none',
        'source_context' => [
            'type' => 'group',
            'name' => '内层群聊',
        ],
        'items' => [[
            'sender_id' => 21,
            'sender_name' => '内层发送者',
            'attachments' => [[
                'type' => 'audio',
                'file_name' => 'music.mp3',
            ]],
        ]],
    ],
];

$method = new ReflectionMethod(MessageForwardService::class, 'applyAnonymity');
$method->setAccessible(true);
$aliases = [];
$arguments = [&$item, 'full', [], &$aliases];
$method->invokeArgs(null, $arguments);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "分层匿名验证失败：{$message}\n");
        exit(1);
    }
};

$assert(($item['sender_name'] ?? '') !== '当前层发送者', '当前层身份未匿名');
$assert(($item['forward_bundle']['items'][0]['sender_name'] ?? '') === '内层发送者', '错误修改了内层快照身份');
$assert(($item['forward_bundle']['source_context']['name'] ?? '') === '内层群聊', '错误隐藏了内层来源');
$assert(($item['attachments'][0]['type'] ?? '') === 'voice', '当前层语音附件被移除');
$assert(($item['forward_bundle']['items'][0]['attachments'][0]['type'] ?? '') === 'audio', '内层音频附件被移除');

fwrite(STDOUT, "分层匿名、嵌套快照与语音附件验证通过\n");
