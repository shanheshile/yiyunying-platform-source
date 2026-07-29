package xyz.jjmxg.yiyunying.ui.common;

import androidx.appcompat.app.AppCompatActivity;

import xyz.jjmxg.yiyunying.core.CrashReporter;

public final class CrashNotice {
    private CrashNotice() {
    }

    public static void showPending(AppCompatActivity activity) {
        String report = CrashReporter.consume();
        if (report.isEmpty() || activity.isFinishing()) return;
        boolean copied = CrashReporter.copyToClipboard(report);
        new YiyunyingDialogBuilder(activity)
            .setTitle("检测到上次运行异常")
            .setMessage(copied
                ? "错误信息已自动复制到剪贴板，也已保存在本机诊断记录中。"
                : "错误信息已保存在本机诊断记录中，可点击再次复制。")
            .setNeutralButton("再次复制", (dialog, which) -> CrashReporter.copyToClipboard(report))
            .setPositiveButton("继续使用", null)
            .show();
    }
}
