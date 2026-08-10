package xyz.jjmxg.yiyunying.ui.settings;

import androidx.annotation.Nullable;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

final class FeatureSavePayload {
    private FeatureSavePayload() { }

    static JsonObject build(String featureCode, boolean enabled, @Nullable JsonElement originalConfig) {
        JsonObject feature = new JsonObject();
        feature.addProperty("feature_code", featureCode);
        feature.addProperty("enabled", enforcedEnabled(featureCode, enabled));
        if (originalConfig != null) feature.add("config", originalConfig.deepCopy());
        return feature;
    }

    static boolean enforcedEnabled(String featureCode, boolean requested) {
        return "forum_media_filename_privacy".equals(featureCode) || requested;
    }
}
