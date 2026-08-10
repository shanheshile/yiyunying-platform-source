package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class AnimatedImagePlaybackPolicyTest {
    @Test public void loopStateHasExplicitLabelsAndAccessibleActions() {
        assertTrue(AnimatedImagePlaybackPolicy.DEFAULT_LOOP_ENABLED);
        assertEquals("循环开", AnimatedImagePlaybackPolicy.loopLabel(true));
        assertEquals("循环关", AnimatedImagePlaybackPolicy.loopLabel(false));
        assertTrue(AnimatedImagePlaybackPolicy.loopDescription(true).contains("关闭"));
        assertTrue(AnimatedImagePlaybackPolicy.loopDescription(false).contains("开启"));
        assertFalse(AnimatedImagePlaybackPolicy.toggled(true));
        assertTrue(AnimatedImagePlaybackPolicy.toggled(false));
        assertTrue(AnimatedImagePlaybackPolicy.replayDescription().contains("第一帧"));
    }
}
