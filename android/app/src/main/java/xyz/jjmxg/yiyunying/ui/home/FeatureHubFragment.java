package xyz.jjmxg.yiyunying.ui.home;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.DrawableRes;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentFeatureHubBinding;
import xyz.jjmxg.yiyunying.databinding.ItemFeatureLinkBinding;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.forum.ForumListActivity;
import xyz.jjmxg.yiyunying.ui.moment.MomentTimelineActivity;
import xyz.jjmxg.yiyunying.ui.poll.PollActivity;
import xyz.jjmxg.yiyunying.ui.resource.ResourceHallActivity;
import xyz.jjmxg.yiyunying.ui.social.FavoriteCenterActivity;
import xyz.jjmxg.yiyunying.ui.settings.UserSettingsActivity;

public final class FeatureHubFragment extends BaseFragment implements UserTabPage {
    private static final String ARG_PAGE = "page";
    private FragmentFeatureHubBinding binding;
    private FeatureAdapter adapter;
    private int page;
    private String query = "";
    private int notificationUnreadCount;

    public static FeatureHubFragment newInstance(int page) {
        FeatureHubFragment fragment = new FeatureHubFragment();
        Bundle arguments = new Bundle();
        arguments.putInt(ARG_PAGE, page);
        fragment.setArguments(arguments);
        return fragment;
    }

