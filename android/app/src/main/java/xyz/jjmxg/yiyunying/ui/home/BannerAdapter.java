package xyz.jjmxg.yiyunying.ui.home;

import android.view.LayoutInflater;
import android.view.ViewGroup;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.RecyclerView;

import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemBannerBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;

public final class BannerAdapter extends RecyclerView.Adapter<BannerAdapter.Holder> {
    private final List<JsonObject> items = new ArrayList<>();

    public void submit(List<JsonObject> values) {
        if (items.equals(values)) return;
        List<JsonObject> previous = new ArrayList<>(items);
        items.clear();
        items.addAll(values);
        DiffUtil.calculateDiff(new DiffUtil.Callback() {
            @Override public int getOldListSize() { return previous.size(); }
            @Override public int getNewListSize() { return items.size(); }
            @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                return identity(previous.get(oldPosition)).equals(identity(items.get(newPosition)));
            }
            @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                return previous.get(oldPosition).equals(items.get(newPosition));
            }
        }).dispatchUpdatesTo(this);
    }

    @NonNull
    @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        return new Holder(ItemBannerBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
    }

    @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
        JsonObject item = items.get(position);
        holder.binding.title.setText(Jsons.string(item, "title"));
        ImageLoader.get().load(Jsons.string(item, "image_url"), holder.binding.image, R.drawable.ic_logo_foreground);
        String link = Jsons.string(item, "link_url");
        holder.binding.getRoot().setOnClickListener(view -> {
            if (!(link.startsWith("http://") || link.startsWith("https://"))) return;
            LinkNavigator.open(view.getContext(), link);
        });
    }

    @Override public int getItemCount() { return items.size(); }

    private static String identity(JsonObject item) {
        String id = Jsons.string(item, "id");
        return id.isEmpty() ? Jsons.string(item, "image_url") + "|" + Jsons.string(item, "link_url") : id;
    }

    static final class Holder extends RecyclerView.ViewHolder {
        final ItemBannerBinding binding;
        Holder(ItemBannerBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }
}
