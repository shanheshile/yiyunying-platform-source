package xyz.jjmxg.yiyunying.ui.main;

import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentManagementPageBinding;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.domain.module.FieldType;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.DynamicFormDialog;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;

public final class AdminMineFragment extends BaseFragment {
    private static final String CACHE = "management.public.profile.cache.v1";
    private FragmentManagementPageBinding binding;
    private JsonObject workbench = new JsonObject();
    private JsonObject publicProfile = new JsonObject();

    public static AdminMineFragment newInstance() { return new AdminMineFragment(); }

    @Override public void onResume() {
        super.onResume();
        // The ViewPager keeps this page alive while the user selects another app on Home.
        // Rebind the connection card to the latest selection before it can be used.
        if (binding != null && ManagementNavigationPolicy.useAdminWorkbench(app().session().role())) {
            render();
        }
    }

    @Nullable @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentManagementPageBinding.inflate(inflater, container, false);
        if (!ManagementNavigationPolicy.useAdminWorkbench(app().session().role())) {
            binding.pageContent.removeAllViews();
            binding.pageContent.addView(ManagementPageUi.title(requireContext(), "我的"));
            binding.pageContent.addView(ManagementPageUi.body(requireContext(), "当前账号继续使用原平台账号目录。"));
            return binding.getRoot();
        }
        readCachedPublicProfile();
        render();
        loadWorkbench();
        return binding.getRoot();
    }

    private void loadWorkbench() {
        track(app().repository().get("/api/admin/workbench", new LinkedHashMap<>(), result -> {
            if (binding == null) return;
            if (!result.isSuccessful()) {
                handleFailure(result, binding.getRoot());
                render();
                return;
            }
            workbench = result.dataObject().deepCopy();
            publicProfile = Jsons.object(workbench, "public_profile").deepCopy();
            cachePublicProfile(publicProfile);
            render();
        }));
    }

    private void render() {
        if (binding == null) return;
        LinearLayout content = binding.pageContent;
        content.removeAllViews();
        LinearLayout titleRow = ManagementPageUi.row(requireContext());
        ManagementPageUi.addWeighted(titleRow, ManagementPageUi.title(requireContext(), "我的"), ManagementPageUi.dp(requireContext(), 8));
        MaterialButton accountSettings = ManagementPageUi.button(requireContext(), "账号设置", R.drawable.ic_settings, false);
        accountSettings.setOnClickListener(view -> showAccountMenu());
        titleRow.addView(accountSettings, new LinearLayout.LayoutParams(-2, -2));
        content.addView(titleRow);
        content.addView(ManagementPageUi.body(requireContext(), "资料、会员、配额、连接验证、设备安全和对外公开信息。"));

        content.addView(ManagementPageUi.heading(requireContext(), "账号信息"));
        content.addView(profileCard());
        content.addView(ManagementPageUi.heading(requireContext(), "连接验证"));
        content.addView(connectionCard());
        content.addView(ManagementPageUi.heading(requireContext(), "资料与设备安全"));
        content.addView(securityCard());
        content.addView(ManagementPageUi.heading(requireContext(), "官方信息与收款码"));
        content.addView(publicProfileCard());
        content.addView(ManagementPageUi.heading(requireContext(), "赞助排行榜"));
        content.addView(sponsorCard());
        content.addView(ManagementPageUi.heading(requireContext(), "分享与关于"));
        content.addView(aboutCard());
    }

    private View profileCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 16));
        JsonObject profile = Jsons.object(workbench, "profile");
        JsonObject membership = Jsons.object(workbench, "membership");
        JsonObject quotas = Jsons.object(workbench, "quotas");
        JsonObject counts = Jsons.object(workbench, "counts");
        String account = Jsons.string(profile, "account");
        if (account.isEmpty()) account = app().session().account();
        String nickname = Jsons.string(profile, "nickname");
        box.addView(ManagementPageUi.title(requireContext(), nickname.isEmpty() ? account : nickname));
        box.addView(ManagementPageUi.body(requireContext(), "账号：" + account + "\n邮箱：" + valueOrUnset(Jsons.string(profile, "email"))
            + "\n会员：" + valueOrUnset(Jsons.string(membership, "level")) + " · 到期：" + valueOrUnset(Jsons.string(membership, "expired_at"))));
        JsonObject appQuota = Jsons.object(quotas, "apps");
        JsonObject documentQuota = Jsons.object(quotas, "remote_documents");
        box.addView(ManagementPageUi.body(requireContext(), "还能创建应用：" + Jsons.longValue(appQuota, "remaining")
            + " · 剩余应用 API 数量：" + Jsons.longValue(quotas, "api_apps_remaining")
            + "\n文档：" + Jsons.longValue(counts, "documents") + " · 剩余远程文档：" + Jsons.longValue(documentQuota, "remaining")
            + " · 云文件：" + Jsons.longValue(counts, "remote_files")
            + " · 当前有效设备：" + Jsons.longValue(counts, "active_devices")));
        return ManagementPageUi.card(requireContext(), box);
    }

    private View connectionCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 16));
        long appId = app().session().selectedAppId();
        String appName = app().session().selectedAppName();
        String appKey = app().session().selectedAppKey();
        box.addView(ManagementPageUi.title(requireContext(), appId > 0 ? appName : "未选择应用"));
        box.addView(ManagementPageUi.body(requireContext(), appId > 0
            ? "应用 API 唯一 ID：" + appKey + "\nToken 到期：" + valueOrUnset(app().session().expiresAt())
                + "\n校验范围：当前安装地址、账号、实时 Token、登录状态与应用 KEY"
            : "请在主页选择应用后再执行实时连接验证。"));
        MaterialButton verify = ManagementPageUi.button(requireContext(), "实时验证账号、Token 与 KEY", R.drawable.ic_api, true);
        verify.setEnabled(appId > 0 && !appKey.isEmpty());
        verify.setOnClickListener(view -> verifyConnection(appId, appKey));
        box.addView(verify);
        return ManagementPageUi.card(requireContext(), box);
    }

    private View securityCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 12));
        LinearLayout first = ManagementPageUi.row(requireContext());
        MaterialButton profile = ManagementPageUi.button(requireContext(), "修改资料/头像/密码", R.drawable.ic_person, false);
        profile.setOnClickListener(view -> host().openModule("profile"));
        ManagementPageUi.addWeighted(first, profile, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton devices = ManagementPageUi.button(requireContext(), "设备安全", R.drawable.ic_phone, false);
        devices.setOnClickListener(view -> loadSessions());
        first.addView(devices, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(first);
        MaterialButton logs = ManagementPageUi.button(requireContext(), "登录记录", R.drawable.ic_document, false);
        logs.setOnClickListener(view -> loadLoginLogs());
        box.addView(logs);
        return ManagementPageUi.card(requireContext(), box);
    }

    private View publicProfileCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        box.addView(ManagementPageUi.body(requireContext(), "官方网址：" + valueOrUnset(Jsons.string(publicProfile, "official_url"))
            + "\n下载地址：" + valueOrUnset(Jsons.string(publicProfile, "download_url"))
            + "\n官方 QQ 群：" + valueOrUnset(Jsons.string(publicProfile, "official_qq_group"))
            + "\n配置版本：" + Jsons.longValue(publicProfile, "revision")));
        LinearLayout qrRow = ManagementPageUi.row(requireContext());
        qrRow.addView(qrImage("支付宝收款码", Jsons.string(publicProfile, "alipay_qr_url")), new LinearLayout.LayoutParams(0, ManagementPageUi.dp(requireContext(), 150), 1f));
        qrRow.addView(qrImage("微信收款码", Jsons.string(publicProfile, "wechat_qr_url")), new LinearLayout.LayoutParams(0, ManagementPageUi.dp(requireContext(), 150), 1f));
        box.addView(qrRow);
        MaterialButton edit = ManagementPageUi.button(requireContext(), "编辑官方信息与收款码链接", R.drawable.ic_settings, true);
        edit.setOnClickListener(view -> editPublicProfile());
        box.addView(edit);
        return ManagementPageUi.card(requireContext(), box);
    }

    private ImageView qrImage(String label, String url) {
        ImageView image = new ImageView(requireContext());
        image.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
        image.setContentDescription(label + (url.isEmpty() ? "未配置" : "，点击查看"));
        ImageLoader.get().load(ImageLoader.get().absoluteUrl(requireContext(), url), image, R.drawable.ic_qr);
        image.setOnClickListener(view -> openUrl(url, label));
        int margin = ManagementPageUi.dp(requireContext(), 4);
        image.setPadding(margin, margin, margin, margin);
        return image;
    }

    private View sponsorCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 14));
        List<JsonObject> sponsors = objects(Jsons.array(workbench, "sponsors"));
        if (sponsors.isEmpty()) box.addView(ManagementPageUi.body(requireContext(), "暂无已确认赞助。收款到账后手动登记，系统会按金额自动排序。"));
        for (JsonObject sponsor : sponsors) {
            TextView row = ManagementPageUi.body(requireContext(), "第 " + Jsons.longValue(sponsor, "rank") + " 名 · "
                + Jsons.string(sponsor, "sponsor_name") + " · ¥" + Jsons.string(sponsor, "amount"));
            row.setPadding(0, ManagementPageUi.dp(requireContext(), 6), 0, ManagementPageUi.dp(requireContext(), 6));
            row.setOnClickListener(view -> sponsorMenu(sponsor));
            box.addView(row);
        }
        MaterialButton add = ManagementPageUi.button(requireContext(), "登记已到账赞助", R.drawable.ic_add, true);
        add.setOnClickListener(view -> editSponsor(null));
        box.addView(add);
        box.addView(ManagementPageUi.body(requireContext(), "支付宝/微信自动到账回调需要商户平台凭据；未配置前只允许人工确认后登记，避免把未付款订单排入榜单。"));
        return ManagementPageUi.card(requireContext(), box);
    }

    private View aboutCard() {
        LinearLayout box = ManagementPageUi.column(requireContext(), ManagementPageUi.dp(requireContext(), 12));
        String intro = Jsons.string(publicProfile, "software_intro");
        String about = Jsons.string(publicProfile, "about_us");
        if (!intro.isEmpty()) box.addView(ManagementPageUi.body(requireContext(), intro));
        if (!about.isEmpty()) box.addView(ManagementPageUi.body(requireContext(), "\n关于我们\n" + about));
        LinearLayout first = ManagementPageUi.row(requireContext());
        MaterialButton official = ManagementPageUi.button(requireContext(), "官方网站", R.drawable.ic_apps, false);
        official.setOnClickListener(view -> openUrl(Jsons.string(publicProfile, "official_url"), "官方网站"));
        ManagementPageUi.addWeighted(first, official, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton download = ManagementPageUi.button(requireContext(), "下载地址", R.drawable.ic_file, false);
        download.setOnClickListener(view -> openUrl(Jsons.string(publicProfile, "download_url"), "下载地址"));
        first.addView(download, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(first);
        LinearLayout second = ManagementPageUi.row(requireContext());
        MaterialButton qq = ManagementPageUi.button(requireContext(), "官方 QQ 群", R.drawable.ic_group, false);
        qq.setOnClickListener(view -> openUrl(Jsons.string(publicProfile, "official_qq_group_link"), "官方 QQ 群"));
        ManagementPageUi.addWeighted(second, qq, ManagementPageUi.dp(requireContext(), 8));
        MaterialButton share = ManagementPageUi.button(requireContext(), "分享软件", R.drawable.ic_forward, false);
        share.setOnClickListener(view -> shareSoftware());
        second.addView(share, new LinearLayout.LayoutParams(0, -2, 1f));
        box.addView(second);
        return ManagementPageUi.card(requireContext(), box);
    }

    private void verifyConnection(long appId, String appKey) {
        JsonObject body = new JsonObject();
        body.addProperty("app_key", appKey);
        track(app().repository().post("/api/admin/apps/" + appId + "/key/verify", body, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            JsonObject data = result.dataObject();
            new YiyunyingDialogBuilder(requireContext()).setTitle("实时连接验证通过")
                .setMessage("服务器地址：当前安装版本配置\n账号：" + Jsons.string(data, "account")
                    + "\n登录状态：在线\nToken：有效且未撤销\n应用：" + Jsons.string(data, "app_name")
                    + "\n应用 API 唯一 ID：" + Jsons.string(data, "api_unique_id")
                    + "\n验证时间：" + Jsons.string(data, "verified_at"))
                .setPositiveButton("完成", null).show();
        }));
    }

    private void editPublicProfile() {
        ActionSpec action = ActionSpec.builder("编辑公开信息", "PUT", "/api/admin/workbench/public-profile")
            .fields(
                FieldSpec.of("official_url", "官方网址（http/https）"),
                FieldSpec.of("download_url", "软件下载地址（http/https）"),
                FieldSpec.of("official_qq_group", "官方 QQ 群名称/群号"),
                FieldSpec.of("official_qq_group_link", "官方 QQ 群链接"),
                FieldSpec.of("alipay_qr_url", "支付宝收款码图片链接"),
                FieldSpec.of("wechat_qr_url", "微信收款码图片链接"),
                FieldSpec.typed("software_intro", "软件介绍", FieldType.MULTILINE, false),
                FieldSpec.typed("about_us", "关于我们", FieldType.MULTILINE, false)
            ).build();
        DynamicFormDialog.show(requireContext(), action, publicProfile, body ->
            track(app().repository().put(action.pathTemplate(), body, result -> {
                if (binding == null || handleFailure(result, binding.getRoot())) return;
                publicProfile = Jsons.object(result.dataObject(), "public_profile").deepCopy();
                cachePublicProfile(publicProfile);
                message(binding.getRoot(), "公开信息已保存，用户端下次刷新会同步");
                loadWorkbench();
            })));
    }

    private void editSponsor(@Nullable JsonObject sponsor) {
        boolean edit = sponsor != null;
        JsonObject formValue = sponsor == null ? null : sponsor.deepCopy();
        if (formValue != null) {
            formValue.addProperty("channel", sponsorChannelLabel(Jsons.string(formValue, "channel")));
        }
        ActionSpec action = ActionSpec.builder(edit ? "修改赞助记录" : "登记已到账赞助", edit ? "PUT" : "POST", "/api/admin/sponsors")
            .fields(
                FieldSpec.required("sponsor_name", "赞助人显示名称"),
                FieldSpec.typed("amount", "确认到账金额（元）", FieldType.TEXT, true),
                FieldSpec.of("channel", "渠道（手动/支付宝/微信/其他）").withDefault("手动"),
                FieldSpec.of("paid_at", "到账时间（可留空使用当前时间）"),
                FieldSpec.typed("note", "备注", FieldType.MULTILINE, false)
            ).build();
        DynamicFormDialog.show(requireContext(), action, formValue, body -> {
            String channel = sponsorChannelCode(Jsons.string(body, "channel"));
            if (channel == null) {
                if (binding != null) message(binding.getRoot(), "渠道只能填写：手动、支付宝、微信或其他");
                return;
            }
            body.addProperty("channel", channel);
            String path = edit ? "/api/admin/sponsors/" + Jsons.longValue(sponsor, "id") : "/api/admin/sponsors";
            if (edit) {
                track(app().repository().put(path, body, result -> finishSponsor(result)));
            } else {
                track(app().repository().post(path, body, result -> finishSponsor(result)));
            }
        });
    }

    private void finishSponsor(xyz.jjmxg.yiyunying.data.api.ApiResult result) {
        if (binding == null || handleFailure(result, binding.getRoot())) return;
        message(binding.getRoot(), result.message().isEmpty() ? "赞助榜已更新" : result.message());
        loadWorkbench();
    }

    private void sponsorMenu(JsonObject sponsor) {
        new YiyunyingDialogBuilder(requireContext()).setTitle(Jsons.string(sponsor, "sponsor_name"))
            .setItems(new String[]{"修改记录", "删除记录"}, (dialog, which) -> {
                if (which == 0) editSponsor(sponsor); else deleteSponsor(sponsor);
            }).setNegativeButton("取消", null).show();
    }

    private void deleteSponsor(JsonObject sponsor) {
        new YiyunyingDialogBuilder(requireContext()).setTitle("删除赞助记录")
            .setMessage("确认删除该榜单记录？这不会发起任何支付或退款。")
            .setNegativeButton("取消", null)
            .setPositiveButton("删除", (dialog, which) -> track(app().repository().delete(
                "/api/admin/sponsors/" + Jsons.longValue(sponsor, "id"), new JsonObject(), this::finishSponsor)))
            .show();
    }

    private void loadSessions() {
        track(app().repository().get("/api/admin/security/sessions", new LinkedHashMap<>(), result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            List<JsonObject> sessions = result.objectItems();
            if (sessions.isEmpty()) { message(binding.getRoot(), "没有可显示的设备会话"); return; }
            String[] labels = new String[sessions.size()];
            for (int index = 0; index < sessions.size(); index++) {
                JsonObject item = sessions.get(index);
                labels[index] = (bool(item, "is_current") ? "当前设备 · " : "")
                    + valueOrUnset(Jsons.string(item, "device")) + "\nIP " + valueOrUnset(Jsons.string(item, "ip"))
                    + " · " + (bool(item, "active") ? "有效" : "已失效");
            }
            new YiyunyingDialogBuilder(requireContext()).setTitle("设备安全保护")
                .setItems(labels, (dialog, which) -> confirmRevokeSession(sessions.get(which)))
                .setNegativeButton("关闭", null).show();
        }));
    }

    private void confirmRevokeSession(JsonObject session) {
        boolean current = bool(session, "is_current");
        new YiyunyingDialogBuilder(requireContext()).setTitle(current ? "退出当前设备" : "撤销设备登录")
            .setMessage("撤销后该 Token 立即失效。" + (current ? "当前账号会返回登录页。" : ""))
            .setNegativeButton("取消", null)
            .setPositiveButton("撤销", (dialog, which) -> track(app().repository().delete(
                "/api/admin/security/sessions/" + Jsons.longValue(session, "id"), new JsonObject(), result -> {
                    if (binding == null || handleFailure(result, binding.getRoot())) return;
                    if (bool(result.dataObject(), "current_session_revoked")) {
                        app().session().clearAuthentication();
                        host().onAuthenticationExpired();
                    } else {
                        message(binding.getRoot(), "设备会话已撤销");
                        loadWorkbench();
                    }
                }))).show();
    }

    private void loadLoginLogs() {
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("page", "1"); query.put("limit", "20");
        track(app().repository().get("/api/admin/login-logs", query, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            StringBuilder text = new StringBuilder();
            for (JsonObject item : result.objectItems()) {
                if (text.length() > 0) text.append("\n\n");
                text.append(Jsons.string(item, "created_at")).append(" · ").append(loginResultLabel(item))
                    .append("\nIP ").append(Jsons.string(item, "ip"));
                String reason = Jsons.string(item, "reason");
                if (!reason.isEmpty()) text.append("\n").append(reason);
            }
            new YiyunyingDialogBuilder(requireContext()).setTitle("最近登录记录")
                .setMessage(text.length() == 0 ? "暂无登录记录" : text.toString()).setPositiveButton("关闭", null).show();
        }));
    }

    @Nullable private static String sponsorChannelCode(String value) {
        switch (value == null ? "" : value.trim().toLowerCase(Locale.ROOT)) {
            case "手动": case "manual": return "manual";
            case "支付宝": case "alipay": return "alipay";
            case "微信": case "wechat": return "wechat";
            case "其他": case "other": return "other";
            default: return null;
        }
    }

    private static String sponsorChannelLabel(String value) {
        String code = sponsorChannelCode(value);
        if ("alipay".equals(code)) return "支付宝";
        if ("wechat".equals(code)) return "微信";
        if ("other".equals(code)) return "其他";
        return "手动";
    }

    private static String loginResultLabel(JsonObject item) {
        String value = Jsons.string(item, "result").trim().toLowerCase(Locale.ROOT);
        if ("1".equals(value) || "success".equals(value) || "成功".equals(value)) return "登录成功";
        if ("0".equals(value) || "failed".equals(value) || "failure".equals(value) || "失败".equals(value)) return "登录失败";
        return "状态未知";
    }

    private void showAccountMenu() {
        new YiyunyingDialogBuilder(requireContext()).setTitle("账号设置")
            .setItems(new String[]{"切换账号", "退出账号", "注销账号"}, (dialog, which) -> {
                if (which < 2) host().onLogoutRequested(); else deactivateAccount();
            }).setNegativeButton("取消", null).show();
    }

    private void deactivateAccount() {
        ActionSpec action = ActionSpec.builder("注销账号", "DELETE", "/api/admin/account")
            .fields(FieldSpec.typed("password", "当前登录密码", FieldType.PASSWORD, true),
                FieldSpec.required("confirm", "输入“注销账号”确认")).confirm(true).build();
        DynamicFormDialog.show(requireContext(), action, null, body -> track(app().repository().delete(action.pathTemplate(), body, result -> {
            if (binding == null || handleFailure(result, binding.getRoot())) return;
            app().session().clearAuthentication();
            host().onAuthenticationExpired();
        })));
    }

    private void shareSoftware() {
        String url = Jsons.string(publicProfile, "download_url");
        if (url.isEmpty()) url = Jsons.string(publicProfile, "official_url");
        String intro = Jsons.string(publicProfile, "software_intro");
        Intent share = new Intent(Intent.ACTION_SEND).setType("text/plain")
            .putExtra(Intent.EXTRA_SUBJECT, "分享软件")
            .putExtra(Intent.EXTRA_TEXT, (intro.isEmpty() ? "推荐你使用这款软件" : intro) + (url.isEmpty() ? "" : "\n" + url));
        startActivity(Intent.createChooser(share, "分享软件"));
    }

    private void openUrl(String value, String label) {
        if (value == null || value.trim().isEmpty()) { message(binding.getRoot(), label + "尚未配置"); return; }
        try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(value.trim()))); }
        catch (RuntimeException exception) { message(binding.getRoot(), label + "无法打开，请检查链接"); }
    }

    private void readCachedPublicProfile() {
        String cached = requireContext().getSharedPreferences(CACHE, 0).getString("public_profile", "");
        try {
            JsonElement parsed = Jsons.parse(cached);
            if (parsed.isJsonObject()) publicProfile = parsed.getAsJsonObject();
        } catch (RuntimeException ignored) { publicProfile = new JsonObject(); }
    }

    private void cachePublicProfile(JsonObject profile) {
        SharedPreferences.Editor editor = requireContext().getSharedPreferences(CACHE, 0).edit();
        editor.putString("public_profile", Jsons.GSON.toJson(profile));
        editor.putString("settings_hash", Jsons.string(profile, "settings_hash"));
        editor.apply();
    }

    private static List<JsonObject> objects(com.google.gson.JsonArray array) {
        java.util.ArrayList<JsonObject> result = new java.util.ArrayList<>();
        for (JsonElement item : array) if (item.isJsonObject()) result.add(item.getAsJsonObject());
        return result;
    }

    private static String valueOrUnset(String value) { return TextUtils.isEmpty(value) ? "未设置" : value; }

    private static boolean bool(JsonObject value, String key) {
        if (value == null || !value.has(key) || value.get(key).isJsonNull()) return false;
        try { return value.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    @Override public void onDestroyView() {
        binding = null;
        workbench = new JsonObject();
        super.onDestroyView();
    }
}
