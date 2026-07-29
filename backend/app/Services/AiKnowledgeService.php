<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Throwable;
use Yiyunying\Core\Database;

final class AiKnowledgeService
{
    public static function retrieve(array $user, string $question): array
    {
        $rows = [];
        try {
            $context = GovernanceService::appContext((int) ($user['app_id'] ?? 0));
            $rows = Database::all(
                "SELECT id, scope_type, title, content, keywords, updated_at
                 FROM ai_knowledge_documents
                 WHERE status = 1 AND root_platform_id = ? AND (
                    scope_type = 'global'
                    OR (scope_type = 'platform' AND platform_id = ?)
                    OR (scope_type = 'admin' AND admin_id = ?)
                    OR (scope_type = 'app' AND app_id = ?)
                 ) ORDER BY priority DESC, id DESC LIMIT 500",
                [
                    (int) $context['root_platform_id'],
                    (int) $context['platform_id'],
                    (int) $context['admin_id'],
                    (int) $context['app_id'],
                ]
            );
        } catch (Throwable $ignored) {
            // Older installations can keep using bot_qa until the migration is applied.
        }
        $scored = [];
        foreach ($rows as $row) {
            $score = self::score($question, (string) $row['title'], (string) $row['keywords'], (string) $row['content']);
            if ($score <= 0) continue;
            $row['score'] = $score;
            $scored[] = $row;
        }
        usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        return array_slice($scored, 0, max(1, min(20, (int) config('ai.knowledge_limit', 8))));
    }

    public static function promptContext(array $documents): string
    {
        $parts = [];
        $limit = max(1, min(4, (int) config('ai.context_document_limit', 2)));
        $characterLimit = max(240, min(1600, (int) config('ai.context_chars_per_document', 450)));
        foreach (array_slice($documents, 0, $limit) as $index => $document) {
            $title = trim((string) ($document['title'] ?? '知识条目'));
            $content = trim((string) ($document['content'] ?? ''));
            if ($content === '') continue;
            $parts[] = '[' . ($index + 1) . '] ' . $title . "\n" . mb_substr($content, 0, $characterLimit);
        }
        return implode("\n\n", $parts);
    }

    public static function sources(array $documents): array
    {
        return array_map(static fn(array $document): array => [
            'id' => (int) ($document['id'] ?? 0),
            'title' => (string) ($document['title'] ?? ''),
            'scope' => (string) ($document['scope_type'] ?? ''),
        ], $documents);
    }

    private static function score(string $query, string $title, string $keywords, string $content): float
    {
        $query = self::normalize($query);
        if ($query === '') return 0;
        $haystacks = [self::normalize($title) => 6.0, self::normalize($keywords) => 4.0, self::normalize($content) => 1.0];
        $tokens = self::tokens($query);
        $score = 0.0;
        foreach ($haystacks as $haystack => $weight) {
            if ($haystack === '') continue;
            if (str_contains($haystack, $query)) $score += 8 * $weight;
            foreach ($tokens as $token) if (str_contains($haystack, $token)) $score += $weight;
        }
        return $score;
    }

    private static function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        return (string) preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $value);
    }

    private static function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) <= 1) {
            $characters = preg_split('//u', str_replace(' ', '', $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($index = 0; $index < count($characters) - 1; $index++) {
                $tokens[] = $characters[$index] . $characters[$index + 1];
            }
        }
        return array_values(array_unique(array_filter($tokens, static fn(string $token): bool => mb_strlen($token) >= 2)));
    }
}
