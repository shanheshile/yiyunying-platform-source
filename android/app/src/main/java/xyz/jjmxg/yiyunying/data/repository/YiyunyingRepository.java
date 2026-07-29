package xyz.jjmxg.yiyunying.data.repository;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Collections;
import java.util.Map;

import okhttp3.RequestBody;

import xyz.jjmxg.yiyunying.data.api.ApiCallback;
import xyz.jjmxg.yiyunying.data.api.ApiClient;
import xyz.jjmxg.yiyunying.data.api.ApiRequest;
import xyz.jjmxg.yiyunying.data.api.AuthMode;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;

public final class YiyunyingRepository {
    private final ApiClient client;

    public YiyunyingRepository(ApiClient client) {
        this.client = client;
    }

    public RequestHandle get(String path, Map<String, String> query, ApiCallback callback) {
        return request("GET", path, new JsonObject(), query, AuthMode.SESSION, "", callback);
    }

    public RequestHandle getCached(String path, Map<String, String> query, ApiCallback callback) {
        ApiRequest request = ApiRequest.builder("GET", path)
            .query(query)
            .auth(AuthMode.SESSION)
            .build();
        return client.enqueueCached(request, callback);
    }

    public RequestHandle getPublic(String path, Map<String, String> query, ApiCallback callback) {
        return request("GET", path, new JsonObject(), query, AuthMode.PUBLIC_APP, "", callback);
    }

    public RequestHandle post(String path, JsonElement body, ApiCallback callback) {
        return request("POST", path, body, Collections.emptyMap(), AuthMode.SESSION, "", callback);
    }

    public RequestHandle postPublic(String path, JsonElement body, ApiCallback callback) {
        return request("POST", path, body, Collections.emptyMap(), AuthMode.PUBLIC_APP, "", callback);
    }

    public RequestHandle post(String path, JsonElement body, String idempotencyKey, ApiCallback callback) {
        return request("POST", path, body, Collections.emptyMap(), AuthMode.SESSION, idempotencyKey, callback);
    }

    public RequestHandle put(String path, JsonElement body, ApiCallback callback) {
        return request("PUT", path, body, Collections.emptyMap(), AuthMode.SESSION, "", callback);
    }

    public RequestHandle delete(String path, JsonElement body, ApiCallback callback) {
        return request("DELETE", path, body, Collections.emptyMap(), AuthMode.SESSION, "", callback);
    }

    public RequestHandle request(
        String method,
        String path,
        JsonElement body,
        Map<String, String> query,
        AuthMode authMode,
        String idempotencyKey,
        ApiCallback callback
    ) {
        ApiRequest request = ApiRequest.builder(method, path)
            .body(body)
            .query(query)
            .auth(authMode)
            .idempotencyKey(idempotencyKey)
            .build();
        return client.enqueue(request, callback);
    }

    public RequestHandle upload(
        String path,
        String fileName,
        String mimeType,
        RequestBody fileBody,
        Map<String, String> fields,
        ApiCallback callback
    ) {
        return client.enqueueMultipart(path, fileName, mimeType, fileBody, fields, callback);
    }
}
