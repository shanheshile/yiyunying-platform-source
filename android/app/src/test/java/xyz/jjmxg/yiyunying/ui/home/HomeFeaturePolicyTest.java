package xyz.jjmxg.yiyunying.ui.home;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class HomeFeaturePolicyTest {
    @Test public void parsesEnvelopeAndLegacyFeatureValues() {
        JsonObject features = new JsonObject();
        JsonObject social = new JsonObject();
        social.addProperty("enabled", false);
        features.add("social", social);
        features.addProperty("forum", true);

        assertFalse(HomeFeaturePolicy.actionEnabled(features, "moments_compose"));
        assertTrue(HomeFeaturePolicy.actionEnabled(features, "forum_posts"));
        assertTrue(HomeFeaturePolicy.actionEnabled(features, "bounties"));
    }

    @Test public void primaryActionRemainsWhenAnyConfiguredChildActionIsEnabled() {
        JsonObject features = new JsonObject();
        for (String code : new String[]{"social", "forum", "bounties", "resources"}) {
            JsonObject disabled = new JsonObject();
            disabled.addProperty("enabled", false);
            features.add(code, disabled);
        }
        JsonObject votes = new JsonObject();
        votes.addProperty("enabled", true);
        features.add("votes", votes);

        assertTrue(HomeFeaturePolicy.anyActionEnabled(features,
            "moments_compose", "forum_posts", "bounties", "resources", "polls"));
        votes.addProperty("enabled", false);
        assertFalse(HomeFeaturePolicy.anyActionEnabled(features,
            "moments_compose", "forum_posts", "bounties", "resources", "polls"));
    }

    @Test public void conversationCreationRespectsExistingSocialAndChatRoomSwitches() {
        JsonObject features = new JsonObject();
        features.addProperty("messages", true);
        features.addProperty("social", false);
        features.addProperty("chat_rooms", true);

        assertFalse(HomeFeaturePolicy.actionEnabled(features, "add_friend"));
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "create_group"));
        assertTrue(HomeFeaturePolicy.actionEnabled(features, "create_chatroom"));
    }
}
