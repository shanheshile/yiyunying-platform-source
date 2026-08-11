<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;

final class VoiceCallController
{
    private const LIVE_STATUSES = ['ringing', 'active'];
    private const TERMINAL_STATUSES = ['declined', 'cancelled', 'missed', 'ended'];

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $calleeId = (int) $request->input('to_user_id', 0);
        $callType = strtolower(trim((string) $request->input('call_type', 'audio')));
        $contextType = strtolower(trim((string) $request->input('context_type', 'private')));
        $contextId = (int) $request->input('context_id', 0);
        if ($calleeId <= 0) throw new HttpException('请选择要呼叫的好友', 422, 422);
        if ($calleeId === (int) $user['id']) throw new HttpException('不能给自己发起语音通话', 422, 422);
        if (!in_array($callType, ['audio', 'video'], true)) {
            throw new HttpException('通话类型只能是语音通话或视频通话', 422, 422);
        }
        if (!in_array($contextType, ['private', 'room'], true)) {
            throw new HttpException('通话上下文只能是私聊或群聊', 422, 422);
        }
        if ($contextType === 'room' && $contextId <= 0) throw new HttpException('请选择通话所在群聊', 422, 422);

        self::expireStaleCalls((int) $user['app_id']);
        $created = Database::transaction(function () use ($user, $calleeId, $callType, $contextType, $contextId): array {
            $first = min((int) $user['id'], $calleeId);
            $second = max((int) $user['id'], $calleeId);
            $lockedUsers = Database::all(
                'SELECT id, status, deleted_at FROM users
                 WHERE app_id = ? AND admin_id = ? AND id IN (?, ?) ORDER BY id FOR UPDATE',
                [(int) $user['app_id'], (int) $user['admin_id'], $first, $second]
            );
            if (count($lockedUsers) !== 2) throw new HttpException('对方账号不存在或不属于当前应用', 404, 404);
            foreach ($lockedUsers as $locked) {
                if ((int) $locked['id'] === $calleeId
                    && ((int) $locked['status'] !== 1 || $locked['deleted_at'] !== null)) {
                    throw new HttpException('对方账号当前不可用', 409, 409);
                }
            }
            $conversationId = null;
            $contextName = '';
            if ($contextType === 'room') {
                $room = Database::one(
                    'SELECT id, name FROM chat_rooms
                     WHERE id = ? AND app_id = ? AND admin_id = ? AND status = 1 AND dissolved_at IS NULL FOR UPDATE',
                    [$contextId, (int) $user['app_id'], (int) $user['admin_id']]
                );
                if ($room === null) throw new HttpException('群聊不存在或已经解散', 404, 404);
                $memberCount = (int) (Database::one(
                    'SELECT COUNT(*) AS total FROM chat_room_members WHERE room_id = ? AND user_id IN (?, ?)',
                    [$contextId, (int) $user['id'], $calleeId]
                )['total'] ?? 0);
                if ($memberCount !== 2) throw new HttpException('只能向当前群聊成员发起通话', 403, 403);
                $contextName = (string) $room['name'];
            } else {
                $friend = Database::one(
                    'SELECT id FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1',
                    [(int) $user['app_id'], (int) $user['id'], $calleeId]
                );
                if ($friend === null) throw new HttpException('只能向好友发起私聊通话', 403, 403);
                $a = min((int) $user['id'], $calleeId);
                $b = max((int) $user['id'], $calleeId);
                Database::execute(
                    "INSERT INTO conversations (admin_id, app_id, type, user_a_id, user_b_id, created_at, updated_at)
                     VALUES (?, ?, 'private', ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()",
                    [(int) $user['admin_id'], (int) $user['app_id'], $a, $b]
                );
                $conversation = Database::one(
                    "SELECT id FROM conversations WHERE app_id = ? AND type = 'private' AND user_a_id = ? AND user_b_id = ? FOR UPDATE",
                    [(int) $user['app_id'], $a, $b]
                );
                if ($conversation === null) throw new HttpException('创建通话会话失败', 500, 500);
                $conversationId = (int) $conversation['id'];
            }

            $contextSql = $contextType === 'room'
                ? "context_type = 'room' AND context_id = ?"
                : "context_type = 'private' AND (context_id IS NULL OR context_id = 0)";
            $sameParams = [(int) $user['app_id'], (int) $user['admin_id'],
                (int) $user['id'], $calleeId, $calleeId, (int) $user['id'], $callType];
            if ($contextType === 'room') $sameParams[] = $contextId;
            $sameCall = Database::one(
                "SELECT id, status, caller_user_id, callee_user_id
                 FROM voice_calls
                 WHERE app_id = ? AND admin_id = ? AND status IN ('ringing','active')
                   AND ((caller_user_id = ? AND callee_user_id = ?)
                     OR (caller_user_id = ? AND callee_user_id = ?))
                   AND call_type = ? AND {$contextSql}
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                $sameParams
            );
            if ($sameCall !== null) {
                $sameCallId = (int) $sameCall['id'];
                $resumeOffer = (string) $sameCall['status'] === 'active'
                    || (int) $sameCall['caller_user_id'] === (int) $user['id'];
                if ($resumeOffer) {
                    // A re-opened client must not replay an obsolete SDP exchange. New rows retain
                    // increasing AUTO_INCREMENT ids, so the peer's after_id cursor remains valid.
                    Database::execute('DELETE FROM voice_call_signals WHERE call_id = ?', [$sameCallId]);
                }
                if ((string) $sameCall['status'] === 'ringing') {
                    Database::execute(
                        'UPDATE voice_calls SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW() WHERE id = ?',
                        [max(15, min(120, (int) config('voice_call.ring_timeout_seconds', 60))), $sameCallId]
                    );
                } else {
                    Database::execute('UPDATE voice_calls SET updated_at = NOW() WHERE id = ?', [$sameCallId]);
                }
                return [
                    'call_id' => $sameCallId,
                    'context_name' => $contextName,
                    'reused' => true,
                    'resume_offer' => $resumeOffer,
                ];
            }

            $busy = Database::one(
                "SELECT id FROM voice_calls
                 WHERE app_id = ? AND status IN ('ringing','active')
                   AND (caller_user_id IN (?, ?) OR callee_user_id IN (?, ?))
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [(int) $user['app_id'], (int) $user['id'], $calleeId, (int) $user['id'], $calleeId]
            );
            if ($busy !== null) throw new HttpException('你或对方正在通话中', 409, 409);

            $callId = Database::insert(
                "INSERT INTO voice_calls
                 (admin_id, app_id, caller_user_id, callee_user_id, conversation_id,
                  context_type, context_id, context_name, status,
                  call_type, started_at, expires_at, duration_seconds, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ringing', ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), 0, NOW(), NOW())",
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $calleeId,
                    $conversationId, $contextType, $contextType === 'room' ? $contextId : null, $contextName, $callType,
                    max(15, min(120, (int) config('voice_call.ring_timeout_seconds', 60)))]
            );
            return [
                'call_id' => $callId,
                'context_name' => $contextName,
                'reused' => false,
                'resume_offer' => false,
            ];
        });
        $callId = (int) $created['call_id'];
        if (!(bool) $created['reused']) self::appendCallEvent($callId, 'started', (int) $user['id']);
        LogService::userOperation($request, $user, 'voice_call', 'create', $callId, [
            'callee_user_id' => $calleeId,
            'call_type' => $callType,
            'context_type' => $contextType,
            'context_id' => $contextType === 'room' ? $contextId : null,
        ]);
        $presented = self::call($user, $callId);
        $presented['reused'] = (bool) $created['reused'];
        $presented['resume_offer'] = (bool) $created['resume_offer'];
        return Response::success(
            ['call' => $presented],
            (bool) $created['reused']
                ? ($presented['status'] === 'active' ? '已重新接入当前通话' : '已恢复当前呼叫')
                : ($callType === 'video' ? '视频通话已发起' : '语音通话已发起'),
            (bool) $created['reused'] ? 200 : 201
        );
    }

