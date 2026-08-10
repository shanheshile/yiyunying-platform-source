<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ForumVisibilityService;
use Yiyunying\Services\MessageMediaService;

final class FavoriteController
{
    private const CATEGORIES = [
        'all' => '全部',
        'messages' => '消息',
        'images' => '图片与动图',
        'videos' => '视频',
        'audio' => '音频',
        'stickers' => '表情包',
        'documents' => '文档',
        'links' => '链接',
        'files' => '文件',
        'posts' => '帖子',
        'moments' => '动态',
        'notes' => '笔记',
        'bounties' => '悬赏',
        'resources' => '资源',
        'apps' => '应用',
        'goods' => '商品',
    ];

    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $category = trim((string) $request->input('category', 'all'));
        if (!isset(self::CATEGORIES[$category])) throw new HttpException('收藏分类不存在', 0, 422);
        $keyword = trim((string) $request->input('keyword', ''));

        $messages = self::messages($user);
        $posts = self::posts($user);
        $moments = self::moments($user);
        $notes = self::notes($user);
        $bounties = self::bounties($user);
        $resources = self::resources($user);
        $apps = self::apps($user);
        $goods = self::goods($user);
        $uploads = self::uploads($user);
        $images = array_values(array_filter(
            array_merge($messages, $uploads),
            static fn(array $item): bool => self::hasMediaType($item, ['image'])
        ));
        $videos = array_values(array_filter(
            array_merge($messages, $uploads),
            static fn(array $item): bool => self::hasMediaType($item, ['video'])
        ));
        $audio = array_values(array_filter(
            array_merge($messages, $uploads),
            static fn(array $item): bool => self::hasMediaType($item, ['audio'])
        ));
        $stickers = array_values(array_filter(
            $messages,
            static fn(array $item): bool => self::hasMediaType($item, ['sticker'])
        ));
        $documents = array_values(array_filter(
            array_merge($messages, $uploads),
            static fn(array $item): bool => self::hasMediaType($item, ['file'])
        ));
        $links = array_values(array_filter($messages, static fn(array $item): bool => self::hasLink($item)));
        $messageFiles = array_values(array_filter($messages, static fn(array $item): bool => self::isFile($item)));
        $files = array_merge($uploads, $messageFiles);

