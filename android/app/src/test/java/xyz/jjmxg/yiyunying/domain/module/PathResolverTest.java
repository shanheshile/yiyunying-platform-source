package xyz.jjmxg.yiyunying.domain.module;

import com.google.gson.JsonObject;

import org.junit.Test;

import java.lang.reflect.Field;
import java.util.regex.Pattern;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;

public class PathResolverTest {
    @Test
    public void escapesBothPlaceholderBracesForAndroidIcu() throws Exception {
        Field field = PathResolver.class.getDeclaredField("PLACEHOLDER");
        field.setAccessible(true);
        Pattern pattern = (Pattern) field.get(null);
        assertEquals("\\{([a-zA-Z0-9_]+)\\}", pattern.pattern());
    }

    @Test
    public void resolvesAppAndItemIdentifiers() {
        JsonObject item = new JsonObject();
        item.addProperty("id", 42);
        assertEquals("/api/admin/apps/7/users/42",
            PathResolver.resolve("/api/admin/apps/{app_id}/users/{user_id}", 7, item));
    }

    @Test
    public void prefersNamedIdentifier() {
        JsonObject item = new JsonObject();
        item.addProperty("id", 2);
        item.addProperty("document_id", 99);
        assertEquals("/api/user/documents/99",
            PathResolver.resolve("/api/user/documents/{document_id}", 0, item));
    }

    @Test
    public void rejectsMissingAppScope() {
        assertThrows(IllegalArgumentException.class,
            () -> PathResolver.resolve("/api/admin/apps/{app_id}/users", 0, null));
    }

    @Test
    public void usesClickedAppForTopLevelAppActions() {
        JsonObject app = new JsonObject();
        app.addProperty("id", 42);
        assertEquals("/api/admin/apps/42",
            PathResolver.resolve("/api/admin/apps/{app_id}", 7, app));
        assertEquals("/api/platform/apps/42/settings",
            PathResolver.resolve("/api/platform/apps/{app_id}/settings", 0, app));
    }

    @Test
    public void keepsSelectedAppForNestedResourceActions() {
        JsonObject user = new JsonObject();
        user.addProperty("id", 42);
        assertEquals("/api/admin/apps/7/users/42",
            PathResolver.resolve("/api/admin/apps/{app_id}/users/{user_id}", 7, user));
    }
}
