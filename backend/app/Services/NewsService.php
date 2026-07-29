<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class NewsService
{
    public static function isNewsQuestion(string $question): bool
    {
        $value = mb_strtolower(trim($question));
        if ($value === '') return false;
        foreach ([
            '今日快报', '每日快报', '新闻', '热点', '热搜', '头条', '要闻', '时事',
            '最新资讯', '实时资讯', '最新消息', '新消息', '今日播报', '今日简报', '今日快讯', '快讯', '早报', '晚报',
            '今天发生了什么', '今日发生了什么', '最近发生了什么', '有什么新鲜事',
            'news', 'headlines',
            'breaking news', 'top stories',
        ] as $keyword) {
            if (str_contains($value, $keyword)) return true;
        }
        if (preg_match('/(?:今天|今日|最近|近期|刚刚|当前|本周|本月).{0,18}(?:发生|大事|事件|动态|消息|情况|进展|变化|趋势)/u', $value)) {
            return true;
        }
        if (preg_match('/(?:发生了?(?:哪些|什么)?|有(?:哪些|什么)).{0,8}(?:大事|重大事件|热点事件)/u', $value)) {
            return true;
        }
        return false;
    }

    public static function extractTopic(string $question): string
    {
        if (!self::isNewsQuestion($question)) return '';
        $value = trim($question);
        $value = trim((string) preg_replace('/[，,。.!！?？:：;；]+$/u', '', $value));
        $value = (string) preg_replace(
            '/^(?:请问|麻烦|请|帮我|给我|替我|我想看|我想知道|想看|想知道|查一下|查询|看看|看一下|告诉我)+/u',
            '',
            $value
        );
        $value = (string) preg_replace('/^(?:来|给|整理)?\s*(?:一份|一条|一些)\s*/u', '', $value);
        $value = (string) preg_replace(
            '/(?:有什么|有哪些|发生了什么|怎么样|如何|来一份|看一下|看看|一份|相关的?|的)?'
            . '(?:新闻|热点|热搜|头条|快报|快讯|资讯|要闻|时事|播报|最新消息|新消息)'
            . '(?:是什么|有哪些|怎么样|吗|呢|呀)?/u',
            ' ',
            $value
        );
        $value = (string) preg_replace(
            '/(?:(?:今天|今日|本日|最近|近期|当地|本地)\s*)?(?:发生了?(?:哪些|什么)?(?:大事|事件)?|有什么新鲜事|有(?:哪些|什么)大事)/u',
            ' ',
            $value
        );
        $value = (string) preg_replace('/(?:有(?:哪些|什么)?(?:新)?(?:进展|变化|趋势|动态|情况))/u', ' ', $value);
        $value = (string) preg_replace(
            '/(?:今天|今日|本日|现在|目前|刚刚|最近|近期|最新|实时|每日|本地|当地|全网)+/u',
            ' ',
            $value
        );
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = trim((string) preg_replace('/(?:上|里|方面)$/u', '', $value));
        $value = trim((string) preg_replace('/^[，,。.!！?？:：;；]+|[，,。.!！?？:：;；]+$/u', '', $value));
        if (mb_strlen($value) < 2 || mb_strlen($value) > 80) return '';
        return $value;
    }

    public static function latest(string $question): array
    {
        $topic = self::extractTopic($question);
        $general = $topic === '';
        $cachePath = self::cachePath($general ? '__top__' : $topic);
        $cacheSeconds = max(60, (int) config('news.cache_seconds', 300));
        $cached = self::readCache($cachePath);
        if ($cached !== null && time() - (int) ($cached['cached_at'] ?? 0) <= $cacheSeconds) {
            return self::response($question, $topic, $cached['items'] ?? [], (int) $cached['cached_at'], true);
        }

        try {
            $items = self::request($topic);
            if ($items === []) throw new HttpException('暂时没有检索到相关新闻，请稍后再试', 0, 404);
            self::writeCache($cachePath, $items);
            return self::response($question, $topic, $items, time(), false);
        } catch (\Throwable $exception) {
            $staleSeconds = max($cacheSeconds, (int) config('news.stale_cache_seconds', 21600));
            if ($cached !== null && time() - (int) ($cached['cached_at'] ?? 0) <= $staleSeconds) {
                $result = self::response(
                    $question,
                    $topic,
                    $cached['items'] ?? [],
                    (int) $cached['cached_at'],
                    true
                );
                $result['stale'] = true;
                $result['notice'] = '实时资讯源暂时不可用，当前展示最近一次成功获取的内容。';
                return $result;
            }
            if ($exception instanceof HttpException) throw $exception;
            throw new HttpException('实时资讯服务暂时不可用，请稍后再试', 0, 502);
        }
    }

    private static function request(string $topic): array
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('服务器未启用 cURL，暂时无法获取实时资讯', 0, 503);
        }
        $topEndpoint = (string) config('news.top_endpoint', 'https://news.google.com/rss');
        $searchEndpoint = (string) config('news.search_endpoint', 'https://news.google.com/rss/search');
        $section = self::sectionForTopic($topic);
        if ($topic === '') {
            $url = $topEndpoint . (str_contains($topEndpoint, '?') ? '&' : '?')
                . http_build_query(['hl' => 'zh-CN', 'gl' => 'CN', 'ceid' => 'CN:zh-Hans']);
        } elseif ($section !== '') {
            $url = rtrim($topEndpoint, '/') . '/headlines/section/topic/' . rawurlencode($section)
                . '?'
                . http_build_query(['hl' => 'zh-CN', 'gl' => 'CN', 'ceid' => 'CN:zh-Hans']);
        } else {
            $query = $topic . ' when:7d';
            $url = $searchEndpoint . (str_contains($searchEndpoint, '?') ? '&' : '?')
                . http_build_query([
                    'q' => $query,
                    'hl' => 'zh-CN',
                    'gl' => 'CN',
                    'ceid' => 'CN:zh-Hans',
                ], '', '&', PHP_QUERY_RFC3986);
        }

        $curl = curl_init($url);
        if ($curl === false) throw new HttpException('实时资讯服务初始化失败', 0, 502);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int) config('news.connect_timeout', 4)),
            CURLOPT_TIMEOUT => max(4, (int) config('news.timeout', 12)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/rss+xml, application/xml, text/xml',
                'Accept-Language: zh-CN,zh;q=0.9',
                'User-Agent: Yiyunying-Backend/2.7 (+https://appht.jjmxg.xyz)',
            ],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new HttpException('实时资讯获取失败：' . mb_substr($detail, 0, 100), 0, 502);
        }

        $items = self::parseRss($raw);
        if ($items === []) throw new HttpException('实时资讯源暂时没有返回有效内容', 0, 502);
        return array_slice($items, 0, max(3, min(20, (int) config('news.limit', 10))));
    }

    private static function sectionForTopic(string $topic): string
    {
        $value = mb_strtolower(trim($topic));
        foreach ([
            'WORLD' => ['国际', '国际大事', '国际要闻', '全球', '全球大事', '世界', '世界大事'],
            'NATION' => ['国内', '国内大事', '国内要闻', '全国', '中国'],
            'BUSINESS' => ['财经', '商业', '经济', '金融', '股市'],
            'TECHNOLOGY' => ['科技', '技术', '人工智能科技'],
            'ENTERTAINMENT' => ['娱乐', '影视', '明星'],
            'SPORTS' => ['体育', '体坛', '赛事'],
            'SCIENCE' => ['科学', '科研'],
            'HEALTH' => ['健康', '医疗', '医学'],
        ] as $section => $aliases) {
            if (in_array($value, $aliases, true)) return $section;
        }
        return '';
    }

    private static function parseRss(string $raw): array
    {
        if (function_exists('simplexml_load_string')) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml instanceof \SimpleXMLElement) {
                $nodes = $xml->channel->item ?? [];
                $items = [];
                $seen = [];
                foreach ($nodes as $node) {
                    $source = trim((string) ($node->source ?? ''));
                    $sourceUrl = isset($node->source) ? trim((string) $node->source['url']) : '';
                    $item = self::normalizeItem([
                        'title' => (string) ($node->title ?? ''),
                        'url' => (string) ($node->link ?? ''),
                        'published' => (string) ($node->pubDate ?? ''),
                        'summary' => (string) ($node->description ?? ''),
                        'source' => $source,
                        'source_url' => $sourceUrl,
                    ]);
                    if ($item === null) continue;
                    $key = mb_strtolower((string) $item['title']);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $items[] = $item;
                }
                if ($items !== []) return $items;
            }
        }

        // 部分精简 PHP 环境未启用 SimpleXML，仍保留一个严格限域的 RSS 后备解析器。
        preg_match_all('/<item>(.*?)<\/item>/si', $raw, $matches);
        $items = [];
        foreach ($matches[1] ?? [] as $block) {
            $source = self::xmlTag($block, 'source');
            $item = self::normalizeItem([
                'title' => self::xmlTag($block, 'title'),
                'url' => self::xmlTag($block, 'link'),
                'published' => self::xmlTag($block, 'pubDate'),
                'summary' => self::xmlTag($block, 'description'),
                'source' => $source,
                'source_url' => '',
            ]);
            if ($item !== null) $items[] = $item;
        }
        return $items;
    }

    private static function xmlTag(string $block, string $tag): string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '(?:\s[^>]*)?>(.*?)<\/'
            . preg_quote($tag, '/') . '>/si', $block, $match)) return '';
        return html_entity_decode(trim((string) $match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function normalizeItem(array $raw): ?array
    {
        $source = self::cleanText((string) ($raw['source'] ?? ''), 80);
        $title = self::cleanText((string) ($raw['title'] ?? ''), 220);
        if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
            $title = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($source) - 3));
        }
        $url = trim((string) ($raw['url'] ?? ''));
        if ($title === '' || !preg_match('#^https?://#i', $url)) return null;
        $timestamp = strtotime((string) ($raw['published'] ?? ''));
        $summary = self::cleanText((string) ($raw['summary'] ?? ''), 260);
        if ($summary === $title || ($source !== '' && $summary === $source)) $summary = '';
        return [
            'title' => $title,
            'source' => $source !== '' ? $source : '新闻来源',
            'source_url' => trim((string) ($raw['source_url'] ?? '')),
            'published_at' => $timestamp === false ? '' : date('Y-m-d H:i', $timestamp),
            'url' => $url,
            'summary' => $summary,
        ];
    }

    private static function cleanText(string $value, int $limit): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, $limit);
    }

    private static function response(
        string $question,
        string $topic,
        array $items,
        int $fetchedAt,
        bool $cached
    ): array {
        $items = array_values(array_filter($items, 'is_array'));
        $display = array_slice($items, 0, 8);
        $scope = $topic === '' ? '今日快报' : $topic . '最新资讯';
        $lines = ['截至' . date('Y年m月d日 H:i', $fetchedAt) . '，为你整理' . $scope . '：'];
        foreach ($display as $index => $item) {
            $meta = trim((string) ($item['source'] ?? ''));
            $published = trim((string) ($item['published_at'] ?? ''));
            if ($published !== '') $meta .= ($meta === '' ? '' : '，') . $published;
            $lines[] = ($index + 1) . '. ' . (string) ($item['title'] ?? '')
                . ($meta === '' ? '' : '（' . $meta . '）');
        }
        $lines[] = '新闻会持续更新，点开对应条目可查看原始报道。';
        return [
            'matched' => true,
            'type' => 'news',
            'title' => $scope,
            'category' => '实时资讯',
            'topic' => $topic,
            'question' => $question,
            'answer' => implode("\n", $lines),
            'fetched_at' => date('Y-m-d H:i:s', $fetchedAt),
            'cached' => $cached,
            'provider' => 'google_news_rss',
            'items' => $items,
        ];
    }

    private static function cachePath(string $topic): string
    {
        $root = dirname(__DIR__, 2) . '/storage/cache/news';
        if (!is_dir($root)) @mkdir($root, 0775, true);
        return $root . '/' . hash('sha256', 'news-v2:' . mb_strtolower(trim($topic))) . '.json';
    }

    private static function readCache(string $path): ?array
    {
        if (!is_readable($path)) return null;
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function writeCache(string $path, array $items): void
    {
        $encoded = json_encode(
            ['cached_at' => time(), 'items' => $items],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (is_string($encoded)) @file_put_contents($path, $encoded, LOCK_EX);
    }
}
