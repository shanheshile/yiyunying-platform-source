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

final class SpeechController
{
    public static function transcribe(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request, 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message']);

        $attachment = null;
        $directUploadId = max(0, (int) $request->input('upload_id', 0));
        if ($directUploadId <= 0) {
            $attachment = self::accessibleAudioAttachment($user, $request);
            $directUploadId = (int) $attachment['upload_id'];
        }
        $upload = Database::one(
            'SELECT * FROM uploads
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1 LIMIT 1',
            [$directUploadId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($upload === null) throw new HttpException('语音文件不存在或已失效', 0, 404);
        if (!str_starts_with(strtolower((string) $upload['mime_type']), 'audio/')) {
            throw new HttpException('所选附件不是可转写的语音文件', 0, 422);
        }
        $language = mb_substr(trim((string) $request->input('language', 'zh')), 0, 20) ?: 'zh';

        $cached = Database::one(
            'SELECT transcript, provider, created_at FROM audio_transcriptions
             WHERE admin_id = ? AND app_id = ? AND upload_id = ? AND language = ? LIMIT 1',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $upload['id'], $language]
        );
        if ($cached !== null) {
            if ($attachment !== null) self::writeAttachmentTranscript($user, $request, (string) $cached['transcript']);
            return Response::success([
                'transcript' => (string) $cached['transcript'],
                'cached' => true,
                'provider' => (string) $cached['provider'],
                'transcribed_at' => (string) $cached['created_at'],
            ], '已读取语音转写缓存');
        }

        $path = self::publicFile((string) $upload['file_path']);
        $maxBytes = max(1048576, (int) config('speech.max_bytes', 104857600));
        if ((int) $upload['size_bytes'] > $maxBytes) {
            throw new HttpException('语音文件超过转写上限，当前上限为 ' . self::sizeText($maxBytes), 0, 422);
        }
        $transcript = self::requestProvider($path, (string) $upload['mime_type'], $language);
        if ($transcript === '') throw new HttpException('没有识别到清晰语音，请重试或检查录音内容', 0, 422);

        Database::execute(
            'INSERT INTO audio_transcriptions
             (admin_id, app_id, upload_id, user_id, language, transcript, provider, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE transcript = VALUES(transcript), provider = VALUES(provider), updated_at = NOW()',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $upload['id'], (int) $user['id'],
                $language, $transcript, mb_substr((string) config('speech.provider', 'openai-compatible'), 0, 80),
            ]
        );
        if ($attachment !== null) self::writeAttachmentTranscript($user, $request, $transcript);
        LogService::userOperation($request, $user, 'speech', 'transcribe', (int) $upload['id']);
        return Response::success(['transcript' => $transcript, 'cached' => false], '语音转文字完成', 201);
    }

    private static function accessibleAudioAttachment(array $user, Request $request): array
    {
        $attachmentId = max(0, (int) $request->input('attachment_id', 0));
        $messageId = max(0, (int) $request->input('message_id', 0));
        $scopeType = strtolower(trim((string) $request->input('scope_type', '')));
        if ($attachmentId <= 0 || $messageId <= 0 || !in_array($scopeType, ['private', 'group', 'service'], true)) {
            throw new HttpException('语音消息定位参数不完整', 0, 422);
        }
        $targetType = [
            'private' => 'private_message',
            'group' => 'group_message',
            'service' => 'service_message',
        ][$scopeType];
        $attachment = Database::one(
            'SELECT id, upload_id, media_type, url FROM media_attachments
             WHERE id = ? AND admin_id = ? AND app_id = ? AND target_type = ? AND target_id = ? LIMIT 1',
            [$attachmentId, (int) $user['admin_id'], (int) $user['app_id'], $targetType, $messageId]
        );
        if ($attachment === null || (string) $attachment['media_type'] !== 'audio' || (int) $attachment['upload_id'] <= 0) {
            throw new HttpException('语音附件不存在或不属于该消息', 0, 404);
        }
        $allowed = match ($scopeType) {
            'private' => Database::one(
                'SELECT message.id FROM messages message
                 INNER JOIN conversations conversation ON conversation.id = message.conversation_id
                 WHERE message.id = ? AND message.app_id = ? AND (conversation.user_a_id = ? OR conversation.user_b_id = ?) LIMIT 1',
                [$messageId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
            ),
            'group' => Database::one(
                'SELECT message.id FROM chat_room_messages message
                 INNER JOIN chat_room_members member ON member.room_id = message.room_id
                 WHERE message.id = ? AND message.app_id = ? AND member.user_id = ? LIMIT 1',
                [$messageId, (int) $user['app_id'], (int) $user['id']]
            ),
            'service' => Database::one(
                'SELECT message.id FROM service_messages message
                 INNER JOIN service_sessions session ON session.id = message.session_id
                 WHERE message.id = ? AND message.app_id = ? AND session.user_id = ? LIMIT 1',
                [$messageId, (int) $user['app_id'], (int) $user['id']]
            ),
        };
        if ($allowed === null) throw new HttpException('你无权转写这条语音消息', 0, 403);
        return $attachment;
    }

    private static function publicFile(string $relative): string
    {
        $public = realpath(YIYUNYING_ROOT . '/public');
        $path = realpath(YIYUNYING_ROOT . '/public/' . ltrim(str_replace('\\', '/', $relative), '/'));
        if ($public === false || $path === false || !is_file($path)
            || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $public) . '/')) {
            throw new HttpException('语音文件已丢失，请重新发送后再转写', 0, 404);
        }
        return $path;
    }

    private static function requestProvider(string $path, string $mime, string $language): string
    {
        $provider = strtolower(trim((string) config('speech.provider', 'openai-compatible')));
        if (in_array($provider, ['local', 'local-command', 'whisper-local'], true)) {
            return self::requestLocalProvider($path, $language);
        }
        $endpoint = trim((string) config('speech.endpoint', ''));
        $apiKey = trim((string) config('speech.api_key', ''));
        if ($endpoint === '' || $apiKey === '') {
            throw new HttpException('语音转文字组件尚未启用，请安装易运盈内置本地转写组件', 0, 503);
        }
        if (!function_exists('curl_init')) throw new HttpException('服务器未启用 cURL，暂时无法进行语音转写', 0, 503);
        $curl = curl_init($endpoint);
        if ($curl === false) throw new HttpException('语音转写服务初始化失败', -1, 500);
        $payload = [
            'file' => new \CURLFile($path, $mime !== '' ? $mime : 'audio/mp4', basename($path)),
            'model' => (string) config('speech.model', 'whisper-1'),
            'language' => $language,
            'response_format' => 'json',
        ];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(30, (int) config('speech.timeout', 120)),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new HttpException('语音转写服务调用失败：' . mb_substr($detail, 0, 120), 0, 502);
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $text = trim((string) ($decoded['text'] ?? $decoded['transcript'] ?? ($decoded['data']['text'] ?? '')));
            if ($text !== '') return mb_substr($text, 0, 20000);
        }
        return mb_substr(trim($raw), 0, 20000);
    }

