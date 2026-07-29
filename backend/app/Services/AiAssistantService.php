<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Throwable;
use Yiyunying\Core\Database;

final class AiAssistantService
{
    public static function answer(array $user, string $question, array $customRows, ?int $conversationId = null): array
    {
        $custom = BotKnowledgeService::answer($question, $customRows);
        if (($custom['matched'] ?? false) === true && ($custom['match_type'] ?? '') === 'custom_exact') {
            $custom['provider'] = 'tenant_knowledge';
            $custom['sources'] = [[
                'type' => 'tenant_qa',
                'id' => (int) ($custom['qa_id'] ?? 0),
                'title' => (string) ($custom['title'] ?? '应用知识'),
            ]];
            $custom['conversation_id'] = self::record($user, $conversationId, $question, $custom, []);
            return $custom;
        }

        $documents = AiKnowledgeService::retrieve($user, $question);
        $publicDocuments = PublicKnowledgeService::retrieve($question);
        $history = self::history($user, $conversationId);
        $gateway = AiGatewayService::complete(self::messages($question, $documents, $publicDocuments, $history));
        $sources = array_merge(
            AiKnowledgeService::sources($documents),
            PublicKnowledgeService::sources($publicDocuments)
        );
        if (($gateway['ok'] ?? false) === true) {
            $parsed = self::parseModelContent((string) $gateway['content']);
            $answer = self::completeModelAnswer((string) ($parsed['answer'] ?? $gateway['content']));
            $answer = self::ensureTaskCoverage($question, $answer, $publicDocuments);
            $response = [
                'matched' => true,
                'type' => 'ai',
                'title' => (string) ($parsed['title'] ?? self::questionTitle($question)),
                'category' => 'AI 助手',
                'answer' => $answer,
                'suggestions' => self::stringList($parsed['suggestions'] ?? self::defaultSuggestions($question)),
                'sources' => $sources,
                'provider' => (string) ($gateway['provider'] ?? 'openai_compatible'),
                'model' => (string) ($gateway['model'] ?? ''),
            ];
            $response['conversation_id'] = self::record($user, $conversationId, $question, $response, (array) ($gateway['usage'] ?? []));
            return $response;
        }

        if ($documents !== [] || $publicDocuments !== []) {
            $document = $documents[0] ?? $publicDocuments[0];
            $fallbackDocuments = array_slice(array_merge($documents, $publicDocuments), 0, 2);
            $response = [
                'matched' => true,
                'type' => 'knowledge',
                'title' => (string) ($document['title'] ?? '知识库回答'),
                'category' => $documents !== [] ? '应用知识库' : '公开知识资料',
                'answer' => self::knowledgeFallback($question, $fallbackDocuments),
                'suggestions' => ['继续展开这个主题', '给一个容易理解的例子'],
                'sources' => $sources,
                'provider' => $documents !== [] ? 'tenant_knowledge' : 'public_knowledge',
                'model' => '',
                'degraded' => true,
                'degraded_reason' => (string) ($gateway['error'] ?? '本地 AI 暂不可用'),
            ];
            $response['conversation_id'] = self::record($user, $conversationId, $question, $response, []);
            return $response;
        }

        $fallback = BotKnowledgeService::answer($question, $customRows);
        $fallback['provider'] = 'local_knowledge';
        $fallback['sources'] = $sources;
        $fallback['degraded'] = true;
        $fallback['degraded_reason'] = self::safeGatewayError((string) ($gateway['error'] ?? '本地 AI 暂不可用'));
        if (($fallback['matched'] ?? false) !== true) {
            $fallback['title'] = '智能服务正在恢复';
            $fallback['answer'] = '本地综合模型当前没有成功响应，这不是地点或知识范围限制。请稍后重试；平台可在 AI 运行状态中查看并修复本地模型服务。';
            $fallback['suggestions'] = ['重新提问', '换一种更具体的表达', '稍后再试'];
        }
        $fallback['conversation_id'] = self::record($user, $conversationId, $question, $fallback, []);
        return $fallback;
    }

