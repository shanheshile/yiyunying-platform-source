package xyz.jjmxg.yiyunying.ui.management;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.databinding.ActivityManagedRecordDetailBinding;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class ManagedRecordDetailActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_JSON = "json";

    public static void open(Context context, String title, JsonObject record) {
        context.startActivity(new Intent(context, ManagedRecordDetailActivity.class)
            .putExtra(EXTRA_TITLE, title).putExtra(EXTRA_JSON, record.toString()));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        ActivityManagedRecordDetailBinding binding = ActivityManagedRecordDetailBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, getIntent().getStringExtra(EXTRA_TITLE));
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        try {
            JsonElement value = JsonParser.parseString(getIntent().getStringExtra(EXTRA_JSON));
            JsonObject record = new JsonObject();
            if (value.isJsonObject()) record = value.getAsJsonObject();
            else if (value.isJsonArray()) record.add("items", value);
            else record.add("content", value);
            RecordDetailDialog.renderInto(this, binding.contentContainer, record);
        } catch (RuntimeException exception) {
            JsonObject error = new JsonObject();
            error.addProperty("content", RuntimeLanguage.translate(this, "资料解析失败，请重新打开后再试").toString());
            RecordDetailDialog.renderInto(this, binding.contentContainer, error);
        }
    }
}
