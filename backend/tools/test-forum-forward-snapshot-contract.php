<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Services\MessageForwardService;

$preview = MessageForwardService::forumSnapshotPreview([
    [
        'sender_name' => '群成员甲',
        'content_type' => 'image',
        'content' => '现场照片',
        'created_at' => '2026-08-14 09:30:00',
        'reply_to_message_id' => 88,
        'source_message_id' => 99,
        'sender_id' => 7,
        'attachments' => [[
            'media_type' => 'image',
            'file_name' => '../现场.jpg',
            'size_bytes' => 1200,
            'url' => 'https://private.example.test/secret.jpg',
            'download_url' => 'https://private.example.test/download',
            'storage_key' => 'private/original/key',
        ]],
    ],
    [
        'anonymous' => true,
        'sender_name' => '匿名成员 1',
        'content_type' => 'file',
        'content' => '',
        'forward_bundle' => ['id' => 44, 'item_count' => 3, 'source_id' => 55],
    ],
]);

if (count($preview) !== 2) throw new RuntimeException('Preview item count mismatch.');
if (($preview[0]['sender'] ?? '') !== '群成员甲' || ($preview[0]['content'] ?? '') !== '现场照片') {
    throw new RuntimeException('Sender/content projection mismatch.');
}
if (($preview[0]['reference_summary'] ?? '') !== '引用了一条消息（原文不提供跳转）') {
    throw new RuntimeException('Reference summary must remain detached.');
}
if (($preview[0]['attachments'][0]['name'] ?? '') !== '现场.jpg') {
    throw new RuntimeException('Attachment name must be reduced to a basename.');
}
if (($preview[1]['nested_summary'] ?? '') !== '嵌套聊天快照 · 3 条') {
    throw new RuntimeException('Nested snapshot summary mismatch.');
}
$encoded = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['private.example.test', 'storage_key', 'source_message_id', 'sender_id', 'reply_to_message_id', 'source_id', 'download_url'] as $forbidden) {
    if (str_contains((string) $encoded, $forbidden)) throw new RuntimeException("Forbidden live field leaked: {$forbidden}");
}

echo "Forum forward snapshot contract checks: passed\n";