    public static function incoming(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $row = Database::one(
            "SELECT id FROM voice_calls
             WHERE app_id = ? AND callee_user_id = ? AND status = 'ringing' AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1",
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['call' => $row === null ? null : self::call($user, (int) $row['id'])]);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        self::expireStaleCalls((int) $user['app_id']);
        $callId = (int) ($params['call_id'] ?? 0);
        self::call($user, $callId, false);
        Database::execute(
            "UPDATE voice_calls SET updated_at = NOW()
             WHERE id = ? AND app_id = ? AND status IN ('ringing','active')
               AND (caller_user_id = ? OR callee_user_id = ?)",
            [$callId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        return Response::success(['call' => self::call($user, $callId)]);
    }

    public static function answer(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $callId = (int) ($params['call_id'] ?? 0);
        $result = Database::transaction(function () use ($user, $callId): string {
            $call = self::lockedCall($user, $callId);
            if ((int) $call['callee_user_id'] !== (int) $user['id']) throw new HttpException('只有被呼叫方可以接听', 403, 403);
            if ($call['status'] === 'active') return 'unchanged';
            if ($call['status'] !== 'ringing') throw new HttpException('当前通话已经结束，无法接听', 409, 409);
            if (strtotime((string) $call['expires_at']) <= time()) {
                Database::execute("UPDATE voice_calls SET status = 'missed', ended_at = NOW(), updated_at = NOW() WHERE id = ?", [$callId]);
                return 'missed';
            }
            Database::execute(
                "UPDATE voice_calls SET status = 'active', answered_at = COALESCE(answered_at, NOW()), updated_at = NOW() WHERE id = ?",
                [$callId]
            );
            return 'answered';
        });
        if ($result === 'missed') {
            self::appendCallEvent($callId, 'missed', (int) $user['id']);
            throw new HttpException('来电已经超时', 409, 409);
        }
        if ($result === 'answered') self::appendCallEvent($callId, 'answered', (int) $user['id']);
        LogService::userOperation($request, $user, 'voice_call', 'answer', $callId);
        return Response::success(['call' => self::call($user, $callId)], '通话已接听');
    }

    public static function decline(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $callId = (int) ($params['call_id'] ?? 0);
        $changed = Database::transaction(function () use ($user, $callId): bool {
            $call = self::lockedCall($user, $callId);
            if ((int) $call['callee_user_id'] !== (int) $user['id']) throw new HttpException('只有被呼叫方可以挂断来电', 403, 403);
            if (in_array((string) $call['status'], self::TERMINAL_STATUSES, true)) return false;
            if ($call['status'] !== 'ringing') throw new HttpException('通话已接听，请使用挂断', 409, 409);
            Database::execute(
                "UPDATE voice_calls SET status = 'declined', ended_at = NOW(), ended_by_user_id = ?, updated_at = NOW() WHERE id = ?",
                [(int) $user['id'], $callId]
            );
            return true;
        });
        if ($changed) self::appendCallEvent($callId, 'declined', (int) $user['id']);
        LogService::userOperation($request, $user, 'voice_call', 'decline', $callId);
        return Response::success(['call' => self::call($user, $callId)], '来电已挂断');
    }

    public static function hangup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $callId = (int) ($params['call_id'] ?? 0);
        $clientDuration = max(0, min(86400, (int) $request->input('duration_seconds', 0)));
        $event = Database::transaction(function () use ($user, $callId, $clientDuration): string {
            $call = self::lockedCall($user, $callId);
            $serverDuration = 0;
            if (!empty($call['answered_at'])) {
                $answeredAt = strtotime((string) $call['answered_at']);
                if ($answeredAt !== false) $serverDuration = max(0, time() - $answeredAt);
            }
            $duration = max($clientDuration, $serverDuration, (int) ($call['duration_seconds'] ?? 0));
            if (in_array((string) $call['status'], self::TERMINAL_STATUSES, true)) {
                if ($duration > (int) ($call['duration_seconds'] ?? 0)) {
                    Database::execute(
                        'UPDATE voice_calls SET duration_seconds = ?, updated_at = NOW() WHERE id = ?',
                        [$duration, $callId]
                    );
                }
                return '';
            }
            $status = $call['status'] === 'active' ? 'ended'
                : ((int) $call['callee_user_id'] === (int) $user['id'] ? 'declined' : 'cancelled');
            Database::execute(
                "UPDATE voice_calls
                 SET status = ?, ended_at = NOW(), ended_by_user_id = ?,
                     duration_seconds = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$status, (int) $user['id'], $duration, $callId]
            );
            return $status;
        });
        if ($event !== '') self::appendCallEvent($callId, $event, (int) $user['id']);
        LogService::userOperation($request, $user, 'voice_call', 'hangup', $callId);
        return Response::success(['call' => self::call($user, $callId)], '通话已结束');
    }

