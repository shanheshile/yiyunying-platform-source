package xyz.jjmxg.yiyunying.domain.module;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import org.junit.Test;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

public class ModuleContractTest {
    private static final int EXPECTED_ROUTE_COUNT = 815;

    @Test
    public void modulesAreUniqueAndRoleScoped() {
        ModuleRegistry registry = new ModuleRegistry();
        assertTrue(registry.forRole(Role.PLATFORM).size() >= 15);
        assertTrue(registry.forRole(Role.ADMIN).size() >= 35);
        assertTrue(registry.forRole(Role.USER).size() >= 20);
        for (Role role : Role.values()) {
            Set<String> ids = new HashSet<>();
            for (ModuleSpec module : registry.forRole(role)) {
                assertEquals(role, module.role());
                assertTrue("duplicate module " + module.id(), ids.add(module.id()));
                assertNotNull(module.title());
            }
        }
    }

    @Test
    public void everyModuleEndpointExistsInGeneratedBackendCatalog() throws Exception {
        JsonArray routes = catalog();
        assertEquals(EXPECTED_ROUTE_COUNT, routes.size());
        Set<String> routeKeys = new HashSet<>();
        for (JsonElement element : routes) {
            JsonObject route = element.getAsJsonObject();
            routeKeys.add(route.get("method").getAsString() + " " + route.get("path").getAsString());
        }
        ModuleRegistry registry = new ModuleRegistry();
        for (Role role : Role.values()) {
            for (ModuleSpec module : registry.forRole(role)) {
                if (!module.listPath().isEmpty()) {
                    String method = module.screenType() == ScreenType.BOT
                        || (module.screenType() == ScreenType.UPLOAD && role != Role.PLATFORM) ? "POST" : "GET";
                    assertTrue("missing " + method + " route for " + role + "/" + module.id() + ": " + module.listPath(),
                        routeKeys.contains(method + " " + module.listPath()));
                }
                if (module.createAction() != null) assertAction(routeKeys, module.createAction(), role, module);
                for (ActionSpec action : module.itemActions()) assertAction(routeKeys, action, role, module);
            }
        }
    }

    @Test
    public void generatedRoutesAreUniqueAndCorrectlyScoped() throws Exception {
        Set<String> keys = new HashSet<>();
        for (JsonElement element : catalog()) {
            JsonObject route = element.getAsJsonObject();
            String path = route.get("path").getAsString();
            String scope = route.get("scope").getAsString();
            assertTrue("duplicate route: " + route, keys.add(route.get("method").getAsString() + " " + path));
            if (path.startsWith("/api/platform/")) assertEquals("platform", scope);
            else if (path.startsWith("/api/admin/")) assertEquals("admin", scope);
            else if (path.startsWith("/api/user/")) assertEquals("user", scope);
            else assertEquals("public", scope);
        }
        assertEquals(EXPECTED_ROUTE_COUNT, keys.size());
    }

    @Test
    public void modulesStayInsideTheirRoleBoundary() {
        ModuleRegistry registry = new ModuleRegistry();
        for (Role role : Role.values()) {
            for (ModuleSpec module : registry.forRole(role)) {
                assertRolePath(role, module.listPath(), module.id());
                if (module.createAction() != null) assertRolePath(role, module.createAction().pathTemplate(), module.id());
                for (ActionSpec action : module.itemActions()) assertRolePath(role, action.pathTemplate(), module.id());
            }
        }
    }

    private static JsonArray catalog() throws Exception {
        Path catalogPath = Path.of("src/main/assets/api_catalog.json");
        if (!Files.exists(catalogPath)) catalogPath = Path.of("app/src/main/assets/api_catalog.json");
        return JsonParser.parseString(new String(Files.readAllBytes(catalogPath), StandardCharsets.UTF_8)).getAsJsonArray();
    }

    private static void assertRolePath(Role role, String path, String moduleId) {
        if (path.isEmpty()) return;
        String allowed = "/api/" + role.wireName() + "/";
        assertTrue("cross-role path for " + role + "/" + moduleId + ": " + path,
            path.startsWith(allowed) || path.startsWith("/api/public/"));
    }

    private static void assertAction(Set<String> routes, ActionSpec action, Role role, ModuleSpec module) {
        String wireMethod = action.method().startsWith("UPLOAD_") ? "POST" : action.method();
        String key = wireMethod + " " + action.pathTemplate();
        assertTrue("missing action route for " + role + "/" + module.id() + ": " + key, routes.contains(key));
    }
}
