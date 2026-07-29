package xyz.jjmxg.yiyunying.ui.profile;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.card.MaterialCardView;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

import java.util.LinkedHashMap;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityUserProfileBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.chat.ChatActivity;
import xyz.jjmxg.yiyunying.ui.chat.ConversationPermissionActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.forum.ForumPostActivity;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.moment.MomentTimelineActivity;
import xyz.jjmxg.yiyunying.ui.home.RelationshipNoticeActivity;
import xyz.jjmxg.yiyunying.ui.social.FriendRequestActivity;
import xyz.jjmxg.yiyunying.ui.upload.ImageGalleryActivity;

public final class UserProfileActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_USER_ID = "user_id";
    private static final String EXTRA_FROM_PERMISSION = "from_permission";
    private static final int MENU_PERMISSION = 9411;

    private ActivityUserProfileBinding binding;
    private RequestHandle request;
    private RequestHandle cachedRequest;
    private RequestHandle actionRequest;
    private long userId;
    private JsonObject profile = new JsonObject();
    private boolean profileRendered;

    public static void open(Context context, long userId) {
        open(context, userId, false);
    }

    public static void openFromPermission(Context context, long userId) {
        open(context, userId, true);
    }

    private static void open(Context context, long userId, boolean fromPermission) {
        if (userId <= 0) return;
        context.startActivity(new Intent(context, UserProfileActivity.class)
            .putExtra(EXTRA_USER_ID, userId)
            .putExtra(EXTRA_FROM_PERMISSION, fromPermission));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        userId = getIntent().getLongExtra(EXTRA_USER_ID, 0);
        if (userId <= 0) { finish(); return; }
        binding = ActivityUserProfileBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        if (userId != AppAccess.from(this).session().actorId()) {
            MenuItem permission = binding.toolbar.getMenu().add(
                0, MENU_PERMISSION, 0, R.string.conversation_permission_title);
            permission.setIcon(R.drawable.ic_more);
            permission.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
            binding.toolbar.setOnMenuItemClickListener(item -> {
                if (item.getItemId() != MENU_PERMISSION) return false;
                if (getIntent().getBooleanExtra(EXTRA_FROM_PERMISSION, false)) {
                    finish();
                    return true;
                }
                String title = Jsons.string(profile, "nickname");
                if (title.isEmpty()) title = Jsons.string(profile, "account");
                ConversationPermissionActivity.openPrivateFromProfile(
                    this, 0L, userId, title, "private_peer:" + userId);
                return true;
            });
        }
        binding.followButton.setOnClickListener(view -> follow());
        binding.friendButton.setOnClickListener(view -> addFriend());
        binding.likeButton.setOnClickListener(view -> likeProfile());
        binding.likeButton.setOnLongClickListener(view -> { unlikeProfile(); return true; });
        binding.messageButton.setOnClickListener(view -> openMessage());
        binding.avatar.setOnClickListener(view -> previewAvatar());
        load();
    }

    private void load() {
        if (request != null) request.cancel();
        if (cachedRequest != null) cachedRequest.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        String path = "/api/user/profiles/" + userId;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        cachedRequest = AppAccess.from(this).repository().getCached(path, query, cached -> {
            cachedRequest = null;
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            JsonObject cachedProfile = Jsons.object(cached.dataObject(), "profile");
            if (cachedProfile.size() == 0) return;
            profile = cachedProfile;
            profileRendered = true;
            render();
            binding.progress.setVisibility(View.INVISIBLE);
        });
        request = AppAccess.from(this).repository().get(path, query, result -> {
            request = null;
            if (binding == null || isFinishing() || isDestroyed()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), profileRendered
                    ? "当前展示已缓存的个人资料，联网后会自动更新"
                    : (result.message().isEmpty() ? "个人主页加载失败" : result.message()), Snackbar.LENGTH_LONG).show();
                return;
            }
            profile = Jsons.object(result.dataObject(), "profile");
            profileRendered = true;
            render();
        });
    }

    private void render() {
        String nickname = Jsons.string(profile, "nickname");
        String account = Jsons.string(profile, "account");
        RuntimeLanguage.setDynamicText(binding.name, nickname.isEmpty() ? account : nickname);
        RuntimeLanguage.setDynamicText(binding.account,
            RuntimeLanguage.translate(this, "账号：") + account + " · UID：" + Jsons.string(profile, "public_no"));
        ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, Jsons.string(profile, "avatar")), binding.avatar, R.drawable.ic_person);
        String title = Jsons.string(profile, "title");
        RuntimeLanguage.setDynamicText(binding.title, title);
        binding.title.setVisibility(title.isEmpty() ? View.GONE : View.VISIBLE);
        String signature = Jsons.string(profile, "signature");
        if (signature.isEmpty()) binding.signature.setText(RuntimeLanguage.translate(this, "这个用户暂未展示个性签名"));
        else RuntimeLanguage.setDynamicText(binding.signature, signature);
        binding.visibilityNotice.setText(Jsons.string(profile, "visibility_notice"));
        binding.followingCount.setText(Jsons.longValue(profile, "following_count") + "\n关注");
        binding.followerCount.setText(Jsons.longValue(profile, "follower_count") + "\n粉丝");
        binding.likeCount.setText(Jsons.longValue(profile, "like_count") + "\n获赞");
        binding.followButton.setText(bool(profile, "followed") ? "取消关注" : "关注");
        boolean self = userId == AppAccess.from(this).session().actorId();
        binding.actions.setVisibility(View.VISIBLE);
        if (self) {
            binding.followButton.setText("编辑资料");
            binding.followButton.setOnClickListener(view -> startActivity(MainActivity.moduleIntent(this, "profile")));
            binding.friendButton.setVisibility(View.GONE);
            binding.likeButton.setVisibility(View.GONE);
            binding.messageButton.setVisibility(View.GONE);
        } else {
            binding.friendButton.setVisibility(View.VISIBLE);
            binding.likeButton.setVisibility(View.VISIBLE);
            binding.messageButton.setVisibility(View.VISIBLE);
            binding.messageButton.setEnabled(bool(profile, "can_send_message"));
            binding.messageButton.setText(bool(profile, "can_send_message") ? "发消息"
                : Jsons.string(profile, "message_permission_notice"));
            if (bool(profile, "is_friend")) {
                binding.friendButton.setText("已是好友");
                binding.friendButton.setEnabled(false);
            } else if (bool(profile, "outgoing_friend_request_pending")) {
                binding.friendButton.setText("已发送申请");
                binding.friendButton.setEnabled(false);
            } else if (bool(profile, "incoming_friend_request_pending")) {
                binding.friendButton.setText("处理好友申请");
                binding.friendButton.setEnabled(true);
            } else if (!bool(profile, "friend_request_enabled")) {
                binding.friendButton.setText("对方已关闭申请");
                binding.friendButton.setEnabled(false);
            } else {
                binding.friendButton.setText("加好友");
                binding.friendButton.setEnabled(bool(profile, "can_send_friend_request"));
            }
        }
        renderDetails();
    }

    private void openMessage() {
        if (!bool(profile, "can_send_message")) {
            Snackbar.make(binding.getRoot(), Jsons.string(profile, "message_permission_notice"), Snackbar.LENGTH_LONG).show();
            return;
        }
        String name = Jsons.string(profile, "nickname");
        if (name.isEmpty()) name = Jsons.string(profile, "account");
        ChatActivity.openPeer(this, userId, name);
    }

    private void renderDetails() {
        binding.detailsContainer.removeAllViews();
        addSectionTitle("公开资料");
        addDetail("注册时间", Jsons.string(profile, "created_at"));
        addDetail("UID", Jsons.string(profile, "public_no"));
        if (!Jsons.string(profile, "phone").isEmpty()) addDetail("手机号码", Jsons.string(profile, "phone"));
        if (!Jsons.string(profile, "email").isEmpty()) addDetail("邮箱", Jsons.string(profile, "email"));
        if (!Jsons.string(profile, "qq").isEmpty()) addDetail("QQ", Jsons.string(profile, "qq"));
        if (!Jsons.string(profile, "gender").isEmpty()) addDetail("性别", Jsons.string(profile, "gender"));
        if (!Jsons.string(profile, "birthday").isEmpty()) addDetail("生日", Jsons.string(profile, "birthday"));
        if (!Jsons.string(profile, "level_code").isEmpty()) addDetail("等级", Jsons.string(profile, "level_code"));
        if (profile.has("experience")) addDetail("经验", String.valueOf(Jsons.longValue(profile, "experience")));
        if (!Jsons.string(profile, "vip_expired_at").isEmpty()) addDetail("会员到期", Jsons.string(profile, "vip_expired_at"));
        if (bool(profile, "details_hidden")) {
            addDetail("资料状态", "详细资料已由用户隐藏。这里只显示昵称、头像、称号和公开统计。 ");
        }
        String ownerName = Jsons.string(profile, "nickname");
        if (ownerName.isEmpty()) ownerName = Jsons.string(profile, "account");
        final String timelineTitle = ownerName;
        addNavigation("生活动态", "查看公开动态、图片、视频、点赞与评论",
            () -> MomentTimelineActivity.openForUser(this, userId, timelineTitle));
        addContentSection("动态笔记", Jsons.array(profile, "notes"), true);
        addContentSection("论坛帖子", Jsons.array(profile, "posts"), false);
    }

    private void addNavigation(String title, String summary, Runnable action) {
        MaterialCardView card = new MaterialCardView(this);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(8);
        card.setLayoutParams(params);
        card.setRadius(dp(6));
        card.setCardElevation(0);
        card.setCardBackgroundColor(getColor(R.color.surface_container));

        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(14), dp(12), dp(14), dp(12));
        TextView heading = new TextView(this);
        heading.setText(title);
        heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        TextView description = new TextView(this);
        description.setText(summary);
        description.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        description.setTextColor(getColor(R.color.on_surface_variant));
        content.addView(heading);
        content.addView(description);
        card.addView(content);
        card.setOnClickListener(view -> action.run());
        binding.detailsContainer.addView(card);
    }

    private void addContentSection(String title, JsonArray items, boolean note) {
        addSectionTitle(title + "（" + items.size() + "）");
        if (items.isEmpty()) { addDetail(title, "暂无公开内容"); return; }
        for (JsonElement element : items) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(8);
            card.setLayoutParams(params); card.setRadius(dp(6)); card.setCardElevation(0);
            card.setCardBackgroundColor(getColor(R.color.surface_container));
            TextView text = new TextView(this); text.setPadding(dp(14), dp(10), dp(14), dp(10));
            text.setText(Jsons.string(item, "title") + "\n" + Jsons.string(item, note ? "updated_at" : "created_at"));
            text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
            card.addView(text);
            card.setOnClickListener(view -> {
                if (note) {
                    String ownerName = Jsons.string(profile, "nickname");
                    if (ownerName.isEmpty()) ownerName = Jsons.string(profile, "account");
                    MomentTimelineActivity.openForUser(this, userId, ownerName);
                }
                else ForumPostActivity.open(this, Jsons.longValue(item, "id"));
            });
            binding.detailsContainer.addView(card);
        }
    }

    private void previewAvatar() {
        String url = Jsons.string(profile, "avatar");
        if (url.isEmpty()) return;
        JsonObject image = new JsonObject(); image.addProperty("url", url); image.addProperty("file_name", "用户头像");
        ImageGalleryActivity.open(this, java.util.Collections.singletonList(image), 0);
    }

    private void addSectionTitle(String title) {
        TextView view = new TextView(this);
        view.setText(title);
        view.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(8);
        binding.detailsContainer.addView(view, params);
    }

    private void addDetail(String label, String value) {
        if (value == null || value.trim().isEmpty()) return;
        MaterialCardView card = new MaterialCardView(this);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(8);
        card.setLayoutParams(params);
        card.setRadius(dp(6));
        card.setCardElevation(0);
        card.setCardBackgroundColor(getColor(R.color.surface_container));
        TextView text = new TextView(this);
        text.setGravity(Gravity.CENTER_VERTICAL);
        text.setMinHeight(dp(54));
        text.setPadding(dp(14), dp(8), dp(14), dp(8));
        text.setText(label + "\n" + value);
        text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        card.addView(text);
        binding.detailsContainer.addView(card);
    }

    private void follow() {
        if (actionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post("/api/user/profiles/" + userId + "/follow", new JsonObject(), result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "关注操作失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            load();
        });
    }

    private void addFriend() {
        if (bool(profile, "incoming_friend_request_pending")) {
            RelationshipNoticeActivity.open(this, "friend_incoming", "好友申请");
            return;
        }
        if (bool(profile, "can_send_friend_request")) FriendRequestActivity.open(this, userId);
    }

    private void sendFriendRequest(String verification) {
        JsonObject body = new JsonObject();
        body.addProperty("to_uid", Jsons.string(profile, "public_no"));
        body.addProperty("message", verification);
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post("/api/user/friends/requests", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            Snackbar.make(binding.getRoot(), result.isSuccessful()
                ? (result.message().isEmpty() ? "好友申请已发送" : result.message())
                : (result.message().isEmpty() ? "好友申请发送失败" : result.message()), Snackbar.LENGTH_LONG).show();
        });
    }

    private void likeProfile() {
        if (actionRequest != null) return;
        JsonObject body = new JsonObject(); body.addProperty("count", 1);
        actionRequest = AppAccess.from(this).repository().post("/api/user/profiles/" + userId + "/likes", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "点赞失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            Snackbar.make(binding.getRoot(), "点赞成功，今天已点赞 " + Jsons.longValue(result.dataObject(), "today_count") + " 次", Snackbar.LENGTH_SHORT).show();
            load();
        });
    }

    private void unlikeProfile() {
        if (actionRequest != null) return;
        new YiyunyingDialogBuilder(this).setTitle("撤回点赞")
            .setMessage("长按操作会撤回今天给该用户的全部点赞。")
            .setPositiveButton("确认撤回", (dialog, which) -> {
                actionRequest = AppAccess.from(this).repository().delete("/api/user/profiles/" + userId + "/likes", new JsonObject(), result -> {
                    actionRequest = null;
                    if (binding == null) return;
                    Snackbar.make(binding.getRoot(), result.isSuccessful() ? "今天的点赞已撤回"
                        : (result.message().isEmpty() ? "撤回失败" : result.message()), Snackbar.LENGTH_LONG).show();
                    if (result.isSuccessful()) load();
                });
            }).setNegativeButton("取消", null).show();
    }

    private boolean bool(JsonObject object, String key) {
        try { return object.has(key) && object.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    @Override protected void onDestroy() {
        if (request != null) request.cancel();
        if (cachedRequest != null) cachedRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }
}
