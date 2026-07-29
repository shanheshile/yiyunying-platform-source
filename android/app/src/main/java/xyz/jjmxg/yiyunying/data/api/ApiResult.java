package xyz.jjmxg.yiyunying.data.api;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonNull;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;

public final class ApiResult {
    private final int httpCode;
    private final int code;
    private final String message;
    private final JsonElement data;
    private final String traceId;
    private final Throwable cause;

    private ApiResult(int httpCode, int code, String message, JsonElement data, String traceId, Throwable cause) {
        this.httpCode = httpCode;
        this.code = code;
        this.message = ApiVisibleMessage.visible(message, httpCode, code);
        this.data = data == null ? JsonNull.INSTANCE : data;
        this.traceId = traceId == null ? "" : traceId;
        this.cause = cause;
    }

    public static ApiResult response(int httpCode, int code, String message, JsonElement data, String traceId) {
        return new ApiResult(httpCode, code, message, data, traceId, null);
    }

    public static ApiResult failure(String message, Throwable cause) {
        return new ApiResult(0, -1, message, JsonNull.INSTANCE, "", cause);
    }

    public boolean isSuccessful() {
        return httpCode >= 200 && httpCode < 300 && code == 1;
    }

    public boolean isAuthenticationFailure() {
        return httpCode == 401 || code == 401;
    }

    public int httpCode() {
        return httpCode;
    }

    public int code() {
        return code;
    }

    public String message() {
        return message;
    }

    public JsonElement data() {
        return data;
    }

    public JsonObject dataObject() {
        return data.isJsonObject() ? data.getAsJsonObject() : new JsonObject();
    }

    public JsonArray items() {
        if (data.isJsonArray()) {
            return data.getAsJsonArray();
        }
        if (data.isJsonObject()) {
            JsonObject object = data.getAsJsonObject();
            if (object.has("items") && object.get("items").isJsonArray()) {
                return object.getAsJsonArray("items");
            }
        }
        return new JsonArray();
    }

    public List<JsonObject> objectItems() {
        List<JsonObject> objects = new ArrayList<>();
        for (JsonElement element : items()) {
            if (element.isJsonObject()) objects.add(element.getAsJsonObject());
        }
        return objects;
    }

    public String traceId() {
        return traceId;
    }

    public Throwable cause() {
        return cause;
    }
}
