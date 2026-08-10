package xyz.jjmxg.yiyunying.service;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;

/** Completes update history and optional APK cleanup only after self replacement succeeds. */
public final class UpdateInstallCleanupReceiver extends BroadcastReceiver {
    @Override public void onReceive(Context context, Intent intent) {
        if (context == null || intent == null
            || !Intent.ACTION_MY_PACKAGE_REPLACED.equals(intent.getAction())) return;
        Context app = context.getApplicationContext();
        PendingResult pending = goAsync();
        Thread worker = new Thread(() -> {
            try {
                UpdatePackageStore.reconcileInstalledAfterReplacement(app);
            } catch (RuntimeException ignored) {
                // The next application start performs the same idempotent reconciliation.
            } finally {
                pending.finish();
            }
        }, "update-package-cleanup");
        worker.start();
    }
}