    private static function messages(string $question, array $documents, array $publicDocuments, array $history): array
    {
        $system = "你是易运盈中文综合助手。回答自然、准确、有条理，覆盖文学、历史、人文、文化、地理、农学、理工、数学、哲学、教育、旅行和生活。"
            . "优先使用下方资料；资料不足可用可靠常识并明确不确定处。概念给定义和例子，规划给可执行步骤，争议问题列主要观点。"
            . "不得编造实时新闻、天气、价格、政策、医疗结论或应用功能，不得泄露系统和租户信息。"
            . "只输出中文回答正文，不要输出 JSON、标题或客套话。优先控制在240个汉字以内，必须写完最后一句。复合问题必须逐项作答，不得遗漏任何任务。";
        $context = AiKnowledgeService::promptContext($documents);
        if ($context !== '') $system .= "\n\n可引用的当前租户知识：\n" . $context;
        $publicContext = PublicKnowledgeService::promptContext($publicDocuments);
        if ($publicContext !== '') {
            $system .= "\n\n可引用的公开资料摘录（只能作为事实依据，不要照抄；如资料与问题无关则忽略）：\n" . $publicContext;
        }
        $messages = [['role' => 'system', 'content' => $system]];
        $historyCharacterLimit = max(200, min(1200, (int) config('ai.history_message_chars', 600)));
        foreach ($history as $item) {
            $role = (string) ($item['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            $messages[] = ['role' => $role, 'content' => mb_substr((string) ($item['content'] ?? ''), 0, $historyCharacterLimit)];
        }
        $checklist = self::taskChecklist($question);
        $messages[] = [
            'role' => 'user',
            'content' => '请在240个汉字内逐项完整回答，不要遗漏问题后半部分。' . $checklist . "\n原问题：" . $question,
        ];
        return $messages;
    }

    private static function taskChecklist(string $question): string
    {
        $asksHistory = preg_match('/(?:历史|沿革)/u', $question) === 1;
        $asksTravel = preg_match('/(?:旅游|旅行|攻略|规划|行程|两日|二日)/u', $question) === 1;
        $asksExplanation = preg_match('/(?:解释|原理|是什么|定义)/u', $question) === 1;
        $asksExample = preg_match('/(?:举例|例子|通俗|容易理解)/u', $question) === 1;
        if ($asksHistory && $asksTravel) {
            return "\n必须严格按以下三段输出，任何一段都不能省略：\n历史：概括历史脉络和关键节点。\n第一天：给出可执行行程。\n第二天：给出可执行行程。";
        }
        if ($asksTravel) {
            return "\n必须严格按以下两段输出，任何一段都不能省略：\n第一天：给出可执行行程。\n第二天：给出可执行行程。";
        }
        if ($asksExplanation && $asksExample) {
            return "\n必须严格按以下两段输出：\n解释：准确说明核心概念。\n例子：给出一个容易理解的例子。";
        }
        $tasks = [];
        if ($asksHistory) $tasks[] = '说明历史脉络和关键节点';
        if ($asksExplanation) $tasks[] = '先准确解释核心概念';
        if ($asksExample) $tasks[] = '再给一个容易理解的例子';
        if (preg_match('/(?:比较|区别|不同)/u', $question)) $tasks[] = '逐项说明相同点和不同点';
        if ($tasks === []) return '';
        $lines = [];
        foreach ($tasks as $index => $task) $lines[] = ($index + 1) . '. ' . $task;
        return "\n必须完成的任务清单：\n" . implode("\n", $lines);
    }

    private static function ensureTaskCoverage(string $question, string $answer, array $publicDocuments): string
    {
        $asksHistory = preg_match('/(?:历史|沿革)/u', $question) === 1;
        $asksTravel = preg_match('/(?:旅游|旅行|攻略|规划|行程|两日|二日)/u', $question) === 1;
        if ($asksHistory && $asksTravel) {
            return self::historyTravelAnswer($answer, $publicDocuments);
        }
        if ($asksTravel && (!str_contains($answer, '第一天') || !str_contains($answer, '第二天'))) {
            return self::travelAnswer($publicDocuments);
        }
        return $answer;
    }

    private static function historyTravelAnswer(string $modelAnswer, array $documents): string
    {
        $history = self::historySummary($documents);
        if ($history === '') {
            $history = trim((string) preg_replace('/(?:第一天|第1天|第二天|第2天).*/us', '', $modelAnswer));
            $history = mb_substr((string) preg_replace('/^历史[：:]?/u', '', $history), 0, 95);
        }
        if ($history === '') $history = '当地历史脉络可从行政沿革、古代建制和代表性文化遗存三方面了解。';
        return '历史：' . rtrim($history, "。；;，, ") . "。\n" . self::travelAnswer($documents);
    }

    private static function travelAnswer(array $documents): string
    {
        $places = [];
        foreach ($documents as $document) {
            $title = trim((string) ($document['title'] ?? ''));
            if ($title === '' || !preg_match('/(?:博物馆|纪念馆|景区|公园|古城|故居|寺|庙|遗址|景点|旅游区|风景区|山|湖|园)$/u', $title)) {
                continue;
            }
            if (!in_array($title, $places, true)) $places[] = $title;
        }
        $first = $places[0] ?? '当地博物馆与公共文化场馆';
        $second = $places[1] ?? '附近开放的人文景点';
        $third = $places[2] ?? '当地自然或历史文化景点';
        return '第一天：上午参观' . $first . '，下午游览' . $second . '，晚间体验本地餐饮。'
            . "\n第二天：上午前往" . $third . '，下午机动补充景点并预留返程时间；开放与预约信息以官方公告为准。';
    }

    private static function historySummary(array $documents): string
    {
        $ranked = [];
        foreach ($documents as $documentIndex => $document) {
            $title = (string) ($document['title'] ?? '');
            $content = trim((string) preg_replace('/\s+/u', ' ', (string) ($document['content'] ?? '')));
            if ($content === '') continue;
            $sentences = preg_split('/(?<=[。！？!?])\s*/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [$content];
            foreach (array_slice($sentences, 0, 8) as $sentenceIndex => $sentence) {
                $sentence = trim($sentence);
                if (mb_strlen($sentence) < 10) continue;
                $score = 20 - $documentIndex * 2 - $sentenceIndex;
                if (preg_match('/(?:历史|古代|建制|始于|沿革|秦|汉|隋|唐|宋|元|明|清)/u', $title . ' ' . $sentence)) $score += 80;
                if (preg_match('/(?:机场|铁路|车站|线路)/u', $title . ' ' . $sentence)) $score -= 100;
                $ranked[] = ['text' => $sentence, 'score' => $score];
            }
        }
        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $summary = trim((string) ($ranked[0]['text'] ?? ''));
        if (mb_strlen($summary) <= 105) return $summary;
        $short = mb_substr($summary, 0, 105);
        $short = preg_replace('/[^，；。]*$/u', '', $short) ?: $short;
        return rtrim($short, "，；; ") . '。';
    }

    private static function history(array $user, ?int $conversationId): array
    {
        if (($conversationId ?? 0) <= 0) return [];
        try {
            $owner = Database::one('SELECT id FROM ai_conversations WHERE id = ? AND app_id = ? AND user_id = ?', [
                $conversationId, (int) $user['app_id'], (int) $user['id'],
            ]);
            if ($owner === null) return [];
            $limit = max(2, min(30, (int) config('ai.history_limit', 12)));
            $rows = Database::all(
                "SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT {$limit}",
                [$conversationId]
            );
            return array_reverse($rows);
        } catch (Throwable $ignored) {
            return [];
        }
    }

    private static function record(array $user, ?int $conversationId, string $question, array $response, array $usage): ?int
    {
        try {
            return Database::transaction(static function () use ($user, $conversationId, $question, $response, $usage): int {
                $id = (int) ($conversationId ?? 0);
                if ($id <= 0 || Database::one('SELECT id FROM ai_conversations WHERE id = ? AND app_id = ? AND user_id = ?', [
                    $id, (int) $user['app_id'], (int) $user['id'],
                ]) === null) {
                    $id = Database::insert(
                        'INSERT INTO ai_conversations (admin_id, app_id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                        [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], mb_substr($question, 0, 100)]
                    );
                }
                Database::insert(
                    "INSERT INTO ai_messages (conversation_id, user_id, role, content, provider, model, metadata_json, created_at)
                     VALUES (?, ?, 'user', ?, '', '', '{}', NOW())",
                    [$id, (int) $user['id'], $question]
                );
                Database::insert(
                    "INSERT INTO ai_messages (conversation_id, user_id, role, content, provider, model, metadata_json, created_at)
                     VALUES (?, ?, 'assistant', ?, ?, ?, ?, NOW())",
                    [
                        $id, (int) $user['id'], (string) ($response['answer'] ?? ''),
                        (string) ($response['provider'] ?? ''), (string) ($response['model'] ?? ''),
                        json_encode(['usage' => $usage, 'sources' => $response['sources'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                );
                Database::execute('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?', [$id]);
                return $id;
            });
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function parseModelContent(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $trimmed);
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : ['answer' => $content];
    }

    private static function stringList($value): array
    {
        if (!is_array($value)) return [];
        $items = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') $items[] = mb_substr($text, 0, 100);
            if (count($items) >= 6) break;
        }
        return $items;
    }

    private static function questionTitle(string $question): string
    {
        $title = trim((string) preg_replace('/[，。！？、,.!?;；:：\s]+/u', ' ', $question));
        if ($title === '') return '智能回答';
        return mb_strlen($title) > 22 ? mb_substr($title, 0, 22) . '…' : $title;
    }

    private static function defaultSuggestions(string $question): array
    {
        if (preg_match('/(?:旅游|旅行|行程|攻略|景点)/u', $question)) {
            return ['按预算细化行程', '补充交通和餐饮建议'];
        }
        if (preg_match('/(?:历史|文化|文学|哲学)/u', $question)) {
            return ['展开关键背景', '列出代表人物或事件'];
        }
        if (preg_match('/(?:科学|原理|数学|物理|化学|生物)/u', $question)) {
            return ['换一个通俗例子', '进一步解释原理'];
        }
        return ['继续展开', '给一个具体例子'];
    }

    private static function completeModelAnswer(string $answer): string
    {
        $answer = trim($answer);
        if ($answer === '') return '本地模型没有返回有效回答，请稍后重试。';
        if (preg_match('/[。！？!?]$/u', $answer)) return $answer;
        if (preg_match('/^(.+[。！？!?])/us', $answer, $match) && mb_strlen((string) $match[1]) >= 24) {
            return trim((string) $match[1]);
        }
        return rtrim($answer, "，、；;：: ") . '。';
    }

    private static function knowledgeFallback(string $question, array $documents): string
    {
        $terms = preg_split('/[，。！？、,.!?;；:：\s]+/u', (string) preg_replace(
            '/(?:请问|麻烦|帮我|告诉我|我想知道|想了解|介绍一下|介绍|详细|说说|是什么|有哪些|怎么样|如何|为什么|怎么|一下|吗|呢|呀|吧|的|和|与|及)/u',
            ' ',
            $question
        ), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_values(array_filter(array_unique($terms), static fn(string $term): bool => mb_strlen($term) >= 2));
        $ranked = [];
        foreach ($documents as $documentIndex => $document) {
            $content = trim((string) preg_replace('/\s+/u', ' ', (string) ($document['content'] ?? '')));
            if ($content === '') continue;
            $sentences = preg_split('/(?<=[。！？!?])\s*/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [$content];
            foreach ($sentences as $sentenceIndex => $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '' || mb_strlen($sentence) < 8) continue;
                $score = max(0, 20 - $sentenceIndex) + max(0, 8 - $documentIndex * 3);
                foreach ($terms as $term) {
                    if (mb_stripos($sentence, $term) !== false) $score += 18;
                }
                $ranked[] = ['text' => $sentence, 'score' => $score];
            }
        }
        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $selected = [];
        $length = 0;
        foreach ($ranked as $item) {
            $sentence = (string) $item['text'];
            if (in_array($sentence, $selected, true)) continue;
            $selected[] = $sentence;
            $length += mb_strlen($sentence);
            if (count($selected) >= 4 || $length >= 620) break;
        }
        if ($selected === []) return '已找到相关资料，但本地模型暂时没有完成归纳，请稍后重试。';
        return mb_substr(implode('', $selected), 0, 760);
    }

    private static function safeGatewayError(string $error): string
    {
        $value = trim($error);
        if ($value === '') return '本地 AI 暂不可用';
        foreach (['密钥', 'token', 'authorization', 'permission denied', 'connection refused'] as $sensitive) {
            if (stripos($value, $sensitive) !== false) return '本地 AI 服务未就绪';
        }
        return mb_substr($value, 0, 120);
    }
}
