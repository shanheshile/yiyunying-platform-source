<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class BotKnowledgeService
{
    public static function answer(string $question, array $customRows = []): array
    {
        $question = trim($question);
        $custom = self::bestCandidate($question, self::customEntries($customRows));
        if ($custom !== null && $custom['score'] >= 0.84) {
            return self::response($custom, 'custom_exact');
        }

        $travel = self::travelAnswer($question);
        if ($travel !== null && ($custom === null || $custom['score'] < 0.72)) {
            return $travel;
        }

        $history = self::historyAnswer($question);
        if ($history !== null && ($custom === null || $custom['score'] < 0.72)) {
            return $history;
        }

        $builtIn = self::bestCandidate($question, self::builtInEntries());
        $best = $custom;
        if ($builtIn !== null && ($best === null || $builtIn['score'] > $best['score'])) {
            $best = $builtIn;
        }
        if ($best !== null && $best['score'] >= 0.50) {
            return self::response($best, (string) ($best['source'] ?? 'built_in'));
        }

        return [
            'matched' => false,
            'type' => 'text',
            'qa_id' => null,
            'title' => '我还需要一点线索',
            'category' => '智能问答',
            'match_type' => 'fallback',
            'confidence' => 0,
            'answer' => "我暂时没有准确理解这个问题。你可以换一种更具体的说法，例如：\n"
                . "• 北京三天怎么玩\n"
                . "• 故宫是什么时候建成的\n"
                . "• 怎么创建笔记并添加附件\n"
                . "• 群聊里怎样上传文件\n"
                . "• 上海明天下不下雨",
            'suggestions' => ['北京三日旅行攻略', '介绍一下故宫历史', '怎么创建笔记', '如何上传群文件'],
        ];
    }

    private static function response(array $candidate, string $matchType): array
    {
        return [
            'matched' => true,
            'type' => 'text',
            'qa_id' => isset($candidate['id']) ? (int) $candidate['id'] : null,
            'title' => (string) ($candidate['title'] ?? '回答'),
            'category' => (string) ($candidate['category'] ?? '智能问答'),
            'match_type' => $matchType,
            'confidence' => round(min(1, max(0, (float) ($candidate['score'] ?? 0))), 3),
            'answer' => (string) ($candidate['answer'] ?? ''),
        ];
    }

    private static function customEntries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            if ($question === '' || $answer === '') continue;
            $entries[] = [
                'id' => (int) ($row['id'] ?? 0),
                'source' => 'custom',
                'title' => $question,
                'category' => '应用知识',
                'questions' => [$question],
                'keywords' => self::splitKeywords((string) ($row['keywords'] ?? '')),
                'answer' => $answer,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }
        return $entries;
    }

    private static function bestCandidate(string $question, array $entries): ?array
    {
        $best = null;
        foreach ($entries as $entry) {
            $score = self::score($question, $entry);
            if (($entry['source'] ?? '') === 'custom') {
                $score += min(0.025, max(0, (int) ($entry['sort_order'] ?? 0)) / 10000);
            }
            if ($best === null || $score > $best['score']) {
                $entry['score'] = min(1, $score);
                $best = $entry;
            }
        }
        return $best;
    }

    private static function score(string $question, array $entry): float
    {
        $query = self::normalize($question);
        if ($query === '') return 0;
        $score = 0;
        foreach ((array) ($entry['questions'] ?? []) as $candidateQuestion) {
            $candidate = self::normalize((string) $candidateQuestion);
            if ($candidate === '') continue;
            if ($query === $candidate) return 1;
            if (str_contains($query, $candidate) || str_contains($candidate, $query)) {
                $shorter = min(self::textLength($query), self::textLength($candidate));
                $longer = max(1, max(self::textLength($query), self::textLength($candidate)));
                $score = max($score, 0.80 + 0.15 * ($shorter / $longer));
            }
            $score = max($score, self::bigramDice($query, $candidate) * 0.82);
        }

        $keywords = [];
        foreach ((array) ($entry['keywords'] ?? []) as $keyword) {
            $normalized = self::normalize((string) $keyword);
            if ($normalized !== '' && self::textLength($normalized) >= 2) $keywords[] = $normalized;
        }
        if ($keywords !== []) {
            $hitWeight = 0;
            $totalWeight = 0;
            $hits = 0;
            foreach ($keywords as $keyword) {
                $weight = min(6, max(2, self::textLength($keyword)));
                $totalWeight += $weight;
                if (str_contains($query, $keyword) || str_contains($keyword, $query)) {
                    $hitWeight += $weight;
                    $hits++;
                }
            }
            if ($hits > 0 && $totalWeight > 0) {
                $coverage = $hitWeight / $totalWeight;
                $keywordScore = 0.55 + 0.35 * $coverage;
                if ($hits >= 2) $keywordScore = max($keywordScore, 0.72 + 0.20 * $coverage);
                $score = max($score, $keywordScore);
            }
        }
        return min(1, $score);
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = strtr($value, [
            '咋样' => '怎么', '怎样' => '怎么', '如何' => '怎么', '咋' => '怎么',
            '新建' => '创建', '建立' => '创建', '帐号' => '账号', '登陆' => '登录',
            '旅游' => '旅行', '自由行' => '旅行', '游玩' => '旅行',
        ]);
        return (string) preg_replace('/[\p{P}\p{S}\s]+/u', '', $value);
    }

    private static function splitKeywords(string $keywords): array
    {
        $items = preg_split('/[\s,，;；|、\/]+/u', trim($keywords)) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
    }

    private static function bigramDice(string $left, string $right): float
    {
        $leftParts = self::bigrams($left);
        $rightParts = self::bigrams($right);
        if ($leftParts === [] || $rightParts === []) return 0;
        $counts = [];
        foreach ($leftParts as $part) $counts[$part] = ($counts[$part] ?? 0) + 1;
        $overlap = 0;
        foreach ($rightParts as $part) {
            if (($counts[$part] ?? 0) <= 0) continue;
            $counts[$part]--;
            $overlap++;
        }
        return (2 * $overlap) / (count($leftParts) + count($rightParts));
    }

    private static function bigrams(string $value): array
    {
        $characters = self::characters($value);
        $length = count($characters);
        if ($length === 0) return [];
        if ($length === 1) return [$value];
        $parts = [];
        for ($index = 0; $index < $length - 1; $index++) {
            $parts[] = $characters[$index] . $characters[$index + 1];
        }
        return $parts;
    }

    private static function textLength(string $value): int
    {
        return count(self::characters($value));
    }

    private static function characters(string $value): array
    {
        if ($value === '') return [];
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($characters) ? $characters : str_split($value);
    }

    private static function travelAnswer(string $question): ?array
    {
        $normalized = self::normalize($question);
        $profiles = self::travelProfiles();
        $destination = self::findProfileName($normalized, $profiles);
        $travelWords = ['旅行', '旅游', '攻略', '怎么玩', '几日游', '景点', '行程', '路线', '好玩', '打卡', '游玩'];
        $hasTripDuration = preg_match('/[1-7一二两三四五六七]\s*(?:天|日)(?:游|旅行)?/u', $question) === 1;
        $hasTravelIntent = self::containsAny($normalized, $travelWords)
            || ($destination !== '' && $hasTripDuration)
            || ($destination !== '' && str_contains($normalized, '怎么安排'));
        if (!$hasTravelIntent) return null;

        $days = self::extractDays($question);
        if ($destination === '') {
            return [
                'matched' => true,
                'type' => 'text',
                'qa_id' => null,
                'title' => '旅行规划助手',
                'category' => '旅游攻略',
                'match_type' => 'travel_intent',
                'confidence' => 0.78,
                'answer' => "可以帮你规划路线。请再告诉我目的地、天数和偏好，例如“成都三日游，喜欢美食和历史”。\n\n"
                    . "我会按天给出景点顺序、交通衔接、当地饮食、住宿区域和避坑提醒。",
            ];
        }

        $profile = $profiles[$destination];
        $routes = (array) ($profile['routes'] ?? []);
        $lines = [];
        for ($day = 1; $day <= $days; $day++) {
            $route = $routes[$day - 1] ?? '安排机动行程：补看喜欢的街区、博物馆或近郊景点，并给返程留出时间';
            $lines[] = "第{$day}天：{$route}";
        }
        $answer = "适合人群：" . $profile['style'] . "\n"
            . "推荐季节：" . $profile['season'] . "\n\n"
            . "行程建议\n" . implode("\n", $lines) . "\n\n"
            . "当地味道：" . $profile['food'] . "\n"
            . "历史提示：" . $profile['history'] . "\n"
            . "实用提醒：景点开放时间、预约规则和交通班次可能调整，出发前请以官方当天信息为准；每天不要排得过满。";
        return [
            'matched' => true,
            'type' => 'text',
            'qa_id' => null,
            'title' => $destination . $days . '日旅行攻略',
            'category' => '旅游攻略',
            'match_type' => 'travel_guide',
            'confidence' => 0.93,
            'answer' => $answer,
        ];
    }

    private static function historyAnswer(string $question): ?array
    {
        $normalized = self::normalize($question);
        if (str_contains($normalized, '聊天记录') || str_contains($normalized, '浏览历史')) return null;
        $profiles = self::historyProfiles();
        $topic = self::findProfileName($normalized, $profiles);
        $historyIntent = self::containsAny($normalized, [
            '历史', '朝代', '由来', '建于', '建成', '谁建', '古代', '年代', '典故', '文物',
        ]);
        if ($topic === '' && !$historyIntent) return null;
        // This service is only the final offline fallback. Never turn an
        // unknown place such as 兖州、曲阜 or any future city into a generic
        // “中国历史” answer. General place knowledge is handled by the local
        // AI before this fallback is reached.
        if ($topic === '') return null;
        $profile = $profiles[$topic];
        return [
            'matched' => true,
            'type' => 'text',
            'qa_id' => null,
            'title' => $topic . '历史简明介绍',
            'category' => '历史资料',
            'match_type' => 'history_knowledge',
            'confidence' => 0.91,
            'answer' => $profile['period'] . "\n\n" . $profile['overview'] . "\n\n关键脉络\n• "
                . implode("\n• ", $profile['points'])
                . "\n\n如果你想继续了解，我还可以按时间线、人物、制度、建筑或旅行参观角度展开。",
        ];
    }

    private static function extractDays(string $question): int
    {
        if (preg_match('/([1-7一二两三四五六七])\s*(?:天|日)/u', $question, $match)) {
            $map = ['一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7];
            $value = (string) ($match[1] ?? '3');
            return isset($map[$value]) ? $map[$value] : max(1, min(7, (int) $value));
        }
        return 3;
    }

    private static function findProfileName(string $normalized, array $profiles): string
    {
        foreach ($profiles as $name => $profile) {
            $aliases = array_merge([$name], (array) ($profile['aliases'] ?? []));
            foreach ($aliases as $alias) {
                if (str_contains($normalized, self::normalize((string) $alias))) return (string) $name;
            }
        }
        return '';
    }

    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, self::normalize((string) $needle))) return true;
        }
        return false;
    }

    private static function travelProfiles(): array
    {
        return [
            '北京' => [
                'aliases' => ['京城'], 'style' => '第一次到访、历史建筑和城市文化爱好者', 'season' => '春秋体感较舒适，冬季适合看雪景但风大',
                'food' => '烤鸭、炸酱面、铜锅涮肉、豆汁焦圈可按口味尝试', 'history' => '北京有三千多年建城史，元明清时期长期作为全国政治中心。',
                'routes' => ['天安门广场—故宫—景山公园', '天坛—前门—大栅栏', '八达岭或慕田峪长城一日', '颐和园—圆明园—北京大学周边', '国家博物馆—什刹海—南锣鼓巷'],
            ],
            '西安' => [
                'aliases' => ['长安'], 'style' => '秦汉唐历史、博物馆和西北美食爱好者', 'season' => '春秋较舒适，夏季注意防晒',
                'food' => '肉夹馍、羊肉泡馍、凉皮、葫芦鸡、甑糕', 'history' => '古称长安，多个王朝在此建都，是丝绸之路的重要起点。',
                'routes' => ['陕西历史博物馆—大雁塔—大唐不夜城', '秦始皇帝陵博物院—华清宫', '西安城墙—碑林—书院门', '小雁塔—西安博物院—回民街周边', '华山或乾陵—法门寺方向择一'],
            ],
            '南京' => [
                'aliases' => ['金陵'], 'style' => '近现代史、六朝文化和城市漫步爱好者', 'season' => '春秋适宜，梅雨季注意降水',
                'food' => '盐水鸭、鸭血粉丝汤、牛肉锅贴、桂花糖芋苗', 'history' => '南京古称金陵、建康，是六朝古都，也承载丰富近现代史。',
                'routes' => ['中山陵—明孝陵—美龄宫', '南京博物院—总统府—1912街区', '夫子庙—科举博物馆—老门东', '城墙中华门段—雨花台—颐和路', '牛首山或栖霞山一日'],
            ],
            '杭州' => [
                'aliases' => ['临安'], 'style' => '湖景、宋韵文化和慢节奏旅行者', 'season' => '春秋最佳，夏季湿热',
                'food' => '片儿川、东坡肉、龙井虾仁、定胜糕', 'history' => '杭州古称临安，南宋时期成为都城，西湖文化延续至今。',
                'routes' => ['断桥—白堤—孤山—曲院风荷', '苏堤—花港观鱼—雷峰塔', '灵隐寺—北高峰—法喜寺', '中国丝绸博物馆—南宋御街—河坊街', '西溪湿地或龙井村半日'],
            ],
            '上海' => [
                'aliases' => ['沪上'], 'style' => '城市建筑、博物馆、商业与夜景爱好者', 'season' => '春秋舒适，梅雨和台风季关注天气',
                'food' => '生煎、小笼、葱油拌面、排骨年糕', 'history' => '上海近代开埠后快速发展，形成中西交汇的城市建筑与商业文化。',
                'routes' => ['外滩—南京东路—人民广场', '上海博物馆—武康路—衡山路', '豫园—城隍庙—陆家嘴夜景', '中华艺术宫—前滩或徐汇滨江', '朱家角或迪士尼一日择一'],
            ],
            '成都' => [
                'aliases' => ['蓉城'], 'style' => '美食、三国文化、熊猫和悠闲生活爱好者', 'season' => '春秋适宜，常年湿度较高',
                'food' => '火锅、串串、钟水饺、担担面、兔头', 'history' => '成都建城史悠久，蜀汉文化、都江堰水利文明和市井生活并存。',
                'routes' => ['成都博物馆—人民公园—宽窄巷子', '熊猫基地—文殊院—建设路', '武侯祠—锦里—玉林路', '都江堰—灌县古城', '青城山或三星堆一日'],
            ],
            '重庆' => [
                'aliases' => ['山城'], 'style' => '立体城市、夜景、火锅和抗战历史爱好者', 'season' => '春秋更舒适，夏季炎热',
                'food' => '重庆火锅、小面、酸辣粉、江湖菜', 'history' => '重庆依山傍水，抗战时期曾作为战时首都，工业与码头文化鲜明。',
                'routes' => ['解放碑—十八梯—山城步道', '湖广会馆—白象居—长江索道', '三峡博物馆—李子坝—鹅岭二厂', '磁器口—渣滓洞或红岩村', '武隆天生三桥或大足石刻一日'],
            ],
            '苏州' => [
                'aliases' => ['姑苏'], 'style' => '古典园林、江南水乡和慢行摄影爱好者', 'season' => '春秋佳，节假日热门园林客流较大',
                'food' => '苏式面、松鼠桂鱼、糖粥、海棠糕', 'history' => '苏州建城历史悠久，古典园林和运河水系体现江南城市传统。',
                'routes' => ['拙政园—苏州博物馆—平江路', '留园—西园寺—山塘街', '虎丘—盘门—网师园夜游', '同里或周庄水乡一日', '金鸡湖—诚品书店—文化艺术中心'],
            ],
            '厦门' => [
                'aliases' => ['鹭岛'], 'style' => '海岸散步、闽南文化和轻松度假者', 'season' => '秋冬较舒适，台风季关注预警',
                'food' => '沙茶面、海蛎煎、土笋冻、花生汤', 'history' => '厦门是闽南文化和近代海洋交流的重要城市，鼓浪屿保留多元建筑。',
                'routes' => ['鼓浪屿历史建筑—日光岩', '厦门大学周边—沙坡尾—演武大桥', '环岛路—曾厝垵—黄厝海滩', '集美学村—园博苑', '植物园—钟鼓索道—八市'],
            ],
            '洛阳' => [
                'aliases' => ['神都'], 'style' => '古都、石窟、博物馆和汉魏隋唐历史爱好者', 'season' => '春季牡丹期热门，秋季也较舒适',
                'food' => '洛阳水席、牛肉汤、浆面条、牡丹燕菜', 'history' => '洛阳长期位于中国古代政治文化核心区域，多朝在此建都。',
                'routes' => ['洛阳博物馆—隋唐洛阳城遗址', '龙门石窟—关林', '白马寺—洛邑古城', '古墓博物馆—应天门夜景', '老君山或少林寺方向一日'],
            ],
        ];
    }

    private static function historyProfiles(): array
    {
        return [
            '中国历史' => [
                'aliases' => ['中华历史'], 'period' => '时间范围：从史前文明到近现代。',
                'overview' => '中国历史可沿“早期国家形成、统一帝国、分裂与再统一、近代转型”四条主线理解。',
                'points' => ['夏商周奠定早期国家与礼制传统', '秦汉建立并巩固统一多民族国家框架', '隋唐推动制度、交通与中外交流发展', '宋元明清经历经济重心变化、疆域整合与社会转型', '近代以来在内外冲击中走向现代国家'],
            ],
            '秦朝' => [
                'aliases' => ['大秦'], 'period' => '公元前221年至公元前207年。',
                'overview' => '秦朝结束战国割据，建立中国历史上第一个中央集权的统一王朝。',
                'points' => ['推行郡县制并加强中央行政', '统一文字、度量衡和车轨', '修筑与连接北方防御工程', '徭役与刑法负担加剧社会矛盾', '秦末起义后迅速灭亡，但制度影响深远'],
            ],
            '汉朝' => [
                'aliases' => ['西汉', '东汉', '大汉'], 'period' => '公元前202年至公元220年，分西汉与东汉。',
                'overview' => '汉朝在秦制基础上调整治理方式，形成影响深远的政治、文化与族群认同。',
                'points' => ['汉初休养生息恢复经济', '汉武帝时期强化中央并拓展对外交流', '丝绸之路促进欧亚往来', '经学与史学发展显著', '后期外戚宦官矛盾和地方势力上升'],
            ],
            '唐朝' => [
                'aliases' => ['大唐'], 'period' => '公元618年至907年。',
                'overview' => '唐朝是制度成熟、文化开放、城市与对外交流高度发展的统一王朝。',
                'points' => ['三省六部与科举制度继续发展', '长安成为国际性城市', '诗歌、书法、绘画与宗教交流繁荣', '安史之乱成为由盛转衰的重要节点', '后期藩镇、宦官与财政问题加重'],
            ],
            '宋朝' => [
                'aliases' => ['北宋', '南宋', '大宋'], 'period' => '公元960年至1279年，分北宋与南宋。',
                'overview' => '宋朝商业、城市、科技与文教发达，同时长期面对北方政权竞争。',
                'points' => ['文官政治与科举规模扩大', '商品经济和海外贸易活跃', '活字印刷、火药与航海技术发展', '理学和宋词具有深远影响', '南宋经济重心进一步南移'],
            ],
            '明朝' => [
                'aliases' => ['大明'], 'period' => '公元1368年至1644年。',
                'overview' => '明朝重建统一秩序，前期迁都北京并开展大规模海上活动，后期社会经济结构持续变化。',
                'points' => ['中央集权进一步强化', '郑和下西洋体现早期海上交流', '北京故宫和都城格局形成', '白银流通与全球贸易联系加深', '后期财政、灾荒与边疆压力叠加'],
            ],
            '清朝' => [
                'aliases' => ['大清'], 'period' => '政权于1636年定国号，1644年入关，1912年结束。',
                'overview' => '清朝完成广阔疆域整合，后期在工业化世界冲击下经历深刻的近代转型。',
                'points' => ['多民族国家治理体系进一步发展', '康雍乾时期社会经济扩张', '人口增长与区域开发显著', '鸦片战争后主权危机加深', '洋务、新政与革命共同推动制度转型'],
            ],
            '故宫' => [
                'aliases' => ['紫禁城'], 'period' => '北京故宫始建于明永乐四年（1406年），主要工程于1420年完成。',
                'overview' => '故宫是明清两代皇宫，以中轴线和层层院落组织礼仪、政务与生活空间。',
                'points' => ['外朝以太和殿等建筑体现国家礼仪', '内廷以乾清宫、交泰殿、坤宁宫为核心', '建筑色彩、屋顶等级和空间尺度均有制度含义', '现为故宫博物院，保存大量宫廷建筑与文物', '参观宜提前了解官方预约与开放区域'],
            ],
            '长城' => [
                'aliases' => ['万里长城'], 'period' => '不同地段跨越战国、秦汉至明代等多个时期，今天常见保存较好的多为明长城。',
                'overview' => '长城不是一条一次建成的连续城墙，而是由城墙、关隘、烽燧和军镇构成的防御与交通体系。',
                'points' => ['早期诸侯国已有边防墙体', '秦汉时期进行连接、扩展与修缮', '明代形成较完整的北方防御体系', '兼具军事、防御、通信和边贸功能', '不同地段年代、材料和保存状态差异很大'],
            ],
            '兵马俑' => [
                'aliases' => ['秦始皇兵马俑', '秦俑'], 'period' => '属于秦始皇帝陵大型陪葬体系，年代约在秦统一前后。',
                'overview' => '兵马俑以规模化陶制军阵表现秦代军制、工艺和帝陵观念。',
                'points' => ['1974年当地农民打井时发现相关遗迹', '陶俑面貌、发式与装备存在差异', '制作体现模制与手工修饰结合', '原有彩绘出土后易受环境变化影响', '应与秦始皇帝陵整体遗址共同理解'],
            ],
            '丝绸之路' => [
                'aliases' => ['丝路'], 'period' => '作为交流网络长期演变，汉代张骞通西域后陆路联系更加活跃。',
                'overview' => '丝绸之路不是单一路线，而是连接东亚、中亚、西亚乃至欧洲的陆海交通与交流网络。',
                'points' => ['货物包括丝绸、香料、宝石、金属和马匹等', '宗教、艺术、技术和语言也沿线传播', '长安、敦煌、撒马尔罕等城市具有节点意义', '路线受政局、气候和贸易中心变化影响', '海上丝绸之路同样是重要组成部分'],
            ],
            '北京' => [
                'aliases' => ['燕京', '北平'], 'period' => '建城史超过三千年，作为全国性都城的历史主要自元代延续至明清。',
                'overview' => '北京从北方军事和区域中心发展为统一王朝都城，城市中轴线集中体现元明清规划传统。',
                'points' => ['燕蓟文化构成早期历史基础', '元大都奠定后世都城格局', '明代营建紫禁城并调整城郭', '清代延续都城体系并发展皇家园林', '近现代成为政治、文化与教育中心'],
            ],
            '西安' => [
                'aliases' => ['长安'], 'period' => '古代长期称长安，是周秦汉唐等重要都城所在地。',
                'overview' => '西安所在关中平原兼具农业、交通与军事优势，是理解中国古代统一王朝的重要城市。',
                'points' => ['周秦政治文明在关中发展', '汉长安城连接丝绸之路', '隋大兴城与唐长安城规划宏大', '唐代成为国际交流中心', '现存遗址与博物馆共同呈现多层历史'],
            ],
            '南京' => [
                'aliases' => ['金陵', '建康'], 'period' => '六朝、明初及近现代多个政权曾在南京建都。',
                'overview' => '南京依托长江交通与江南经济，兼具古代都城、近代开埠和现代历史记忆。',
                'points' => ['六朝时期称建康并形成文化中心', '明初营建南京城墙与都城', '太平天国时期称天京', '中华民国时期具有重要政治地位', '城市遗址分布跨越古代与近现代'],
            ],
            '洛阳' => [
                'aliases' => ['神都', '洛邑'], 'period' => '夏商周至汉魏隋唐时期多次成为都城或政治中心。',
                'overview' => '洛阳位于河洛地区，是早期国家、礼制文明、佛教传播与古代都城研究的重要地点。',
                'points' => ['周代洛邑与礼制传统关系密切', '东汉、曹魏、西晋等在此建都', '北魏洛阳推动佛教艺术发展', '隋唐东都连接大运河与全国交通', '龙门石窟和都城遗址体现多时期历史'],
            ],
        ];
    }

    private static function builtInEntries(): array
    {
        return [
            [
                'source' => 'built_in', 'title' => '我能帮你做什么', 'category' => '使用帮助',
                'questions' => ['你能做什么', '有什么功能', '怎么使用机器人'],
                'keywords' => ['功能介绍', '机器人能力', '帮助', '会什么'],
                'answer' => "我可以回答易运盈后台的使用问题，查询指定城市或当前位置天气，整理城市旅行攻略和历史资料。\n\n"
                    . "你可以直接用自然语言提问，不必记固定口令。问题越具体，答案越准确。",
            ],
            [
                'source' => 'built_in', 'title' => '注册与登录', 'category' => '账号帮助',
                'questions' => ['怎么注册账号', '为什么登录不了', '用户如何登录'],
                'keywords' => ['注册', '登录', '账号', '密码', '验证码'],
                'answer' => "注册入口是否显示、是否需要手机号或邮箱，由当前应用管理员配置。填写账号、昵称和两次一致的密码后提交；启用邮箱或手机验证时还需输入验证码。\n\n登录失败时请先核对账号、应用归属和密码，再检查账号是否停用、应用是否维护或上级服务是否到期。",
            ],
            [
                'source' => 'built_in', 'title' => '找回与修改密码', 'category' => '账号帮助',
                'questions' => ['忘记密码怎么办', '怎么找回密码', '如何修改密码'],
                'keywords' => ['忘记密码', '找回密码', '修改密码', '重置密码'],
                'answer' => "在登录页选择“找回密码”，按管理员启用的邮箱或手机方式完成验证并设置新密码。已登录时可在“我的—设置—账号与安全”修改密码。无法验证原联系方式时，需要提交解绑或人工审核申请。",
            ],
            [
                'source' => 'built_in', 'title' => '创建笔记', 'category' => '笔记',
                'questions' => ['怎么创建笔记', '如何发笔记', '笔记怎么添加附件'],
                'keywords' => ['笔记', '动态', '创建', '附件', '发布'],
                'answer' => "进入“我的笔记”或顶部笔记入口，选择新建后填写标题和内容。笔记可以只发文字、只发图片，也可以同时添加图片、视频或文件附件；发布前还可设置可见范围和标签。",
            ],
            [
                'source' => 'built_in', 'title' => '上传与管理文件', 'category' => '文件',
                'questions' => ['怎么上传文件', '上传后在哪里看', '如何删除文件'],
                'keywords' => ['上传文件', '文件管理', '预览文件', '删除文件', '下载'],
                'answer' => "进入文件中心后可选择相册、拍摄或系统文件。上传完成会进入文件列表，可按名称和类型搜索，并可预览、下载、转发、收藏或删除自己上传的内容。群文件还会显示上传者、时间、大小和下载次数。",
            ],
            [
                'source' => 'built_in', 'title' => '私聊与群聊', 'category' => '消息',
                'questions' => ['怎么给好友发消息', '怎么创建群聊', '如何查看聊天记录'],
                'keywords' => ['私聊', '好友消息', '群聊', '聊天记录', '发消息'],
                'answer' => "在消息页搜索好友、UID 或群聊，点开会话即可发送文字、语音、图片、视频和文件。右上角可进入会话设置；聊天记录搜索支持日期、文字、图片、视频、文件、链接和标签。",
            ],
            [
                'source' => 'built_in', 'title' => '群文件与群相册', 'category' => '群聊',
                'questions' => ['群聊怎么上传文件', '怎么创建群相册', '群文件在哪里'],
                'keywords' => ['群文件', '群相册', '上传群文件', '相册', '文件夹'],
                'answer' => "进入群聊右上角的群设置，在“群应用”中打开文件或相册。你可以直接上传，也可以先创建文件夹或相册再添加内容。上传者可管理自己的内容，群主、群管理员和上级管理角色拥有相应管理权限。",
            ],
            [
                'source' => 'built_in', 'title' => '论坛与动态', 'category' => '社区',
                'questions' => ['怎么发帖子', '帖子如何评论', '动态里有什么'],
                'keywords' => ['论坛', '帖子', '评论', '动态', '板块', '发帖'],
                'answer' => "“动态”汇集论坛、悬赏、资源、商店和投票等入口。进入论坛板块后可查看帖子列表并发帖；帖子支持分节内容、附件、标签、评论、回复、点赞、收藏、转发和举报，是否收费由发布规则决定。",
            ],
            [
                'source' => 'built_in', 'title' => '收藏中心', 'category' => '内容管理',
                'questions' => ['收藏在哪里看', '怎么收藏消息', '如何发送收藏内容'],
                'keywords' => ['收藏', '收藏中心', '收藏消息', '收藏帖子', '发送收藏'],
                'answer' => "收藏中心按聊天、图片、视频、文件、链接、帖子、资源等类型归类。打开收藏可先查看完整详情，再选择发送、转发或取消收藏；从聊天的“更多功能—收藏”进入时，查看详情后返回不会丢失已选状态。",
            ],
            [
                'source' => 'built_in', 'title' => '隐私与陌生人消息', 'category' => '隐私',
                'questions' => ['怎么关闭陌生人消息', '如何隐藏个人资料', '不让别人看动态'],
                'keywords' => ['隐私', '陌生人消息', '隐藏资料', '不让他看', '动态权限'],
                'answer' => "进入“我的—设置—消息与隐私”，可控制陌生人消息、资料字段、关注与粉丝列表以及笔记、帖子、悬赏等动态的可见范围。关闭陌生人消息后，对方只能先申请好友。",
            ],
            [
                'source' => 'built_in', 'title' => '余额与转账', 'category' => '资产',
                'questions' => ['怎么转账', '余额有什么用', '为什么不能给自己转账'],
                'keywords' => ['余额', '转账', '账单', '充值', '不能给自己'],
                'answer' => "余额用途由当前应用管理员配置，可用于商城、会员、付费内容或活动。转账时选择一个或多个接收人并确认金额，系统禁止给自己转账；转账记录和状态可在账单或聊天卡片详情中查看。",
            ],
            [
                'source' => 'built_in', 'title' => '二维码与加好友', 'category' => '社交',
                'questions' => ['怎么扫码加好友', '在哪里看我的二维码', '如何通过UID加好友'],
                'keywords' => ['二维码', '扫码', '加好友', 'UID', '好友申请'],
                'answer' => "在消息页的添加入口可搜索账号名称或 UID，也可扫描个人二维码。找到用户后可先查看公开主页，再填写验证信息、备注、分组和动态权限并发送申请。二维码与 UID 关联，但不同类型对象使用各自独立的 UID。",
            ],
            [
                'source' => 'built_in', 'title' => '通知与免打扰', 'category' => '消息',
                'questions' => ['为什么还有消息通知', '如何设置免打扰', '通知中心在哪里'],
                'keywords' => ['通知', '免打扰', '消息提醒', '红点', '通知中心'],
                'answer' => "会话免打扰后仍可在消息列表看到无数字的小红点，但不会按普通消息响铃或震动。点赞、评论等业务通知进入通知中心并按类型折叠；聊天、客服和机器人会话保留在消息列表。系统通知权限也需在手机设置中开启。",
            ],
            [
                'source' => 'built_in', 'title' => '更新与维护', 'category' => '应用',
                'questions' => ['软件怎么更新', '为什么显示维护中', '强制更新是什么意思'],
                'keywords' => ['软件更新', '版本更新', '强制更新', '维护', '公告'],
                'answer' => "应用启动时会读取所属范围的公告、维护和版本策略。普通更新可以稍后处理，强制更新需要升级后继续使用；维护范围可能只影响某个应用、某一级角色或某项功能。",
            ],
            [
                'source' => 'built_in', 'title' => '联系客服', 'category' => '帮助',
                'questions' => ['怎么联系客服', '客服消息能撤回吗', '找人工客服'],
                'keywords' => ['客服', '人工客服', '在线客服', '联系管理员'],
                'answer' => "在线客服会话可从消息列表或“我的—帮助与客服”进入。发给客服的消息不能撤回，但可以长按复制；客服会话也不会提供匿名转发。涉及账号审核、解绑或付款问题时，请提供必要订单或账号信息。",
            ],
        ];
    }
}
