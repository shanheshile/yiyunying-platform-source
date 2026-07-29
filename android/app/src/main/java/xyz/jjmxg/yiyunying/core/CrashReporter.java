package xyz.jjmxg.yiyunying.core;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.os.Build;
import android.util.Log;

import java.io.File;
import java.io.ByteArrayOutputStream;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.PrintWriter;
import java.io.StringWriter;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

import xyz.jjmxg.yiyunying.BuildConfig;

public final class CrashReporter {
    private static final String TAG = "YiyunyingCrash";
    private static final String FILE_NAME = "last_runtime_error.txt";
    private static Context context;
    private static boolean installed;

    private CrashReporter() {
    }

    public static synchronized void install(Context value) {
        context = value.getApplicationContext();
        if (installed) return;
        installed = true;
        Thread.UncaughtExceptionHandler previous = Thread.getDefaultUncaughtExceptionHandler();
        Thread.setDefaultUncaughtExceptionHandler((thread, throwable) -> {
            recordInternal("未捕获异常/" + thread.getName(), throwable, true);
            if (previous != null) previous.uncaughtException(thread, throwable);
        });
    }

    public static synchronized void record(String area, Throwable throwable) {
        recordInternal(area, throwable, false);
    }

    private static synchronized void recordInternal(String area, Throwable throwable,
                                                    boolean copyAutomatically) {
        if (context == null || throwable == null) return;
        StringWriter stack = new StringWriter();
        throwable.printStackTrace(new PrintWriter(stack));
        String report = "时间：" + new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.CHINA).format(new Date())
            + "\n位置：" + area
            + "\n版本：" + BuildConfig.VERSION_NAME + " (" + BuildConfig.VERSION_CODE + ")"
            + "\n设备：" + Build.MANUFACTURER + " " + Build.MODEL + " / Android " + Build.VERSION.RELEASE
            + "\n\n" + stack;
        try (FileOutputStream output = new FileOutputStream(file())) {
            output.write(report.getBytes(StandardCharsets.UTF_8));
        } catch (Exception writeError) {
            Log.e(TAG, "无法写入运行诊断", writeError);
        }
        if (copyAutomatically) copyToClipboard(report);
        Log.e(TAG, area, throwable);
    }

    /** Best-effort only: clipboard access must never mask or replace the original failure. */
    public static synchronized boolean copyToClipboard(String report) {
        if (context == null || report == null || report.trim().isEmpty()) return false;
        try {
            ClipboardManager manager = (ClipboardManager) context.getSystemService(Context.CLIPBOARD_SERVICE);
            if (manager == null) return false;
            manager.setPrimaryClip(ClipData.newPlainText("易运盈运行诊断", report));
            return true;
        } catch (RuntimeException clipboardError) {
            Log.w(TAG, "无法自动复制运行诊断", clipboardError);
            return false;
        }
    }

    public static synchronized String consume() {
        if (context == null) return "";
        File file = file();
        if (!file.isFile()) return "";
        try (FileInputStream input = new FileInputStream(file);
             ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int count;
            while ((count = input.read(buffer)) != -1) output.write(buffer, 0, count);
            String report = new String(output.toByteArray(), StandardCharsets.UTF_8);
            if (!file.delete()) file.deleteOnExit();
            return report;
        } catch (Exception readError) {
            Log.e(TAG, "无法读取运行诊断", readError);
            return "";
        }
    }

    private static File file() {
        return new File(context.getFilesDir(), FILE_NAME);
    }
}