        $collections = compact(
            'messages', 'images', 'videos', 'audio', 'stickers', 'documents', 'links', 'files',
            'posts', 'moments', 'notes', 'bounties', 'resources', 'apps', 'goods'
        );
        $all = array_merge($messages, $posts, $moments, $notes, $bounties, $resources, $apps, $goods, $uploads);
        $items = $category === 'all' ? $all : $collections[$category];
        if ($keyword !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($keyword): bool {
                return mb_stripos((string) ($item['title'] ?? '') . ' ' . (string) ($item['summary'] ?? ''), $keyword) !== false;
            }));
        }
        usort($items, static fn(array $left, array $right): int => strcmp(
            (string) ($right['favorited_at'] ?? ''), (string) ($left['favorited_at'] ?? '')
        ));
        $items = array_slice($items, 0, 500);

        $categories = [];
        foreach (self::CATEGORIES as $code => $name) {
            $categories[] = [
                'code' => $code,
                'name' => $name,
                'count' => $code === 'all' ? count($all) : count($collections[$code]),
            ];
        }
        return Response::success(['category' => $category, 'categories' => $categories, 'items' => $items]);
    }

    private static function messages(array $user): array
    {
        $private = Database::all(
            "SELECT m.id, m.conversation_id AS scope_id, m.content_type, m.content, m.created_at,
                    s.updated_at AS favorited_at, 'private' AS scope_type,
                    CASE WHEN conv.user_a_id = ? THEN conv.user_b_id ELSE conv.user_a_id END AS peer_user_id,
                    peer.account AS peer_account,
                    COALESCE(NULLIF(friend.remark, ''), NULLIF(profile.nickname, ''), peer.account, '私聊') AS scope_name
             FROM message_user_states s INNER JOIN messages m ON m.id = s.message_id
             INNER JOIN conversations conv ON conv.id = m.conversation_id
             LEFT JOIN users peer ON peer.id = CASE WHEN conv.user_a_id = ? THEN conv.user_b_id ELSE conv.user_a_id END
                AND peer.app_id = m.app_id AND peer.admin_id = m.admin_id
             LEFT JOIN user_profiles profile ON profile.user_id = peer.id
             LEFT JOIN friends friend ON friend.app_id = m.app_id AND friend.user_id = ? AND friend.friend_user_id = peer.id AND friend.status = 1
             WHERE s.user_id = ? AND s.is_favorite = 1 AND s.is_deleted = 0 AND m.app_id = ?",
            [(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['app_id']]
        );
        $private = MessageMediaService::hydrate($private, 'private_message', (int) $user['app_id']);
        $group = Database::all(
            "SELECT m.id, m.room_id AS scope_id, m.content_type, m.content, m.created_at,
                    s.updated_at AS favorited_at, 'group' AS scope_type, room.name AS scope_name
             FROM communication_message_states s INNER JOIN chat_room_messages m ON m.id = s.message_id
             INNER JOIN chat_rooms room ON room.id = m.room_id
             WHERE s.user_id = ? AND s.app_id = ? AND s.scope_type = 'group'
               AND s.is_favorite = 1 AND s.is_deleted = 0 AND m.status = 1",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $group = MessageMediaService::hydrate($group, 'group_message', (int) $user['app_id']);
        $service = Database::all(
            "SELECT m.id, m.session_id AS scope_id, 'text' AS content_type, m.content, m.created_at,
                    s.updated_at AS favorited_at, 'service' AS scope_type, '在线客服' AS scope_name
             FROM communication_message_states s INNER JOIN service_messages m ON m.id = s.message_id
             WHERE s.user_id = ? AND s.app_id = ? AND s.scope_type = 'service'
               AND s.is_favorite = 1 AND s.is_deleted = 0",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $service = MessageMediaService::hydrate($service, 'service_message', (int) $user['app_id']);
        $items = array_merge($private, $group, $service);
        foreach ($items as &$item) {
            $content = trim((string) ($item['content'] ?? ''));
            $type = (string) ($item['content_type'] ?? 'text');
            $item['favorite_type'] = 'message';
            $item['target_id'] = (int) $item['id'];
            $item['message_id'] = (int) $item['id'];
            $item['source_type'] = 'chat';
            $item['source_action'] = '回到聊天位置';
            if (($item['scope_type'] ?? '') === 'private') {
                $item['peer_user_id'] = (int) ($item['peer_user_id'] ?? 0);
                $item['peer_name'] = (string) ($item['scope_name'] ?? '');
                $item['peer_account'] = (string) ($item['peer_account'] ?? '');
            }
            $item['title'] = $content !== '' ? mb_substr($content, 0, 80) : self::typeName($type);
            $item['summary'] = (string) ($item['scope_name'] ?? '聊天') . ' · ' . self::typeName($type);
            $item['preview_url'] = self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '聊天消息',
                '来源' => (string) ($item['scope_name'] ?? '聊天'),
                '内容类型' => self::typeName($type),
                '内容' => $content !== '' ? $content : self::typeName($type),
                '发送时间' => (string) ($item['created_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }

    private static function posts(array $user): array
    {
        $items = Database::all(
            'SELECT p.id, p.user_id, p.title, p.content, p.created_at, f.created_at AS favorited_at,
                    profile.nickname AS author_name
             FROM forum_favorites f INNER JOIN forum_posts p ON p.id = f.post_id
             LEFT JOIN user_profiles profile ON profile.user_id = p.user_id
             WHERE f.app_id = ? AND f.user_id = ? AND p.status = 1 AND p.deleted_at IS NULL
               AND (p.audit_status = \'approved\' OR p.user_id = ?)',
            [(int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        $items = ForumVisibilityService::hydratePosts(
            $items, (int) $user['app_id'], (int) $user['id']
        );
        foreach ($items as &$item) {
            $item['favorite_type'] = 'post';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'forum_post';
            $item['source_action'] = '打开帖子';
            $item['summary'] = '论坛帖子 · ' . ((string) ($item['author_name'] ?? '') ?: '匿名用户');
            $item['preview_url'] = self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '论坛帖子',
                '标题' => (string) ($item['title'] ?? ''),
                '作者' => (string) ($item['author_name'] ?? '') ?: '匿名用户',
                '内容' => (string) ($item['content'] ?? ''),
                '发布时间' => (string) ($item['created_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }

    private static function moments(array $user): array
    {
        $items = Database::all(
            "SELECT moment.id, moment.user_id, moment.content, moment.location_name,
                    moment.created_at, moment.edited_at, favorite.created_at AS favorited_at,
                    author.account AS author_account, profile.nickname AS author_name
             FROM moment_favorites favorite
             INNER JOIN user_moments moment ON moment.id = favorite.moment_id
             INNER JOIN users author ON author.id = moment.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = moment.user_id
              WHERE favorite.admin_id = ? AND favorite.app_id = ? AND favorite.user_id = ?
                AND moment.admin_id = ? AND moment.app_id = ?
                AND moment.status = 1 AND moment.deleted_at IS NULL
                AND (moment.audit_status = 'approved' OR moment.user_id = ?)",
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
            ]
        );
        $items = MessageMediaService::hydrate($items, 'moment', (int) $user['app_id']);
        foreach ($items as &$item) {
            $content = trim((string) ($item['content'] ?? ''));
            $author = trim((string) ($item['author_name'] ?? ''));
            if ($author === '') $author = (string) ($item['author_account'] ?? '用户');
            $item['favorite_type'] = 'moment';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'moment';
            $item['source_action'] = '打开动态';
            $item['title'] = $content !== '' ? mb_substr($content, 0, 80) : '图片动态';
            $item['summary'] = '动态 · ' . $author;
            $item['preview_url'] = self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '动态',
                '动态内容' => $content !== '' ? $content : '图片动态',
                '发布者' => $author,
                '位置' => (string) ($item['location_name'] ?? ''),
                '发布时间' => (string) ($item['created_at'] ?? ''),
                '编辑时间' => (string) ($item['edited_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }

    private static function notes(array $user): array
    {
        $items = Database::all(
            "SELECT document.id, document.title, document.content, document.content_type,
                    document.tags_json, document.created_at, document.updated_at,
                    favorite.created_at AS favorited_at
             FROM content_favorites favorite
             INNER JOIN documents document ON document.id = favorite.content_id
             WHERE favorite.admin_id = ? AND favorite.app_id = ? AND favorite.user_id = ?
               AND favorite.content_type = 'document'
               AND document.admin_id = ? AND document.app_id = ? AND document.user_id = ?
               AND document.status = 1 AND document.deleted_at IS NULL",
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
            ]
        );
        $items = MessageMediaService::hydrate($items, 'note', (int) $user['app_id']);
        foreach ($items as &$item) {
            $content = trim(strip_tags((string) ($item['content'] ?? '')));
            $tags = json_decode((string) ($item['tags_json'] ?? '[]'), true);
            if (!is_array($tags)) $tags = [];
            $item['favorite_type'] = 'note';
            $item['target_id'] = (int) $item['id'];
            $item['document_id'] = (int) $item['id'];
            $item['source_type'] = 'note';
            $item['source_action'] = '打开笔记';
            $item['summary'] = '笔记 · ' . ($content !== '' ? mb_substr($content, 0, 80) : '暂无正文');
            $item['preview_url'] = self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '笔记',
                '标题' => (string) ($item['title'] ?? '未命名笔记'),
                '正文摘要' => $content !== '' ? mb_substr($content, 0, 300) : '暂无正文',
                '标签' => $tags,
                '创建时间' => (string) ($item['created_at'] ?? ''),
                '修改时间' => (string) ($item['updated_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
            unset($item['tags_json']);
        }
        unset($item);
        return $items;
    }

    private static function bounties(array $user): array
    {
        $items = Database::all(
            "SELECT bounty.id, bounty.creator_user_id AS user_id, bounty.title,
                    bounty.description AS content, bounty.requirements_json,
                    bounty.reward_integral, bounty.status, bounty.audit_status,
                    bounty.deadline_at, bounty.created_at,
                    reaction.created_at AS favorited_at,
                    creator.account AS author_account, profile.nickname AS author_name
             FROM bounty_reactions reaction
             INNER JOIN bounties bounty ON bounty.id = reaction.bounty_id
             INNER JOIN users creator ON creator.id = bounty.creator_user_id
             LEFT JOIN user_profiles profile ON profile.user_id = bounty.creator_user_id
             WHERE reaction.user_id = ? AND reaction.reaction_type = 'favorite'
               AND bounty.admin_id = ? AND bounty.app_id = ? AND bounty.deleted_at IS NULL
               AND (bounty.audit_status = 'approved' OR bounty.creator_user_id = ?)",
            [
                (int) $user['id'], (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
            ]
        );
        $items = MessageMediaService::hydrate($items, 'bounty', (int) $user['app_id']);
        foreach ($items as &$item) {
            $author = trim((string) ($item['author_name'] ?? ''));
            if ($author === '') $author = (string) ($item['author_account'] ?? '用户');
            $requirements = json_decode((string) ($item['requirements_json'] ?? '[]'), true);
            if (!is_array($requirements)) $requirements = [];
            $item['favorite_type'] = 'bounty';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'bounty';
            $item['source_action'] = '打开悬赏';
            $item['summary'] = '悬赏 · ' . $author . ' · 奖励 ' . (int) ($item['reward_integral'] ?? 0) . ' 余额';
            $item['preview_url'] = self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '悬赏',
                '标题' => (string) ($item['title'] ?? ''),
                '发布者' => $author,
                '说明' => (string) ($item['content'] ?? ''),
                '要求' => $requirements,
                '奖励余额' => (int) ($item['reward_integral'] ?? 0),
                '状态' => (string) ($item['status'] ?? ''),
                '截止时间' => (string) ($item['deadline_at'] ?? ''),
                '发布时间' => (string) ($item['created_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
            unset($item['requirements_json']);
        }
        unset($item);
        return $items;
    }
    private static function resources(array $user): array
    {
        $items = Database::all(
            "SELECT resource.id, resource.title, resource.description AS content, resource.cover_url,
                    category.name AS category_name, reaction.created_at AS favorited_at
             FROM resource_reactions reaction INNER JOIN resources resource ON resource.id = reaction.resource_id
             LEFT JOIN resource_categories category ON category.id = resource.category_id
             WHERE reaction.user_id = ? AND reaction.reaction_type = 'favorite'
               AND resource.app_id = ? AND resource.status = 1 AND resource.deleted_at IS NULL",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $items = MessageMediaService::hydrate($items, 'resource', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['favorite_type'] = 'resource';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'resource';
            $item['source_action'] = '打开资源';
            $item['summary'] = '资源 · ' . ((string) ($item['category_name'] ?? '') ?: '未分类');
            $item['preview_url'] = (string) ($item['cover_url'] ?? '') ?: self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '资源',
                '标题' => (string) ($item['title'] ?? ''),
                '分类' => (string) ($item['category_name'] ?? '') ?: '未分类',
                '介绍' => (string) ($item['content'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }

    private static function apps(array $user): array
    {
        $items = Database::all(
            "SELECT app.id, app.name AS title, app.description AS content, app.icon_url,
                    app.version_name, reaction.created_at AS favorited_at
             FROM store_app_reactions reaction INNER JOIN store_apps app ON app.id = reaction.store_app_id
             WHERE reaction.user_id = ? AND reaction.reaction_type = 'favorite'
               AND app.app_id = ? AND app.status = 1 AND app.deleted_at IS NULL",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $items = MessageMediaService::hydrate($items, 'store_app', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['favorite_type'] = 'app';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'store_app';
            $item['source_action'] = '打开应用';
            $item['summary'] = '应用 · 版本 ' . (string) ($item['version_name'] ?? '未知');
            $item['preview_url'] = (string) ($item['icon_url'] ?? '') ?: self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '应用',
                '应用名称' => (string) ($item['title'] ?? ''),
                '版本' => (string) ($item['version_name'] ?? '未知'),
                '介绍' => (string) ($item['content'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }

    private static function goods(array $user): array
    {
        $items = Database::all(
            "SELECT goods.id, goods.name AS title, goods.description AS content, goods.cover_url,
                    goods.catalog_code, goods.goods_type, goods.price_integral, goods.price_money,
                    goods.stock, goods.sales_count, goods.created_at,
                    category.name AS category_name, reaction.created_at AS favorited_at
             FROM shop_goods_reactions reaction
             INNER JOIN shop_goods goods ON goods.id = reaction.goods_id
             LEFT JOIN shop_categories category ON category.id = goods.category_id
             WHERE reaction.admin_id = ? AND reaction.app_id = ? AND reaction.user_id = ?
               AND reaction.reaction_type = 'favorite'
               AND goods.admin_id = ? AND goods.app_id = ? AND goods.status = 1",
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                (int) $user['admin_id'], (int) $user['app_id'],
            ]
        );
        $items = MessageMediaService::hydrate($items, 'shop_goods', (int) $user['app_id']);
        foreach ($items as &$item) {
            $catalogName = (string) ($item['catalog_code'] ?? 'shop') === 'balance_shop'
                ? '余额商店'
                : '商店';
            $price = (int) ($item['price_integral'] ?? 0) > 0
                ? '余额 ' . (int) $item['price_integral']
                : ((float) ($item['price_money'] ?? 0) > 0
                    ? '¥' . number_format((float) $item['price_money'], 2)
                    : '免费');
            $item['favorite_type'] = 'goods';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'shop_goods';
            $item['source_action'] = '打开商品';
            $item['summary'] = $catalogName . ' · '
                . ((string) ($item['category_name'] ?? '') ?: '未分类') . ' · ' . $price;
            $item['preview_url'] = (string) ($item['cover_url'] ?? '') ?: self::previewUrl($item);
            $item['snapshot'] = [
                '收藏类型' => '商品',
                '商品名称' => (string) ($item['title'] ?? ''),
                '商城' => $catalogName,
                '分类' => (string) ($item['category_name'] ?? '') ?: '未分类',
                '介绍' => (string) ($item['content'] ?? ''),
                '类型' => (string) ($item['goods_type'] ?? 'virtual'),
                '价格' => $price,
                '库存' => (int) ($item['stock'] ?? 0),
                '销量' => (int) ($item['sales_count'] ?? 0),
                '发布时间' => (string) ($item['created_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'attachments' => $item['attachments'] ?? [],
            ];
        }
        unset($item);
        return $items;
    }
    private static function uploads(array $user): array
    {
        $items = Database::all(
            "SELECT upload.id, upload.original_name AS title, upload.file_url, upload.mime_type,
                    upload.size_bytes, upload.created_at, favorite.created_at AS favorited_at
             FROM content_favorites favorite INNER JOIN uploads upload ON upload.id = favorite.content_id
             WHERE favorite.user_id = ? AND favorite.app_id = ? AND favorite.content_type = 'upload'
               AND upload.status = 1",
            [(int) $user['id'], (int) $user['app_id']]
        );
        foreach ($items as &$item) {
            $item['favorite_type'] = 'upload';
            $item['target_id'] = (int) $item['id'];
            $item['source_type'] = 'upload';
            $item['source_action'] = '打开文件';
            $item['content_type'] = self::mimeType((string) ($item['mime_type'] ?? ''));
            $item['summary'] = '文件 · ' . self::typeName((string) $item['content_type']);
            $item['preview_url'] = (string) ($item['file_url'] ?? '');
            $item['snapshot'] = [
                '收藏类型' => '文件',
                '文件名' => (string) ($item['title'] ?? ''),
                '文件类型' => self::typeName((string) $item['content_type']),
                '文件大小' => self::formatBytes((int) ($item['size_bytes'] ?? 0)),
                '上传时间' => (string) ($item['created_at'] ?? ''),
                '收藏时间' => (string) ($item['favorited_at'] ?? ''),
                'preview_url' => (string) ($item['preview_url'] ?? ''),
            ];
        }
        unset($item);
        return $items;
    }

    private static function isImage(array $item): bool
    {
        return self::hasMediaType($item, ['image', 'sticker']);
    }

    private static function hasLink(array $item): bool
    {
        return preg_match('~https?://[^\\s]+~iu', (string) ($item['content'] ?? '')) === 1;
    }

    private static function isFile(array $item): bool
    {
        return self::hasMediaType($item, ['file', 'audio', 'video']);
    }

    private static function hasMediaType(array $item, array $types): bool
    {
        if (in_array((string) ($item['content_type'] ?? ''), $types, true)) return true;
        foreach (($item['attachments'] ?? []) as $attachment) {
            if (in_array((string) ($attachment['media_type'] ?? ''), $types, true)) return true;
        }
        return false;
    }

    private static function previewUrl(array $item): string
    {
        foreach (($item['attachments'] ?? []) as $attachment) {
            $value = (string) ($attachment['thumbnail_url'] ?? '') ?: (string) ($attachment['url'] ?? '');
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function mimeType(string $mime): string
    {
        if (str_starts_with(strtolower($mime), 'image/')) return 'image';
        if (str_starts_with(strtolower($mime), 'video/')) return 'video';
        if (str_starts_with(strtolower($mime), 'audio/')) return 'audio';
        return 'file';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        $value = $unit === 0
            ? (string) (int) $size
            : rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.');
        return $value . ' ' . $units[$unit];
    }

    private static function typeName(string $type): string
    {
        return match ($type) {
            'image' => '图片', 'sticker' => '表情包', 'video' => '视频',
            'audio' => '音频', 'file' => '文件', 'mixed' => '图文消息',
            default => '文字消息',
        };
    }
}
