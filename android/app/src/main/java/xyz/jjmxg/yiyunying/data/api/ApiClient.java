package xyz.jjmxg.yiyunying.data.api;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.os.Build;

import androidx.appcompat.app.AppCompatDelegate;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.IOException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.Executor;
import java.util.concurrent.TimeUnit;

import okhttp3.Cache;
import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.ConnectionPool;
import okhttp3.Dispatcher;
import okhttp3.HttpUrl;
import okhttp3.MediaType;
import okhttp3.MultipartBody;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.ResponseBody;
import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.data.session.SessionProvider;
import xyz.jjmxg.yiyunying.domain.Role;

public final class ApiClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private static final Object REFRESH_LOCK = new Object();

    private final SessionProvider session;
    private final OkHttpClient client;
    private final OkHttpClient uploadClient;
    private final Executor callbackExecutor;
    private final OfflineJsonCache offlineCache;

    public ApiClient(SessionProvider session, OkHttpClient client, Executor callbackExecutor) {
        this(null, session, client, callbackExecutor);
    }

    public ApiClient(Context context, SessionProvider session, OkHttpClient client, Executor callbackExecutor) {
        this.session = session;
        this.client = client;
        this.uploadClient = client.newBuilder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(2, TimeUnit.MINUTES)
            .writeTimeout(2, TimeUnit.MINUTES)
            .callTimeout(5, TimeUnit.MINUTES)
            .build();
        this.callbackExecutor = callbackExecutor;
        this.offlineCache = context == null ? null : new OfflineJsonCache(context.getApplicationContext());
    }

    public static OkHttpClient defaultHttpClient(Context context) {
        Cache cache = new Cache(context.getCacheDir(), 24L * 1024L * 1024L);
        Dispatcher dispatcher = new Dispatcher();
        dispatcher.setMaxRequests(64);
        dispatcher.setMaxRequestsPerHost(16);
        return new OkHttpClient.Builder()
            .cache(cache)
            .dispatcher(dispatcher)
            .connectionPool(new ConnectionPool(10, 5, TimeUnit.MINUTES))
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(25, TimeUnit.SECONDS)
            .writeTimeout(25, TimeUnit.SECONDS)
            .callTimeout(45, TimeUnit.SECONDS)
            // ApiClient owns the conservative retry policy. Disabling OkHttp's
            // transparent recovery prevents hidden replay of mutation bodies.
            .retryOnConnectionFailure(false)
            .followRedirects(false)
            .followSslRedirects(false)
            .build();
    }

    public RequestHandle enqueue(ApiRequest apiRequest, ApiCallback callback) {
        RequestHandle handle = new RequestHandle();
        final List<String> routes;
        try {
            routes = requestRoutes();
        } catch (RuntimeException exception) {
            dispatch(handle, callback, ApiResult.failure("服务器线路配置无效", exception));
            return handle;
        }
        enqueueInternal(apiRequest, callback, handle, routes, 0, true);
        return handle;
    }

    /**
     * Reads a previously cached GET response without waiting for a network failure.
     * A cache miss intentionally produces no callback so callers can run a normal
     * network request in parallel and use this only for immediate read-only UI.
     */
    public RequestHandle enqueueCached(ApiRequest apiRequest, ApiCallback callback) {
        RequestHandle handle = new RequestHandle();
        final Request request;
        try {
            request = buildRequest(apiRequest, session.baseUrl());
        } catch (RuntimeException exception) {
            dispatch(handle, callback, ApiResult.failure("缓存请求地址或参数无效", exception));
            return handle;
        }
        client.dispatcher().executorService().execute(() -> {
            if (handle.isCancelled()) return;
            ApiResult cached = readOffline(apiRequest, request);
            if (cached != null) dispatch(handle, callback, cached);
        });
        return handle;
    }

    public RequestHandle enqueueMultipart(
        String path,
        String fileName,
        String mimeType,
        RequestBody fileBody,
        Map<String, String> fields,
        ApiCallback callback
    ) {
        RequestHandle handle = new RequestHandle();
        enqueueMultipartInternal(path, fileName, mimeType, fileBody, fields, callback, handle);
        return handle;
    }

    private void enqueueMultipartInternal(
        String path,
        String fileName,
        String mimeType,
        RequestBody fileBody,
        Map<String, String> fields,
        ApiCallback callback,
        RequestHandle handle
    ) {
        final Request request;
        final String tokenBefore = session.accessToken();
        try {
            HttpUrl url = resolveRelative(path);
            MultipartBody.Builder streaming = new MultipartBody.Builder().setType(MultipartBody.FORM);
            for (Map.Entry<String, String> field : fields.entrySet()) {
                streaming.addFormDataPart(field.getKey(), field.getValue());
            }
            streaming.addFormDataPart("file", fileName, fileBody);
            Request.Builder builder = new Request.Builder()
                .url(url)
                .header("Accept", "application/json")
                .header("Accept-Language", currentLanguageTag())
                .header("Authorization", "Bearer " + tokenBefore)
                .header("X-App-Key", session.appKey())
                .header("User-Agent", "YiyunyingAndroid/" + BuildConfig.VERSION_NAME + " Android/" + Build.VERSION.SDK_INT)
                .post(streaming.build());
            request = builder.build();
        } catch (RuntimeException exception) {
            dispatch(handle, callback, ApiResult.failure("无法创建上传请求", exception));
            return;
        }
        Call call = uploadClient.newCall(request);
        handle.attach(call);
        call.enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException exception) {
                if (!handle.isCancelled()) dispatch(handle, callback, ApiResult.failure(networkMessage(exception), exception));
            }
            @Override public void onResponse(Call call, Response response) {
                ApiResult result = parseResponse(response);
                // Upload is a mutation. A 401 response must be returned without
                // refreshing and replaying an already-consumed request body.
                dispatch(handle, callback, result);
            }
        });
    }


    private void enqueueInternal(
        ApiRequest apiRequest,
        ApiCallback callback,
        RequestHandle handle,
        List<String> routes,
        int routeIndex,
        boolean allowRefresh
    ) {
        if (handle.isCancelled()) {
            return;
        }
        final Request request;
        try {
            request = buildRequest(apiRequest, routes.get(routeIndex));
        } catch (RuntimeException exception) {
            dispatch(handle, callback, ApiResult.failure("请求地址或参数无效", exception));
            return;
        }
        Call call = client.newCall(request);
        handle.attach(call);
        call.enqueue(new Callback() {
            @Override
            public void onFailure(Call call, IOException exception) {
                if (handle.isCancelled()) return;
                if (RouteFailoverPolicy.canTryNext(apiRequest, routeIndex, routes.size())
                        && RouteFailoverPolicy.isRetryableNetworkFailure(exception)) {
                    enqueueInternal(apiRequest, callback, handle, routes, routeIndex + 1, allowRefresh);
                    return;
                }
                ApiResult cached = readOffline(apiRequest, request);
                dispatch(handle, callback, cached == null
                    ? ApiResult.failure(networkMessage(exception), exception) : cached);
            }

            @Override
            public void onResponse(Call call, Response response) {
                if (RouteFailoverPolicy.canTryNext(apiRequest, routeIndex, routes.size())
                        && RouteFailoverPolicy.isTransientServerStatus(response.code())) {
                    response.close();
                    enqueueInternal(apiRequest, callback, handle, routes, routeIndex + 1, allowRefresh);
                    return;
                }
                ApiResult result = parseResponse(response);
                if (result.isSuccessful()) writeOffline(apiRequest, request, result);
                if (allowRefresh
                        && RouteFailoverPolicy.isReadOnlyMethod(apiRequest.method())
                        && result.isAuthenticationFailure()
                        && canRefreshUserToken()) {
                    String tokenBefore = session.accessToken();
                    if (refreshUserToken(tokenBefore)) {
                        // Refresh is itself a single-route mutation. Retry the read on
                        // the same selected route after the token update succeeds.
                        enqueueInternal(apiRequest, callback, handle, routes, routeIndex, false);
                        return;
                    }
                }
                dispatch(handle, callback, result);
            }
        });
    }

    private ApiResult readOffline(ApiRequest apiRequest, Request request) {
        if (!isCacheable(apiRequest) || offlineCache == null) return null;
        return offlineCache.get(cacheKey(request), resourceCacheKey(request));
    }

    private void writeOffline(ApiRequest apiRequest, Request request, ApiResult result) {
        if (!isCacheable(apiRequest) || offlineCache == null) return;
        offlineCache.put(
            cacheKey(request),
            resourceCacheKey(request),
            OfflineCachePolicy.contentKind(request.url().toString()),
            result
        );
    }

    private boolean isCacheable(ApiRequest request) {
        return OfflineCachePolicy.isCacheable(request);
    }

    private String cacheKey(Request request) {
        return session.cacheIdentity() + "|" + session.baseUrl() + "|" + request.url();
    }

    private String resourceCacheKey(Request request) {
        String namespace = session.cacheIdentity() + "|" + session.baseUrl();
        return OfflineCachePolicy.resourceKey(namespace, request.url().toString());
    }

    private Request buildRequest(ApiRequest apiRequest, String baseUrl) {
        HttpUrl resolved = resolveRelative(apiRequest.path(), baseUrl);
        HttpUrl.Builder url = resolved.newBuilder();
        for (Map.Entry<String, String> query : apiRequest.query().entrySet()) {
            url.addQueryParameter(query.getKey(), query.getValue());
        }

        Request.Builder builder = new Request.Builder()
            .url(url.build())
            .header("Accept", "application/json")
            .header("Accept-Language", currentLanguageTag())
            .header("User-Agent", "YiyunyingAndroid/" + BuildConfig.VERSION_NAME + " Android/" + Build.VERSION.SDK_INT)
            .header("X-Request-Id", UUID.randomUUID().toString());

        if (apiRequest.authMode() == AuthMode.SESSION && !session.accessToken().isEmpty()) {
            builder.header("Authorization", "Bearer " + session.accessToken());
        }
        if ((apiRequest.authMode() == AuthMode.PUBLIC_APP || session.role() == Role.USER)
            && !session.appKey().isEmpty()) {
            builder.header("X-App-Key", session.appKey());
        }
        if (!apiRequest.idempotencyKey().isEmpty()) {
            builder.header("Idempotency-Key", apiRequest.idempotencyKey());
        }

        String method = apiRequest.method();
        if ("GET".equals(method) || "HEAD".equals(method)) {
            builder.method(method, null);
        } else {
            String json = Jsons.GSON.toJson(apiRequest.body());
            builder.method(method, RequestBody.create(json, JSON));
        }
        return builder.build();
    }

    private HttpUrl resolveRelative(String path) {
        return resolveRelative(path, session.baseUrl());
    }

    private HttpUrl resolveRelative(String path, String baseUrl) {
        String value = path == null ? "" : path.trim();
        if (value.matches("(?i)^https?://.*")) {
            throw new IllegalArgumentException("API path must be relative to the configured server");
        }
        HttpUrl base = HttpUrl.get(baseUrl);
        HttpUrl resolved = base.resolve(value.startsWith("/") ? value.substring(1) : value);
        if (resolved == null || !resolved.scheme().equals(base.scheme())
            || !resolved.host().equals(base.host()) || resolved.port() != base.port()) {
            throw new IllegalArgumentException("Unable to resolve endpoint on the configured server");
        }
        return resolved;
    }

    private List<String> requestRoutes() {
        List<String> configured = session.baseUrls();
        if (configured == null || configured.isEmpty()) {
            throw new IllegalArgumentException("No API routes configured");
        }
        ArrayList<String> routes = new ArrayList<>(configured.size());
        for (String route : configured) {
            HttpUrl parsed = HttpUrl.get(route);
            String normalized = parsed.toString();
            if (!normalized.endsWith("/")) normalized += "/";
            if (!routes.contains(normalized)) routes.add(normalized);
        }
        String primary = HttpUrl.get(session.baseUrl()).toString();
        if (!primary.endsWith("/")) primary += "/";
        if (routes.isEmpty() || !primary.equals(routes.get(0))) {
            throw new IllegalArgumentException("Primary API route mismatch");
        }
        return Collections.unmodifiableList(routes);
    }

    private ApiResult parseResponse(Response response) {
        try (response) {
            ResponseBody body = response.body();
            String raw = body == null ? "" : body.string();
            if (!raw.isEmpty() && raw.charAt(0) == '\ufeff') {
                raw = raw.substring(1);
            }
            String trimmed = raw.trim();
            if (trimmed.isEmpty()) {
                return ApiResult.response(response.code(), response.isSuccessful() ? 1 : response.code(),
                    emptyResponseMessage(response), new JsonObject(), response.header("X-Trace-Id", ""));
            }
            if (looksLikeHtml(trimmed)) {
                return ApiResult.response(response.code(), -1, htmlResponseMessage(response), new JsonObject(),
                    response.header("X-Trace-Id", ""));
            }
            JsonElement parsed;
            try {
                parsed = JsonParser.parseString(trimmed);
            } catch (RuntimeException exception) {
                return ApiResult.response(response.code(), -1, invalidJsonMessage(response), new JsonObject(),
                    response.header("X-Trace-Id", ""));
            }
            if (!parsed.isJsonObject()) {
                return ApiResult.response(response.code(), -1,
                    "服务器返回了无法识别的内容，请稍后重试。", parsed,
                    response.header("X-Trace-Id", ""));
            }
            JsonObject root = parsed.getAsJsonObject();
            int code = Jsons.intValue(root, "code", response.isSuccessful() ? 1 : response.code());
            String message = Jsons.string(root, "msg");
            if (message.isEmpty()) {
                message = Jsons.string(root, "message");
            }
            JsonElement data = root.has("data") ? root.get("data") : new JsonObject();
            return ApiResult.response(response.code(), code, message, data, Jsons.string(root, "trace_id"));
        } catch (Exception exception) {
            return ApiResult.failure("读取服务器响应失败：" + exception.getClass().getSimpleName(), exception);
        }
    }

    private static boolean looksLikeHtml(String body) {
        String lower = body.length() > 256
            ? body.substring(0, 256).toLowerCase(Locale.ROOT)
            : body.toLowerCase(Locale.ROOT);
        return lower.startsWith("<!doctype html") || lower.startsWith("<html")
            || lower.contains("<head>") || lower.contains("<body>");
    }

    private static String htmlResponseMessage(Response response) {
        int status = response.code();
        if (status == 413) {
            return "上传内容过大，请压缩后重试或联系管理员调整上传限制。";
        }
        if (status == 404) {
            return "请求的服务或内容不存在，请检查软件版本后重试。";
        }
        if (status == 403) {
            return "当前账号没有此操作权限。";
        }
        if (status >= 300 && status < 400) {
            return "服务地址发生变化，请更新软件后重试。";
        }
        if (status >= 500) {
            return "服务器暂时无法处理请求，请稍后重试。";
        }
        return "服务器返回了无法识别的内容，请稍后重试。";
    }

    private static String invalidJsonMessage(Response response) {
        return "服务器返回了无法识别的内容，请稍后重试。";
    }

    private static String emptyResponseMessage(Response response) {
        if (response.isSuccessful()) {
            return "服务器暂未返回处理结果，请稍后重试。";
        }
        return "服务器暂时无法处理请求，请稍后重试。";
    }

    private boolean canRefreshUserToken() {
        return session.role() == Role.USER && !session.refreshToken().isEmpty() && !session.appKey().isEmpty();
    }

    private boolean refreshUserToken(String tokenBefore) {
        synchronized (REFRESH_LOCK) {
            if (!tokenBefore.equals(session.accessToken()) && !session.accessToken().isEmpty()) {
                return true;
            }
            JsonObject body = new JsonObject();
            body.addProperty("refresh_token", session.refreshToken());
            body.addProperty("app_key", session.appKey());
            HttpUrl base = HttpUrl.get(session.baseUrl());
            HttpUrl url = base.resolve("api/user/token/refresh");
            if (url == null) {
                return false;
            }
            Request request = new Request.Builder()
                .url(url)
                .header("Accept", "application/json")
                .header("Accept-Language", currentLanguageTag())
                .header("X-App-Key", session.appKey())
                .post(RequestBody.create(Jsons.GSON.toJson(body), JSON))
                .build();
            try {
                Response response = client.newCall(request).execute();
                ApiResult result = parseResponse(response);
                if (!result.isSuccessful()) {
                    return false;
                }
                JsonObject data = result.dataObject();
                String access = Jsons.string(data, "access_token");
                String refresh = Jsons.string(data, "refresh_token");
                if (access.isEmpty() || refresh.isEmpty()) {
                    return false;
                }
                session.updateUserTokens(
                    access,
                    refresh,
                    Jsons.string(data, "expires_at"),
                    Jsons.string(data, "refresh_expires_at")
                );
                return true;
            } catch (Exception ignored) {
                return false;
            }
        }
    }

    private void dispatch(RequestHandle handle, ApiCallback callback, ApiResult result) {
        callbackExecutor.execute(() -> {
            if (!handle.isCancelled()) {
                try {
                    callback.onResult(result);
                } catch (RuntimeException | LinkageError exception) {
                    CrashReporter.record("接口回调", exception);
                    if (BuildConfig.DEBUG && "robolectric".equals(Build.FINGERPRINT)) throw exception;
                }
            }
        });
    }

    private static String currentLanguageTag() {
        String configured = AppCompatDelegate.getApplicationLocales().toLanguageTags();
        if (configured != null && !configured.trim().isEmpty()) {
            int separator = configured.indexOf(',');
            return separator < 0 ? configured : configured.substring(0, separator);
        }
        String system = Locale.getDefault().toLanguageTag();
        return system == null || system.trim().isEmpty() ? "zh-CN" : system;
    }

    private static String networkMessage(IOException exception) {
        String name = exception.getClass().getSimpleName();
        if (name.contains("Timeout")) {
            return "请求超时，请稍后重试";
        }
        if (name.contains("UnknownHost")) {
            return "无法解析服务器地址";
        }
        if (name.contains("Connect")) {
            return "无法连接服务器";
        }
        return "网络请求失败";
    }

    public static boolean isNetworkAvailable(Context context) {
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null || manager.getActiveNetwork() == null) {
            return false;
        }
        NetworkCapabilities capabilities = manager.getNetworkCapabilities(manager.getActiveNetwork());
        return capabilities != null && capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
    }
}
