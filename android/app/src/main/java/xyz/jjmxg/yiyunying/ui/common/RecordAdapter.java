package xyz.jjmxg.yiyunying.ui.common;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.RecyclerView;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.HashSet;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;

public final class RecordAdapter extends RecyclerView.Adapter<RecordAdapter.Holder> {
    public interface Listener {
        void onRecordClick(JsonObject item);
        default void onRecordLongPress(JsonObject item) { }
        void onRecordActions(View anchor, JsonObject item);
        default void onRecordSelectionToggle(JsonObject item) { }
    }

    private final ModuleSpec spec;
    private final Listener listener;
    private final List<JsonObject> items = new ArrayList<>();
    private long selectedAppId;
    private boolean selectionMode;
    private final Set<String> selectedRecords = new HashSet<>();

    public RecordAdapter(ModuleSpec spec, Listener listener) {
        this.spec = spec;
        this.listener = listener;
        setHasStableIds(true);
    }

    public void submit(List<JsonObject> next) {
        List<JsonObject> old = new ArrayList<>(items);
        if (old.size() + next.size() > 300) {
            items.clear();
            items.addAll(next);
            notifyDataSetChanged();
            return;
        }
        DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
            @Override public int getOldListSize() { return old.size(); }
            @Override public int getNewListSize() { return next.size(); }
            @Override public boolean areItemsTheSame(int oldPos, int newPos) {
                return identity(old.get(oldPos)).equals(identity(next.get(newPos)));
            }
            @Override public boolean areContentsTheSame(int oldPos, int newPos) {
                return old.get(oldPos).equals(next.get(newPos));
            }
        });
        items.clear();
        items.addAll(next);
        diff.dispatchUpdatesTo(this);
    }

    public void setSelectedAppId(long value) {
        selectedAppId = value;
        notifyItemRangeChanged(0, items.size());
    }

    public void setSelection(boolean enabled, Set<String> selected) {
        selectionMode = enabled;
        selectedRecords.clear();
        selectedRecords.addAll(selected);
        notifyItemRangeChanged(0, items.size());
    }

    public List<JsonObject> currentItems() { return new ArrayList<>(items); }

    @Override
    public long getItemId(int position) {
        String value = identity(items.get(position));
        try { return Long.parseLong(value); } catch (NumberFormatException ignored) { return value.hashCode(); }
    }

    @NonNull
    @Override
    public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
    }

    @Override
    public void onBindViewHolder(@NonNull Holder holder, int position) {
        JsonObject item = items.get(position);
        String title = DisplayText.first(item, spec.primaryKeys());
        if (title.isEmpty()) title = spec.title() + " #" + identity(item);
        RuntimeLanguage.setDynamicText(holder.binding.title, title);
        String subtitle = secondary(holder, item, 0, 2);
        String metadata = secondary(holder, item, 2, spec.secondaryKeys().size());
        RuntimeLanguage.setDynamicText(holder.binding.subtitle, subtitle);
        holder.binding.subtitle.setVisibility(subtitle.isEmpty() ? View.GONE : View.VISIBLE);
        RuntimeLanguage.setDynamicText(holder.binding.metadata, metadata);
        holder.binding.metadata.setVisibility(metadata.isEmpty() ? View.GONE : View.VISIBLE);
        RuntimeLanguage.setDynamicText(holder.binding.avatar, firstCharacter(title));
        holder.binding.moreButton.setVisibility(!selectionMode && !spec.itemActions().isEmpty() ? View.VISIBLE : View.GONE);
        holder.binding.selectionCheck.setVisibility(selectionMode ? View.VISIBLE : View.GONE);
        holder.binding.selectionCheck.setOnCheckedChangeListener(null);
        holder.binding.selectionCheck.setChecked(selectedRecords.contains(identity(item)));
        holder.binding.selectionCheck.setOnCheckedChangeListener((button, checked) -> listener.onRecordSelectionToggle(item));
        holder.binding.moreButton.setOnClickListener(view ->
            UiGuard.run(view, "列表操作/" + spec.id(), () -> listener.onRecordActions(view, item)));
        holder.binding.getRoot().setOnClickListener(view -> UiGuard.run(view, "列表点击/" + spec.id(), () -> {
            if (selectionMode) listener.onRecordSelectionToggle(item); else listener.onRecordClick(item);
        }));
        holder.binding.getRoot().setOnLongClickListener(view -> {
            listener.onRecordLongPress(item);
            return true;
        });
        boolean selected = "apps".equals(spec.id()) && Jsons.longValue(item, "id") == selectedAppId;
        holder.binding.getRoot().setStrokeWidth(selected ? dp(holder, 2) : 0);
        holder.binding.getRoot().setStrokeColor(ThemeColors.primary(holder.itemView.getContext()));
    }

    @Override public int getItemCount() { return items.size(); }

    private String secondary(Holder holder, JsonObject item, int start, int end) {
        List<String> parts = new ArrayList<>();
        int safeEnd = Math.min(end, spec.secondaryKeys().size());
        for (int index = start; index < safeEnd; index++) {
            String key = spec.secondaryKeys().get(index);
            if (!RecordDetailDialog.isVisibleField(item, key)) continue;
            JsonElement value = item.get(key);
            if (value != null && !value.isJsonNull()) {
                String display = RecordDetailDialog.safeFieldValue(key, value);
                if (!display.isEmpty() && !"-".equals(display)) {
                    String label = RuntimeLanguage.translate(holder.itemView.getContext(), DisplayText.label(key)).toString();
                    String localizedValue = DisplayText.isBusinessDataField(key)
                        ? display : RuntimeLanguage.translate(holder.itemView.getContext(), display).toString();
                    parts.add(label + " " + localizedValue);
                }
            }
        }
        return String.join(" · ", parts);
    }

    private String identity(JsonObject item) {
        String value = Jsons.string(item, spec.idKey());
        return value.isEmpty()
            ? "record-" + Integer.toUnsignedString(item.toString().hashCode())
            : value;
    }

    private static String firstCharacter(String value) {
        if (value == null || value.isEmpty()) return "#";
        int end = value.offsetByCodePoints(0, 1);
        return value.substring(0, end).toUpperCase(Locale.getDefault());
    }

    private static int dp(Holder holder, int value) {
        return Math.round(value * holder.itemView.getResources().getDisplayMetrics().density);
    }

    static final class Holder extends RecyclerView.ViewHolder {
        final ItemRecordBinding binding;
        Holder(ItemRecordBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }
}
