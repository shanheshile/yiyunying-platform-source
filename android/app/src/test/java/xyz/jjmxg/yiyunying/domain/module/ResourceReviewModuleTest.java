package xyz.jjmxg.yiyunying.domain.module;

import org.junit.Test;

import java.util.Arrays;

import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

public final class ResourceReviewModuleTest {
    @Test public void resourcesAndStoreAppsHaveDetailAndThreeExplicitReviewDecisions() {
        ModuleRegistry registry = new ModuleRegistry();
        for (String id : Arrays.asList("resources", "store_apps")) {
            ModuleSpec module = registry.find(Role.ADMIN, id);
            assertNotNull(id, module);
            assertTrue(module.searchable());
            assertTrue(module.paged());
            assertTrue(module.itemActions().stream().anyMatch(action ->
                "GET".equals(action.method()) && action.title().contains("详情")));

            assertDecision(module, "approved", false);
            assertDecision(module, "rejected", true);
            assertDecision(module, "on_hold", true);
        }
    }

    @Test public void applicationStoreHasRealEditDeleteAndCategoryManagementActions() {
        ModuleRegistry registry = new ModuleRegistry();
        ModuleSpec apps = registry.find(Role.ADMIN, "store_apps");
        ModuleSpec categories = registry.find(Role.ADMIN, "store_categories");
        assertNotNull(apps);
        assertNotNull(categories);
        assertTrue(apps.itemActions().stream().anyMatch(action ->
            "PUT".equals(action.method()) && action.pathTemplate().endsWith("/{store_app_id}")));
        assertTrue(apps.itemActions().stream().anyMatch(action ->
            "DELETE".equals(action.method()) && action.pathTemplate().endsWith("/{store_app_id}")));
        assertTrue(categories.itemActions().stream().anyMatch(action -> "PUT".equals(action.method())));
        assertTrue(categories.itemActions().stream().anyMatch(action -> "DELETE".equals(action.method())));
    }

    @Test public void genericUserResourceSubmissionIsAlwaysScopedToSourceMarket() {
        ModuleRegistry registry = new ModuleRegistry();
        ModuleSpec resources = registry.find(Role.USER, "resources");
        assertNotNull(resources);
        assertNotNull(resources.createAction());
        assertEquals("source_market", resources.createAction().fixedValues().get("resource_type"));

        ModuleSpec categories = registry.find(Role.ADMIN, "resource_categories");
        assertNotNull(categories);
        assertNotNull(categories.createAction());
        assertEquals("source_market", categories.createAction().fixedValues().get("resource_type"));
        assertTrue(categories.createAction().fields().stream().noneMatch(field ->
            "resource_type".equals(field.key())));
    }

    private static void assertDecision(ModuleSpec module, String status, boolean reasonRequired) {
        ActionSpec action = module.itemActions().stream()
            .filter(candidate -> status.equals(candidate.fixedValues().get("audit_status")))
            .findFirst()
            .orElseThrow(() -> new AssertionError(module.id() + " missing " + status));
        assertEquals("PUT", action.method());
        assertTrue(action.refreshAfter());
        if (reasonRequired) {
            assertTrue(action.fields().stream().anyMatch(field ->
                "reason".equals(field.key()) && field.required()));
        }
    }
}
