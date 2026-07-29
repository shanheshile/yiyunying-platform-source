package xyz.jjmxg.yiyunying.service;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.domain.Role;

public final class MessageNotificationBootReceiver extends BroadcastReceiver {
    @Override public void onReceive(Context context, Intent intent) {
        String action = intent == null ? "" : intent.getAction();
        if (!Intent.ACTION_BOOT_COMPLETED.equals(action)
            && !Intent.ACTION_LOCKED_BOOT_COMPLETED.equals(action)
            && !Intent.ACTION_USER_UNLOCKED.equals(action)
            && !Intent.ACTION_MY_PACKAGE_REPLACED.equals(action)) return;
        if (!AppAccess.from(context).session().isAuthenticated()
            || AppAccess.from(context).session().role() != Role.USER) return;
        try {
            MessageNotificationService.start(context);
        } catch (RuntimeException ignored) {
            // Android may defer background starts; the next app entry starts it again.
        }
    }
}
