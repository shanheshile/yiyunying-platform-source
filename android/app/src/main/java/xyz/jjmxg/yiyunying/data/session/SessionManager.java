package xyz.jjmxg.yiyunying.data.session;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.annotation.Nullable;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;
import java.util.UUID;

public final class SessionManager implements SessionProvider {
    private static final String PREFS = "yiyunying.session.v1";
    private static final String KEY_ACCESS_TOKEN = "secure.access_token";
    private static final String KEY_REFRESH_TOKEN = "secure.refresh_token";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_ROLE = "role";
    private static final String KEY_ACCOUNT = "account";
    private static final String KEY_APP_KEY = "app_key";
    private static final String KEY_PLATFORM_KEY = "platform_key";
    private static final String KEY_EXPIRES_AT = "expires_at";
    private static final String KEY_REFRESH_EXPIRES_AT = "refresh_expires_at";
    private static final String KEY_ACTOR_ID = "actor_id";
    private static final String KEY_ACTOR_LEVEL = "actor_level";
    private static final String KEY_SELECTED_APP_ID = "selected_app_id";
    private static final String KEY_SELECTED_APP_NAME = "selected_app_name";
    private static final String KEY_SELECTED_APP_KEY = "selected_app_key";
    private static final String KEY_ADMIN_ACCESS_MODE = "admin_access_mode";
    private static final String KEY_ADMIN_ACCESS_REASON = "admin_access_reason";
    private static final String KEY_LOGIN_MARKER = "login_marker";
    private static final String KEY_CARD_DEVICE_ID = "card.device_id";
    private static final String KEY_CARD_DEVICE_SECRET = "secure.card_device_secret";
    private static final String KEY_CARD_BINDING_APP_KEY = "card.binding_app_key";

    private final SharedPreferences preferences;
    private final SecureValueStore secureStore;

    public SessionManager(Context context) {
        preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        secureStore = new SecureValueStore(preferences);
        reconcileEditionIdentity();
    }

    public synchronized void configureConnection(String baseUrl, String appKey, String platformKey) {
        preferences.edit()
            .putString(KEY_BASE_URL, EndpointPolicy.normalize(baseUrl))
            .putString(KEY_APP_KEY, safe(appKey))
            .putString(KEY_PLATFORM_KEY, safe(platformKey))
            .apply();
    }

    public synchronized void saveAuthenticated(
        Role role,
        String account,
        String accessToken,
        @Nullable String refreshToken,
        @Nullable String expiresAt,
        @Nullable String refreshExpiresAt,
        @Nullable String appKey,
        long actorId,
        int actorLevel
    ) {
        secureStore.put(KEY_ACCESS_TOKEN, accessToken);
        secureStore.put(KEY_REFRESH_TOKEN, refreshToken);
        SharedPreferences.Editor editor = preferences.edit()
            .putString(KEY_ROLE, role.wireName())
            .putString(KEY_ACCOUNT, safe(account))
            .putLong(KEY_ACTOR_ID, actorId)
            .putInt(KEY_ACTOR_LEVEL, actorLevel)
            .putString(KEY_LOGIN_MARKER, UUID.randomUUID().toString())
            .putString(KEY_EXPIRES_AT, safe(expiresAt))
            .putString(KEY_REFRESH_EXPIRES_AT, safe(refreshExpiresAt));
        if (appKey != null && !appKey.trim().isEmpty()) {
            editor.putString(KEY_APP_KEY, appKey.trim());
        }
        editor.apply();
    }

    public synchronized void clearAuthentication() {
        secureStore.put(KEY_ACCESS_TOKEN, "");
        secureStore.put(KEY_REFRESH_TOKEN, "");
        preferences.edit()
            .remove(KEY_ROLE)
            .remove(KEY_EXPIRES_AT)
            .remove(KEY_REFRESH_EXPIRES_AT)
            .remove(KEY_ACTOR_ID)
            .remove(KEY_ACTOR_LEVEL)
            .remove(KEY_SELECTED_APP_ID)
            .remove(KEY_SELECTED_APP_NAME)
            .remove(KEY_SELECTED_APP_KEY)
            .remove(KEY_ADMIN_ACCESS_MODE)
            .remove(KEY_ADMIN_ACCESS_REASON)
            .remove(KEY_LOGIN_MARKER)
            .apply();
    }

    public synchronized boolean updateAdminAccess(String mode, String reason) {
        String normalizedMode = safe(mode).isEmpty() ? "full" : safe(mode);
        boolean changed = !normalizedMode.equals(adminAccessMode()) || !safe(reason).equals(adminAccessReason());
        preferences.edit()
            .putString(KEY_ADMIN_ACCESS_MODE, normalizedMode)
            .putString(KEY_ADMIN_ACCESS_REASON, safe(reason))
            .apply();
        return changed;
    }

    public synchronized void selectApp(long appId, String appName, String appKey) {
        preferences.edit()
            .putLong(KEY_SELECTED_APP_ID, appId)
            .putString(KEY_SELECTED_APP_NAME, safe(appName))
            .putString(KEY_SELECTED_APP_KEY, safe(appKey))
            .apply();
    }

    public boolean isAuthenticated() {
        return !accessToken().isEmpty() && preferences.contains(KEY_ROLE);
    }

    public boolean isCompatibleWithEdition() {
        return isAuthenticated() && AppEdition.acceptsSession(role(), actorLevel());
    }

    @Override
    public String baseUrl() {
        return preferences.getString(KEY_BASE_URL, BuildConfig.DEFAULT_API_BASE_URL);
    }

    @Override
    public String accessToken() {
        return secureStore.get(KEY_ACCESS_TOKEN);
    }

