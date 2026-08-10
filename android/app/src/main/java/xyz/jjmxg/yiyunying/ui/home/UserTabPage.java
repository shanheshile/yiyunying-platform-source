package xyz.jjmxg.yiyunying.ui.home;

import com.google.gson.JsonObject;

interface UserTabPage {
    void onSearchQuery(String query);
    void onPrimaryAction();

    default void onFeatureFlags(JsonObject features) { }

    default boolean isPrimaryActionAvailable() { return true; }
}
