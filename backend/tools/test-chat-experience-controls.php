<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260810_chat_experience_controls.sql',
    'app_service' => $root . '/app/Services/AppService.php',
    'admin_controller' => $root . '/app/Controllers/Admin/AppController.php',
    'media_service' => $root . '/app/Services/MessageMediaService.php',
    'file_feedback_controller' => $root . '/app/Controllers/User/FileFeedbackController.php',
    'communication_controller' => $root . '/app/Controllers/User/CommunicationController.php',
    'group_controller' => $root . '/app/Controllers/User/GroupController.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$install = file_get_contents($files['install']);
$migration = file_get_contents($files['migration']);
$appService = file_get_contents($files['app_service']);
$adminController = file_get_contents($files['admin_controller']);
$mediaService = file_get_contents($files['media_service']);
$fileFeedbackController = file_get_contents($files['file_feedback_controller']);
$communicationController = file_get_contents($files['communication_controller']);
$groupController = file_get_contents($files['group_controller']);
$featureCodes = [
    'chat_camera',
    'chat_album',
    'chat_contact_card',
    'chat_call_record_label',
];

$checks = [
    'portable migration has no stored procedure' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $migration),
    'migration preserves existing administrator choices' => !str_contains($migration, '`enabled` = VALUES(`enabled`)'),
    'admin endpoint accepts feature maps' => str_contains($adminController, 'if (!array_is_list($items))'),
    'admin endpoint distinguishes missing feature config' => str_contains($adminController, "array_key_exists('config', \$item)")
        && str_contains($adminController, '$configProvided'),
    'feature toggles preserve config when omitted' => str_contains($appService, ': \'enabled = VALUES(enabled), updated_at = NOW()\''),
    'chat upload provenance comes from persisted upload records' => str_contains($mediaService, 'SELECT id, scene FROM uploads'),
    'chat policy recognizes canonical camera upload scene' => str_contains($mediaService, "'chat_camera', 'chat_album'"),
    'chat camera upload is gated by its own feature' => str_contains(
        $fileFeedbackController,
        "'chat_camera' => ['chat_camera']"
    ),
    'chat album upload is gated by its own feature' => str_contains(
        $fileFeedbackController,
        "'chat_album' => ['chat_album']"
    ),
    'unknown upload scenes fall back to remote files' => str_contains(
        $fileFeedbackController,
        "default => ['remote_files']"
    ),
    'forged client media source is discarded' => str_contains($mediaService, "unset(\$metadata['source'])")
        && !str_contains($mediaService, "\$source = strtolower(trim((string) (\$metadata['source']"),
    'contact card policy is server enforced' => str_contains($mediaService, "'chat_contact_card'"),
    'private and chatroom sends enforce policy' => substr_count($communicationController, 'MessageMediaService::assertChatFeatures') >= 3,
    'group sends enforce policy' => str_contains($groupController, 'MessageMediaService::assertChatFeatures'),
    'group create with icon enforces avatar feature' => str_contains($groupController, "if (\$icon !== '')")
        && str_contains($groupController, "\$isChatroom ? 'chatroom_avatar_upload' : 'group_avatar_upload'"),
];
foreach ($featureCodes as $featureCode) {
    $checks["install seeds {$featureCode}"] = str_contains($install, "'{$featureCode}'");
    $checks["new apps seed {$featureCode}"] = str_contains($appService, "'{$featureCode}'");
    $checks["existing apps migrate {$featureCode}"] = str_contains($migration, "'{$featureCode}'");
}

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Chat experience control contract failed: {$name}\n");
        exit(1);
    }
}

echo "Chat experience control contract: passed\n";
