package xyz.jjmxg.yiyunying.domain.api;

import android.content.Context;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.domain.Role;

public final class ApiRouteCatalog {
    private ApiRouteCatalog() {
    }

    public static List<ApiRoute> load(Context context, Role role) {
        List<ApiRoute> routes = new ArrayList<>();
        try (BufferedReader reader = new BufferedReader(new InputStreamReader(
            context.getAssets().open("api_catalog.json"), StandardCharsets.UTF_8))) {
            JsonArray array = JsonParser.parseReader(reader).getAsJsonArray();
            for (JsonElement element : array) {
                if (!element.isJsonObject()) continue;
                JsonObject object = element.getAsJsonObject();
                String scope = Jsons.string(object, "scope");
                if (!("public".equals(scope) || role.wireName().equals(scope))) continue;
                routes.add(new ApiRoute(
                    Jsons.string(object, "method"),
                    Jsons.string(object, "path"),
                    scope,
                    Jsons.string(object, "handler")
                ));
            }
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to load API catalog", exception);
        }
        routes.sort(Comparator.comparing(ApiRoute::scope).thenComparing(ApiRoute::path).thenComparing(ApiRoute::method));
        return routes;
    }
}
