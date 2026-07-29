<?php
declare(strict_types=1);

use Yiyunying\Services\AiGatewayService;
use Yiyunying\Services\AiAssistantService;
use Yiyunying\Services\BotKnowledgeService;
use Yiyunying\Services\NewsService;
use Yiyunying\Services\PublicKnowledgeService;
use Yiyunying\Services\WeatherService;

require dirname(__DIR__) . '/bootstrap.php';

$failures = [];
$expectations = [
    '北京明天天气' => '北京',
    '北京明天几点到几点下雨' => '北京',
    '济宁天气怎么样' => '济宁',
    '兖州今天几点下雨' => '兖州',
    '曲阜未来三天天气' => '曲阜',
    '成都明天温度' => '成都',
    '乌鲁木齐风力预报' => '乌鲁木齐',
    '东京明天下雨吗' => '东京',
    '东京明天几点到几点下雨' => '东京',
    '新加坡后天湿度' => '新加坡',
    '开罗今天最高温度' => '开罗',
    'London weather tomorrow' => 'London',
];
foreach ($expectations as $question => $expected) {
    $actual = WeatherService::extractLocationQuery($question);
    if (mb_strtolower($actual) !== mb_strtolower($expected)) {
        $failures[] = "地点提取失败：{$question}，期望 {$expected}，实际 {$actual}";
    }
}

$newsExpectations = [
    '兖州今日热点' => '兖州',
    '成都最近有什么新闻' => '成都',
    '科技新闻' => '科技',
    '美国大选最新消息' => '美国大选',
    '今日快报' => '',
    '今天发生了什么' => '',
    '兖州最近发生了什么' => '兖州',
    '今天国际上发生了哪些大事' => '国际',
    '兖州最近有什么新消息' => '兖州',
    '给我一份人工智能今日快讯' => '人工智能',
    '人工智能最近有什么进展' => '人工智能',
    '国内今日要闻' => '国内',
    '体育最近有什么新进展' => '体育',
];
foreach ($newsExpectations as $question => $expectedTopic) {
    if (!NewsService::isNewsQuestion($question)) {
        $failures[] = '实时资讯意图识别失败：' . $question;
        continue;
    }
    $actualTopic = NewsService::extractTopic($question);
    if (mb_strtolower($actualTopic) !== mb_strtolower($expectedTopic)) {
        $failures[] = "资讯主题提取失败：{$question}，期望 {$expectedTopic}，实际 {$actualTopic}";
    }
}

foreach (['兖州的历史', '曲阜历史', '成都的城市历史', '滕州旅游规划'] as $question) {
    $answer = BotKnowledgeService::answer($question);
    if (($answer['title'] ?? '') === '中国历史历史简明介绍') {
        $failures[] = "未知地点被错误套用中国历史模板：{$question}";
    }
}

$queryPlan = new ReflectionMethod(PublicKnowledgeService::class, 'queryPlan');
$queryPlan->setAccessible(true);
$knowledgeExpectations = [
    '请介绍成都的历史，并给出两天旅游规划' => '成都',
    '光合作用的光反应和碳反应有什么区别' => '光合作用',
    '量子力学的测不准原理是什么' => '量子力学',
    '水稻栽培的分蘖期管理有哪些要点' => '水稻栽培',
    '亚里士多德的实体哲学是什么' => '亚里士多德',
    '解释量子纠缠，并举一个容易理解的例子' => '量子纠缠',
];
foreach ($knowledgeExpectations as $question => $expectedSubject) {
    $plan = $queryPlan->invoke(null, $question);
    $subjects = is_array($plan['subjects'] ?? null) ? $plan['subjects'] : [];
    if (!in_array($expectedSubject, $subjects, true)) {
        $failures[] = '知识主题提取失败：' . $question . '，期望 ' . $expectedSubject
            . '，实际 ' . implode('、', $subjects);
    }
}

$rejectDocument = new ReflectionMethod(PublicKnowledgeService::class, 'rejectDocument');
$rejectDocument->setAccessible(true);
if ($rejectDocument->invoke(null, ['title' => '兖州站'], '介绍兖州历史并规划旅行') !== true) {
    $failures[] = '历史旅行检索未过滤车站资料';
}
if ($rejectDocument->invoke(null, ['title' => '兖州区'], '介绍兖州历史并规划旅行') !== false) {
    $failures[] = '历史旅行检索错误过滤行政区资料';
}

$taskChecklist = new ReflectionMethod(AiAssistantService::class, 'taskChecklist');
$taskChecklist->setAccessible(true);
$travelChecklist = (string) $taskChecklist->invoke(null, '介绍兖州的历史，并规划一个两日旅行行程');
foreach (['历史脉络', '第一天', '第二天'] as $required) {
    if (!str_contains($travelChecklist, $required)) $failures[] = '复合任务清单缺少：' . $required;
}
$travelPlan = $queryPlan->invoke(null, '介绍兖州的历史，并规划一个两日旅行行程');
if (($travelPlan['subjects'][0] ?? '') !== '兖州') {
    $failures[] = '复合问题没有优先使用干净地点主题：' . implode('、', $travelPlan['subjects'] ?? []);
}
if (!in_array('兖州 旅游', $travelPlan['queries'] ?? [], true)) {
    $failures[] = '复合问题搜索计划缺少兖州旅游';
}

$selectDocuments = new ReflectionMethod(PublicKnowledgeService::class, 'selectDocuments');
$selectDocuments->setAccessible(true);
$selectedDocuments = $selectDocuments->invoke(null, [
    ['title' => '兖州区', 'content' => '兖州区历史悠久。', 'provider' => '维基百科', '_score' => 800],
    ['title' => '兖州博物馆', 'content' => '当地文化场馆。', 'provider' => '维基百科', '_score' => 300],
    ['title' => '兴隆文化园', 'content' => '当地旅游景点。', 'provider' => '维基百科', '_score' => 250],
], '介绍兖州历史并规划两日旅行', 3);
if (($selectedDocuments[0]['title'] ?? '') !== '兖州区' || ($selectedDocuments[1]['title'] ?? '') !== '兖州博物馆') {
    $failures[] = '复合检索没有分别保留历史与旅游资料';
}

$ensureTaskCoverage = new ReflectionMethod(AiAssistantService::class, 'ensureTaskCoverage');
$ensureTaskCoverage->setAccessible(true);
$coveredAnswer = (string) $ensureTaskCoverage->invoke(
    null,
    '介绍兖州历史并规划两日旅行',
    '历史：这里只回答了历史。',
    $selectedDocuments
);
foreach (['历史：', '第一天：', '第二天：', '兖州博物馆'] as $required) {
    if (!str_contains($coveredAnswer, $required)) $failures[] = '复合回答自动补全缺少：' . $required;
}

$diagnostics = AiGatewayService::diagnostics();
$result = [
    'ok' => $failures === [],
    'routing_checks' => count($expectations) + count($newsExpectations) + 4 + count($knowledgeExpectations) + 12,
    'failures' => $failures,
    'ai' => $diagnostics,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 1);
