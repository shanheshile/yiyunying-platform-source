<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class PublicKnowledgeService
{
    public static function retrieve(string $question): array
    {
        $question = trim($question);
        if (!(bool) config('ai.public_knowledge_enabled', true) || mb_strlen($question) < 2) {
            return [];
        }

        $cachePath = self::cachePath($question);
        $cached = self::readCache($cachePath);
        $cacheSeconds = max(3600, (int) config('ai.public_knowledge_cache_seconds', 604800));
        $cachedDocuments = is_array($cached['documents'] ?? null) ? $cached['documents'] : [];
        if ($cachedDocuments !== [] && (time() - (int) ($cached['cached_at'] ?? 0)) <= $cacheSeconds) {
            return $cachedDocuments;
        }

        $plan = self::queryPlan($question);
        $queries = $plan['queries'];
        $subjects = $plan['subjects'];
        $sources = [[
            'provider' => '维基百科',
            'endpoint' => 'https://zh.wikipedia.org/w/api.php',
        ]];
        if (self::isTravelQuestion($question)) {
            $sources[] = [
                'provider' => '维基导游',
                'endpoint' => 'https://zh.wikivoyage.org/w/api.php',
            ];
        }

        $documents = [];
        foreach ($sources as $source) {
            foreach ($subjects as $subject) {
                foreach (self::requestTitle((string) $source['endpoint'], (string) $source['provider'], $subject) as $row) {
                    self::remember($documents, $row, $question, $subjects, -1);
                }
            }
            foreach ($queries as $query) {
                $rows = self::request((string) $source['endpoint'], (string) $source['provider'], $query);
                foreach ($rows as $row) {
                    self::remember($documents, $row, $question, $subjects, array_search($query, $queries, true));
                }
            }
        }
        uasort($documents, static fn(array $left, array $right): int => ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0));
        $limit = max(1, min(8, (int) config('ai.public_knowledge_limit', 4)));
        $result = self::selectDocuments(array_values($documents), $question, $limit);
        foreach ($result as &$document) unset($document['_score']);
        unset($document);
        // A transient DNS/TLS/API failure must not lock the robot into an empty
        // answer for the full cache lifetime. Only durable, useful results are cached.
        if ($result !== []) self::writeCache($cachePath, $result);
        return $result;
    }

    public static function promptContext(array $documents): string
    {
        $parts = [];
        $limit = max(1, min(4, (int) config('ai.context_document_limit', 2)));
        $characterLimit = max(240, min(1600, (int) config('ai.context_chars_per_document', 450)));
        foreach (array_slice($documents, 0, $limit) as $index => $document) {
            $title = trim((string) ($document['title'] ?? '公开资料'));
            $content = trim((string) ($document['content'] ?? ''));
            if ($content === '') continue;
            $parts[] = '[公开资料' . ($index + 1) . '] ' . $title . "\n" . mb_substr($content, 0, $characterLimit);
        }
        return implode("\n\n", $parts);
    }

    public static function sources(array $documents): array
    {
        $sources = [];
        foreach ($documents as $document) {
            $sources[] = [
                'id' => 0,
                'title' => (string) ($document['title'] ?? ''),
                'scope' => 'public',
                'provider' => (string) ($document['provider'] ?? ''),
                'url' => (string) ($document['url'] ?? ''),
            ];
        }
        return $sources;
    }

    private static function request(string $endpoint, string $provider, string $query): array
    {
        $url = $endpoint . '?' . http_build_query([
            'action' => 'query',
            'generator' => 'search',
            'gsrsearch' => $query,
            'gsrnamespace' => 0,
            'gsrlimit' => 5,
            'prop' => 'extracts|info',
            'exintro' => 1,
            'explaintext' => 1,
            'exsectionformat' => 'plain',
            'inprop' => 'url',
            'redirects' => 1,
            'format' => 'json',
            'formatversion' => 2,
        ], '', '&', PHP_QUERY_RFC3986);
        return self::requestUrl($url, $provider, $query);
    }

    private static function requestUrl(string $url, string $provider, string $fallbackTitle): array
    {
        $timeout = max(3, min(15, (int) config('ai.public_knowledge_timeout', 6)));
        $headers = [
            'Accept: application/json',
            'Accept-Language: zh-CN,zh;q=0.9',
            'User-Agent: Yiyunying-Backend/2.7 (public-knowledge; contact=admin@appht.jjmxg.xyz)',
        ];
        $raw = false;
        $status = 0;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                $raw = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
            }
        } elseif ((bool) ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers) . "\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
            foreach ($responseHeaders as $responseHeader) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $responseHeader, $match)) {
                    $status = (int) $match[1];
                    break;
                }
            }
        }
        if (!is_string($raw) || $status < 200 || $status >= 300) return [];
        $decoded = json_decode($raw, true);
        $pages = is_array($decoded['query']['pages'] ?? null) ? $decoded['query']['pages'] : [];
        $documents = [];
        foreach ($pages as $page) {
            $content = trim((string) ($page['extract'] ?? ''));
            if ($content === '') continue;
            $documents[] = [
                'title' => trim((string) ($page['title'] ?? $fallbackTitle)),
                'content' => mb_substr($content, 0, 5000),
                'url' => (string) ($page['fullurl'] ?? ''),
                'provider' => $provider,
            ];
        }
        return $documents;
    }

    private static function requestTitle(string $endpoint, string $provider, string $title): array
    {
        $url = $endpoint . '?' . http_build_query([
            'action' => 'query',
            'titles' => $title,
            'prop' => 'extracts|info',
            'exintro' => 1,
            'explaintext' => 1,
            'inprop' => 'url',
            'redirects' => 1,
            'format' => 'json',
            'formatversion' => 2,
        ], '', '&', PHP_QUERY_RFC3986);
        return self::requestUrl($url, $provider, $title);
    }

    private static function queryPlan(string $question): array
    {
        $compact = trim((string) preg_replace(
            '/(?:请问|麻烦|劳驾|能不能|可以|帮我|告诉我|我想知道|想了解|请介绍|介绍一下|介绍|解释|说明|讲解|分析|概述|详细说说|说一下|讲一讲|请|一下)/u',
            ' ',
            $question
        ));
        $compact = trim((string) preg_replace('/[，。！？、,.!?;；:：\s]+/u', ' ', $compact));
        $subjects = [];
        foreach ([
            '/^(.{2,30}?)(?:\s+并|\s+同时|$)/u',
            '/^(.{2,30}?)(?:的)?(?:历史|沿革|文化|地理|位置|人口|经济|旅游|旅行|攻略|规划|行程|景点|美食|气候|天气)(?:\s|和|及|并|$)/u',
            '/^(.{2,30}?)的.{1,40}?(?:区别|关系|原理|过程|作用|定义|是什么|有哪些|如何|为什么|怎么)/u',
            '/^(.{2,30}?)(?:是什么|有哪些|怎么样|如何|为什么|怎么)(?:\s|$)/u',
        ] as $pattern) {
            if (preg_match($pattern, $compact, $match)) {
                $candidate = self::cleanSubject((string) ($match[1] ?? ''));
                if ($candidate !== '') $subjects[] = $candidate;
            }
        }
        if ($subjects === [] && mb_strlen($compact) <= 24) {
            $candidate = self::cleanSubject($compact);
            if ($candidate !== '') $subjects[] = $candidate;
        }
        $subjects = array_values(array_unique($subjects));
        usort($subjects, static fn(string $left, string $right): int => mb_strlen($left) <=> mb_strlen($right));

        $intents = [];
        foreach (['历史', '沿革', '文化', '地理', '旅游', '旅行', '攻略', '规划', '景点', '科学', '原理', '定义', '区别', '关系', '作品', '人物', '农业', '哲学'] as $intent) {
            if (mb_stripos($question, $intent) !== false) $intents[] = $intent;
        }
        if (self::isTravelQuestion($question) && !in_array('旅游', $intents, true)) $intents[] = '旅游';

        $queries = [];
        foreach ($subjects as $subject) {
            $queries[] = $subject;
            foreach ($intents as $intent) $queries[] = $subject . ' ' . $intent;
            $relation = trim((string) preg_replace('/(?:有什么|有何|是什么|有哪些|怎么样|如何|为什么|怎么|吗|呢|呀|吧|请)/u', ' ', $compact));
            $relation = trim((string) preg_replace('/\s+/u', ' ', $relation));
            if ($relation !== '' && $relation !== $subject) $queries[] = $relation;
        }
        if ($compact !== '') $queries[] = $compact;
        $queries[] = $question;
        return [
            'subjects' => array_slice($subjects, 0, 3),
            'queries' => array_slice(array_values(array_unique(array_filter($queries))), 0, 5),
        ];
    }

    private static function cleanSubject(string $subject): string
    {
        $subject = trim((string) preg_replace('/^(?:关于|有关|对于|说说|介绍)\s*/u', '', $subject));
        $subject = trim((string) preg_replace(
            '/(?:的)?(?:历史|沿革|文化|地理|旅游|旅行|攻略|规划|行程|景点|美食)$/u',
            '',
            $subject
        ));
        $subject = trim((string) preg_replace('/(?:的|相关|方面|内容|情况|知识|资料)$/u', '', $subject));
        if (mb_strlen($subject) < 2 || mb_strlen($subject) > 30) return '';
        return $subject;
    }

    private static function selectDocuments(array $documents, string $question, int $limit): array
    {
        $selected = [];
        $seen = [];
        $append = static function (?array $document) use (&$selected, &$seen, $limit): void {
            if ($document === null || count($selected) >= $limit) return;
            $key = mb_strtolower((string) ($document['provider'] ?? '') . ':' . (string) ($document['title'] ?? ''));
            if ($key === '' || isset($seen[$key])) return;
            $seen[$key] = true;
            $selected[] = $document;
        };

        $asksHistory = preg_match('/(?:历史|沿革)/u', $question) === 1;
        $asksTravel = self::isTravelQuestion($question);
        if ($asksHistory) {
            $history = array_values(array_filter($documents, [self::class, 'isHistoryDocument']));
            usort($history, static fn(array $left, array $right): int =>
                self::historyDocumentScore($right) <=> self::historyDocumentScore($left));
            $append($history[0] ?? null);
        }
        if ($asksTravel) {
            $travel = array_values(array_filter($documents, [self::class, 'isTravelDocument']));
            foreach ($travel as $document) $append($document);
        }
        foreach ($documents as $document) $append($document);
        return array_slice($selected, 0, $limit);
    }

    private static function isHistoryDocument(array $document): bool
    {
        $title = (string) ($document['title'] ?? '');
        $content = mb_substr((string) ($document['content'] ?? ''), 0, 1800);
        return preg_match('/(?:历史|沿革|古代|故城|遗址|建制)/u', $title . ' ' . $content) === 1;
    }

    private static function historyDocumentScore(array $document): int
    {
        $title = (string) ($document['title'] ?? '');
        $score = (int) ($document['_score'] ?? 0);
        if (preg_match('/(?:历史|沿革|古代|故城)/u', $title)) $score += 600;
        if (preg_match('/(?:博物馆|公园|景区|旅游)/u', $title)) $score -= 180;
        return $score;
    }

    private static function isTravelDocument(array $document): bool
    {
        $title = (string) ($document['title'] ?? '');
        if (($document['provider'] ?? '') === '维基导游') return true;
        if (preg_match('/(?:博物馆|纪念馆|景区|公园|古城|故居|寺|庙|遗址|景点|旅游区|风景区|山|湖|园)$/u', $title)) {
            return true;
        }
        $content = mb_substr((string) ($document['content'] ?? ''), 0, 1200);
        return preg_match('/(?:旅游景点|国家级景区|著名景点|游览|游客)/u', $content) === 1;
    }

    private static function remember(array &$documents, array $row, string $question, array $subjects, $queryIndex): void
    {
        if (self::rejectDocument($row, $question)) return;
        $key = mb_strtolower((string) ($row['provider'] ?? '') . ':' . (string) ($row['title'] ?? ''));
        if ($key === '') return;
        $row['_score'] = self::relevanceScore($row, $question, $subjects, is_int($queryIndex) ? $queryIndex : 99);
        if (!isset($documents[$key]) || (float) $row['_score'] > (float) ($documents[$key]['_score'] ?? -PHP_FLOAT_MAX)) {
            $documents[$key] = $row;
        }
    }

    private static function relevanceScore(array $document, string $question, array $subjects, int $queryIndex): float
    {
        $title = mb_strtolower(trim((string) ($document['title'] ?? '')));
        $content = mb_strtolower((string) ($document['content'] ?? ''));
        $score = max(0, 30 - max(0, $queryIndex) * 3);
        foreach ($subjects as $subject) {
            $needle = mb_strtolower($subject);
            if ($title === $needle) $score += 500;
            elseif (str_starts_with($title, $needle)) $score += 300;
            elseif (str_contains($title, $needle)) $score += 180;
            if (str_contains(mb_substr($content, 0, 800), $needle)) $score += 40;
        }
        foreach (self::significantTerms($question) as $term) {
            if (str_contains($title, $term)) $score += 24;
            elseif (str_contains(mb_substr($content, 0, 1200), $term)) $score += 4;
        }
        if (!preg_match('/(?:车站|火车站|高铁站|机场|铁路|线路)/u', $question)
            && preg_match('/(?:站|机场|铁路|线路)$/u', $title)) {
            $score -= 220;
        }
        if (preg_match('/(?:历史|文化|地理|旅游|旅行|攻略|规划|行程|景点)/u', $question)
            && preg_match('/(?:省|市|区|县|镇|乡)$/u', $title)) {
            $score += 90;
        }
        if (str_contains($title, '消歧义')) $score -= 250;
        if (($document['provider'] ?? '') === '维基导游' && self::isTravelQuestion($question)) $score += 45;
        return $score;
    }

    private static function rejectDocument(array $document, string $question): bool
    {
        $title = mb_strtolower(trim((string) ($document['title'] ?? '')));
        if ($title === '') return true;
        $asksTransport = preg_match('/(?:车站|火车站|高铁站|机场|铁路|地铁|线路|列车|航班)/u', $question) === 1;
        $asksPlaceKnowledge = preg_match('/(?:历史|沿革|文化|地理|旅游|旅行|攻略|规划|行程|景点|美食)/u', $question) === 1;
        if (!$asksTransport && $asksPlaceKnowledge
            && preg_match('/(?:站|机场|铁路|地铁|线路|列车)$/u', $title)) {
            return true;
        }
        return false;
    }

    private static function significantTerms(string $question): array
    {
        $value = trim((string) preg_replace(
            '/(?:请问|麻烦|帮我|告诉我|我想知道|想了解|介绍一下|介绍|详细|说说|是什么|有哪些|怎么样|如何|为什么|怎么|有什么|有何|一下|吗|呢|呀|吧|的|和|与|及|并)/u',
            ' ',
            $question
        ));
        $terms = preg_split('/[，。！？、,.!?;；:：\s]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique(array_filter(array_map(
            static fn(string $term): string => mb_strtolower(trim($term)),
            $terms
        ), static fn(string $term): bool => mb_strlen($term) >= 2))), 0, 10);
    }

    private static function isTravelQuestion(string $question): bool
    {
        foreach (['旅游', '旅行', '攻略', '行程', '景点', '游玩', '路线', '住宿', '两日游', '一日游', 'travel', 'trip'] as $keyword) {
            if (mb_stripos($question, $keyword) !== false) return true;
        }
        return false;
    }

    private static function cachePath(string $question): string
    {
        $root = dirname(__DIR__, 2) . '/storage/cache/public-knowledge';
        if (!is_dir($root)) @mkdir($root, 0775, true);
        return $root . '/' . hash('sha256', 'retrieval-v6:' . mb_strtolower(trim($question))) . '.json';
    }

    private static function readCache(string $path): ?array
    {
        if (!is_readable($path)) return null;
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function writeCache(string $path, array $documents): void
    {
        $encoded = json_encode(
            ['cached_at' => time(), 'documents' => $documents],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (is_string($encoded)) @file_put_contents($path, $encoded, LOCK_EX);
    }
}
