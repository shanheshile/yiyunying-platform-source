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

    @Test public void shortVideoNavigationAndPublishingHaveIndependentControls() {
        JsonObject features = new JsonObject();
        features.addProperty("social", true);
        features.addProperty("short_videos", true);
        features.addProperty("short_video_publish", false);

        assertTrue(HomeFeaturePolicy.actionEnabled(features, "short_videos"));
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_video_publish"));

        features.addProperty("short_videos", false);
        features.addProperty("short_video_publish", true);
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_videos"));
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_video_publish"));
    }

    @Test public void shortVideoNavigationFailsClosedAndHonorsEffectiveDenial() {
        JsonObject features = new JsonObject();
        features.addProperty("social", true);
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_videos"));

        JsonObject forcedDenied = new JsonObject();
        forcedDenied.addProperty("enabled", true);
        forcedDenied.addProperty("effective_enabled", false);
        features.add("short_videos", forcedDenied);
        features.addProperty("short_video_publish", true);
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_videos"));
        assertFalse(HomeFeaturePolicy.actionEnabled(features, "short_video_publish"));
    }
}
