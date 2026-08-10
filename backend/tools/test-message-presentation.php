<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/MessagePresentationService.php';

use Yiyunying\Services\MessagePresentationService;

$communicationController = file_get_contents($root . '/app/Controllers/User/CommunicationController.php');
$groupController = file_get_contents($root . '/app/Controllers/User/GroupController.php');
$searchService = file_get_contents($root . '/app/Services/ChatSearchService.php');
$recordService = file_get_contents($root . '/app/Services/ChatRecordService.php');
foreach ([
    'private messages join viewer remarks' => str_contains($communicationController, 'LEFT JOIN friends viewer_friend'),
    'private messages expose nickname and account separately' => str_contains($communicationController, 'p.nickname AS sender_nickname')
        && str_contains($communicationController, 'u.account AS sender_account'),
    'favorite and forward views retain the same identity priority' => substr_count($communicationController, "AS sender_remark") >= 4
        && str_contains($communicationController, "MessagePresentationService::hydrate([\$row], \$scope, (int) \$user['id'])")
        && str_contains($communicationController, "return MessagePresentationService::hydrate(\$rows, 'private', (int) \$user['id'])"),
    'group messages join viewer remarks' => str_contains($groupController, 'LEFT JOIN friends viewer_friend'),
    'group messages pass viewer to presentation' => str_contains(
        $groupController,
        "MessagePresentationService::hydrate(\$items, 'group', \$userId)"
    ),
    'search results keep viewer remarks and viewer perspective' => substr_count($searchService, 'LEFT JOIN friends viewer_friend') >= 4
        && str_contains($searchService, "MessagePresentationService::hydrate(\$items, 'private', (int) \$user['id'])")
        && str_contains($searchService, "MessagePresentationService::hydrate(\$items, 'group', (int) \$user['id'])"),
    'record filters keep viewer remarks and bind every keyword placeholder' => substr_count($recordService, 'LEFT JOIN friends viewer_friend') >= 2
        && str_contains($recordService, "substr_count(\$keywordSql, '?')")
        && str_contains($recordService, "MessagePresentationService::hydrate(\$items, 'private', (int) \$user['id'])"),
] as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Message presentation failed: {$name}\n");
        exit(1);
    }
}

$message = [
    'sender_type' => 'user',
    'sender_id' => 7,
    'sender_name' => 'user',
    'sender_remark' => '好友备注',
    'sender_nickname' => '昵称',
    'sender_account' => 'account-7',
    'content_type' => 'text',
    'content' => 'hello',
];
MessagePresentationService::decorate($message, 'private', 99);
if (($message['sender_name'] ?? '') !== '好友备注') {
    fwrite(STDERR, "Message presentation failed: remark is not preferred\n");
    exit(1);
}

$nicknameFallback = [
    'sender_type' => 'user',
    'sender_name' => 'precomputed-account',
    'sender_nickname' => '昵称',
    'sender_account' => 'account-8',
];
MessagePresentationService::decorate($nicknameFallback, 'private', 99);
if (($nicknameFallback['sender_name'] ?? '') !== '昵称') {
    fwrite(STDERR, "Message presentation failed: nickname fallback\n");
    exit(1);
}

$accountFallback = [
    'sender_type' => 'user',
    'sender_name' => 'user',
    'sender_nickname' => 'user',
    'sender_account' => 'account-9',
];
MessagePresentationService::decorate($accountFallback, 'private', 99);
if (($accountFallback['sender_name'] ?? '') !== 'account-9') {
    fwrite(STDERR, "Message presentation failed: account fallback\n");
    exit(1);
}

$recall = [
    'sender_type' => 'system',
    'sender_id' => 7,
    'sender_name' => '系统消息',
    'sender_remark' => '好友备注',
    'content_type' => 'recall',
];
$peerRecall = $recall;
MessagePresentationService::decorate($peerRecall, 'private', 99);
if (($peerRecall['content'] ?? '') !== '好友备注撤回了一条消息') {
    fwrite(STDERR, "Message presentation failed: peer recall identity\n");
    exit(1);
}

$ownRecall = $recall;
MessagePresentationService::decorate($ownRecall, 'private', 7);
if (($ownRecall['content'] ?? '') !== '你撤回了一条消息') {
    fwrite(STDERR, "Message presentation failed: own recall perspective\n");
    exit(1);
}

echo "Message presentation contract: passed\n";
