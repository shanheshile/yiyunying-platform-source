package xyz.jjmxg.yiyunying.ui.wallet;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.chip.Chip;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityWalletLedgerBinding;
import xyz.jjmxg.yiyunying.databinding.ItemWalletLedgerBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

public final class WalletLedgerActivity extends SystemInsetActivity {
    private static final String EXTRA_CATEGORY = "wallet_ledger_category";

    private ActivityWalletLedgerBinding binding;
    private final List<JsonObject> items = new ArrayList<>();
    private LedgerAdapter adapter;
    private RequestHandle request;
    private String category = "all";

    public static void open(Context context) {
        open(context, "all");
    }

    public static void open(Context context, String category) {
        context.startActivity(new Intent(context, WalletLedgerActivity.class)
            .putExtra(EXTRA_CATEGORY, category == null ? "all" : category));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityWalletLedgerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        category = getIntent().getStringExtra(EXTRA_CATEGORY);
        if (category == null || category.trim().isEmpty()) category = "all";
        binding.toolbar.setTitle(tr("资金来往明细"));
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        adapter = new LedgerAdapter();
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(this::load);
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "100");
        query.put("category", category);
        request = AppAccess.from(this).repository().get("/api/user/wallet/logs", query, result -> {
            request = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                binding.emptyText.setText(result.message().isEmpty()
                    ? tr("资金明细加载失败，请下拉重试") : result.message());
                binding.emptyText.setVisibility(View.VISIBLE);
                return;
            }
            JsonObject data = result.dataObject();
            String active = Jsons.string(data, "active_category");
            if (!active.isEmpty()) category = active;
            renderCategories(Jsons.array(data, "categories"));
            renderSummary(Jsons.array(data, "summary"));
            items.clear();
            items.addAll(result.objectItems());
            adapter.notifyDataSetChanged();
            binding.emptyText.setText(tr("当前分类还没有资金来往记录"));
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void renderCategories(JsonArray categories) {
        binding.categoryChips.removeAllViews();
        for (JsonElement element : categories) {
            if (!element.isJsonObject()) continue;
            JsonObject entry = element.getAsJsonObject();
            String code = Jsons.string(entry, "code");
            Chip chip = new Chip(this);
            chip.setText(tr(Jsons.string(entry, "name")) + " " + Jsons.longValue(entry, "count"));
            chip.setCheckable(true);
            chip.setChecked(category.equals(code));
            chip.setOnClickListener(view -> {
                if (category.equals(code)) return;
                category = code;
                load();
            });
            binding.categoryChips.addView(chip);
        }
    }

    private void renderSummary(JsonArray summary) {
        if (summary.size() == 0) {
            binding.summary.setText(tr("收入、支出按不同资产分别统计"));
            return;
        }
        StringBuilder text = new StringBuilder();
        for (JsonElement element : summary) {
            if (!element.isJsonObject()) continue;
            JsonObject row = element.getAsJsonObject();
            if (text.length() > 0) text.append('\n');
            text.append(tr(Jsons.string(row, "asset_name")))
                .append("  ").append(tr("收入")).append(" +").append(amount(row, "income"))
                .append("  ·  ").append(tr("支出")).append(" -").append(amount(row, "expense"))
                .append("  ·  ").append(tr("净额")).append(" ").append(amount(row, "net"));
        }
        binding.summary.setText(text);
    }

    private static String amount(JsonObject object, String key) {
        if (!object.has(key) || object.get(key).isJsonNull()) return "0";
        try {
            double value = object.get(key).getAsDouble();
            if (Math.rint(value) == value) return String.format(Locale.US, "%.0f", value);
            return String.format(Locale.US, "%.2f", value).replaceAll("0+$", "").replaceAll("\\.$", "");
        } catch (RuntimeException ignored) {
            return Jsons.string(object, key);
        }
    }

    private void showDetail(JsonObject item) {
        JsonObject detail = new JsonObject();
        addDetail(detail, "category_name", Jsons.string(item, "category_name"));
        addDetail(detail, "scene_name", Jsons.string(item, "scene_name"));
        addDetail(detail, "direction_name", Jsons.string(item, "direction_name"));
        addDetail(detail, "amount_text", Jsons.string(item, "amount_text"));
        String asset = Jsons.string(item, "asset_name");
        addDetail(detail, "before_value", amount(item, "before_value") + (asset.isEmpty() ? "" : " " + asset));
        addDetail(detail, "after_value", amount(item, "after_value") + (asset.isEmpty() ? "" : " " + asset));
        addDetail(detail, "created_at", Jsons.string(item, "created_at"));
        addDetail(detail, "trace_no", Jsons.string(item, "trace_no"));
        addDetail(detail, "reference_no", Jsons.string(item, "reference_no"));
        addDetail(detail, "remark", Jsons.string(item, "remark"));
        RecordDetailDialog.show(this, tr("资金明细详情"), detail);
    }

    private static void addDetail(JsonObject target, String key, String value) {
        if (value != null && !value.trim().isEmpty()) target.addProperty(key, value.trim());
    }

    private String tr(String value) {
        CharSequence translated = RuntimeLanguage.translate(this, value);
        return translated == null ? value : translated.toString();
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        binding = null;
        super.onDestroy();
    }

    private final class LedgerAdapter extends RecyclerView.Adapter<LedgerAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull android.view.ViewGroup parent, int viewType) {
            return new Holder(ItemWalletLedgerBinding.inflate(getLayoutInflater(), parent, false));
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            holder.binding.title.setText(Jsons.string(item, "scene_name"));
            holder.binding.amount.setText(Jsons.string(item, "amount_text"));
            String direction = Jsons.string(item, "direction");
            int color = "expense".equals(direction) ? R.color.error
                : ("income".equals(direction) ? R.color.success : R.color.on_surface_variant);
            holder.binding.amount.setTextColor(ContextCompat.getColor(WalletLedgerActivity.this, color));
            holder.binding.meta.setText(Jsons.string(item, "category_name") + " · "
                + Jsons.string(item, "direction_name") + " · " + Jsons.string(item, "created_at"));
            holder.binding.trace.setText(tr("流水号") + " " + Jsons.string(item, "trace_no"));
            holder.binding.getRoot().setOnClickListener(view -> showDetail(item));
        }

        @Override public int getItemCount() { return items.size(); }

        private final class Holder extends RecyclerView.ViewHolder {
            final ItemWalletLedgerBinding binding;
            Holder(ItemWalletLedgerBinding binding) {
                super(binding.getRoot());
                this.binding = binding;
            }
        }
    }
}