    private static function requestLocalProvider(string $path, string $language): string
    {
        $command = self::resolveLocalCommand(trim((string) config('speech.command', '')));
        $rawArgs = trim((string) config('speech.command_args', ''));
        if ($rawArgs === '') {
            throw new HttpException('易运盈本地语音转文字组件缺失，请重新部署后执行安装脚本', 0, 503);
        }
        if (!function_exists('proc_open')) {
            throw new HttpException('服务器禁用了 proc_open，无法运行本地语音转文字程序', 0, 503);
        }
        $args = json_decode($rawArgs, true);
        if (!is_array($args) || array_filter($args, static fn ($value): bool => !is_scalar($value)) !== []) {
            throw new HttpException('STT_COMMAND_ARGS 必须是 JSON 字符串数组', 0, 500);
        }
        $base = YIYUNYING_ROOT . '/storage/tmp/stt_' . bin2hex(random_bytes(8));
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new HttpException('无法创建语音转写临时目录', -1, 500);
        }
        $output = $base . '/transcript.txt';
        $replacements = [
            '{input}' => $path,
            '{language}' => $language,
            '{model}' => (string) config('speech.model', 'base'),
            '{output}' => $output,
            '{output_dir}' => $base,
        ];
        $processArgs = [$command];
        foreach ($args as $arg) $processArgs[] = strtr((string) $arg, $replacements);
        $pipes = [];
        $processWarning = '';
        set_error_handler(static function (int $severity, string $message) use (&$processWarning): bool {
            $processWarning = $message;
            return true;
        });
        try {
            $process = @proc_open($processArgs, [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, $base);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            self::removeDirectory($base);
            $message = str_contains(strtolower($processWarning), 'permission denied')
                ? '本地语音转写程序没有执行权限，服务端正在维护该组件'
                : '本地语音转写程序启动失败，请稍后重试';
            throw new HttpException($message, 0, 503);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(30, (int) config('speech.timeout', 120));
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($timedOut) {
            self::removeDirectory($base);
            throw new HttpException('本地语音转写超时，请提高 STT_TIMEOUT 或检查服务器性能', 0, 504);
        }
        $text = is_file($output) ? trim((string) file_get_contents($output)) : '';
        if ($text === '') {
            $files = glob($base . '/*.txt') ?: [];
            if ($files !== []) $text = trim((string) file_get_contents($files[0]));
        }
        if ($text === '') $text = trim($stdout);
        self::removeDirectory($base);
        // Some PHP builds report -1 from proc_close after proc_get_status has
        // already observed a successful child exit. A non-empty transcript is
        // authoritative unless the process returned a positive error code.
        if ($exitCode > 0 || $text === '') {
            $detail = trim($stderr) !== '' ? trim($stderr) : ('退出码 ' . $exitCode);
            if (str_contains($detail, 'faster-whisper') || str_contains($detail, 'No module named')) {
                throw new HttpException('本地语音转文字组件尚未安装，请在后端目录执行 deploy/install-local-stt.sh', 0, 503);
            }
            if (str_contains(strtolower($detail), 'permission denied')
                || str_contains(strtolower($detail), 'proc_open')
                || str_contains(strtolower($detail), 'php warning')) {
                throw new HttpException('本地语音转写组件没有执行权限，请稍后重试', 0, 503);
            }
            throw new HttpException('本地语音转写失败：' . self::safeProcessDetail($detail), 0, 502);
        }
        return mb_substr($text, 0, 20000);
    }

    private static function resolveLocalCommand(string $configured): string
    {
        $root = YIYUNYING_ROOT;
        $candidates = array_values(array_unique(array_filter([
            $configured,
            PHP_OS_FAMILY === 'Windows' ? $root . '/storage/stt/venv/Scripts/python.exe' : $root . '/storage/stt/venv/bin/python3',
            PHP_OS_FAMILY === 'Windows' ? 'python.exe' : '/usr/bin/python3',
            PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/local/bin/python3',
            PHP_OS_FAMILY === 'Windows' ? '' : 'python3',
        ], static fn ($value): bool => is_string($value) && trim($value) !== '')));
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            $hasPath = str_contains($candidate, '/') || str_contains($candidate, '\\');
            if (!$hasPath) return $candidate;
            if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
                return $candidate;
            }
        }
        throw new HttpException('易运盈本地语音转文字组件不可执行，请稍后重试', 0, 503);
    }

    private static function safeProcessDetail(string $detail): string
    {
        $detail = preg_replace('/(?:NOTICE|WARNING|PHP message|PHP Warning)\s*:?/iu', '', $detail) ?? '';
        $detail = preg_replace('/\s+/u', ' ', trim($detail)) ?? '';
        if ($detail === '') return '转写服务暂时不可用';
        return mb_substr($detail, 0, 120);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $directory . '/' . $name;
            if (is_dir($path)) self::removeDirectory($path); else @unlink($path);
        }
        @rmdir($directory);
    }

    private static function writeAttachmentTranscript(array $user, Request $request, string $transcript): void
    {
        $attachmentId = max(0, (int) $request->input('attachment_id', 0));
        if ($attachmentId <= 0) return;
        $row = Database::one(
            'SELECT id, metadata_json FROM media_attachments WHERE id = ? AND admin_id = ? AND app_id = ? LIMIT 1',
            [$attachmentId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($row === null) return;
        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $metadata['transcript'] = $transcript;
        $metadata['transcribed_at'] = date('Y-m-d H:i:s');
        Database::execute('UPDATE media_attachments SET metadata_json = ? WHERE id = ?', [
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['id'],
        ]);
    }

    private static function sizeText(int $bytes): string
    {
        return $bytes >= 1073741824
            ? number_format($bytes / 1073741824, 2) . ' GB'
            : number_format($bytes / 1048576, 1) . ' MB';
    }
}
