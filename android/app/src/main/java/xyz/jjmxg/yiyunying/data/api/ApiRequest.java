package xyz.jjmxg.yiyunying.data.api;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;

public final class ApiRequest {
    private final String method;
    private final String path;
    private final JsonElement body;
    private final Map<String, String> query;
    private final AuthMode authMode;
    private final String idempotencyKey;

    private ApiRequest(Builder builder) {
        method = builder.method;
        path = builder.path;
        body = builder.body;
        query = Collections.unmodifiableMap(new LinkedHashMap<>(builder.query));
        authMode = builder.authMode;
        idempotencyKey = builder.idempotencyKey;
    }

    public String method() {
        return method;
    }

    public String path() {
        return path;
    }

    public JsonElement body() {
        return body;
    }

    public Map<String, String> query() {
        return query;
    }

    public AuthMode authMode() {
        return authMode;
    }

    public String idempotencyKey() {
        return idempotencyKey;
    }

    public static Builder builder(String method, String path) {
        return new Builder(method, path);
    }

    public static final class Builder {
        private final String method;
        private final String path;
        private JsonElement body = new JsonObject();
        private final Map<String, String> query = new LinkedHashMap<>();
        private AuthMode authMode = AuthMode.SESSION;
        private String idempotencyKey = "";

        private Builder(String method, String path) {
            this.method = method == null ? "GET" : method.trim().toUpperCase(Locale.ROOT);
            this.path = path == null ? "" : path.trim();
        }

        public Builder body(JsonElement value) {
            body = value == null ? new JsonObject() : value;
            return this;
        }

        public Builder query(String key, Object value) {
            if (key != null && value != null && !String.valueOf(value).isEmpty()) {
                query.put(key, String.valueOf(value));
            }
            return this;
        }

        public Builder query(Map<String, String> values) {
            if (values != null) {
                values.forEach(this::query);
            }
            return this;
        }

        public Builder auth(AuthMode value) {
            authMode = value == null ? AuthMode.SESSION : value;
            return this;
        }

        public Builder idempotencyKey(String value) {
            idempotencyKey = value == null ? "" : value.trim();
            return this;
        }

        public ApiRequest build() {
            if (path.isEmpty() || !(path.startsWith("/") || path.startsWith("http://") || path.startsWith("https://"))) {
                throw new IllegalArgumentException("API path must be absolute");
            }
            return new ApiRequest(this);
        }
    }
}
