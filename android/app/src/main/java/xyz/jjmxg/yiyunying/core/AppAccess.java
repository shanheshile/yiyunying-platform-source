package xyz.jjmxg.yiyunying.core;

import android.content.Context;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

public final class AppAccess {
    private AppAccess() {
    }

    public static AppContainer from(Context context) {
        return ((YiyunyingApplication) context.getApplicationContext()).container();
    }
}
