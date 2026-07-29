package xyz.jjmxg.yiyunying.ui.home;

import android.graphics.Typeface;
import android.text.SpannableString;
import android.text.Spanned;
import android.text.style.ForegroundColorSpan;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.RecyclerView;

import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemMessageCenterBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.ThemeColors;

final class MessageCenterAdapter extends RecyclerView.Adapter<RecyclerView.ViewHolder> {
    interface Listener {
        void onClick(JsonObject item);
        void onLongPress(JsonObject item);
        void onSectionClick(JsonObject section);
    }

    private static final int TYPE_ITEM = 0;
    private static final int TYPE_SECTION = 1;
    private final List<JsonObject> items = new ArrayList<>();
    private final Listener listener;


    MessageCenterAdapter(Listener listener) {
        this.listener = listener;
        setHasStableIds(true);
    }

    void submit(List<JsonObject> next) {
        List<JsonObject> previous = new ArrayList<>(items);
        List<Integer> changedPositions = changedPositionsForSameOrder(previous, next);
        if (changedPositions != null) {
            if (changedPositions.isEmpty()) return;
            items.clear();
            items.addAll(next);
            if (changedPositions.size() > 20) {
                notifyItemRangeChanged(0, items.size());
            } else {
                for (int position : changedPositions) notifyItemChanged(position);
            }
            return;
        }
        DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
            @Override public int getOldListSize() { return previous.size(); }
            @Override public int getNewListSize() { return next.size(); }
            @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                return key(previous.get(oldPosition)).equals(key(next.get(newPosition)));
            }
            @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                return previous.get(oldPosition).equals(next.get(newPosition));
            }
        }, false);
        items.clear();
        items.addAll(next);
        diff.dispatchUpdatesTo(this);
    }

    @Nullable
    private static List<Integer> changedPositionsForSameOrder(List<JsonObject> left, List<JsonObject> right) {
        if (left.size() != right.size()) return null;
        List<Integer> changed = new ArrayList<>();
        for (int index = 0; index < left.size(); index++) {
            if (!key(left.get(index)).equals(key(right.get(index)))) return null;
            if (!left.get(index).equals(right.get(index))) changed.add(index);
        }
        return changed;
    }

    @Override public int getItemViewType(int position) {
        return "section".equals(Jsons.string(items.get(position), "type")) ? TYPE_SECTION : TYPE_ITEM;
    }

    @Override public long getItemId(int position) { return key(items.get(position)).hashCode(); }

    @NonNull @Override public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        if (viewType == TYPE_SECTION) {
            TextView title = new TextView(parent.getContext());
            title.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(parent, 42)));
            title.setGravity(Gravity.CENTER_VERTICAL);
            title.setPadding(dp(parent, 16), 0, dp(parent, 16), 0);
            title.setTextColor(parent.getContext().getColor(R.color.on_surface_variant));
            title.setTextSize(14);
            title.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
            title.setBackgroundColor(parent.getContext().getColor(R.color.surface_container));
            return new SectionHolder(title);
        }
        return new ItemHolder(ItemMessageCenterBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
    }

    @Override public void onBindViewHolder(@NonNull RecyclerView.ViewHolder holder, int position) {
        JsonObject item = items.get(position);
        if (holder instanceof SectionHolder) {
            SectionHolder section = (SectionHolder) holder;
            boolean collapsed = booleanValue(item, "collapsed");
            section.title.setText(Jsons.string(item, "title") + (collapsed ? "  展开" : "  收起"));
            section.title.setOnClickListener(view -> listener.onSectionClick(item));
            return;
        }
        ItemHolder itemHolder = (ItemHolder) holder;
        String title = Jsons.string(item, "title");
        itemHolder.binding.title.setText(title);
        boolean hasDraft = booleanValue(item, "has_draft");
        String message = hasDraft ? Jsons.string(item, "draft_content") : Jsons.string(item, "last_message");
        if (message.isEmpty()) message = "还没有消息";
        if (booleanValue(item, "is_stranger")) message = "陌生人 · " + message;
        if (hasDraft) {
            String value = "[草稿] " + message.replace('\n', ' ');
            SpannableString styled = new SpannableString(value);
            styled.setSpan(new ForegroundColorSpan(itemHolder.itemView.getContext().getColor(R.color.warning)),
                0, 4, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
            itemHolder.binding.lastMessage.setTextColor(itemHolder.itemView.getContext().getColor(R.color.on_surface_variant));
            itemHolder.binding.lastMessage.setText(styled);
            itemHolder.binding.time.setText("草稿");
        } else {
            String messageType = Jsons.string(item, "last_message_type");
            int color = itemHolder.itemView.getContext().getColor(R.color.on_surface_variant);
            if ("red_packet".equals(messageType)) color = itemHolder.itemView.getContext().getColor(R.color.error);
            else if ("transfer".equals(messageType)) color = ThemeColors.primary(itemHolder.itemView.getContext());
            else if ("gift".equals(messageType)) color = itemHolder.itemView.getContext().getColor(R.color.warning);
            itemHolder.binding.lastMessage.setTextColor(color);
            itemHolder.binding.lastMessage.setText(message);
            itemHolder.binding.time.setText(compactTime(Jsons.string(item, "last_message_at")));
        }
        int unread = Jsons.intValue(item, "unread_count", 0);
        boolean muted = booleanValue(item, "is_muted");
        ViewGroup.LayoutParams unreadParams = itemHolder.binding.unread.getLayoutParams();
        if (muted) {
            unreadParams.width = dp(itemHolder.itemView, 8);
            unreadParams.height = dp(itemHolder.itemView, 8);
            itemHolder.binding.unread.setMinWidth(dp(itemHolder.itemView, 8));
            itemHolder.binding.unread.setPadding(0, 0, 0, 0);
            itemHolder.binding.unread.setText("");
        } else {
            unreadParams.width = ViewGroup.LayoutParams.WRAP_CONTENT;
            unreadParams.height = dp(itemHolder.itemView, 22);
            itemHolder.binding.unread.setMinWidth(dp(itemHolder.itemView, 22));
            int horizontal = dp(itemHolder.itemView, 6);
            itemHolder.binding.unread.setPadding(horizontal, 0, horizontal, 0);
            itemHolder.binding.unread.setText(unread > 99 ? "99+" : String.valueOf(unread));
        }
        itemHolder.binding.unread.setLayoutParams(unreadParams);
        itemHolder.binding.unread.setVisibility(unread > 0 ? View.VISIBLE : View.INVISIBLE);
        List<String> states = new ArrayList<>();
        if (booleanValue(item, "is_pinned")) states.add("置顶");
        if (booleanValue(item, "is_bottomed")) states.add("置底");
        if (booleanValue(item, "is_muted")) states.add("免打扰");
        String state = String.join(" · ", states);
        itemHolder.binding.stateLabel.setText(state);
        itemHolder.binding.stateLabel.setVisibility(state.isEmpty() ? View.GONE : View.VISIBLE);

        String type = Jsons.string(item, "type");
        String avatarUrl = Jsons.string(item, "avatar");
        if (!avatarUrl.isEmpty()) {
            itemHolder.binding.avatarImage.setVisibility(View.VISIBLE);
            itemHolder.binding.avatar.setVisibility(View.GONE);
            ImageLoader.get().loadThumbnail(ImageLoader.get().absoluteUrl(itemHolder.itemView.getContext(), avatarUrl),
                itemHolder.binding.avatarImage, R.drawable.ic_person);
        } else {
            itemHolder.binding.avatarImage.setVisibility(View.GONE);
            itemHolder.binding.avatar.setVisibility(View.VISIBLE);
            itemHolder.binding.avatar.setText(avatarText(type, title));
        }
        itemHolder.binding.getRoot().setOnClickListener(view -> listener.onClick(item));
        itemHolder.binding.getRoot().setOnLongClickListener(view -> {
            listener.onLongPress(item);
            return true;
        });
    }

    @Override public int getItemCount() { return items.size(); }

    private static String key(JsonObject item) {
        if ("section".equals(Jsons.string(item, "type"))) return "section:" + Jsons.string(item, "section_key");
        return Jsons.string(item, "type") + ":" + Jsons.longValue(item, "target_id");
    }

    private static String avatarText(String type, String title) {
        switch (type) {
            case "group": return "群";
            case "service": return "客";
            case "bot": return "AI";
            case "system": return "系";
            case "notification": return "通";
            default: return firstCharacter(title);
        }
    }

    private static String compactTime(String value) {
        if (value == null || value.isEmpty()) return "";
        return value.length() >= 16 ? value.substring(5, 16) : value;
    }

    private static String firstCharacter(String value) {
        if (value == null || value.isEmpty()) return "?";
        int end = value.offsetByCodePoints(0, 1);
        return value.substring(0, end).toUpperCase(Locale.getDefault());
    }

    private static boolean booleanValue(JsonObject object, String key) {
        try { return object.has(key) && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static int dp(ViewGroup parent, int value) {
        return Math.round(value * parent.getResources().getDisplayMetrics().density);
    }

    private static int dp(View view, int value) {
        return Math.round(value * view.getResources().getDisplayMetrics().density);
    }

    static final class ItemHolder extends RecyclerView.ViewHolder {
        final ItemMessageCenterBinding binding;
        ItemHolder(ItemMessageCenterBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }

    static final class SectionHolder extends RecyclerView.ViewHolder {
        final TextView title;
        SectionHolder(TextView title) { super(title); this.title = title; }
    }
}
