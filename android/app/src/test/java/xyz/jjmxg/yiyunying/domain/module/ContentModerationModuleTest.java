package xyz.jjmxg.yiyunying.domain.module;

import com.google.gson.JsonPrimitive;

import org.junit.Test;

import java.util.Arrays;

import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

public class ContentModerationModuleTest {
    @Test
    public void everyCommunityReviewModuleHasApproveRejectAndOnHoldActions() {
        ModuleRegistry registry = new ModuleRegistry();
        for (String id : Arrays.asList(
            "forum_posts", "forum_comments", "moments", "moment_comments",
            "short_videos", "short_video_comments")) {
            ModuleSpec module = registry.find(Role.ADMIN, id);
            assertNotNull(id, module);
            assertEquals("审核", module.group());

            ActionSpec approve = action(module, "approved");
            ActionSpec reject = action(module, "rejected");
            ActionSpec onHold = action(module, "on_hold");
            assertEquals("PUT", approve.method());
            assertEquals("PUT", reject.method());
            assertEquals("PUT", onHold.method());
            assertTrue(approve.refreshAfter());
            assertTrue(reject.refreshAfter());
            assertTrue(onHold.refreshAfter());
            assertTrue(approve.fields().isEmpty());
            assertTrue(reject.fields().stream().anyMatch(field ->
                "reason".equals(field.key()) && field.required()));
            assertTrue(onHold.fields().stream().anyMatch(field ->
                "reason".equals(field.key()) && !field.required()));
        }
    }

    @Test
    public void onHoldAuditStatusHasAStableChineseLabel() {
        assertEquals("暂定", DisplayText.fieldValue("audit_status", new JsonPrimitive("on_hold")));
    }

    private static ActionSpec action(ModuleSpec module, String status) {
        return module.itemActions().stream()
            .filter(candidate -> status.equals(candidate.fixedValues().get("audit_status")))
            .findFirst()
            .orElseThrow(() -> new AssertionError(module.id() + " missing " + status));
    }
}
