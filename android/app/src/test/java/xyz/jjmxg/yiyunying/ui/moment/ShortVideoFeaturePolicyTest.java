package xyz.jjmxg.yiyunying.ui.moment;

import com.google.gson.JsonObject;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class ShortVideoFeaturePolicyTest {
    @Test public void effectiveFlagWinsAndMissingOrMalformedFlagsFailClosed() {
        JsonObject features = new JsonObject();
        JsonObject disabled = new JsonObject();
        disabled.addProperty("enabled", false);
        features.add("short_video_comments", disabled);
        JsonObject forcedDenied = new JsonObject();
        forcedDenied.addProperty("enabled", true);
        forcedDenied.addProperty("effective_enabled", false);
        features.add("short_video_likes", forcedDenied);
        JsonObject enabled = new JsonObject();
        enabled.addProperty("enabled", true);
        features.add("short_video_publish", enabled);
        features.addProperty("short_video_forwards", "不是布尔值");

        assertFalse(ShortVideoFeaturePolicy.enabled(features, "short_video_comments"));
        assertTrue(ShortVideoFeaturePolicy.enabled(features, "short_video_publish"));
        assertFalse(ShortVideoFeaturePolicy.enabled(features, "short_video_likes"));
        assertFalse(ShortVideoFeaturePolicy.enabled(features, "short_video_favorites"));
        assertFalse(ShortVideoFeaturePolicy.enabled(features, "short_video_forwards"));
    }

    @Test public void shortVideoComposerAcceptsExactlyOneVideo() {
        assertEquals(1, ShortVideoFeaturePolicy.maxSelection(true));
        assertEquals("short_video", ShortVideoFeaturePolicy.contentKind(true));
        assertTrue(ShortVideoFeaturePolicy.acceptsMime(true, "video/mp4"));
        assertFalse(ShortVideoFeaturePolicy.acceptsMime(true, "image/jpeg"));
    }

    @Test public void ordinaryMomentsKeepExistingMixedMediaRules() {
        assertEquals(9, ShortVideoFeaturePolicy.maxSelection(false));
        assertEquals("moment", ShortVideoFeaturePolicy.contentKind(false));
        assertTrue(ShortVideoFeaturePolicy.acceptsMime(false, "image/webp"));
        assertTrue(ShortVideoFeaturePolicy.acceptsMime(false, "video/mp4"));
    }
}
