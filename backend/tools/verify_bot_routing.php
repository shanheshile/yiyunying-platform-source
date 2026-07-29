<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Services\BotKnowledgeService;
use Yiyunying\Services\NewsService;
use Yiyunying\Services\WeatherService;

$failures = [];

$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected === $actual) {
        echo "[通过] {$label}: " . json_encode($actual, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo "[失败] {$label}: 期望 "
        . json_encode($expected, JSON_UNESCAPED_UNICODE)
        . '，实际 '
        . json_encode($actual, JSON_UNESCAPED_UNICODE)
        . PHP_EOL;
};

foreach ([
    '兖州天气' => '兖州',
    '兖州明天几点到几点下雨' => '兖州',
    '北京明天天气' => '北京',
    '成都未来三天天气' => '成都',
    '今天几点下雨' => '',
] as $question => $expectedLocation) {
    $assertSame(
        '天气地点识别/' . $question,
        $expectedLocation,
        WeatherService::extractLocationQuery($question)
    );
}

foreach ([
    '兖州热点' => '兖州',
    '今日快报' => '',
    '成都新闻' => '成都',
    '今天发生了什么' => '',
] as $question => $expectedTopic) {
    $assertSame('新闻识别/' . $question, true, NewsService::isNewsQuestion($question));
    $assertSame('新闻主题/' . $question, $expectedTopic, NewsService::extractTopic($question));
}

foreach (['兖州旅游规划', '成都历史和两日游'] as $question) {
    $answer = BotKnowledgeService::answer($question);
    $assertSame('知识回答命中/' . $question, true, (bool) ($answer['matched'] ?? false));
    $assertSame(
        '知识回答非空/' . $question,
        true,
        trim((string) ($answer['answer'] ?? '')) !== ''
    );
}

$unknownHistory = BotKnowledgeService::answer('兖州历史');
$assertSame(
    '未知城市历史不误套固定城市资料',
    false,
    (bool) ($unknownHistory['matched'] ?? false)
);

if (in_array('--network', $argv, true)) {
    foreach (['兖州历史', '兖州旅游规划', '成都历史和两日游'] as $question) {
        $documents = \Yiyunying\Services\PublicKnowledgeService::retrieve($question);
        $assertSame('公开知识检索非空/' . $question, true, $documents !== []);
        if ($documents !== []) {
            echo '[资料] ' . $question . ': ' . implode('、', array_map(
                static fn(array $document): string => (string) ($document['title'] ?? ''),
                array_slice($documents, 0, 4)
            )) . PHP_EOL;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, '机器人路由回归失败：' . implode('、', $failures) . PHP_EOL);
    exit(1);
}

echo '机器人路由回归全部通过。' . PHP_EOL;