    @Override
    public String refreshToken() {
        return secureStore.get(KEY_REFRESH_TOKEN);
    }

    @Override
    public String appKey() {
        if (role() == Role.ADMIN) {
            String selected = selectedAppKey();
            if (!selected.isEmpty()) return selected;
        }
        return preferences.getString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY);
    }

    /** The compiled login identity, unaffected by an admin's current app selection. */
    public String configuredAppKey() {
        return preferences.getString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY);
    }

    public String platformKey() {
        return preferences.getString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY);
    }

    @Override
    public Role role() {
        return Role.fromWireName(preferences.getString(KEY_ROLE, Role.USER.wireName()));
    }

    @Override
    public String cacheIdentity() {
        return role().wireName() + "|" + actorId() + "|" + appKey() + "|" + loginMarker();
    }

    public String account() {
        return preferences.getString(KEY_ACCOUNT, "");
    }

    public String expiresAt() {
        return preferences.getString(KEY_EXPIRES_AT, "");
    }

    public long selectedAppId() {
        return preferences.getLong(KEY_SELECTED_APP_ID, 0L);
    }

    public String selectedAppName() {
        return preferences.getString(KEY_SELECTED_APP_NAME, "");
    }

    public String selectedAppKey() {
        return preferences.getString(KEY_SELECTED_APP_KEY, "");
    }

    public long actorId() {
        return preferences.getLong(KEY_ACTOR_ID, 0L);
    }

    public int actorLevel() {
        return preferences.getInt(KEY_ACTOR_LEVEL, 0);
    }

    public String adminAccessMode() {
        return preferences.getString(KEY_ADMIN_ACCESS_MODE, "full");
    }

    public String adminAccessReason() {
        return preferences.getString(KEY_ADMIN_ACCESS_REASON, "");
    }

    public String loginMarker() {
        return preferences.getString(KEY_LOGIN_MARKER, "");
    }

    public synchronized String cardDeviceId() {
        String value = preferences.getString(KEY_CARD_DEVICE_ID, "");
        if (value != null && !value.isEmpty()) return value;
        value = UUID.randomUUID().toString();
        preferences.edit().putString(KEY_CARD_DEVICE_ID, value).apply();
        return value;
    }

    public synchronized void saveCardBinding(String appKey, String deviceSecret) {
        secureStore.put(KEY_CARD_DEVICE_SECRET, deviceSecret);
        preferences.edit().putString(KEY_CARD_BINDING_APP_KEY, safe(appKey)).apply();
    }

    public boolean hasCardBinding(String appKey) {
        return !secureStore.get(KEY_CARD_DEVICE_SECRET).isEmpty()
            && safe(appKey).equals(preferences.getString(KEY_CARD_BINDING_APP_KEY, ""));
    }

    public String cardDeviceSecret() {
        return secureStore.get(KEY_CARD_DEVICE_SECRET);
    }

    public synchronized void clearCardBinding() {
        secureStore.put(KEY_CARD_DEVICE_SECRET, "");
        preferences.edit().remove(KEY_CARD_BINDING_APP_KEY).apply();
    }

    public boolean isAdminBillingOnly() {
        return role() == Role.ADMIN && "billing_only".equals(adminAccessMode());
    }

    @Override
    public synchronized void updateUserTokens(
        String accessToken,
        String refreshToken,
        String expiresAt,
        String refreshExpiresAt
    ) {
        secureStore.put(KEY_ACCESS_TOKEN, accessToken);
        secureStore.put(KEY_REFRESH_TOKEN, refreshToken);
        preferences.edit()
            .putString(KEY_EXPIRES_AT, safe(expiresAt))
            .putString(KEY_REFRESH_EXPIRES_AT, safe(refreshExpiresAt))
            .apply();
    }

    private static String safe(@Nullable String value) {
        return value == null ? "" : value.trim();
    }

    private void reconcileEditionIdentity() {
        final String buildBase;
        try {
            buildBase = EndpointPolicy.normalize(BuildConfig.DEFAULT_API_BASE_URL);
        } catch (IllegalArgumentException exception) {
            // Do not crash before LoginActivity can show its Chinese configuration error. The
            // invalid build must also never fall back to a previously persisted endpoint.
            clearAuthentication();
            preferences.edit()
                .putString(KEY_BASE_URL, "")
                .putString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY)
                .putString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY)
                .apply();
            return;
        }
        boolean hadConnectionIdentity = preferences.contains(KEY_BASE_URL)
            || preferences.contains(KEY_APP_KEY)
            || preferences.contains(KEY_PLATFORM_KEY);
        boolean endpointChanged = !buildBase.equals(preferences.getString(KEY_BASE_URL, buildBase));
        boolean tenantChanged;
        if (AppEdition.role() == Role.USER) {
            tenantChanged = !BuildConfig.DEFAULT_APP_KEY.equals(preferences.getString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY));
        } else if (AppEdition.role() == Role.ADMIN) {
            tenantChanged = !BuildConfig.DEFAULT_PLATFORM_KEY.equals(preferences.getString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY))
                || !BuildConfig.DEFAULT_APP_KEY.equals(preferences.getString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY));
        } else {
            tenantChanged = !BuildConfig.DEFAULT_PLATFORM_KEY.equals(preferences.getString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY));
        }
        if (hadConnectionIdentity && isAuthenticated() && (endpointChanged || tenantChanged)) {
            clearAuthentication();
        }
        preferences.edit()
            .putString(KEY_BASE_URL, buildBase)
            .putString(KEY_APP_KEY, BuildConfig.DEFAULT_APP_KEY)
            .putString(KEY_PLATFORM_KEY, BuildConfig.DEFAULT_PLATFORM_KEY)
            .apply();
    }
}
