package xyz.jjmxg.yiyunying.domain.chat;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonObject;

import org.junit.Test;

public final class ChatFeatureFlagsTest {
    @Test public void readsBackendEnvelopeAndLegacyBooleanValues() {
        JsonObject features = new JsonObject();
        JsonObject camera = new JsonObject();
        camera.addProperty("enabled", false);
        camera.add("config", null);
        features.add("chat_camera", camera);
        features.addProperty("chat_album", false);

        assertFalse(ChatFeatureFlags.enabled(features, "chat_camera", true));
        assertFalse(ChatFeatureFlags.enabled(features, "chat_album", true));
        assertTrue(ChatFeatureFlags.enabled(features, "chat_contact_card", true));
    }

    @Test public void malformedValuesUseTheExplicitFallback() {
        JsonObject features = new JsonObject();
        features.addProperty("chat_camera", "not-a-boolean");

        assertTrue(ChatFeatureFlags.enabled(features, "chat_camera", true));
        assertFalse(ChatFeatureFlags.enabled(features, "chat_camera", false));
    }
}
