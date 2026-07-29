package xyz.jjmxg.yiyunying.ui.notification;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.RecyclerView;

import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemNotificationBinding;
import xyz.jjmxg.yiyunying.databinding.ItemNotificationGroupBinding;

final class NotificationCenterAdapter extends RecyclerView.Adapter<RecyclerView.ViewHolder> {
    interface Listener {
        void onNotificationClick(JsonObject notification);
        void onGroupToggle(JsonObject group);
        void onGroupRead(JsonObject group);
    }

    private static final int TYPE_NOTIFICATION = 0;
    private static final int TYPE_GROUP = 1;
    private final List<JsonObject> rows = new ArrayList<>();
    private final Listener listener;

    NotificationCenterAdapter(Listener listener) {
        this.listener = listener;
    }

    void submit(List<JsonObject> next) {
        List<JsonObject> before = copyRows(rows);
        List<JsonObject> after = copyRows(next);
        DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
            @Override public int getOldListSize() { return before.size(); }
            @Override public int getNewListSize() { return after.size(); }
            @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                return key(before.get(oldPosition)).equals(key(after.get(newPosition)));
            }
            @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                return before.get(oldPosition).equals(after.get(newPosition));
            }
        });
        rows.clear();
        rows.addAll(after);
        diff.dispatchUpdatesTo(this);
    }

    @Override public int getItemViewType(int position) {
        return "group".equals(Jsons.string(rows.get(position), "row_type")) ? TYPE_GROUP : TYPE_NOTIFICATION;
    }

    @NonNull @Override
    public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        LayoutInflater inflater = LayoutInflater.from(parent.getContext());
        if (viewType == TYPE_GROUP) {
            return new GroupHolder(ItemNotificationGroupBinding.inflate(inflater, parent, false));
        }
        return new NotificationHolder(ItemNotificationBinding.inflate(inflater, parent, false));
    }

    @Override public void onBindViewHolder(@NonNull RecyclerView.ViewHolder holder, int position) {
        JsonObject row = rows.get(position);
        if (holder instanceof GroupHolder) {
            bindGroup((GroupHolder) holder, row);
        } else {
            bindNotification((NotificationHolder) holder, row);
        }
    }

    private void bindGroup(GroupHolder holder, JsonObject group) {
        int unread = Jsons.intValue(group, "unread_count", 0);
        boolean collapsed = booleanValue(group, "collapsed");
        holder.binding.title.setText(RuntimeLanguage.translate(
            holder.itemView.getContext(), Jsons.string(group, "name")));
        holder.binding.description.setText(RuntimeLanguage.translate(
            holder.itemView.getContext(), Jsons.string(group, "description")));
        holder.binding.unread.setText(unread > 99 ? "99+" : String.valueOf(unread));
        holder.binding.unread.setVisibility(unread > 0 ? View.VISIBLE : View.GONE);
        holder.binding.readGroup.setVisibility(unread > 0 ? View.VISIBLE : View.GONE);
        holder.binding.toggle.setText(RuntimeLanguage.translate(
            holder.itemView.getContext(), collapsed ? "展开" : "收起"));
        holder.binding.getRoot().setOnClickListener(view -> listener.onGroupToggle(group));
        holder.binding.toggle.setOnClickListener(view -> listener.onGroupToggle(group));
        holder.binding.readGroup.setOnClickListener(view -> listener.onGroupRead(group));
    }

    private void bindNotification(NotificationHolder holder, JsonObject item) {
        String group = Jsons.string(item, "group_key");
        holder.binding.typeIcon.setText(iconText(group));
        RuntimeLanguage.setDynamicText(holder.binding.title,
            nonEmpty(Jsons.string(item, "title"), "通知"));
        RuntimeLanguage.setDynamicText(holder.binding.content,
            nonEmpty(Jsons.string(item, "content"), "暂无详细内容"));
        RuntimeLanguage.setDynamicText(holder.binding.meta,
            Jsons.string(item, "group_name") + " · " + compactTime(Jsons.string(item, "created_at")));
        holder.binding.unreadDot.setVisibility(booleanValue(item, "is_read") ? View.INVISIBLE : View.VISIBLE);
        holder.binding.getRoot().setOnClickListener(view -> listener.onNotificationClick(item));
    }

    @Override public int getItemCount() { return rows.size(); }

    private static String key(JsonObject row) {
        if ("group".equals(Jsons.string(row, "row_type"))) return "group:" + Jsons.string(row, "key");
        String sourceType = Jsons.string(row, "source_type");
        long sourceId = Jsons.longValue(row, "source_id");
        if (sourceId > 0) return "notification:" + sourceType + ":" + sourceId;
        return "notification:" + sourceType + ":" + Jsons.string(row, "notification_type")
            + ":" + Jsons.string(row, "created_at") + ":" + Jsons.string(row, "title");
    }

    private static List<JsonObject> copyRows(List<JsonObject> source) {
        List<JsonObject> result = new ArrayList<>(source.size());
        for (JsonObject row : source) {
            if (row != null) result.add(row.deepCopy());
        }
        return result;
    }

    private static String iconText(String group) {
        switch (group) {
            case "likes": return "赞";
            case "comments": return "评";
            case "social": return "友";
            case "orders": return "单";
            case "wallet": return "余";
            case "activities": return "活";
            case "content": return "文";
            case "system": return "系";
            default: return "通";
        }
    }

    private static String compactTime(String value) {
        if (value == null || value.isEmpty()) return "";
        return value.length() >= 16 ? value.substring(0, 16) : value;
    }

    private static String nonEmpty(String value, String fallback) {
        return value == null || value.trim().isEmpty() ? fallback : value;
    }

    private static boolean booleanValue(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    static final class GroupHolder extends RecyclerView.ViewHolder {
        final ItemNotificationGroupBinding binding;
        GroupHolder(ItemNotificationGroupBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }

    static final class NotificationHolder extends RecyclerView.ViewHolder {
        final ItemNotificationBinding binding;
        NotificationHolder(ItemNotificationBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }
}