    public static function signal(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $callId = (int) ($params['call_id'] ?? 0);
        $call = self::callAccess($user, $callId);
        if (!in_array((string) $call['status'], self::LIVE_STATUSES, true)) throw new HttpException('通话已经结束，不能继续发送信令', 409, 409);
        $batch = $request->input('items', null);
        $items = is_array($batch) ? $batch : [[
            'signal_type' => $request->input('signal_type', ''),
            'payload' => $request->input('payload', []),
        ]];
        if ($items === [] || count($items) > 100) throw new HttpException('通话信令批次不能为空且不能超过100条', 422, 422);
        $encodedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new HttpException('通话信令项目格式错误', 422, 422);
            $type = strtolower(trim((string) ($item['signal_type'] ?? '')));
            if (!in_array($type, ['offer', 'answer', 'ice', 'media'], true)) throw new HttpException('不支持的通话信令类型', 422, 422);
            if ((string) $call['status'] === 'ringing') {
                if ($type === 'offer' && (int) $call['caller_user_id'] !== (int) $user['id']) {
                    throw new HttpException('只有呼叫方可以发送通话邀请', 403, 403);
                }
                if ($type === 'answer' && (int) $call['callee_user_id'] !== (int) $user['id']) {
                    throw new HttpException('只有接听方可以发送通话应答', 403, 403);
                }
            }
            $payload = $item['payload'] ?? [];
            if (!is_array($payload) && !is_string($payload)) throw new HttpException('通话信令内容格式错误', 422, 422);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > 65535) throw new HttpException('通话信令内容过大', 413, 413);
            $encodedItems[] = [$type, $encoded];
        }
        $signalIds = Database::transaction(function () use ($encodedItems, $callId, $user): array {
            $ids = [];
            foreach ($encodedItems as [$type, $encoded]) {
                $ids[] = Database::insert(
                    'INSERT INTO voice_call_signals
                     (call_id, admin_id, app_id, from_user_id, signal_type, payload_json, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [$callId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $type, $encoded]
                );
            }
            Database::execute('UPDATE voice_calls SET updated_at = NOW() WHERE id = ?', [$callId]);
            return $ids;
        });
        return Response::success([
            'signal_id' => count($signalIds) === 1 ? $signalIds[0] : 0,
            'signal_ids' => $signalIds,
        ], '通话信令已发送', 201);
    }

    public static function signals(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $callId = (int) ($params['call_id'] ?? 0);
        self::callAccess($user, $callId);
        $afterId = max(0, (int) $request->input('after_id', 0));
        $rows = Database::all(
            'SELECT id, from_user_id, signal_type, payload_json, created_at
             FROM voice_call_signals
             WHERE call_id = ? AND id > ? AND from_user_id <> ?
             ORDER BY id ASC LIMIT 200',
            [$callId, $afterId, (int) $user['id']]
        );
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['payload_json'], true);
            $row['payload'] = is_array($decoded) || is_string($decoded) ? $decoded : [];
            unset($row['payload_json']);
        }
        unset($row);
        return Response::success(['items' => $rows]);
    }

    private static function call(array $user, int $callId, bool $present = true): array
    {
        $userId = (int) $user['id'];
        $call = Database::one(
            "SELECT call_row.*,
                    CASE WHEN call_row.caller_user_id = ? THEN call_row.callee_user_id ELSE call_row.caller_user_id END AS peer_user_id,
                    peer.uid AS peer_uid, peer.account AS peer_account,
                    profile.nickname AS peer_nickname, profile.avatar AS peer_avatar,
                    friend.remark AS peer_remark,
                    CASE WHEN call_row.answered_at IS NULL THEN call_row.duration_seconds
                         WHEN call_row.ended_at IS NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, call_row.answered_at, NOW()))
                         ELSE call_row.duration_seconds END AS current_duration_seconds
             FROM voice_calls call_row
             INNER JOIN users peer ON peer.id = CASE WHEN call_row.caller_user_id = ? THEN call_row.callee_user_id ELSE call_row.caller_user_id END
             LEFT JOIN user_profiles profile ON profile.user_id = peer.id
             LEFT JOIN friends friend ON friend.app_id = call_row.app_id AND friend.user_id = ?
                 AND friend.friend_user_id = peer.id AND friend.status = 1
             WHERE call_row.id = ? AND call_row.app_id = ? AND call_row.admin_id = ?
               AND (call_row.caller_user_id = ? OR call_row.callee_user_id = ?)",
            [$userId, $userId, $userId, $callId, (int) $user['app_id'], (int) $user['admin_id'], $userId, $userId]
        );
        if ($call === null) throw new HttpException('语音通话不存在或无权访问', 404, 404);
        if (!$present) return $call;
        $name = trim((string) ($call['peer_remark'] ?? ''));
        if ($name === '') $name = trim((string) ($call['peer_nickname'] ?? ''));
        if ($name === '') $name = trim((string) ($call['peer_account'] ?? ''));
        if ($name === '') $name = '用户 ' . (string) ($call['peer_uid'] ?? $call['peer_user_id']);
        $call['peer_name'] = $name;
        $call['direction'] = (int) $call['caller_user_id'] === $userId ? 'outgoing' : 'incoming';
        $call['can_answer'] = $call['direction'] === 'incoming' && $call['status'] === 'ringing';
        $call['is_terminal'] = in_array((string) $call['status'], self::TERMINAL_STATUSES, true);
        $call['status_label'] = self::statusLabel((string) $call['status']);
        $call['ice_servers'] = (array) config('voice_call.ice_servers', []);
        $call['signal_poll_ms'] = max(80, min(5000, (int) config('voice_call.signal_poll_ms', 100)));
        return $call;
    }

    private static function callAccess(array $user, int $callId): array
    {
        $call = Database::one(
            'SELECT id, status, caller_user_id, callee_user_id
             FROM voice_calls
             WHERE id = ? AND app_id = ? AND admin_id = ?
               AND (caller_user_id = ? OR callee_user_id = ?)',
            [$callId, (int) $user['app_id'], (int) $user['admin_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($call === null) throw new HttpException('语音通话不存在或无权访问', 404, 404);
        return $call;
    }

    private static function lockedCall(array $user, int $callId): array
    {
        $call = Database::one(
            'SELECT * FROM voice_calls WHERE id = ? AND app_id = ? AND admin_id = ?
             AND (caller_user_id = ? OR callee_user_id = ?) FOR UPDATE',
            [$callId, (int) $user['app_id'], (int) $user['admin_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($call === null) throw new HttpException('语音通话不存在或无权访问', 404, 404);
        return $call;
    }

    private static function expireStaleCalls(int $appId): void
    {
        $missed = Database::all(
            "SELECT id, caller_user_id FROM voice_calls
             WHERE app_id = ? AND status = 'ringing' AND expires_at <= NOW() LIMIT 200",
            [$appId]
        );
        foreach ($missed as $call) {
            $changed = Database::execute(
                "UPDATE voice_calls SET status = 'missed', ended_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND status = 'ringing'",
                [(int) $call['id']]
            );
            if ($changed > 0) self::appendCallEvent((int) $call['id'], 'missed', (int) $call['caller_user_id']);
        }
        $activeTimeout = max(20, min(300, (int) config('voice_call.active_timeout_seconds', 45)));
        $activeCutoff = date('Y-m-d H:i:s', time() - $activeTimeout);
        $stale = Database::all(
            "SELECT id, caller_user_id FROM voice_calls
             WHERE app_id = ? AND status = 'active' AND updated_at < ? LIMIT 200",
            [$appId, $activeCutoff]
        );
        foreach ($stale as $call) {
            $changed = Database::execute(
                "UPDATE voice_calls SET status = 'ended', ended_at = NOW(),
                    duration_seconds = CASE WHEN answered_at IS NULL THEN 0 ELSE TIMESTAMPDIFF(SECOND, answered_at, NOW()) END,
                    updated_at = NOW() WHERE id = ? AND status = 'active'",
                [(int) $call['id']]
            );
            if ($changed > 0) self::appendCallEvent((int) $call['id'], 'ended', (int) $call['caller_user_id']);
        }
    }

    private static function appendCallEvent(int $callId, string $event, int $_actorId): void
    {
        Database::transaction(static function () use ($callId, $event): void {
            $call = Database::one('SELECT * FROM voice_calls WHERE id = ? FOR UPDATE', [$callId]);
            if ($call === null) return;
            $content = self::callCardContent($call, $event);
            $callerId = (int) $call['caller_user_id'];

            if ((string) $call['context_type'] === 'room' && (int) $call['context_id'] > 0) {
                $messageId = (int) ($call['room_message_id'] ?? 0);
                if ($messageId <= 0) {
                    $messageId = Database::insert(
                        'INSERT INTO chat_room_messages
                         (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id,
                          content_type, content, tags_json, status, created_at)
                         VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())',
                        [(int) $call['admin_id'], (int) $call['app_id'], (int) $call['context_id'],
                            $callerId, 'user', 'call', $content, '["通话记录"]']
                    );
                    Database::execute(
                        'UPDATE voice_calls SET room_message_id = ?, updated_at = NOW() WHERE id = ?',
                        [$messageId, $callId]
                    );
                } else {
                    Database::execute(
                        "UPDATE chat_room_messages
                         SET user_id = ?, sender_type = 'user', sender_admin_id = NULL,
                             content_type = 'call', content = ?, tags_json = ?, status = 1
                         WHERE id = ? AND room_id = ?",
                        [$callerId, $content, '["通话记录"]', $messageId, (int) $call['context_id']]
                    );
                }
                return;
            }

            $conversationId = (int) ($call['conversation_id'] ?? 0);
            if ($conversationId <= 0) return;
            $messageId = (int) ($call['private_message_id'] ?? 0);
            $createdMessage = false;
            if ($messageId <= 0) {
                $legacy = Database::one(
                    "SELECT id FROM messages
                     WHERE conversation_id = ? AND title = ? AND status = 1
                       AND tags_json LIKE '%通话记录%'
                     ORDER BY id ASC LIMIT 1 FOR UPDATE",
                    [$conversationId, (string) $callId]
                );
                $messageId = (int) ($legacy['id'] ?? 0);
            }
            if ($messageId <= 0) {
                $messageId = Database::insert(
                    'INSERT INTO messages
                     (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
                      title, content_type, content, tags_json, is_read, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())',
                    [(int) $call['admin_id'], (int) $call['app_id'], $conversationId, 'user', $callerId,
                        (int) $call['callee_user_id'], (string) $callId, 'call', $content, '["通话记录"]']
                );
                $createdMessage = true;
            } else {
                Database::execute(
                    "UPDATE messages
                     SET sender_type = 'user', sender_id = ?, receiver_user_id = ?,
                         content_type = 'call', content = ?, tags_json = ?, status = 1
                     WHERE id = ? AND conversation_id = ?",
                    [$callerId, (int) $call['callee_user_id'], $content, '["通话记录"]',
                        $messageId, $conversationId]
                );
            }
            Database::execute(
                'UPDATE voice_calls SET private_message_id = ?, updated_at = NOW() WHERE id = ?',
                [$messageId, $callId]
            );
            Database::execute(
                "UPDATE messages SET status = 0
                 WHERE conversation_id = ? AND title = ? AND id <> ?
                   AND sender_type = 'system' AND tags_json LIKE '%通话记录%'",
                [$conversationId, (string) $callId, $messageId]
            );
            if ($createdMessage) {
                Database::execute(
                    'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$messageId, $conversationId]
                );
            }
        });
    }

    private static function callCardContent(array $call, string $event): string
    {
        $kind = (string) $call['call_type'] === 'video' ? '视频通话' : '语音通话';
        $duration = max(0, (int) ($call['duration_seconds'] ?? 0));
        $durationText = intdiv($duration, 60) . '分' . ($duration % 60) . '秒';
        $state = match ($event) {
            'started' => '等待对方接听',
            'answered' => '正在通话',
            'declined' => '对方未接听',
            'cancelled' => '已取消',
            'missed' => '无人接听',
            default => '通话时间：' . $durationText,
        };
        return $kind . "\n" . $state;
    }

    private static function statusLabel(string $status): string
    {
        return [
            'ringing' => '等待接听', 'active' => '通话中', 'declined' => '未接',
            'cancelled' => '已取消', 'missed' => '未接听', 'ended' => '通话结束',
        ][$status] ?? '未知状态';
    }

    private static function user(Request $request): array
    {
        return AuthService::user($request, 'messages');
    }
}
