package xyz.jjmxg.yiyunying.domain.module;

import org.junit.Test;

import java.util.Arrays;

import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

public class ContentModerationModuleTest {
    @Test
    public void everyCommunityReviewModuleHasRealApproveAndRequiredReasonRejectActions() {
        ModuleRegistry registry = new ModuleRegistry();
        for (String id : Arrays.asList("forum_posts", "forum_comments", "moments", "moment_comments")) {
            ModuleSpec module = registry.find(Role.ADMIN, id);
            assertNotNull(id, module);
            assertEquals("审核", module.group());

            ActionSpec approve = action(module, "approved");
            ActionSpec reject = action(module, "rejected");
            assertEquals("PUT", approve.method());
            assertEquals("PUT", reject.method());
            assertTrue(approve.refreshAfter());
            assertTrue(reject.refreshAfter());
            assertTrue(reject.fields().stream().anyMatch(field ->
                "reason".equals(field.key()) && field.required()));
        }
    }

    private static ActionSpec action(ModuleSpec module, String status) {
        return module.itemActions().stream()
            .filter(candidate -> status.equals(candidate.fixedValues().get("audit_status")))
            .findFirst()
            .orElseThrow(() -> new AssertionError(module.id() + " missing " + status));
    }
}
