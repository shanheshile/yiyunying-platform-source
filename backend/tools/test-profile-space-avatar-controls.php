<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260810_profile_space_avatar_controls.sql',
    'app_service' => $root . '/app/Services/AppService.php',
    'routes' => $root . '/routes/api.php',
    'group_controller' => $root . '/app/Controllers/User/GroupController.php',
    'forum_controller' => $root . '/app/Controllers/Admin/ForumController.php',
    'group_activity' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/chat/GroupSpaceActivity.java',
    'module_registry' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'generic_module' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java',
    'profile' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/profile/ProfileFragment.java',
];

$contents = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $contents[$name] = (string) file_get_contents($path);
}

$featureCodes = [
    'group_avatar_upload',
    'chatroom_avatar_upload',
    'forum_plate_avatar_upload',
];
$checks = [
    'portable migration has no stored procedure' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $contents['migration']),
    'migration preserves existing administrator choices' => !str_contains($contents['migration'], '`enabled` = VALUES(`enabled`)'),
    'group avatar route is present' => str_contains($contents['routes'], "/api/user/chat-rooms/{room_id}/avatar"),
    'forum plate avatar route is present' => str_contains($contents['routes'], "/api/admin/apps/{app_id}/forum-plates/{plate_id}/avatar"),
    'group avatar requires manager' => str_contains($contents['group_controller'], 'ChatRoomService::requireManager($user, $room)'),
    'group avatar uses validated image upload' => str_contains($contents['group_controller'], 'ProfileAvatarService::upload($roomKind'),
    'group direct icon update is also policy controlled' => substr_count($contents['group_controller'], "'group_avatar_upload'") >= 2,
    'group create icon is policy controlled by room type' => str_contains($contents['group_controller'], "if (\$icon !== '')")
        && str_contains($contents['group_controller'], "\$isChatroom ? 'chatroom_avatar_upload' : 'group_avatar_upload'"),
    'plate avatar verifies app ownership' => str_contains($contents['forum_controller'], 'admin_id = ? AND app_id = ?'),
    'plate avatar uses validated image upload' => str_contains($contents['forum_controller'], "ProfileAvatarService::upload('forum_plate'"),
    'plate direct icon create and update are also policy controlled' =>
        substr_count($contents['forum_controller'], "'forum_plate_avatar_upload'") >= 3
        && str_contains($contents['forum_controller'], "if (\$icon !== '')"),
    'group UI has local avatar picker' => str_contains($contents['group_activity'], 'MediaPickerActivity.imageIntent(this, 1)'),
    'group UI uploads to scoped endpoint' => str_contains($contents['group_activity'], 'base() + "/avatar"'),
    'admin board UI exposes image upload action' => str_contains($contents['module_registry'], '"UPLOAD_IMAGE"'),
    'generic admin uploader validates upload policy' => str_contains($contents['generic_module'], 'UploadPolicyStore.accepts(context, "image", size)'),
    'personal profile follows admin edit policy' => str_contains($contents['profile'], 'profile_edit_enabled'),
];
foreach ($featureCodes as $featureCode) {
    $checks["install seeds {$featureCode}"] = str_contains($contents['install'], "'{$featureCode}'");
    $checks["new apps seed {$featureCode}"] = str_contains($contents['app_service'], "'{$featureCode}'");
    $checks["existing apps migrate {$featureCode}"] = str_contains($contents['migration'], "'{$featureCode}'");
}

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Profile-space avatar contract failed: {$name}\n");
        exit(1);
    }
}

echo "Profile-space avatar control contract: passed\n";
