package xyz.jjmxg.yiyunying.data.repository;

import android.content.Context;
import android.os.Build;

import com.google.gson.JsonObject;

import java.util.Collections;

import xyz.jjmxg.yiyunying.data.api.ApiCallback;
import xyz.jjmxg.yiyunying.data.api.ApiResult;
import xyz.jjmxg.yiyunying.data.api.AuthMode;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.AppEdition;
import xyz.jjmxg.yiyunying.domain.Role;

public final class AuthRepository {
    private final Context context;
    private final SessionManager session;
    private final YiyunyingRepository repository;

    public AuthRepository(Context context, SessionManager session, YiyunyingRepository repository) {
        this.context = context.getApplicationContext();
        this.session = session;
        this.repository = repository;
    }

    public RequestHandle login(
        Role role,
        String baseUrl,
        String platformKey,
        String appKey,
        String account,
        String password,
        ApiCallback callback
    ) {
        session.configureConnection(baseUrl, appKey, platformKey);
        JsonObject body = new JsonObject();
        body.addProperty("account", account.trim());
        body.addProperty("password", password);
        body.addProperty("device", deviceName());
        if (role == Role.ADMIN || role == Role.PLATFORM) {
            body.addProperty("platform_key", platformKey.trim());
        }
        if (role == Role.ADMIN || role == Role.USER) {
            body.addProperty("app_key", appKey.trim());
        }
        return repository.request(
            "POST",
            role.loginPath(),
            body,
            Collections.emptyMap(),
            role == Role.USER ? AuthMode.PUBLIC_APP : AuthMode.NONE,
            "",
            result -> {
                if (result.isSuccessful()) {
                    JsonObject actor = Jsons.object(result.dataObject(), role.wireName());
                    int platformLevel = Jsons.intValue(actor, "level", 0);
                    String editionError = AppEdition.platformLevelError(role, platformLevel);
                    if (!editionError.isEmpty()) {
                        session.clearAuthentication();
                        callback.onResult(ApiResult.failure(editionError, null));
                        return;
                    }
                    saveSession(role, account, result, AppEdition.actorLevel(role, platformLevel));
                }
                callback.onResult(result);
            }
        );
    }

    public RequestHandle register(
        Role role,
        String baseUrl,
        String platformKey,
        String appKey,
        JsonObject fields,
        ApiCallback callback
    ) {
        if (role == Role.PLATFORM) {
            callback.onResult(ApiResult.failure("平台账号需要由上级平台创建", null));
            return new RequestHandle();
        }
        session.configureConnection(baseUrl, appKey, platformKey);
        fields.addProperty("device", deviceName());
        if (role == Role.ADMIN) {
            fields.addProperty("platform_key", platformKey.trim());
            fields.addProperty("app_key", appKey.trim());
        } else {
            fields.addProperty("app_key", appKey.trim());
        }
        return repository.request(
            "POST",
            "/api/" + role.wireName() + "/register",
            fields,
            Collections.emptyMap(),
            role == Role.USER ? AuthMode.PUBLIC_APP : AuthMode.NONE,
            "",
            result -> {
                if (result.isSuccessful() && role == Role.USER) {
                    saveSession(role, Jsons.string(fields, "account"), result, 4);
                }
                callback.onResult(result);
            }
        );
    }

    public RequestHandle loginWithCard(
        String baseUrl,
        String appKey,
        String cardCode,
        ApiCallback callback
    ) {
        session.configureConnection(baseUrl, appKey, session.platformKey());
        JsonObject body = new JsonObject();
        body.addProperty("app_key", appKey.trim());
        body.addProperty("card_code", cardCode.trim());
        body.addProperty("device_id", session.cardDeviceId());
        body.addProperty("device_label", deviceName());
        return repository.request(
            "POST",
            "/api/public/card-login",
            body,
            Collections.emptyMap(),
            AuthMode.PUBLIC_APP,
            "",
            result -> {
                if (result.isSuccessful()) {
                    JsonObject data = result.dataObject();
                    JsonObject user = Jsons.object(data, "user");
                    saveSession(Role.USER, Jsons.string(user, "account"), result, 4);
                    session.saveCardBinding(appKey, Jsons.string(data, "device_secret"));
                }
                callback.onResult(result);
            }
        );
    }

    public RequestHandle autoLoginWithCard(
        String baseUrl,
        String appKey,
        ApiCallback callback
    ) {
        session.configureConnection(baseUrl, appKey, session.platformKey());
        JsonObject body = new JsonObject();
        body.addProperty("app_key", appKey.trim());
        body.addProperty("device_id", session.cardDeviceId());
        body.addProperty("device_secret", session.cardDeviceSecret());
        body.addProperty("device_label", deviceName());
        return repository.request(
            "POST",
            "/api/public/card-auto-login",
            body,
            Collections.emptyMap(),
            AuthMode.PUBLIC_APP,
            "",
            result -> {
                if (result.isSuccessful()) {
                    JsonObject user = Jsons.object(result.dataObject(), "user");
                    saveSession(Role.USER, Jsons.string(user, "account"), result, 4);
                }
                callback.onResult(result);
            }
        );
    }

    public RequestHandle logout(ApiCallback callback) {
        Role role = session.role();
        return repository.post(role.logoutPath(), new JsonObject(), result -> {
            session.clearAuthentication();
            callback.onResult(result);
        });
    }

    private void saveSession(Role role, String account, ApiResult result, int actorLevel) {
        JsonObject data = result.dataObject();
        JsonObject actor = Jsons.object(data, role.wireName());
        session.saveAuthenticated(
            role,
            account,
            Jsons.string(data, "access_token"),
            Jsons.string(data, "refresh_token"),
            Jsons.string(data, "expires_at"),
            Jsons.string(data, "refresh_expires_at"),
            Jsons.string(data, "app_key"),
            Jsons.longValue(actor, "id"),
            actorLevel
        );
        JsonObject access = Jsons.object(data, "access");
        session.updateAdminAccess(
            role == Role.ADMIN ? Jsons.string(access, "mode") : "full",
            role == Role.ADMIN ? Jsons.string(access, "reason") : ""
        );
    }

    private String deviceName() {
        return Build.MANUFACTURER + " " + Build.MODEL + " / Android " + Build.VERSION.RELEASE
            + " / " + session.cardDeviceId();
    }
}
