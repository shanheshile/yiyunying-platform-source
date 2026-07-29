<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class AiGatewayService
{
    private static int $retryAfter = 0;

    public static function available(): bool
    {
        return (bool) config('ai.enabled', false)
            && trim((string) config('ai.endpoint', '')) !== ''
            && trim((string) config('ai.model', '')) !== '';
    }

    public static function complete(array $messages): array
    {
        if (!self::available()) {
            return ['ok' => false, 'error' => 'AI 服务尚未配置'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '服务器未安装 cURL 扩展'];
        }
        if (self::$retryAfter > time()) {
            return ['ok' => false, 'error' => '本地 AI 正在恢复，已使用知识库回答'];
        }

        $provider = strtolower(trim((string) config('ai.provider', 'ollama')));
        $endpoint = rtrim((string) config('ai.endpoint', ''), '/');
        $temperature = max(0, min(2, (float) config('ai.temperature', 0.35)));
        $maxTokens = max(64, min(2048, (int) config('ai.max_tokens', 180)));
        if ($provider === 'ollama') {
            if (!str_ends_with($endpoint, '/api/chat')) $endpoint .= '/api/chat';
            $payload = [
                'model' => (string) config('ai.model', ''),
                'messages' => array_values($messages),
                'stream' => false,
                'format' => 'json',
                'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens],
            ];
        } else {
            if (!str_ends_with($endpoint, '/chat/completions')) $endpoint .= '/chat/completions';
            $payload = [
                'model' => (string) config('ai.model', ''),
                'messages' => array_values($messages),
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ];
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'];
        $apiKey = trim((string) config('ai.api_key', ''));
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int) config('ai.connect_timeout', 5)),
            CURLOPT_TIMEOUT => max(5, (int) config('ai.timeout', 30)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            self::$retryAfter = time() + max(5, min(300, (int) config('ai.retry_after_seconds', 20)));
            return ['ok' => false, 'error' => $error !== '' ? $error : 'AI 服务暂时不可用', 'http_status' => $status];
        }
        $decoded = json_decode($raw, true);
        $content = is_array($decoded) ? trim((string) ($provider === 'ollama'
            ? ($decoded['message']['content'] ?? '')
            : ($decoded['choices'][0]['message']['content'] ?? ''))) : '';
        if ($content === '') return ['ok' => false, 'error' => 'AI 服务没有返回有效内容'];
        self::$retryAfter = 0;
        return [
            'ok' => true,
            'content' => $content,
            'model' => (string) ($decoded['model'] ?? config('ai.model', '')),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'provider' => $provider,
        ];
    }

    public static function diagnostics(): array
    {
        $provider = strtolower(trim((string) config('ai.provider', 'ollama')));
        $result = [
            'configured' => self::available(),
            'available' => false,
            'provider' => $provider,
            'model' => (string) config('ai.model', ''),
            'message' => self::available() ? '本地 AI 尚未完成连通检查' : '本地 AI 尚未配置',
        ];
        if (!self::available() || !function_exists('curl_init')) return $result;
        $endpoint = self::baseEndpoint((string) config('ai.endpoint', ''), $provider);
        $healthUrl = $provider === 'ollama' ? $endpoint . '/api/tags' : $endpoint . '/models';
        $curl = curl_init($healthUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(5, (int) config('ai.connect_timeout', 5))),
            CURLOPT_TIMEOUT => max(2, min(10, (int) config('ai.connect_timeout', 5) + 2)),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $result['available'] = is_string($raw) && $status >= 200 && $status < 300;
        $result['http_status'] = $status;
        $result['message'] = $result['available'] ? '本地 AI 运行正常' : ($error !== '' ? '本地 AI 连接失败：' . $error : '本地 AI 暂不可用');
        return $result;
    }

    private static function baseEndpoint(string $endpoint, string $provider): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        if ($provider === 'ollama') {
            return (string) preg_replace('#/api/(chat|generate)$#i', '', $endpoint);
        }
        return (string) preg_replace('#/(chat/completions|models)$#i', '', $endpoint);
    }
}
