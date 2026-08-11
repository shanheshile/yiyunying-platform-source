package xyz.jjmxg.yiyunying.ui.home;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class UserQuickAccessPolicyTest {
    @Test public void legacyEntriesRemainCompatibleButShortVideoFailsClosed() {
        JsonObject missing = new JsonObject();

        assertTrue(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.PRIVATE_CHAT));
        assertTrue(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.GROUP_CHAT));
        assertTrue(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.RED_PACKETS));
        assertTrue(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.FORUM));
        assertTrue(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.SHOP));
        assertFalse(UserQuickAccessPolicy.visible(missing, UserQuickAccessPolicy.SHORT_VIDEOS));
    }

    @Test public void authenticatedEffectiveDenialOverridesConfiguredAllow() {
        JsonObject features = new JsonObject();
        features.add("messages", state(true, false));
        features.add("social", state(true, true));
        features.add("chat_rooms", state(true, true));
        features.add("red_packets", state(true, false));
        features.add("forum", state(true, false));
        features.add("shop", state(true, false));

        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.PRIVATE_CHAT));
        assertTrue(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.GROUP_CHAT));
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.RED_PACKETS));
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.FORUM));
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.SHOP));
    }

    @Test public void shortVideoRequiresSocialAndExplicitEffectivePermission() {
        JsonObject features = new JsonObject();
        features.add("social", state(true, true));
        features.add("short_videos", state(true, true));
        assertTrue(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.SHORT_VIDEOS));

        features.add("short_videos", state(true, false));
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.SHORT_VIDEOS));

        features.add("short_videos", state(true, true));
        features.add("social", state(true, false));
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.SHORT_VIDEOS));
    }

    @Test public void privateChatNeedsSocialAndMessagesButGroupChatIsIndependent() {
        JsonObject features = new JsonObject();
        features.addProperty("messages", true);
        features.addProperty("social", false);
        features.addProperty("chat_rooms", true);
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.PRIVATE_CHAT));
        assertTrue(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.GROUP_CHAT));

        features.addProperty("messages", false);
        assertTrue(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.GROUP_CHAT));

        features.addProperty("chat_rooms", false);
        assertFalse(UserQuickAccessPolicy.visible(features, UserQuickAccessPolicy.GROUP_CHAT));
    }

    @Test public void unknownEntryNeverCreatesAnUncontrolledShortcut() {
        assertFalse(UserQuickAccessPolicy.visible(new JsonObject(), "unknown"));
        assertFalse(UserQuickAccessPolicy.visible(new JsonObject(), null));
    }

    private static JsonObject state(boolean enabled, boolean effectiveEnabled) {
        JsonObject state = new JsonObject();
        state.addProperty("enabled", enabled);
        state.addProperty("effective_enabled", effectiveEnabled);
        return state;
    }
}
