package xyz.jjmxg.yiyunying.ui.settings;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotSame;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonNull;
import com.google.gson.JsonObject;

import org.junit.Test;

public final class FeatureSavePayloadTest {
    @Test public void returnsOriginalConfigurationWithToggleUpdate() {
        JsonObject config = new JsonObject();
        config.addProperty("max_duration_seconds", 60);

        JsonObject payload = FeatureSavePayload.build("chat_camera", false, config);

        assertEquals("chat_camera", payload.get("feature_code").getAsString());
        assertFalse(payload.get("enabled").getAsBoolean());
        assertEquals(config, payload.get("config"));
        assertNotSame(config, payload.get("config"));
    }

    @Test public void keepsExplicitNullDistinctFromMissingConfiguration() {
        JsonObject explicitNull = FeatureSavePayload.build("chat_camera", true, JsonNull.INSTANCE);
        JsonObject missing = FeatureSavePayload.build("chat_camera", true, null);

        assertTrue(explicitNull.has("config"));
        assertTrue(explicitNull.get("config").isJsonNull());
        assertFalse(missing.has("config"));
    }

    @Test public void filenamePrivacyCannotBeDisabledByAdminPayload() {
        JsonObject payload = FeatureSavePayload.build("forum_media_filename_privacy", false, null);

        assertTrue(payload.get("enabled").getAsBoolean());
        assertTrue(FeatureSavePayload.enforcedEnabled("forum_media_filename_privacy", false));
        assertFalse(FeatureSavePayload.enforcedEnabled("chat_camera", false));
    }
}
