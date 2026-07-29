package xyz.jjmxg.yiyunying.ui.bot;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

public final class BotQuestionClassifierTest {
    @Test public void recognizesChineseWeatherIntent() {
        assertTrue(BotQuestionClassifier.isWeatherQuestion("明天需要带伞吗"));
        assertTrue(BotQuestionClassifier.isWeatherQuestion("后天紫外线强不强"));
        assertTrue(BotQuestionClassifier.isWeatherQuestion("今天穿什么比较合适"));
    }

    @Test public void recognizesMixedLanguageWeatherIntent() {
        assertTrue(BotQuestionClassifier.isWeatherQuestion("today weather 怎么样"));
        assertTrue(BotQuestionClassifier.isWeatherQuestion("humidity 是多少"));
    }

    @Test public void doesNotClaimUnrelatedQuestions() {
        assertFalse(BotQuestionClassifier.isWeatherQuestion("如何创建群聊"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("今天有什么活动"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("今日快报"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("兖州今日热点"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("今天国际上发生了哪些大事"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("人工智能最近有什么进展"));
        assertFalse(BotQuestionClassifier.isWeatherQuestion("给我看看今天的财经新闻"));
    }

    @Test public void keepsExplicitWeatherQuestionsOnWeatherRoute() {
        assertTrue(BotQuestionClassifier.isWeatherQuestion("北京明天几点到几点下雨"));
        assertTrue(BotQuestionClassifier.isWeatherQuestion("兖州未来三天的天气和降雨时段"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("北京明天几点到几点下雨"));
        assertEquals("兖州", BotQuestionClassifier.extractRequestedLocation("兖州未来三天的天气和降雨时段"));
    }

    @Test public void extractsExplicitChineseLocationWithoutTreatingTimeAsLocation() {
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("北京明天天气怎么样"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("北京明日天气"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("北京次日天气预报"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("今天北京几点下雨"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("北京今天几点下雨"));
        assertEquals("北京", BotQuestionClassifier.extractRequestedLocation("天气 北京"));
        assertEquals("上海", BotQuestionClassifier.extractRequestedLocation("帮我看看上海最近会下雨吗"));
        assertEquals("广州", BotQuestionClassifier.extractRequestedLocation("查一下广州未来三天天气"));
        assertEquals("山东济宁兖州", BotQuestionClassifier.extractRequestedLocation("山东济宁兖州天气"));
        assertEquals("", BotQuestionClassifier.extractRequestedLocation("明天需要带伞吗"));
        assertEquals("", BotQuestionClassifier.extractRequestedLocation("我这里今天天气怎么样"));
    }

    @Test public void extractsEnglishLocation() {
        assertEquals("New York", BotQuestionClassifier.extractRequestedLocation("weather in New York?"));
        assertEquals("London", BotQuestionClassifier.extractRequestedLocation("London weather tomorrow"));
    }
}
