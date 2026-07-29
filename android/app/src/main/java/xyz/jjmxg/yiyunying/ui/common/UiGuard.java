package xyz.jjmxg.yiyunying.ui.common;

import android.os.Build;
import android.view.View;

import com.google.android.material.snackbar.Snackbar;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.core.CrashReporter;

public final class UiGuard {
    private UiGuard() {
    }

    public static boolean run(View anchor, String area, Runnable action) {
        try {
            action.run();
            return true;
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record(area, exception);
            if (anchor != null && anchor.isAttachedToWindow()) {
                Snackbar.make(anchor, "页面操作失败，软件已保留诊断信息，请重试", Snackbar.LENGTH_LONG).show();
            }
            if (BuildConfig.DEBUG && "robolectric".equals(Build.FINGERPRINT)) throw exception;
            return false;
        }
    }
}