    @Override public void onCreate(@Nullable Bundle state) {
        super.onCreate(state);
        page = requireArguments().getInt(ARG_PAGE, 1);
    }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentFeatureHubBinding.inflate(inflater, container, false);
        adapter = new FeatureAdapter(this::openFeature);
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        filter();
        return binding.getRoot();
    }

    @Override public void onSearchQuery(String value) {
        query = value == null ? "" : value;
        filter();
    }

    public void setNotificationUnreadCount(int count) {
        notificationUnreadCount = Math.max(0, count);
        filter();
    }

    @Override public void onPrimaryAction() {
        if (page == 1) {
            showQuickActions(new String[]{"发布动态", "发布帖子", "发布悬赏", "投稿资源", "发起投票"},
                new String[]{"moments_compose", "forum_posts", "bounties", "resources", "polls"});
        } else if (page == 2) {
            showQuickActions(new String[]{"进入余额商店", "发红包", "参与抽奖", "兑换权益"},
                new String[]{"shop_goods", "red_packets", "lottery", "card_redeem"});
        } else {
            UserSettingsActivity.open(requireContext());
        }
    }

    private void filter() {
        if (binding == null) return;
        String needle = query.trim().toLowerCase(Locale.ROOT);
        List<FeatureItem> visible = new ArrayList<>();
        for (FeatureItem item : items()) {
            if (needle.isEmpty() || (item.title + " " + item.subtitle).toLowerCase(Locale.ROOT).contains(needle)) {
                visible.add(item);
            }
        }
        adapter.submit(visible);
        binding.emptyState.setVisibility(visible.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void openFeature(FeatureItem item) {
        if ("favorites_center".equals(item.moduleId)) FavoriteCenterActivity.open(requireContext());
        else if ("user_settings".equals(item.moduleId)) UserSettingsActivity.open(requireContext());
        else if ("moments".equals(item.moduleId)) MomentTimelineActivity.open(requireContext(), false);
        else if ("moments_compose".equals(item.moduleId)) MomentTimelineActivity.open(requireContext(), true);
        else if ("forum_posts".equals(item.moduleId)) ForumListActivity.open(requireContext());
        else if ("polls".equals(item.moduleId)) PollActivity.open(requireContext());
        else if ("resource_hall".equals(item.moduleId)) ResourceHallActivity.open(requireContext());
        else host().openModule(item.moduleId);
    }

    private void showQuickActions(String[] labels, String[] modules) {
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        for (int i = 0; i < labels.length && i < modules.length; i++) {
            String label = labels[i];
            String module = modules[i];
            actions.add(new GlassActionDialog.Action(label, actionIcon(module), () -> openQuickAction(module)));
        }
        GlassActionDialog.show(requireContext(), "选择操作", actions);
    }

    private void openQuickAction(String module) {
        if ("moments_compose".equals(module)) MomentTimelineActivity.open(requireContext(), true);
        else if ("forum_posts".equals(module)) ForumListActivity.open(requireContext());
        else if ("polls".equals(module)) PollActivity.open(requireContext());
        else if ("resources".equals(module)) ResourceHallActivity.open(requireContext());
        else host().openModule(module);
    }

    @DrawableRes private int actionIcon(String module) {
        if ("moments_compose".equals(module)) return R.drawable.ic_album;
        if ("forum_posts".equals(module)) return R.drawable.ic_forum;
        if ("bounties".equals(module) || "red_packets".equals(module)) return R.drawable.ic_wallet;
        if ("resources".equals(module) || "shop_goods".equals(module)) return R.drawable.ic_apps;
        if ("polls".equals(module)) return R.drawable.ic_stats;
        return R.drawable.ic_content;
    }

    private List<FeatureItem> items() {
        List<FeatureItem> result = new ArrayList<>();
        if (page == 1) {
            result.add(item("生活动态", "按时间轴查看图文动态、附近位置与编辑记录", "moments", R.drawable.ic_album));
            result.add(item("论坛", "进入板块，浏览帖子、评论、回复与付费分节", "forum_posts", R.drawable.ic_forum));
            result.add(item("悬赏", "按分类查看任务、投稿和选中结果", "bounties", R.drawable.ic_wallet));
            result.add(item("资源大厅", "应用商店与源码商城，按分类搜索、查看和获取", "resource_hall", R.drawable.ic_apps));
            result.add(item("投票", "按活动分类查看多选投票与结果", "polls", R.drawable.ic_stats));
        } else if (page == 2) {
            result.add(item("余额商店", "使用余额购买商品、会员和开放权益", "shop_goods", R.drawable.ic_apps));
            result.add(item("红包", "查看可领取红包或创建红包", "red_packets", R.drawable.ic_wallet));
            result.add(item("抽奖", "按活动查看奖品、规则和开奖记录", "lottery", R.drawable.ic_content));
            result.add(item("兑换", "使用卡密兑换余额、会员或其他权益", "card_redeem", R.drawable.ic_content_paste));
        } else {
            result.add(item("个人资料", "昵称、头像、签名与账号资料", "profile", R.drawable.ic_person));
            result.add(item("资产与签到", "余额、会员、签到、邀请与流水", "wallet", R.drawable.ic_wallet));
            result.add(item("我的收藏", "消息、图片、链接、文件、帖子、资源与应用", "favorites_center", R.drawable.ic_content));
            FeatureItem notifications = item("通知中心", "点赞、评论、好友、订单、权益和系统通知", "notifications", R.drawable.ic_document);
            notifications.badgeCount = notificationUnreadCount;
            result.add(notifications);
            result.add(item("我的订单", "查看实际购买后自动生成的订单", "orders", R.drawable.ic_file));
            result.add(item("意见反馈", "提交问题、建议和图片", "feedbacks", R.drawable.ic_file));
            result.add(item("邀请记录", "查看邀请码和邀请奖励", "invites", R.drawable.ic_users));
            result.add(item("文件与存储", "查看、上传、下载与管理软件内文件", "upload", R.drawable.ic_file));
        }
        return result;
    }

    private static FeatureItem item(String title, String subtitle, String module, @DrawableRes int icon) {
        return new FeatureItem(title, subtitle, module, icon);
    }

    @Override public void onDestroyView() { binding = null; super.onDestroyView(); }

    private static final class FeatureItem {
        final String title;
        final String subtitle;
        final String moduleId;
        final int icon;
        int badgeCount;
        FeatureItem(String title, String subtitle, String moduleId, int icon) {
            this.title = title; this.subtitle = subtitle; this.moduleId = moduleId; this.icon = icon;
        }
    }

    private static final class FeatureAdapter extends RecyclerView.Adapter<FeatureAdapter.Holder> {
        interface Listener { void onClick(FeatureItem item); }
        private final List<FeatureItem> items = new ArrayList<>();
        private final Listener listener;
        private String snapshot = "";
        FeatureAdapter(Listener listener) {
            this.listener = listener;
            setHasStableIds(true);
        }
        void submit(List<FeatureItem> next) {
            StringBuilder value = new StringBuilder();
            for (FeatureItem item : next) {
                value.append(item.moduleId).append(':').append(item.badgeCount).append('|');
            }
            String nextSnapshot = value.toString();
            if (snapshot.equals(nextSnapshot)) return;
            snapshot = nextSnapshot;
            items.clear();
            items.addAll(next);
            notifyDataSetChanged();
        }
        @Override public long getItemId(int position) { return items.get(position).moduleId.hashCode(); }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemFeatureLinkBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            FeatureItem item = items.get(position);
            holder.binding.title.setText(item.title);
            holder.binding.subtitle.setText(item.subtitle);
            holder.binding.icon.setImageResource(item.icon);
            holder.binding.badge.setVisibility(item.badgeCount > 0 ? View.VISIBLE : View.GONE);
            holder.binding.badge.setText(item.badgeCount > 99 ? "99+" : String.valueOf(item.badgeCount));
            holder.binding.getRoot().setOnClickListener(view -> {
                int current = holder.getBindingAdapterPosition();
                if (current != RecyclerView.NO_POSITION) listener.onClick(items.get(current));
            });
        }
        @Override public int getItemCount() { return items.size(); }
        static final class Holder extends RecyclerView.ViewHolder {
            final ItemFeatureLinkBinding binding;
            Holder(ItemFeatureLinkBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
