package xyz.jjmxg.yiyunying.ui.main;

import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.chip.Chip;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentManagementPageBinding;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

public final class AdminCommunityFragment extends BaseFragment {
    private static final String[][] CATEGORIES = {
        {"general", "综合类"}, {"technology", "技术类"}, {"help", "求助类"},
        {"share", "分享类"}, {"communication", "交流类"},
    };
    private FragmentManagementPageBinding binding;
    private String category = "general";
    private boolean permissionAllowed;

    public static AdminCommunityFragment newInstance() { return new AdminCommunityFragment(); }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentManagementPageBinding.inflate(inflater, container, false);
        if (!ManagementNavigationPolicy.useAdminWorkbench(app().session().role())) {
            binding.pageContent.removeAllViews();
            binding.pageContent.addView(ManagementPageUi.title(requireContext(), "交流"));
            binding.pageContent.addView(ManagementPageUi.body(requireContext(), "当前账号继续使用原平台交流目录。"));
            return binding.getRoot();
        }
        renderAccessState("正在验证交流管理权限…");
        loadPermission();
        return binding.getRoot();
    }

    private void loadPermission() {
        track(app().repository().get("/api/admin/permissions", new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            if (!result.isSuccessful()) {
                permissionAllowed = false;
                handleFailure(result, binding.getRoot());
                renderAccessState("无法验证交流管理权限，请稍后重试。");
                return;
            }
            permissionAllowed = ManagementNavigationPolicy.permissionAllowed(result.dataObject(), "forum.manage");
            if (!permissionAllowed) {
                renderAccessState("当前账号没有交流与论坛管理权限。");
                return;
            }
            renderChrome();
            loadPosts();
        }));
    }

    private void renderAccessState(String message) {
        if (binding == null) return;
        binding.pageContent.removeAllViews();
        binding.pageContent.addView(ManagementPageUi.title(requireContext(), "交流"));
        binding.pageContent.addView(ManagementPageUi.body(requireContext(), message));
    }

    private void renderChrome() {
        LinearLayout content = binding.pageContent;
        content.removeAllViews();
        content.addView(ManagementPageUi.title(requireContext(), "交流"));
        content.addView(ManagementPageUi.body(requireContext(), "管理员交流区与各应用论坛相对独立；这里用于管理员之间的技术、求助、分享与日常交流。"));

        HorizontalScrollView scroller = new HorizontalScrollView(requireContext());
        scroller.setHorizontalScrollBarEnabled(false);
        LinearLayout chips = ManagementPageUi.row(requireContext());
        for (String[] item : CATEGORIES) {
            Chip chip = ManagementPageUi.chip(requireContext(), item[1]);
            chip.setChecked(category.equals(item[0]));
            chip.setOnClickListener(view -> {
                category = item[0];
                renderChrome();
                loadPosts();
            });
            chips.addView(chip);
        }
        scroller.addView(chips, new HorizontalScrollView.LayoutParams(-2, -2));
        content.addView(scroller);

        LinearLayout actions = ManagementPageUi.row(requireContext());
        MaterialButton publish = ManagementPageUi.button(requireContext(), "发帖子", R.drawable.ic_add, true);
        publish.setOnClickListener(view -> publish());
        ManagementPageUi.addWeighted(actions, publish, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton appForum = ManagementPageUi.button(requireContext(), "当前应用论坛", R.drawable.ic_forum, false);
        appForum.setOnClickListener(view -> host().openModule("forum_posts"));
        actions.addView(appForum, new LinearLayout.LayoutParams(0, -2, 1f));
        content.addView(actions);
        content.addView(ManagementPageUi.heading(requireContext(), categoryName(category) + "帖子"));

        LinearLayout list = ManagementPageUi.column(requireContext(), 0);
        list.setTag(R.id.management_community_list);
        list.addView(ManagementPageUi.body(requireContext(), "正在加载…"));
        content.addView(list);
    }

    private void loadPosts() {
        if (!permissionAllowed) return;
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", "1");
        query.put("limit", "30");
        query.put("category_code", category);
        track(app().repository().get("/api/admin/community/posts", query, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            LinearLayout list = binding.getRoot().findViewWithTag(R.id.management_community_list);
            if (list == null) return;
            list.removeAllViews();
            List<JsonObject> posts = result.objectItems();
            if (posts.isEmpty()) {
                list.addView(ManagementPageUi.card(requireContext(), paddedText("这个分类还没有帖子，发布第一篇交流内容吧。")));
                return;
            }
            for (JsonObject post : posts) list.addView(postCard(post));
        }));
    }

    private View postCard(JsonObject post) {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        String title = Jsons.string(post, "title");
        boolean top = Jsons.intValue(post, "is_top", 0) == 1;
        box.addView(ManagementPageUi.title(requireContext(), (top ? "置顶 · " : "") + title));
        String content = Jsons.string(post, "content");
        if (content.length() > 180) content = content.substring(0, 180) + "…";
        box.addView(ManagementPageUi.body(requireContext(), Jsons.string(post, "author_name") + " · "
            + Jsons.string(post, "created_at") + "\n" + content + "\n点赞 " + Jsons.longValue(post, "like_count")
            + " · 收藏 " + Jsons.longValue(post, "favorite_count") + " · 评论 " + Jsons.longValue(post, "comment_count")));

        LinearLayout first = ManagementPageUi.row(requireContext());
        MaterialButton like = ManagementPageUi.button(requireContext(), Jsons.intValue(post, "liked", 0) == 1 ? "取消点赞" : "点赞", R.drawable.ic_like, false);
        like.setOnClickListener(view -> reaction(post, "like"));
        ManagementPageUi.addWeighted(first, like, ManagementPageUi.dp(requireContext(), 6));
        MaterialButton favorite = ManagementPageUi.button(requireContext(), Jsons.intValue(post, "favorited", 0) == 1 ? "取消收藏" : "收藏", R.drawable.ic_favorite, false);
        favorite.setOnClickListener(view -> reaction(post, "favorite"));
        ManagementPageUi.addWeighted(first, favorite, ManagementPageUi.dp(requireContext(), 6));
        MaterialButton comment = ManagementPageUi.button(requireContext(), "评论", R.drawable.ic_comment, false);
        comment.setOnClickListener(view -> comment(post));
        first.addView(comment, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(first);

        LinearLayout second = ManagementPageUi.row(requireContext());
        MaterialButton detail = ManagementPageUi.button(requireContext(), "详情", R.drawable.ic_chevron_right, false);
        detail.setOnClickListener(view -> detail(post));
        ManagementPageUi.addWeighted(second, detail, ManagementPageUi.dp(requireContext(), 6));
        MaterialButton pin = ManagementPageUi.button(requireContext(), top ? "取消置顶" : "置顶", R.drawable.ic_sort, false);
        pin.setOnClickListener(view -> pin(post, !top));
        ManagementPageUi.addWeighted(second, pin, ManagementPageUi.dp(requireContext(), 6));
        MaterialButton more = ManagementPageUi.button(requireContext(), "举报/删除", R.drawable.ic_more, false);
        more.setOnClickListener(view -> moderationMenu(post));
        second.addView(more, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(second);
        return ManagementPageUi.card(requireContext(), box);
    }

    private void publish() {
        ActionSpec action = ActionSpec.builder("发布" + categoryName(category) + "帖子", "POST", "/api/admin/community/posts")
            .fields(FieldSpec.required("title", "帖子标题"), FieldSpec.typed("content", "帖子内容", FieldType.MULTILINE, true))
            .fixed("category_code", category).build();
        DynamicFormDialog.show(requireContext(), action, null, body -> {
            body.addProperty("category_code", category);
            post("/api/admin/community/posts", body, "帖子已发布");
        });
    }

    private void reaction(JsonObject post, String type) {
        JsonObject body = new JsonObject();
        body.addProperty("reaction_type", type);
        post("/api/admin/community/posts/" + Jsons.longValue(post, "id") + "/reactions", body, "操作成功");
    }

    private void comment(JsonObject post) {
        ActionSpec action = ActionSpec.builder("评论帖子", "POST", "")
            .fields(FieldSpec.typed("content", "评论内容", FieldType.MULTILINE, true)).build();
        DynamicFormDialog.show(requireContext(), action, null, body ->
            post("/api/admin/community/posts/" + Jsons.longValue(post, "id") + "/comments", body, "评论成功"));
    }

    private void pin(JsonObject post, boolean pinned) {
        JsonObject body = new JsonObject();
        body.addProperty("pinned", pinned);
        post("/api/admin/community/posts/" + Jsons.longValue(post, "id") + "/pin", body, pinned ? "帖子已置顶" : "已取消置顶");
    }

    private void detail(JsonObject post) {
        long postId = Jsons.longValue(post, "id");
        track(app().repository().get("/api/admin/community/posts/" + postId, new LinkedHashMap<>(), result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            JsonObject full = Jsons.object(result.dataObject(), "post");
            int comments = Jsons.array(full, "comments").size();
            new YiyunyingDialogBuilder(requireContext())
                .setTitle(Jsons.string(full, "title"))
                .setMessage(Jsons.string(full, "content") + "\n\n分类：" + Jsons.string(full, "category_name")
                    + "\n作者：" + Jsons.string(full, "author_name") + "\n评论：" + comments + " 条")
                .setPositiveButton("关闭", null).show();
        }));
    }

    private void moderationMenu(JsonObject post) {
        String[] choices = {"举报帖子", "删除帖子"};
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("帖子操作")
            .setItems(choices, (dialog, which) -> {
                if (which == 0) report(post); else confirmDelete(post);
            }).setNegativeButton("取消", null).show();
    }

    private void report(JsonObject post) {
        ActionSpec action = ActionSpec.builder("举报帖子", "POST", "")
            .fields(FieldSpec.typed("reason", "举报原因", FieldType.MULTILINE, true)).build();
        DynamicFormDialog.show(requireContext(), action, null, body ->
            post("/api/admin/community/posts/" + Jsons.longValue(post, "id") + "/reports", body, "举报已提交"));
    }

    private void confirmDelete(JsonObject post) {
        new YiyunyingDialogBuilder(requireContext()).setTitle("删除帖子")
            .setMessage("确认删除“" + Jsons.string(post, "title") + "”？只有作者本人可删除自己的帖子。")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> {
                JsonObject body = new JsonObject();
                track(app().repository().delete("/api/admin/community/posts/" + Jsons.longValue(post, "id"), body, result -> {
                    if (binding == null || handleFailure(result, binding.getRoot())) return;
                    message(binding.getRoot(), "帖子已删除");
                    loadPosts();
                }));
            }).show();
    }

    private void post(String path, JsonObject body, String success) {
        track(app().repository().post(path, body, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            message(binding.getRoot(), TextUtils.isEmpty(result.message()) ? success : result.message());
            loadPosts();
        }));
    }

    private View paddedText(String value) {
        View text = ManagementPageUi.body(requireContext(), value);
        int padding = ManagementPageUi.dp(requireContext(), 16);
        text.setPadding(padding, padding, padding, padding);
        return text;
    }

    private static String categoryName(String code) {
        for (String[] category : CATEGORIES) if (category[0].equals(code)) return category[1];
        return "综合类";
    }

    @Override public void onDestroyView() {
        binding = null;
        permissionAllowed = false;
        super.onDestroyView();
    }
}
