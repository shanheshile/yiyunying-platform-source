<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\UserOverviewService;
use Yiyunying\Services\UploadLibraryService;
use Yiyunying\Services\UploadLimitService;
use Yiyunying\Services\UploadStorageService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumExperienceService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\CommunicationTakeoverService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\RedPacketManagementService;
use Yiyunying\Services\RolePermissionService;

final class OversightController
{
    private const ALLOWED_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'txt', 'md', 'json', 'csv', 'rtf', 'odt', 'ods', 'odp',
        'zip', 'tar', 'gz',
        'docx', 'xlsx', 'pptx',
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac',
        'mp4', 'webm', 'mov', 'mkv', 'avi', '3gp', 'm4v', 'apk',
    ];

    public static function forumPlates(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $items = Database::all(
            'SELECT plate.*, COUNT(DISTINCT post.id) AS post_count,
                    COALESCE(SUM(CASE WHEN post.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS recent_post_count
             FROM forum_plates plate LEFT JOIN forum_posts post ON post.plate_id = plate.id
               AND post.status = 1 AND post.deleted_at IS NULL
             WHERE plate.admin_id = ? AND plate.app_id = ? AND plate.status = 1
             GROUP BY plate.id ORDER BY plate.sort_order, plate.id',
            [(int) $app['admin_id'], (int) $app['id']]
        );
        PlatformService::log($request, $actor, 'forum', 'plates', 'app', (int) $app['id']);
        return Response::success(['items' => $items]);
    }

    public static function forumPosts(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $where = ['post.admin_id = ?', 'post.app_id = ?', 'post.deleted_at IS NULL'];
        $values = [(int) $app['admin_id'], (int) $app['id']];
        $plateId = (int) $request->input('plate_id', 0);
        if ($plateId > 0) { $where[] = 'post.plate_id = ?'; $values[] = $plateId; }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(post.title LIKE ? OR post.content LIKE ? OR u.account LIKE ? OR profile.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($values, $like, $like, $like, $like);
        }
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));
        if ($dateFrom !== '') { $where[] = 'post.created_at >= ?'; $values[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo !== '') { $where[] = 'post.created_at <= ?'; $values[] = $dateTo . ' 23:59:59'; }
        $sqlWhere = implode(' AND ', $where);
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_posts post INNER JOIN users u ON u.id = post.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = u.id WHERE {$sqlWhere}", $values
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT post.*, plate.name AS plate_name, u.uid, u.account, profile.nickname, profile.avatar
             FROM forum_posts post INNER JOIN forum_plates plate ON plate.id = post.plate_id
             INNER JOIN users u ON u.id = post.user_id LEFT JOIN user_profiles profile ON profile.user_id = u.id
             WHERE {$sqlWhere}
             ORDER BY post.is_top DESC, post.is_essence DESC, post.is_locked DESC, post.heat_score DESC,
                      COALESCE(post.last_activity_at, post.created_at) DESC, post.id DESC LIMIT {$limit} OFFSET {$offset}",
            $values
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_post', (int) $app['id']);
        PlatformService::log($request, $actor, 'forum', 'posts', 'app', (int) $app['id']);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function forumPost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $post = Database::one(
            'SELECT post.*, plate.name AS plate_name, u.uid, u.account, profile.nickname, profile.avatar
             FROM forum_posts post INNER JOIN forum_plates plate ON plate.id = post.plate_id
             INNER JOIN users u ON u.id = post.user_id LEFT JOIN user_profiles profile ON profile.user_id = u.id
             WHERE post.id = ? AND post.admin_id = ? AND post.app_id = ?',
            [(int) $params['post_id'], (int) $app['admin_id'], (int) $app['id']]
        );
        if ($post === null) throw new HttpException('帖子不存在或不在当前平台作用域', 404, 404);
        $post = ContentTagService::hydrate([$post])[0];
        $post = MessageMediaService::hydrate([$post], 'forum_post', (int) $app['id'])[0];
        $post = MessageForwardService::hydrate([$post], 'forum_post', (int) $app['id'])[0];
        $post['sections'] = ForumExperienceService::sections($post, null, true);
        $post['comments'] = Database::all(
            'SELECT comment.*, user.uid, user.account, profile.nickname, profile.avatar
             FROM forum_comments comment INNER JOIN users user ON user.id = comment.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = user.id
             WHERE comment.post_id = ? AND comment.app_id = ? ORDER BY comment.is_pinned DESC, comment.pin_order DESC, comment.id ASC LIMIT 2000',
            [(int) $post['id'], (int) $app['id']]
        );
        $post['comments'] = ContentTagService::hydrate($post['comments']);
        $post['comments'] = MessageMediaService::hydrate($post['comments'], 'forum_comment', (int) $app['id']);
        $post['comments'] = MessageForwardService::hydrate($post['comments'], 'forum_comment', (int) $app['id']);
        $post['paid_rule'] = Database::one(
            'SELECT price_integral AS price_balance, asset_type, preview_content, status, created_at, updated_at FROM forum_paid_contents WHERE post_id = ?',
            [(int) $post['id']]
        );
        PlatformService::log($request, $actor, 'forum', 'post_detail', 'post', (int) $post['id'], null, ['app_id' => (int) $app['id']]);
        return Response::success(['post' => $post]);
    }

    public static function uploads(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        return Response::success(UploadLibraryService::list(
            (int) $app['admin_id'], (int) $app['id'], null, $request
        ));
    }

    public static function upload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException('缺少 multipart/form-data 文件字段 file', 0, 422);
        }
        $file = $_FILES['file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException('文件上传失败', 0, 422, [
                'upload_error' => (int) ($file['error'] ?? -1),
            ]);
        }
        $limit = UploadLimitService::validate((int) $app['id'], $file);
        if (!$limit['valid']) {
            throw new HttpException(
                UploadLimitService::label((string) $limit['category']) . '大小超出当前应用限制',
                0,
                422,
                $limit + ['unit' => '字节']
            );
        }
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('上传临时文件无效', 0, 422);
        $result = UploadStorageService::store(
            $file,
            (int) $app['admin_id'],
            (int) $app['id'],
            null,
            (string) $request->input('scene', 'platform'),
            self::ALLOWED_UPLOAD_EXTENSIONS,
            ['original_upload' => $request->input('original_upload', false)]
        );
        PlatformService::log(
            $request,
            $actor,
            'upload',
            'create',
            'upload',
            (int) $result['upload_id'],
            null,
            ['app_id' => (int) $app['id'], 'reused' => (bool) $result['reused']]
        );
        return Response::success(
            $result,
            (bool) $result['reused'] ? '已复用相同文件，无需重复上传' : '文件上传成功',
            201
        );
    }

    public static function deleteUpload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $result = UploadLibraryService::remove(
            (int) $app['admin_id'], (int) $app['id'], null, (int) $params['upload_id']
        );
        PlatformService::log($request, $actor, 'upload', 'delete', 'upload', (int) $params['upload_id'], null, [
            'app_id' => (int) $app['id'],
        ]);
        return Response::success($result, '上传文件已删除');
    }

    public static function users(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        return Response::success(UserOverviewService::list((int) $app['admin_id'], (int) $app['id'], $request));
    }

    public static function overview(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $sections = UserOverviewService::overview((int) $app['admin_id'], (int) $app['id'], (int) $params['user_id']);
        PlatformService::log($request, $actor, 'user_audit', 'overview', 'user', (int) $params['user_id']);
        return Response::success([
            'user' => $sections['资料与资产']['用户资料'] ?? [],
            'sections' => $sections,
            'scope' => ['platform_id' => (int) $actor['id'], 'app_id' => (int) $app['id']],
        ]);
    }

    public static function userPermissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $user = self::scopedUser($app, (int) $params['user_id']);
        PlatformService::log($request, $actor, 'user_permissions', 'view', 'user', (int) $user['id']);
        return Response::success(RolePermissionService::userPayload($user, (int) $actor['level']));
    }

    public static function saveUserPermissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        PlatformService::requireCapability($actor, 'governance.manage');
        $user = self::scopedUser($app, (int) $params['user_id']);
        $input = $request->input('permissions', []);
        if (!is_array($input)) {
            throw new HttpException('权限配置必须是对象', 0, 422);
        }
        $permissions = RolePermissionService::normalizeUserInput($input);
        $before = RolePermissionService::userPayload($user, (int) $actor['level']);
        Database::transaction(static function () use ($actor, $app, $user, $permissions): void {
            foreach ($permissions as $code => $value) {
                RolePermissionService::assertUserPermissionMutable(
                    (int) $app['id'], (int) $user['id'], (string) $code, (int) $actor['level']
                );
                Database::execute(
                    'INSERT INTO user_feature_permissions
                     (admin_id, app_id, user_id, feature_code, enabled, config_json, updated_by_type, updated_by_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_json = VALUES(config_json),
                       updated_by_type = VALUES(updated_by_type), updated_by_id = VALUES(updated_by_id), updated_at = NOW()',
                    [
                        (int) $app['admin_id'], (int) $app['id'], (int) $user['id'], (string) $code,
                        (bool) $value['allowed'] ? 1 : 0,
                        is_array($value['config']) ? json_encode($value['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'platform', (int) $actor['id'],
                    ]
                );
            }
        });
        $after = RolePermissionService::userPayload($user, (int) $actor['level']);
        PlatformService::log($request, $actor, 'user_permissions', 'update', 'user', (int) $user['id'], $before, $after);
        return Response::success($after, '用户权限已保存');
    }
    public static function communications(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = (int) $request->input('channel_id', 0);
        if ($channelId <= 0) throw new HttpException('channel_id 必须大于 0', 0, 422);
        $takeoverPolicy = CommunicationTakeoverService::assertPlatform($actor, $app, 'view', $channelType);
        $data = UserOverviewService::communications(
            (int) $app['admin_id'], (int) $app['id'], (int) $params['user_id'],
            $channelType, $channelId, $request
        );
        $data['takeover_policy'] = $takeoverPolicy;
        CommunicationTakeoverService::recordView(
            $request, (int) $app['admin_id'], (int) $app['id'], 'platform', (int) $actor['id'],
            (int) $actor['level'], $channelType, $channelId, (int) $params['user_id']
        );
        PlatformService::log($request, $actor, 'user_audit', 'communications', 'user', (int) $params['user_id'], null, [
            'channel_type' => $channelType, 'channel_id' => $channelId,
        ]);
        return Response::success($data);
    }

    public static function participate(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $userId = (int) $params['user_id'];
        UserOverviewService::overview((int) $app['admin_id'], (int) $app['id'], $userId);
        $type = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        $content = Validator::string($request->input('content', ''), 'content', 1, 10000);
        CommunicationTakeoverService::assertPlatform($actor, $app, 'send', $type);
        $result = CommunicationTakeoverService::sendSystemMessage(
            $request, (int) $app['admin_id'], (int) $app['id'], $userId, $type, $channelId, $content,
            'platform', (int) $actor['id'], (int) $actor['level']
        );
        PlatformService::log($request, $actor, 'user_audit', 'participate', 'user', $userId, null, [
            'channel_type' => $type, 'channel_id' => $channelId, 'message_id' => $result['message_id'],
        ]);
        return Response::success($result, '系统接管消息已发送', 201);
    }

    public static function updateCommunication(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $userId = (int) $params['user_id'];
        $messageId = (int) $params['message_id'];
        UserOverviewService::overview((int) $app['admin_id'], (int) $app['id'], $userId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        $content = Validator::string($request->input('content', ''), 'content', 1, 10000);
        CommunicationTakeoverService::assertPlatform($actor, $app, 'update', $channelType);
        $result = CommunicationTakeoverService::updateManagedMessage(
            $request, (int) $app['admin_id'], (int) $app['id'], $userId, $channelType,
            $channelId, $messageId, $content, 'platform', (int) $actor['id'], (int) $actor['level']
        );
        PlatformService::log($request, $actor, 'user_audit', 'communication_update', 'user', $userId, null, [
            'channel_type' => $channelType, 'channel_id' => $channelId, 'message_id' => $messageId,
        ]);
        return Response::success($result, '聊天内容已修改');
    }

    public static function deleteCommunication(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $userId = (int) $params['user_id'];
        $messageId = (int) $params['message_id'];
        UserOverviewService::overview((int) $app['admin_id'], (int) $app['id'], $userId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        CommunicationTakeoverService::assertPlatform($actor, $app, 'delete', $channelType);
        $result = CommunicationTakeoverService::deleteManagedMessage(
            $request, (int) $app['admin_id'], (int) $app['id'], $userId, $channelType,
            $channelId, $messageId, 'platform', (int) $actor['id'], (int) $actor['level']
        );
        PlatformService::log($request, $actor, 'user_audit', 'communication_delete', 'user', $userId, null, [
            'channel_type' => $channelType, 'channel_id' => $channelId, 'message_id' => $messageId,
        ]);
        return Response::success($result, '聊天内容已删除');
    }

    public static function takeoverPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        return Response::success(CommunicationTakeoverService::forPlatform($actor, $app));
    }

    public static function saveTakeoverPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        return Response::success(
            CommunicationTakeoverService::saveForPlatform($request, $actor, $app),
            '通信接管策略已保存'
        );
    }

    public static function takeoverAudits(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        return Response::success(CommunicationTakeoverService::audits(
            $request, (int) $app['admin_id'], (int) $app['id']
        ));
    }

    public static function forwardBundle(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [, $app] = self::context($request, (int) $params['app_id']);
        return Response::success([
            'forward' => MessageForwardService::showForManager(
                (int) $app['admin_id'], (int) $app['id'], (int) $params['forward_id']
            ),
        ]);
    }

    public static function forceRefundRedPacket(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $packetId = (int) $params['packet_id'];
        $result = RedPacketManagementService::forceRefund(
            (int) $app['admin_id'],
            (int) $app['id'],
            $packetId
        );
        PlatformService::log(
            $request,
            $actor,
            'red_packet',
            'force_refund',
            'red_packet',
            $packetId,
            $result['packet'],
            ['refund_amount' => $result['refund_amount'], 'asset_type' => $result['asset_type']]
        );
        return Response::success([
            'packet_id' => $packetId,
            'refund_amount' => $result['refund_amount'],
            'status' => 'refunded',
        ], '红包已强制结束，剩余金额已退回发送者');
    }

    public static function deleteGroupAsset(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, (int) $params['app_id']);
        $roomId = (int) $params['room_id'];
        $room = Database::one('SELECT id FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ?', [$roomId, (int) $app['admin_id'], (int) $app['id']]);
        if ($room === null) throw new HttpException('群聊不存在', 404, 404);
        $assetType = trim((string) $params['asset_type']);
        $assetId = (int) $params['asset_id'];
        $map = [
            'file' => ['chat_room_files', '群文件'],
            'album' => ['chat_room_albums', '群相册'],
            'photo' => ['chat_room_album_photos', '群相册照片'],
            'vote' => ['chat_room_votes', '群投票'],
        ];
        if (!isset($map[$assetType])) throw new HttpException('asset_type 仅支持 file、album、photo 或 vote', 0, 422);
        [$table, $label] = $map[$assetType];
        $asset = $assetType === 'photo'
            ? Database::one('SELECT photo.id FROM chat_room_album_photos photo INNER JOIN chat_room_albums album ON album.id = photo.album_id WHERE photo.id = ? AND album.room_id = ? AND photo.status = 1', [$assetId, $roomId])
            : Database::one("SELECT id FROM {$table} WHERE id = ? AND room_id = ? AND status " . ($assetType === 'vote' ? "<> 'deleted'" : '= 1'), [$assetId, $roomId]);
        if ($asset === null) throw new HttpException($label . '不存在', 404, 404);
        Database::execute("UPDATE {$table} SET status = ? WHERE id = ?", [$assetType === 'vote' ? 'deleted' : 0, $assetId]);
        PlatformService::log($request, $actor, 'group_asset', 'delete', $assetType, $assetId, ['app_id' => (int) $app['id'], 'room_id' => $roomId]);
        return Response::success(['asset_type' => $assetType, 'asset_id' => $assetId], $label . '已删除');
    }

    private static function scopedUser(array $app, int $userId): array
    {
        $user = Database::one(
            'SELECT u.id, u.uid, u.admin_id, u.app_id, u.account, u.status, p.nickname
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL',
            [$userId, (int) $app['admin_id'], (int) $app['id']]
        );
        if ($user === null) {
            throw new HttpException('用户不存在或不在当前管理范围', 404, 404);
        }
        return $user;
    }
    private static function context(Request $request, int $appId): array
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'data.manage');
        $app = PlatformService::ownedApp($actor, $appId);
        return [$actor, $app];
    }
}
