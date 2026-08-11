package xyz.jjmxg.yiyunying.domain.module;

import org.junit.Test;

import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

public final class ShopGoodsCommentManagementModuleTest {
    @Test public void adminShopGoodsCommentsExposeChineseContextAndGovernanceActions() {
        ModuleRegistry registry = new ModuleRegistry();
        ModuleSpec module = registry.find(Role.ADMIN, "shop_goods_comments");

        assertNotNull(module);
        assertEquals("商品评论管理", module.title());
        assertEquals("/api/admin/apps/{app_id}/shop-goods-comments", module.listPath());
        assertTrue(module.requiresApp());
        assertTrue(module.paged());
        assertTrue(module.searchable());
        assertTrue(module.primaryKeys().contains("content"));
        assertTrue(module.secondaryKeys().contains("goods_name"));
        assertTrue(module.secondaryKeys().contains("parent_content"));
        assertTrue(module.secondaryKeys().contains("score_label"));
        assertTrue(module.secondaryKeys().contains("status_label"));

        ActionSpec detail = action(module, "查看评论详情");
        assertEquals("GET", detail.method());
        assertEquals("/api/admin/apps/{app_id}/shop-goods-comments/{comment_id}", detail.pathTemplate());

        ActionSpec hide = action(module, "隐藏评论");
        assertEquals("PUT", hide.method());
        assertTrue(hide.pathTemplate().endsWith("/{comment_id}/hide"));
        assertTrue(hide.confirmationRequired());
        assertFalse(hide.destructive());

        ActionSpec restore = action(module, "恢复评论");
        assertEquals("PUT", restore.method());
        assertTrue(restore.pathTemplate().endsWith("/{comment_id}/restore"));
        assertTrue(restore.confirmationRequired());
        assertFalse(restore.destructive());

        ActionSpec delete = action(module, "删除评论");
        assertEquals("DELETE", delete.method());
        assertTrue(delete.confirmationRequired());
        assertTrue(delete.destructive());
    }

    @Test public void shopGoodsCommentGovernanceIsAdminOnly() {
        ModuleRegistry registry = new ModuleRegistry();
        assertNull(registry.find(Role.PLATFORM, "shop_goods_comments"));
        assertNull(registry.find(Role.USER, "shop_goods_comments"));
    }

    private static ActionSpec action(ModuleSpec module, String title) {
        return module.itemActions().stream()
            .filter(candidate -> title.equals(candidate.title()))
            .findFirst()
            .orElseThrow(() -> new AssertionError("缺少操作：" + title));
    }
}
