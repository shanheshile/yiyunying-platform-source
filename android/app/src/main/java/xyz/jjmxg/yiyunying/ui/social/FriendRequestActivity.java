package xyz.jjmxg.yiyunying.ui.social;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.ArrayAdapter;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityFriendRequestBinding;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;

public final class FriendRequestActivity extends SystemInsetActivity {
    private static final String EXTRA_USER_ID = "user_id";

    private ActivityFriendRequestBinding binding;
    private final List<JsonObject> groups = new ArrayList<>();
    private RequestHandle profileRequest;
    private RequestHandle groupRequest;
    private RequestHandle actionRequest;
    private JsonObject profile = new JsonObject();
    private long userId;

    public static void open(Context context, long userId) {
        context.startActivity(new Intent(context, FriendRequestActivity.class).putExtra(EXTRA_USER_ID, userId));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        userId = getIntent().getLongExtra(EXTRA_USER_ID, 0);
        if (userId <= 0) { finish(); return; }
        binding = ActivityFriendRequestBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.messageInput.setText("我是【" + AppAccess.from(this).session().account() + "】");
        binding.sendButton.setEnabled(false);
        binding.sendButton.setOnClickListener(view -> send());
        loadProfile();
        loadGroups();
    }

    private void loadProfile() {
        binding.progress.setVisibility(View.VISIBLE);
        profileRequest = AppAccess.from(this).repository().get("/api/user/profiles/" + userId,
            new LinkedHashMap<>(), result -> {
                profileRequest = null;
                if (binding == null) return;
                finishProgressIfReady();
                if (!result.isSuccessful()) {
                    binding.stateNotice.setText(result.message().isEmpty() ? "用户资料加载失败" : result.message());
                    return;
                }
                profile = Jsons.object(result.dataObject(), "profile");
                renderProfile();
            });
    }

    private void loadGroups() {
        groupRequest = AppAccess.from(this).repository().get("/api/user/friend-groups",
            new LinkedHashMap<>(), result -> {
                groupRequest = null;
                if (binding == null) return;
                finishProgressIfReady();
                groups.clear();
                if (result.isSuccessful()) groups.addAll(result.objectItems());
                List<String> names = new ArrayList<>();
                names.add("我的好友（默认）");
                for (JsonObject group : groups) names.add(Jsons.string(group, "name"));
                binding.groupSpinner.setAdapter(new ArrayAdapter<>(this,
                    android.R.layout.simple_spinner_dropdown_item, names));
            });
    }

    private void renderProfile() {
        String nickname = Jsons.string(profile, "nickname");
        binding.name.setText(nickname.isEmpty() ? Jsons.string(profile, "account") : nickname);
        binding.account.setText("账号：" + Jsons.string(profile, "account") + "\nUID：" + Jsons.string(profile, "public_no"));
        ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, Jsons.string(profile, "avatar")),
            binding.avatar, R.drawable.ic_person);
        boolean enabled = bool(profile, "can_send_friend_request");
        String notice;
        if (bool(profile, "is_friend")) notice = "对方已经是你的好友";
        else if (bool(profile, "outgoing_friend_request_pending")) notice = "好友申请已经发送，请等待对方处理";
        else if (!bool(profile, "friend_request_enabled")) notice = "对方已关闭好友申请";
        else if (bool(profile, "blocked") || bool(profile, "blocked_by")) notice = "当前关系状态不允许发送好友申请";
        else notice = "验证信息、备注和分组均可按需填写。动态权限会在成为好友后生效。";
        binding.stateNotice.setText(notice);
        binding.sendButton.setEnabled(enabled);
    }

    private void send() {
        if (actionRequest != null || !bool(profile, "can_send_friend_request")) return;
        JsonObject body = new JsonObject();
        body.addProperty("to_uid", Jsons.string(profile, "public_no"));
        body.addProperty("message", text(binding.messageInput.getText()));
        body.addProperty("requester_remark", text(binding.remarkInput.getText()));
        int position = binding.groupSpinner.getSelectedItemPosition();
        if (position > 0 && position - 1 < groups.size()) {
            body.addProperty("requester_group_id", Jsons.longValue(groups.get(position - 1), "id"));
        }
        body.addProperty("hide_my_dynamic", binding.hideMyDynamic.isChecked());
        body.addProperty("hide_their_dynamic", binding.hideTheirDynamic.isChecked());
        binding.progress.setVisibility(View.VISIBLE);
        binding.sendButton.setEnabled(false);
        actionRequest = AppAccess.from(this).repository().post("/api/user/friends/requests", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful()
                ? (result.message().isEmpty() ? "好友申请已发送" : result.message())
                : (result.message().isEmpty() ? "好友申请发送失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) binding.getRoot().postDelayed(this::finish, 700L);
            else binding.sendButton.setEnabled(bool(profile, "can_send_friend_request"));
        });
    }

    private void finishProgressIfReady() {
        if (profileRequest == null && groupRequest == null && binding != null) binding.progress.setVisibility(View.INVISIBLE);
    }

    private static String text(CharSequence value) { return value == null ? "" : value.toString().trim(); }
    private static boolean bool(JsonObject value, String key) {
        try { return value.has(key) && value.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    @Override protected void onDestroy() {
        if (profileRequest != null) profileRequest.cancel();
        if (groupRequest != null) groupRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        binding = null;
        super.onDestroy();
    }
}
