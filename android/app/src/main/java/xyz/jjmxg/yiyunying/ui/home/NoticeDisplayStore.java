package xyz.jjmxg.yiyunying.ui.home;

import android.content.Context;
import android.content.SharedPreferences;

import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

import xyz.jjmxg.yiyunying.data.api.Jsons;

final class NoticeDisplayStore {
    private final SharedPreferences preferences;

    NoticeDisplayStore(Context context) {
        preferences = context.getSharedPreferences("yiyunying.notice.display.v1", Context.MODE_PRIVATE);
    }

    boolean shouldShow(JsonObject notice, String loginMarker) {
        if (Jsons.intValue(notice, "is_popup", 0) != 1) return false;
        String frequency = Jsons.string(notice, "popup_frequency");
        if (frequency.isEmpty()) frequency = "once";
        if ("none".equals(frequency)) return false;
        if ("always".equals(frequency)) return true;
        String key = key(notice, frequency);
        String expected = marker(notice, frequency, loginMarker);
        return !expected.equals(preferences.getString(key, ""));
    }

    void markShown(JsonObject notice, String loginMarker) {
        String frequency = Jsons.string(notice, "popup_frequency");
        if (frequency.isEmpty()) frequency = "once";
        if ("none".equals(frequency) || "always".equals(frequency)) return;
        preferences.edit().putString(key(notice, frequency), marker(notice, frequency, loginMarker)).apply();
    }

    private static String key(JsonObject notice, String frequency) {
        return "notice." + Jsons.longValue(notice, "id") + "." + frequency;
    }

    private static String marker(JsonObject notice, String frequency, String loginMarker) {
        String revision = Jsons.string(notice, "updated_at");
        if ("daily".equals(frequency)) {
            return revision + ":" + new SimpleDateFormat("yyyy-MM-dd", Locale.CHINA).format(new Date());
        }
        if ("login".equals(frequency)) return revision + ":" + loginMarker;
        return revision;
    }
}
