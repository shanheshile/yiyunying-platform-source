<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class MessagePresentationService
{
    public static function hydrate(array $items, string $scope): array
    {
        foreach ($items as &$item) self::decorate($item, $scope);
        unset($item);
        return $items;
    }

    public static function decorate(array &$item, string $scope): void
    {
        $scope = strtolower(trim($scope));
        $senderType = strtolower(trim((string) ($item['sender_type'] ?? 'user')));
        $role = strtolower(trim((string) ($item['role'] ?? $item['sender_role'] ?? '')));
        $badge = '';
        $tone = 'neutral';
        $name = trim((string) ($item['sender_name'] ?? $item['nickname'] ?? $item['account'] ?? ''));

        if (!empty($item['anonymous'])) {
            $badge = '匿名';
            $tone = 'neutral';
            $role = 'anonymous';
            if ($name === '') $name = trim((string) ($item['anonymous_alias'] ?? '默认用户')) ?: '默认用户';
            $item['sender_id'] = 0;
            $item['sender_avatar'] = '';
            $item['sender_avatar_text'] = '默';
        } elseif ($scope === 'service' && $senderType === 'admin') {
            $badge = '客服';
            $tone = 'secondary';
            $role = 'service';
            if ($name === '') $name = '在线客服';
        } elseif ($senderType === 'system' || $senderType === 'platform'
            || ($senderType === 'admin' && in_array($scope, ['private', 'group'], true))) {
            $senderType = 'system';
            $name = '系统消息';
            $badge = '系统';
            $tone = 'primary';
            $role = 'system';
            $item['actor_hidden_from_members'] = true;
        } elseif ($scope === 'group' && $role === 'owner') {
            $badge = '群主';
            $tone = 'warning';
        } elseif ($scope === 'group' && $role === 'admin') {
            $badge = '版主';
            $tone = 'secondary';
        }

        if ($name === '') {
            $name = match ($senderType) {
                'system' => '系统消息', 'admin' => $scope === 'service' ? '在线客服' : '管理员',
                default => $scope === 'group' ? '群成员' : '用户',
            };
        }
        $item['sender_type'] = $senderType;
        $item['sender_name'] = $name;
        $item['sender_display_name'] = $name;
        $item['sender_badge'] = $badge;
        $item['sender_badge_tone'] = $tone;
        $item['sender_role'] = $role;
    }
}
