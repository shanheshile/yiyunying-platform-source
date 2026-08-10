package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

import xyz.jjmxg.yiyunying.R;

public final class ActionIconResolverTest {
    @Test public void mapsAllRequestedContentActions() {
        assertEquals(R.drawable.ic_delete, ActionIconResolver.resolve("删除评论", 0));
        assertEquals(R.drawable.ic_favorite, ActionIconResolver.resolve("取消收藏", 0));
        assertEquals(R.drawable.ic_forward, ActionIconResolver.resolve("转发", 0));
        assertEquals(R.drawable.ic_like, ActionIconResolver.resolve("取消点赞", 0));
        assertEquals(R.drawable.ic_comment, ActionIconResolver.resolve("查看评论", 0));
        assertEquals(R.drawable.ic_reply, ActionIconResolver.resolve("回复", 0));
        assertEquals(R.drawable.ic_delete, ActionIconResolver.resolve("清空历史", 0));
        assertEquals(R.drawable.ic_delete, ActionIconResolver.resolve("移入回收站", 0));
        assertEquals(R.drawable.ic_like, ActionIconResolver.resolve("已赞 12", 0));
    }

    @Test public void preservesSpecificFallbackAndFlagsDestructiveActions() {
        assertEquals(R.drawable.ic_refresh, ActionIconResolver.resolve("刷新", R.drawable.ic_refresh));
        assertTrue(ActionIconResolver.destructive("删除所选"));
        assertTrue(ActionIconResolver.destructive("清理缓存"));
        assertFalse(ActionIconResolver.destructive("收藏"));
        assertEquals("删除所选", ActionIconResolver.description("删除所选", "确定"));
        assertEquals("确定", ActionIconResolver.description("", "确定"));
    }
}
