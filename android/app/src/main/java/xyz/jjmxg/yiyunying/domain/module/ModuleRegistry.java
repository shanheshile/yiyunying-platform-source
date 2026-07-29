package xyz.jjmxg.yiyunying.domain.module;

import java.util.ArrayList;
import java.util.Collections;
import java.util.EnumMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.domain.Role;

public final class ModuleRegistry {
    private final Map<Role, List<ModuleSpec>> modules = new EnumMap<>(Role.class);
    private final Map<String, ModuleSpec> byId = new LinkedHashMap<>();

    public ModuleRegistry() {
        modules.put(Role.PLATFORM, platformModules());
        modules.put(Role.ADMIN, adminModules());
        modules.put(Role.USER, userModules());
        for (List<ModuleSpec> roleModules : modules.values()) {
            for (ModuleSpec module : roleModules) {
                byId.put(module.role().wireName() + ":" + module.id(), module);
            }
        }
    }

    public List<ModuleSpec> forRole(Role role) {
        return modules.getOrDefault(role, Collections.emptyList());
    }

    public ModuleSpec find(Role role, String id) {
        return byId.get(role.wireName() + ":" + id);
    }

    private static List<ModuleSpec> platformModules() {
        Role role = Role.PLATFORM;
        List<ModuleSpec> result = new ArrayList<>();
        result.add(special("dashboard", "数据总览", role, "平台", ScreenType.DASHBOARD, "/api/platform/dashboard"));

        result.add(ModuleSpec.builder("operators", "授权平台", role).group("组织")
            .path("/api/platform/operators").paged().searchable("keyword")
            .primary("nickname", "account", "platform_key", "id")
            .secondary("level", "status", "membership_expired_at")
            .create(action("创建授权平台", "POST", "/api/platform/operators",
                req("account", "账号"), pwd("password", "初始密码"), req("nickname", "名称"),
                field("platform_key", "平台标识"), integer("membership_days", "赠送会员天数"),
                integer("admin_quota", "管理员额度"), integer("balance", "赠送余额"),
                integer("admin_free_trial_days", "下级管理员赠送会员天数"),
                integer("admin_free_app_quota", "下级管理员赠送应用额度"),
                integer("admin_free_remote_document_quota", "下级管理员赠送远程文档额度"),
                integer("admin_free_balance", "下级管理员赠送余额")))
            .action(itemAction("编辑", "PUT", "/api/platform/operators/{operator_id}",
                field("account", "账号"), field("nickname", "名称"), field("email", "邮箱"), field("phone", "手机"),
                field("membership_level", "会员等级"), date("membership_expired_at", "会员到期时间"),
                integer("admin_quota", "管理员额度"), integer("balance", "余额"),
                field("access_start_time", "允许开始时间"), field("access_end_time", "允许结束时间"),
                field("allowed_weekdays", "允许使用星期")))
            .action(itemAction("设置下级注册赠送", "PUT", "/api/platform/operators/{operator_id}/settings",
                json("settings", "下级管理员注册赠送规则")))
            .action(itemAction("调整权益", "PUT", "/api/platform/operators/{operator_id}/entitlement"))
            .action(itemAction("重置密码", "PUT", "/api/platform/operators/{operator_id}/password",
                pwd("new_password", "新密码")))
            .action(confirmItem("封禁", "POST", "/api/platform/operators/{operator_id}/ban", false))
            .action(confirmItem("解封", "POST", "/api/platform/operators/{operator_id}/unban", false))
            .action(confirmItem("删除", "DELETE", "/api/platform/operators/{operator_id}", true))
            .build());

        result.add(ModuleSpec.builder("admins", "管理员账号", role).group("组织")
            .path("/api/platform/admins").paged().searchable("keyword")
            .primary("nickname", "account", "id")
            .secondary("membership_level", "membership_status", "membership_expired_at", "status")
            .create(action("创建管理员", "POST", "/api/platform/admins",
                req("account", "账号"), pwd("password", "初始密码"), field("nickname", "昵称"),
                field("email", "邮箱"), integer("membership_days", "会员天数"),
                integer("app_quota", "应用额度"), integer("remote_document_quota", "远程文档额度"),
                integer("balance", "余额")))
            .action(itemAction("编辑资料", "PUT", "/api/platform/admins/{admin_id}",
                field("account", "账号"), field("nickname", "昵称"), field("avatar", "头像地址"),
                field("email", "邮箱"), field("phone", "手机"), integer("status", "账号状态")))
            .action(itemAction("调整权益", "PUT", "/api/platform/admins/{admin_id}/entitlement"))
            .action(itemAction("重置密码", "PUT", "/api/platform/admins/{admin_id}/password", pwd("new_password", "新密码")))
            .action(confirmItem("封禁", "POST", "/api/platform/admins/{admin_id}/ban", false))
            .action(confirmItem("解封", "POST", "/api/platform/admins/{admin_id}/unban", false))
            .action(confirmItem("删除及下游数据", "DELETE", "/api/platform/admins/{admin_id}", true))
            .build());

        result.add(ModuleSpec.builder("apps", "全部应用", role).group("组织")
            .path("/api/platform/apps").paged().searchable("keyword")
            .primary("name", "app_key", "id").secondary("admin_account", "user_count", "status")
            .action(itemAction("编辑应用", "PUT", "/api/platform/apps/{app_id}",
                field("name", "名称"), field("description", "描述"), field("version", "版本"), integer("status", "状态")))
            .action(itemAction("资源投稿权限", "PUT", "/api/platform/apps/{app_id}/settings",
                bool("resource_user_submit_enabled", "允许普通用户投稿资源"),
                bool("resource_submit_audit", "源码投稿后需要审核"),
                bool("store_user_submit_enabled", "允许普通用户投稿应用"),
                bool("store_submit_audit", "应用投稿后需要审核")))
            .action(itemAction("强制配置", "PUT", "/api/platform/apps/{app_id}/settings", json("settings", "应用规则配置")))
            .action(confirmItem("删除应用", "DELETE", "/api/platform/apps/{app_id}", true))
            .build());
        result.add(ModuleSpec.builder("uploads", "应用上传文件", role).group("组织").requiresApp()
            .screen(ScreenType.UPLOAD).path("/api/platform/apps/{app_id}/uploads").build());

        result.add(ModuleSpec.builder("exchange_products", "余额兑换商品", role).group("计费")
            .path("/api/platform/exchange-products").dataKey("items")
            .primary("name", "product_code", "id").secondary("product_type", "price_balance", "stock", "status")
            .create(action("新增兑换商品", "POST", "/api/platform/exchange-products",
                req("product_code", "商品编码"), req("name", "商品名称"), req("product_type", "商品类型"),
                json("grant", "发放规则"), integer("price_balance", "余额价格"), integer("stock", "库存"),
                integer("per_admin_limit", "每账号限购")))
            .action(itemAction("编辑", "PUT", "/api/platform/exchange-products/{product_id}",
                field("name", "商品名称"), json("grant", "发放规则"), integer("price_balance", "余额价格"),
                integer("stock", "库存"), integer("per_admin_limit", "每账号限购")))
            .action(confirmItem("启用", "POST", "/api/platform/exchange-products/{product_id}/enable", false))
            .action(confirmItem("停用", "POST", "/api/platform/exchange-products/{product_id}/disable", false))
            .action(confirmItem("删除", "DELETE", "/api/platform/exchange-products/{product_id}", true))
            .build());

        result.add(ModuleSpec.builder("exchanges", "兑换订单", role).group("计费")
            .path("/api/platform/exchanges").paged().searchable("keyword")
            .primary("order_no", "product_name", "id").secondary("admin_account", "total_balance", "status", "created_at")
            .action(itemAction("退款", "POST", "/api/platform/exchanges/{exchange_id}/refund", field("reason", "退款原因")).confirm(false))
            .build());
        result.add(simplePaged("balance_logs", "余额流水", role, "计费", "/api/platform/balance-logs",
            new String[]{"scene", "remark", "id"}, new String[]{"change_value", "before_value", "after_value", "created_at"}));

        result.add(ModuleSpec.builder("governance", "强制治理", role).group("治理")
            .path("/api/platform/governance-rules").paged().searchable("feature_code")
            .primary("feature_code", "target_type", "id").secondary("effect", "target_id", "target_level", "forced", "status")
            .create(action("新建治理规则", "POST", "/api/platform/governance-rules",
                req("feature_code", "功能编码"), req("target_type", "目标 global/level/platform/admin/app/user"),
                integer("target_id", "目标 ID"), integer("target_level", "目标等级"), req("effect", "效果 allow/deny/config"),
                json("value", "规则详细配置"), bool("forced", "强制锁定"), integer("priority", "优先级"),
                date("starts_at", "开始时间"), date("ends_at", "结束时间"), field("remark", "备注")))
            .action(itemAction("编辑", "PUT", "/api/platform/governance-rules/{rule_id}",
                field("effect", "效果"), json("value", "规则详细配置"), bool("forced", "强制锁定"),
                integer("priority", "优先级"), bool("status", "启用"), field("remark", "备注")))
            .action(confirmItem("删除", "DELETE", "/api/platform/governance-rules/{rule_id}", true)).build());
        result.add(ModuleSpec.builder("platform_community", "同级交流", role).group("治理")
            .path("/api/platform/community/posts").paged().searchable("keyword")
            .primary("title", "author_name", "id").secondary("target_level", "like_count", "comment_count", "created_at")
            .create(action("发布交流帖", "POST", "/api/platform/community/posts",
                integer("target_level", "可见等级"), integer("scope_platform_id", "指定平台 ID"),
                req("title", "标题"), multilineRequired("content", "内容"), json("attachments", "附件列表")))
            .action(itemAction("评论", "POST", "/api/platform/community/posts/{post_id}/comments", multilineRequired("content", "评论")))
            .action(itemAction("点赞", "POST", "/api/platform/community/posts/{post_id}/reactions", field("reaction_type", "like/favorite").withDefault("like")))
            .action(confirmItem("删除", "DELETE", "/api/platform/community/posts/{post_id}", true)).build());
        result.add(ModuleSpec.builder("hierarchy_activities", "层级活动", role).group("互动")
            .path("/api/platform/activities").paged().searchable("keyword")
            .primary("title", "activity_type", "id")
            .secondary("owner", "total_balance", "remaining_balance", "remaining_slots", "status")
            .create(action("发布层级活动", "POST", "/api/platform/activities",
                req("activity_type", "类型 red_packet/lottery/bounty"), field("funding_mode", "资金 balance/issued").withDefault("balance"),
                req("title", "活动标题"), multiline("description", "活动说明"),
                integer("total_balance", "红包总余额"), integer("total_count", "红包份数"), field("packet_mode", "红包 equal/random").withDefault("random"),
                integer("reward_balance", "悬赏余额"), json("prizes", "抽奖奖项"),
                bool("audience_sync", "可见与参与同步").withDefault("true"),
                json("targets", "同步目标").withDefault("[{\"type\":\"level\",\"level\":3}]"),
                json("visibility_targets", "额外可见目标"), json("participation_targets", "可参与目标"),
                integer("per_actor_limit", "每账号次数").withDefault("1"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(itemAction("领取红包", "POST", "/api/platform/activities/{activity_id}/claim").confirm(false).idempotent())
            .action(itemAction("参与抽奖", "POST", "/api/platform/activities/{activity_id}/draw").confirm(false).idempotent())
            .action(itemAction("提交悬赏", "POST", "/api/platform/activities/{activity_id}/submit", multilineRequired("content", "投稿内容"), json("attachments", "附件列表")))
            .action(itemAction("选中投稿", "POST", "/api/platform/activities/{activity_id}/award", integerRequired("submission_id", "投稿 ID")))
            .action(confirmItem("结束活动", "POST", "/api/platform/activities/{activity_id}/close", false))
            .action(confirmItem("取消并退款", "POST", "/api/platform/activities/{activity_id}/cancel", false)).build());
        result.add(ModuleSpec.builder("software_updates", "软件更新", role).group("生命周期")
            .path("/api/platform/software-updates").paged().primary("version_name", "edition_code", "id")
            .secondary("version_code", "target_type", "target_level", "force_update", "status")
            .create(action("发布更新", "POST", "/api/platform/software-updates",
                req("edition_code", "软件包 all/platform_owner/authorized_platform/admin/user"),
                req("target_type", "目标 global/level/platform/admin/app"), integer("target_id", "目标 ID"),
                integer("target_level", "目标等级"), req("version_name", "版本名"), integerRequired("version_code", "版本号"),
                integer("min_supported_version_code", "最低可用版本"), req("download_url", "下载地址"),
                multilineRequired("release_notes", "更新说明"), bool("force_update", "强制更新"), integer("priority", "优先级")))
            .action(itemAction("编辑更新", "PUT", "/api/platform/software-updates/{policy_id}",
                req("edition_code", "适用软件包"), req("target_type", "发布范围"), integer("target_id", "指定对象 ID"),
                integer("target_level", "适用等级"), req("version_name", "版本名称"), integerRequired("version_code", "版本号"),
                integer("min_supported_version_code", "最低支持版本"), req("download_url", "安装包地址"),
                multilineRequired("release_notes", "更新内容"), bool("force_update", "是否强制更新"), integer("priority", "显示优先级")))
            .action(confirmItem("删除", "DELETE", "/api/platform/software-updates/{policy_id}", true)).build());
        result.add(ModuleSpec.builder("maintenances", "维护管理", role).group("生命周期")
            .path("/api/platform/maintenances").paged().primary("title", "edition_code", "id")
            .secondary("target_type", "target_level", "forced", "starts_at", "ends_at", "status")
            .create(action("发布维护", "POST", "/api/platform/maintenances",
                req("edition_code", "软件包"), req("target_type", "目标类型"), integer("target_id", "目标 ID"),
                integer("target_level", "目标等级"), req("title", "维护标题"), multilineRequired("message", "维护说明"),
                bool("forced", "强制维护"), json("allowlist", "IP 白名单"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(itemAction("编辑维护", "PUT", "/api/platform/maintenances/{policy_id}",
                req("edition_code", "适用软件包"), req("target_type", "维护范围"), integer("target_id", "指定对象 ID"),
                integer("target_level", "适用等级"), req("title", "维护标题"), multilineRequired("message", "维护内容"),
                bool("forced", "是否强制维护"), json("allowlist", "免维护 IP 列表"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(confirmItem("删除", "DELETE", "/api/platform/maintenances/{policy_id}", true)).build());
        result.add(ModuleSpec.builder("poll_categories", "投票分类", role).group("互动")
            .path("/api/platform/poll-categories").dataKey("items").primary("name", "id")
            .secondary("target_level", "poll_count", "status")
            .create(action("新增分类", "POST", "/api/platform/poll-categories", integer("target_level", "可见等级"), req("name", "分类名"), field("color", "颜色"), integer("sort_order", "排序")))
            .action(itemAction("编辑", "PUT", "/api/platform/poll-categories/{category_id}", field("name", "分类名"), field("color", "颜色"), integer("sort_order", "排序"), bool("enabled", "启用")))
            .action(confirmItem("删除", "DELETE", "/api/platform/poll-categories/{category_id}", true)).build());
        result.add(ModuleSpec.builder("polls", "分级投票", role).group("互动")
            .path("/api/platform/polls").paged().primary("title", "creator_name", "id")
            .secondary("target_level", "category_names", "ballot_count", "status")
            .create(action("创建投票", "POST", "/api/platform/polls", integer("target_level", "可见等级"), integer("app_id", "指定 App ID"),
                req("title", "标题"), multiline("description", "描述"), json("category_ids", "分类 ID 数组"), json("options", "选项数组"),
                bool("multiple_choice", "多选"), integer("min_select", "最少选择"), integer("max_select", "最多选择"),
                bool("allow_change", "允许改票"), field("result_visibility", "结果可见规则"), date("ends_at", "结束时间")))
            .action(confirmItem("关闭", "POST", "/api/platform/polls/{poll_id}/close", false))
            .action(confirmItem("删除", "DELETE", "/api/platform/polls/{poll_id}", true)).build());

        result.add(ModuleSpec.builder("purchase_orders", "购买申请", role).group("计费")
            .path("/api/platform/purchase-orders").paged()
            .primary("order_no", "purchase_type", "id").secondary("admin_account", "quantity", "amount", "status")
            .action(itemAction("确认交付", "POST", "/api/platform/purchase-orders/{order_id}/fulfill", json("grant", "交付内容")).confirm(false))
            .action(itemAction("拒绝申请", "POST", "/api/platform/purchase-orders/{order_id}/reject", field("reason", "原因")).confirm(false))
            .build());
        result.add(ModuleSpec.builder("feedbacks", "管理员反馈", role).group("服务")
            .path("/api/platform/admin-feedbacks").paged()
            .primary("title", "type", "id").secondary("admin_account", "status", "created_at")
            .action(itemAction("回复", "POST", "/api/platform/admin-feedbacks/{feedback_id}/reply", req("reply", "回复内容")))
            .build());

        result.add(simple("ip_statistics", "注册 IP 统计", role, "审计", "/api/platform/ip-statistics",
            new String[]{"ip", "platform_id"}, new String[]{"attempts", "successful", "failed", "last_seen_at"}));
        result.add(simplePaged("registration_logs", "管理员注册日志", role, "审计", "/api/platform/admin-registration-logs",
            new String[]{"account", "ip", "id"}, new String[]{"result", "reason", "created_at"}));
        result.add(simplePaged("admin_login_logs", "管理员登录日志", role, "审计", "/api/platform/admin-login-logs",
            new String[]{"account", "nickname", "ip", "id"}, new String[]{"result", "reason", "created_at"}));
        result.add(simplePaged("operation_logs", "平台操作日志", role, "审计", "/api/platform/operation-logs",
            new String[]{"module", "action", "target_type", "id"}, new String[]{"actor_level", "target_id", "created_at"}));
        result.add(simple("data_console", "数据总控", role, "审计", "/api/platform/data-console/tables",
            new String[]{"table_name", "id"}, new String[]{"record_estimate", "column_count", "writable", "updated_at"}));
        FieldSpec[] platformAiKnowledgeFields = new FieldSpec[]{
            field("scope_type", "作用范围（global/platform/admin/app）").withDefault("platform"),
            integer("platform_id", "授权平台 ID"), integer("admin_id", "管理员 ID"), integer("app_id", "应用 ID"),
            req("title", "知识标题"), multilineRequired("content", "知识正文"),
            field("keywords", "关键词（逗号分隔）"), field("source_url", "来源链接"),
            integer("priority", "优先级"), bool("status", "启用")
        };
        result.add(ModuleSpec.builder("ai_knowledge", "AI 综合知识库", role).group("智能")
            .path("/api/platform/ai-knowledge").paged().searchable("q").dataKey("items")
            .primary("title", "scope_type", "id").secondary("keywords", "priority", "status", "updated_at")
            .create(action("新增 AI 知识", "POST", "/api/platform/ai-knowledge", platformAiKnowledgeFields))
            .action(itemAction("编辑", "PUT", "/api/platform/ai-knowledge/{document_id}", platformAiKnowledgeFields))
            .action(confirmItem("删除", "DELETE", "/api/platform/ai-knowledge/{document_id}", true)).build());
        result.add(special("settings", "平台规则", role, "账户", ScreenType.SETTINGS, "/api/platform/settings"));
        result.add(special("profile", "平台资料", role, "账户", ScreenType.PROFILE, "/api/platform/me"));
        return Collections.unmodifiableList(result);
    }

    private static List<ModuleSpec> adminModules() {
        Role role = Role.ADMIN;
        List<ModuleSpec> result = new ArrayList<>();
        result.add(special("dashboard", "应用总览", role, "应用", ScreenType.DASHBOARD, "/api/admin/apps/{app_id}/statistics").toBuilder().requiresApp().build());

        result.add(ModuleSpec.builder("apps", "应用管理", role).group("应用")
            .path("/api/admin/apps").paged().searchable("keyword")
            .primary("name", "app_key", "id").secondary("version", "status", "user_count", "created_at")
            .create(action("创建应用", "POST", "/api/admin/apps",
                req("name", "应用名称"), req("app_key", "应用标识"), field("description", "应用描述"),
                field("version", "版本号"), field("logo", "图标地址")))
            .action(itemAction("编辑", "PUT", "/api/admin/apps/{app_id}",
                field("name", "应用名称"), field("description", "应用描述"), field("version", "版本号"), field("logo", "图标地址")))
            .action(confirmItem("启用", "POST", "/api/admin/apps/{app_id}/enable", false))
            .action(confirmItem("停用", "POST", "/api/admin/apps/{app_id}/disable", false))
            .action(confirmItem("重置密钥", "POST", "/api/admin/apps/{app_id}/secret/reset", false))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}", true))
            .build());
        result.add(special("app_settings", "应用规则", role, "应用", ScreenType.SETTINGS, "/api/admin/apps/{app_id}/settings").toBuilder().requiresApp().build());
        result.add(ModuleSpec.builder("domains", "应用域名", role).group("应用").requiresApp()
            .path("/api/admin/apps/{app_id}/domains").dataKey("items")
            .primary("domain", "id").secondary("status", "created_at")
            .create(action("绑定域名", "POST", "/api/admin/apps/{app_id}/domains", req("domain", "域名")))
            .action(confirmItem("删除域名", "DELETE", "/api/admin/apps/{app_id}/domains/{domain_id}", true)).build());

        result.add(special("entitlement", "我的权益", role, "计费", ScreenType.WALLET, "/api/admin/entitlement"));
        result.add(simple("exchange_products", "可兑换商品", role, "计费", "/api/admin/exchange-products",
            new String[]{"name", "product_code", "id"}, new String[]{"price_balance", "stock", "status"}));
        result.add(ModuleSpec.builder("exchanges", "我的兑换", role).group("计费")
            .path("/api/admin/exchanges").paged().primary("order_no", "product_name", "id")
            .secondary("quantity", "total_balance", "status", "created_at")
            .create(action("立即兑换", "POST", "/api/admin/exchanges",
                integerRequired("product_id", "商品 ID"), integerRequired("quantity", "数量")).idempotent())
            .build());
        result.add(simplePaged("balance_logs", "平台余额流水", role, "计费", "/api/admin/balance-logs",
            new String[]{"scene", "remark", "id"}, new String[]{"change_value", "before_value", "after_value", "created_at"}));
        result.add(ModuleSpec.builder("purchase_orders", "购买申请", role).group("计费")
            .path("/api/admin/purchase-orders").paged().primary("order_no", "purchase_type", "id")
            .secondary("quantity", "amount", "status", "created_at")
            .create(action("提交购买申请", "POST", "/api/admin/purchase-orders",
                req("purchase_type", "购买类型"), integerRequired("quantity", "数量"), decimal("amount", "金额"), field("remark", "备注")))
            .build());
        result.add(ModuleSpec.builder("platform_feedbacks", "平台反馈", role).group("计费")
            .path("/api/admin/platform-feedbacks").paged().primary("title", "type", "id")
            .secondary("status", "reply", "created_at")
            .create(action("提交反馈", "POST", "/api/admin/platform-feedbacks",
                req("type", "类型"), req("title", "标题"), multilineRequired("content", "内容"))).build());

        result.add(ModuleSpec.builder("users", "用户账号", role).group("用户").requiresApp()
            .path("/api/admin/apps/{app_id}/users").paged().searchable("keyword")
            .primary("nickname", "account", "id").secondary("level_code", "balance", "document_credit", "status")
            .create(action("创建用户", "POST", "/api/admin/apps/{app_id}/users",
                req("account", "账号"), pwd("password", "密码"), field("nickname", "昵称"), field("email", "邮箱"), field("phone", "手机")))
            .action(itemAction("编辑资料", "PUT", "/api/admin/apps/{app_id}/users/{user_id}",
                field("email", "邮箱"), field("phone", "手机"), integer("status", "账号状态"),
                field("nickname", "昵称"), field("qq", "QQ"), field("signature", "个性签名"),
                field("avatar", "头像地址"), field("background", "背景地址"), field("gender", "性别"),
                field("title", "用户头衔"), bool("public_profile", "允许他人查看资料")))
            .action(itemAction("调整权益", "PUT", "/api/admin/apps/{app_id}/users/{user_id}/entitlement"))
            .action(itemAction("设置转账限制", "PUT", "/api/admin/apps/{app_id}/users/{user_id}/transfer-policy",
                bool("can_send", "允许转出"), bool("can_receive", "允许接收"),
                decimal("single_limit", "单笔最大金额"),
                decimal("daily_send_limit", "每日转出上限"), decimal("daily_receive_limit", "每日接收上限"),
                decimal("daily_pair_limit", "每日向同一用户转出上限"),
                json("blocked_send_to_user_ids", "禁止转给的用户编号"),
                json("blocked_receive_from_user_ids", "禁止接收的用户编号")))
            .action(itemAction("查看通信审计", "GET", "/api/admin/apps/{app_id}/users/{user_id}/communications",
                req("channel_type", "通信类型（private/group/service）"), integerRequired("channel_id", "会话或群聊编号")))
            .action(itemAction("重置密码", "PUT", "/api/admin/apps/{app_id}/users/{user_id}/password", pwd("new_password", "新密码")))
            .action(confirmItem("封禁", "POST", "/api/admin/apps/{app_id}/users/{user_id}/ban", false))
            .action(confirmItem("解封", "POST", "/api/admin/apps/{app_id}/users/{user_id}/unban", false))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/users/{user_id}", true)).build());
        result.add(ModuleSpec.builder("user_tags", "用户标签", role).group("用户").requiresApp()
            .path("/api/admin/apps/{app_id}/user-tags").dataKey("items").primary("name", "id").secondary("color", "created_at")
            .create(action("新增标签", "POST", "/api/admin/apps/{app_id}/user-tags", req("name", "标签名"), field("color", "颜色")))
            .action(itemAction("编辑", "PUT", "/api/admin/apps/{app_id}/user-tags/{tag_id}", field("name", "标签名"), field("color", "颜色")))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/user-tags/{tag_id}", true)).build());

        result.add(ModuleSpec.builder("documents", "文档管理", role).group("内容").requiresApp().screen(ScreenType.DOCUMENTS)
            .path("/api/admin/apps/{app_id}/documents").paged().searchable("keyword")
            .primary("title", "id").secondary("owner_type", "account", "word_count", "version_no", "updated_at")
            .create(action("新建管理员文档", "POST", "/api/admin/apps/{app_id}/documents",
                req("title", "标题"), multiline("content", "正文"), field("content_type", "内容类型"), bool("is_public", "公开")))
            .action(confirmItem("移入回收站", "DELETE", "/api/admin/apps/{app_id}/documents/{document_id}", true))
            .action(confirmItem("恢复文档", "POST", "/api/admin/apps/{app_id}/documents/{document_id}/restore", false)).build());
        result.add(simplePagedApp("document_shares", "文档分享", role, "内容", "/api/admin/apps/{app_id}/document-shares",
            new String[]{"share_code", "document_title", "id"}, new String[]{"user_account", "view_count", "expired_at", "status"}));
        result.add(crudModule("notices", "公告", role, "内容", "/api/admin/apps/{app_id}/notices", "notice_id", true,
            new FieldSpec[]{req("title", "标题"), multilineRequired("content", "内容"), field("type", "类型"),
                bool("display_enabled", "显示公告"), bool("is_popup", "弹窗显示"),
                field("popup_frequency", "弹窗频率 once/daily/login/always/none"),
                field("audience_type", "展示对象 all/vip/normal/user_ids/levels/tags"), json("audience", "对象值数组"),
                date("start_at", "开始时间"), date("end_at", "结束时间")},
            new String[]{"title", "type", "id"}, new String[]{"display_enabled", "is_popup", "popup_frequency", "audience_type", "status"}));
        result.add(ModuleSpec.builder("versions", "版本发布", role).group("内容").requiresApp()
            .path("/api/admin/apps/{app_id}/versions").dataKey("items").primary("version_name", "version_code", "id")
            .secondary("force_update", "status", "created_at")
            .create(action("发布版本", "PUT", "/api/admin/apps/{app_id}/versions",
                req("version_name", "版本名称"), integerRequired("version_code", "版本代码"),
                integer("min_supported_version_code", "最低可用版本"), req("apk_url", "安装包地址"),
                multiline("update_content", "更新内容"), bool("force_update", "强制更新")))
            .action(itemAction("编辑版本", "PUT", "/api/admin/apps/{app_id}/versions",
                req("version_name", "版本名称"), integerRequired("version_code", "版本号"),
                integer("min_supported_version_code", "最低支持版本"), req("apk_url", "安装包地址"),
                multiline("update_content", "更新说明"), bool("force_update", "是否强制更新"))).build());
        result.add(ModuleSpec.builder("maintenances", "用户端维护", role).group("内容").requiresApp()
            .path("/api/admin/apps/{app_id}/maintenances").dataKey("items")
            .primary("title", "id").secondary("forced", "starts_at", "ends_at", "status")
            .create(action("发布维护", "POST", "/api/admin/apps/{app_id}/maintenances",
                req("title", "维护标题"), multilineRequired("message", "维护说明"), bool("forced", "强制维护"),
                json("allowlist", "IP 白名单数组"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(itemAction("编辑维护", "PUT", "/api/admin/apps/{app_id}/maintenances/{policy_id}",
                req("title", "维护标题"), multilineRequired("message", "维护内容"), bool("forced", "是否强制维护"),
                json("allowlist", "免维护 IP 列表"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/maintenances/{policy_id}", true)).build());
        result.add(crudModule("banners", "轮播图", role, "内容", "/api/admin/apps/{app_id}/banners", "banner_id", true,
            new FieldSpec[]{req("title", "标题"), req("image_url", "图片地址"), field("link_url", "跳转地址"), field("position", "位置"), integer("sort_order", "排序")},
            new String[]{"title", "position", "id"}, new String[]{"image_url", "sort_order", "status"}));
        result.add(ModuleSpec.builder("remote_configs", "远程配置", role).group("内容").requiresApp()
            .path("/api/admin/apps/{app_id}/remote-configs").dataKey("items")
            .primary("config_key", "id").secondary("config_value", "value_type", "status", "updated_at")
            .create(action("保存远程配置", "PUT", "/api/admin/apps/{app_id}/remote-configs",
                req("config_key", "配置键"), multiline("config_value", "配置值"), field("value_type", "类型"),
                field("description", "说明"), integer("status", "状态"))).build());

        result.add(crudModule("resource_categories", "资源分类", role, "社区", "/api/admin/apps/{app_id}/resource-categories", "category_id", true,
            new FieldSpec[]{req("name", "分类名称"), field("description", "描述"), integer("sort_order", "排序")},
            new String[]{"name", "id"}, new String[]{"sort_order", "status"}));
        result.add(ModuleSpec.builder("resources", "资源审核", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/resources").paged().searchable("keyword")
            .primary("title", "id").secondary("user_account", "audit_status", "price_balance", "created_at")
            .action(itemAction("审核", "PUT", "/api/admin/apps/{app_id}/resources/{resource_id}/audit", req("audit_status", "审核状态"), field("audit_remark", "审核意见")))
            .action(itemAction("编辑", "PUT", "/api/admin/apps/{app_id}/resources/{resource_id}",
                integer("category_id", "分类编号"), field("title", "标题"), multiline("description", "描述"),
                field("cover_url", "封面地址"), field("download_url", "下载地址"), integer("price_balance", "余额价格"),
                bool("is_top", "置顶"), bool("is_recommended", "推荐"), integer("status", "状态")))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/resources/{resource_id}", true)).build());
        result.add(ModuleSpec.builder("store_apps", "应用商店", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/store-apps").paged().searchable("keyword")
            .primary("name", "version_name", "id").secondary("download_count", "price_balance", "status")
            .create(action("上架应用", "POST", "/api/admin/apps/{app_id}/store-apps",
                req("name", "应用名称"), req("package_name", "应用包名"), field("description", "描述"), req("version_name", "版本"), req("apk_url", "安装包地址"),
                integer("price_balance", "余额价格"), json("images", "截图列表"))).build());
        result.add(ModuleSpec.builder("store_categories", "商店分类", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/store-categories").dataKey("items")
            .primary("name", "id").secondary("sort_order", "status")
            .create(action("新增商店分类", "POST", "/api/admin/apps/{app_id}/store-categories",
                req("name", "分类名"), field("description", "描述"), integer("sort_order", "排序"))).build());
        result.add(ModuleSpec.builder("forum_plates", "论坛板块", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-plates").dataKey("items").primary("name", "id").secondary("sort_order", "status")
            .create(action("新建板块", "POST", "/api/admin/apps/{app_id}/forum-plates", req("name", "板块名"), field("description", "描述"), integer("sort_order", "排序")))
            .action(itemAction("编辑", "PUT", "/api/admin/apps/{app_id}/forum-plates/{plate_id}", field("name", "板块名"), field("description", "描述"), integer("sort_order", "排序"))).build());
        result.add(ModuleSpec.builder("forum_categories", "论坛二级分类", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-categories").dataKey("items")
            .primary("name", "plate_name", "id").secondary("post_count", "tag_count", "sort_order", "status")
            .create(action("新建二级分类", "POST", "/api/admin/apps/{app_id}/forum-categories",
                integerRequired("plate_id", "所属板块编号"), req("name", "分类名称"), multiline("description", "分类说明"), integer("sort_order", "排序")))
            .action(itemAction("编辑分类", "PUT", "/api/admin/apps/{app_id}/forum-categories/{category_id}",
                integer("plate_id", "所属板块编号"), field("name", "分类名称"), multiline("description", "分类说明"), integer("sort_order", "排序"), integer("status", "状态 1启用/0停用")))
            .action(confirmItem("删除分类", "DELETE", "/api/admin/apps/{app_id}/forum-categories/{category_id}", true)).build());
        result.add(ModuleSpec.builder("forum_tags", "论坛规范标签", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-tags").dataKey("items")
            .primary("name", "plate_name", "category_name", "id").secondary("description", "sort_order", "status")
            .create(action("新建标签", "POST", "/api/admin/apps/{app_id}/forum-tags",
                integerRequired("plate_id", "所属板块编号"), integer("category_id", "二级分类编号"), req("name", "标签名称"),
                multiline("description", "标签说明"), integer("sort_order", "排序")))
            .action(itemAction("编辑标签", "PUT", "/api/admin/apps/{app_id}/forum-tags/{tag_id}",
                integer("plate_id", "所属板块编号"), integer("category_id", "二级分类编号"), field("name", "标签名称"),
                multiline("description", "标签说明"), integer("sort_order", "排序"), integer("status", "状态 1启用/0停用")))
            .action(confirmItem("删除标签", "DELETE", "/api/admin/apps/{app_id}/forum-tags/{tag_id}", true)).build());
        result.add(ModuleSpec.builder("forum_structure_requests", "论坛建设与版主申请", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-structure-requests").paged()
            .primary("name", "nickname", "account", "id")
            .secondary("request_type", "plate_name", "category_name", "reason", "status", "review_comment", "created_at")
            .action(itemAction("通过申请", "POST", "/api/admin/apps/{app_id}/forum-structure-requests/{request_id}/review",
                multiline("review_comment", "审核说明，可留空")).fixed("decision", "approve"))
            .action(itemAction("拒绝申请", "POST", "/api/admin/apps/{app_id}/forum-structure-requests/{request_id}/review",
                multiline("review_comment", "未通过原因，可留空")).fixed("decision", "reject")).build());
        result.add(ModuleSpec.builder("forum_moderators", "论坛版主管理", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-moderators").dataKey("items")
            .primary("nickname", "account", "plate_name", "id")
            .secondary("uid", "status", "created_at", "updated_at")
            .action(itemAction("启用版主", "PUT", "/api/admin/apps/{app_id}/forum-moderators/{moderator_id}").fixed("status", "1"))
            .action(itemAction("停用版主", "PUT", "/api/admin/apps/{app_id}/forum-moderators/{moderator_id}").fixed("status", "0")).build());
        result.add(ModuleSpec.builder("forum_posts", "论坛帖子", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-posts").paged().searchable("keyword").primary("title", "id")
            .secondary("account", "audit_status_name", "audit_reason", "is_top", "is_essence", "is_locked", "created_at")
            .action(itemAction("审核帖子", "PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}/audit",
                req("audit_status", "审核结果 approved/rejected"), multiline("reason", "审核说明")))
            .action(itemAction("编辑帖子", "PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}",
                field("title", "帖子标题"), multiline("content", "帖子内容"), integer("plate_id", "板块编号"),
                integer("category_id", "二级分类编号，0 表示清除"), json("tags", "标签数组"), integer("status", "状态 1正常/0停用")))
            .action(itemAction("置顶设置", "PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}/top", bool("enabled", "置顶")))
            .action(itemAction("加精设置", "PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}/essence", bool("enabled", "加精")))
            .action(itemAction("锁定设置", "PUT", "/api/admin/apps/{app_id}/forum-posts/{post_id}/lock", bool("enabled", "锁定")))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/forum-posts/{post_id}", true)).build());
        result.add(ModuleSpec.builder("forum_comments", "论坛评论审核", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/forum-comments").paged().searchable("keyword")
            .primary("content", "nickname", "id").secondary("post_title", "audit_status_name", "audit_reason", "created_at")
            .action(itemAction("审核评论", "PUT", "/api/admin/apps/{app_id}/forum-comments/{comment_id}/audit",
                req("audit_status", "审核结果 approved/rejected"), multiline("reason", "审核说明")))
            .action(confirmItem("删除评论", "DELETE", "/api/admin/apps/{app_id}/forum-comments/{comment_id}", true)).build());
        result.add(ModuleSpec.builder("reports", "举报处理", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/reports").paged().primary("reason", "report_tag_name", "id")
            .secondary("target_type_name", "target_summary", "reporter_account", "status_name", "created_at")
            .action(itemAction("处理", "PUT", "/api/admin/apps/{app_id}/reports/{report_id}", req("status", "处理状态"), field("handle_remark", "处理意见"))).build());
        result.add(crudModule("forum_report_tags", "举报标签", role, "社区",
            "/api/admin/apps/{app_id}/forum-report-tags", "tag_id", true,
            new FieldSpec[]{req("name", "标签名称"), multiline("description", "标签说明"), integer("sort_order", "排序"), integer("status", "状态 1启用/0停用")},
            new String[]{"name", "id"}, new String[]{"description", "sort_order", "status"}));
        result.add(ModuleSpec.builder("admin_community", "管理员交流", role).group("社区")
            .path("/api/admin/community/posts").paged().searchable("keyword")
            .primary("title", "author_name", "id").secondary("like_count", "comment_count", "created_at")
            .create(action("发布交流帖", "POST", "/api/admin/community/posts", req("title", "标题"), multilineRequired("content", "内容"), json("attachments", "附件数组")))
            .action(itemAction("评论", "POST", "/api/admin/community/posts/{post_id}/comments", multilineRequired("content", "评论")))
            .action(itemAction("点赞或取消点赞", "POST", "/api/admin/community/posts/{post_id}/reactions").fixed("reaction_type", "like"))
            .action(itemAction("收藏或取消收藏", "POST", "/api/admin/community/posts/{post_id}/reactions").fixed("reaction_type", "favorite"))
            .action(confirmItem("删除", "DELETE", "/api/admin/community/posts/{post_id}", true)).build());
        result.add(ModuleSpec.builder("hierarchy_activities", "层级活动", role).group("社区")
            .path("/api/admin/activities").paged().searchable("keyword")
            .primary("title", "activity_type", "id")
            .secondary("total_balance", "remaining_balance", "remaining_slots", "status")
            .create(action("发布层级活动", "POST", "/api/admin/activities",
                req("activity_type", "类型 red_packet/lottery/bounty"), field("funding_mode", "资金方式").withDefault("balance"),
                req("title", "活动标题"), multiline("description", "活动说明"),
                integer("total_balance", "红包总余额"), integer("total_count", "红包份数"), field("packet_mode", "红包 equal/random").withDefault("random"),
                integer("reward_balance", "悬赏余额"), json("prizes", "抽奖奖项"),
                bool("audience_sync", "可见与参与同步").withDefault("true"),
                json("targets", "同步目标").withDefault("[{\"type\":\"level\",\"level\":4}]"),
                json("visibility_targets", "额外可见目标"), json("participation_targets", "可参与目标"),
                integer("per_actor_limit", "每账号次数").withDefault("1"), date("starts_at", "开始时间"), date("ends_at", "结束时间")))
            .action(itemAction("领取红包", "POST", "/api/admin/activities/{activity_id}/claim").confirm(false).idempotent())
            .action(itemAction("参与抽奖", "POST", "/api/admin/activities/{activity_id}/draw").confirm(false).idempotent())
            .action(itemAction("提交悬赏", "POST", "/api/admin/activities/{activity_id}/submit", multilineRequired("content", "投稿内容"), json("attachments", "附件列表")))
            .action(itemAction("选中投稿", "POST", "/api/admin/activities/{activity_id}/award", integerRequired("submission_id", "投稿 ID")))
            .action(confirmItem("结束活动", "POST", "/api/admin/activities/{activity_id}/close", false))
            .action(confirmItem("取消并退款", "POST", "/api/admin/activities/{activity_id}/cancel", false)).build());
        result.add(ModuleSpec.builder("poll_categories", "投票分类", role).group("社区")
            .path("/api/admin/poll-categories").dataKey("items").primary("name", "id")
            .secondary("target_level", "app_id", "poll_count", "status")
            .create(action("新增分类", "POST", "/api/admin/poll-categories", integer("target_level", "等级 3 或 4"), integer("app_id", "4 级 App ID"), req("name", "分类名"), field("color", "颜色"), integer("sort_order", "排序")))
            .action(itemAction("编辑", "PUT", "/api/admin/poll-categories/{category_id}", field("name", "分类名"), field("color", "颜色"), bool("enabled", "启用")))
            .action(confirmItem("删除", "DELETE", "/api/admin/poll-categories/{category_id}", true)).build());
        result.add(ModuleSpec.builder("polls", "分级投票", role).group("社区")
            .path("/api/admin/polls").paged().primary("title", "creator_name", "id")
            .secondary("target_level", "app_id", "category_names", "ballot_count", "status")
            .create(action("创建投票", "POST", "/api/admin/polls", integer("target_level", "等级 3 或 4"), integer("app_id", "4 级 App ID"),
                req("title", "标题"), multiline("description", "描述"), json("category_ids", "分类 ID 数组"), json("options", "选项数组"),
                bool("multiple_choice", "多选"), integer("min_select", "最少选择"), integer("max_select", "最多选择"),
                bool("allow_change", "允许改票"), field("result_visibility", "结果可见规则"), date("ends_at", "结束时间")))
            .action(confirmItem("关闭", "POST", "/api/admin/polls/{poll_id}/close", false))
            .action(confirmItem("删除", "DELETE", "/api/admin/polls/{poll_id}", true)).build());
        result.add(ModuleSpec.builder("bounties", "悬赏管理", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/bounties").paged().searchable("keyword")
            .primary("title", "creator_nickname", "id")
            .secondary("category_name", "audit_status_name", "audit_reason", "reward_balance", "attachment_count", "submission_count", "status", "deadline_at", "created_at")
            .action(itemAction("审核悬赏", "PUT", "/api/admin/apps/{app_id}/bounties/{bounty_id}/audit",
                req("audit_status", "审核结果 approved/rejected"), multiline("reason", "未通过原因，可留空")))
            .action(itemAction("编辑悬赏", "PUT", "/api/admin/apps/{app_id}/bounties/{bounty_id}",
                integer("category_id", "悬赏分类编号"), field("title", "悬赏标题"), multiline("description", "悬赏说明"), date("deadline_at", "截止时间")))
            .action(confirmItem("下架并退款", "POST", "/api/admin/apps/{app_id}/bounties/{bounty_id}/cancel", false))
            .action(confirmItem("删除悬赏", "DELETE", "/api/admin/apps/{app_id}/bounties/{bounty_id}", true))
            .build());
        result.add(ModuleSpec.builder("bounty_categories", "悬赏分类", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/bounty-categories").dataKey("items")
            .primary("name", "id").secondary("description", "bounty_count", "sort_order", "status")
            .create(action("新建悬赏分类", "POST", "/api/admin/apps/{app_id}/bounty-categories",
                req("name", "分类名称"), multiline("description", "分类说明"), integer("sort_order", "排序")))
            .action(itemAction("编辑分类", "PUT", "/api/admin/apps/{app_id}/bounty-categories/{category_id}",
                field("name", "分类名称"), multiline("description", "分类说明"), integer("sort_order", "排序"), integer("status", "状态 1启用/0停用")))
            .action(confirmItem("删除分类", "DELETE", "/api/admin/apps/{app_id}/bounty-categories/{category_id}", true)).build());
        result.add(ModuleSpec.builder("bounty_category_requests", "悬赏分类申请", role).group("社区").requiresApp()
            .path("/api/admin/apps/{app_id}/bounty-category-requests").paged()
            .primary("name", "nickname", "account", "id")
            .secondary("description", "reason", "status", "review_comment", "created_at")
            .action(itemAction("通过申请", "POST", "/api/admin/apps/{app_id}/bounty-category-requests/{request_id}/review",
                multiline("review_comment", "审核说明，可留空")).fixed("decision", "approve"))
            .action(itemAction("拒绝申请", "POST", "/api/admin/apps/{app_id}/bounty-category-requests/{request_id}/review",
                multiline("review_comment", "未通过原因，可留空")).fixed("decision", "reject")).build());

        result.add(ModuleSpec.builder("messages", "消息记录", role).group("沟通").requiresApp()
            .path("/api/admin/apps/{app_id}/messages").paged().primary("title", "content", "id")
            .secondary("sender_type", "receiver_account", "is_read", "created_at")
            .create(action("发送系统消息", "POST", "/api/admin/apps/{app_id}/system-messages",
                req("title", "标题"), multilineRequired("content", "内容"), integer("user_id", "指定用户 ID（可选）"))).build());
        result.add(ModuleSpec.builder("service_sessions", "客服会话", role).group("沟通").requiresApp()
            .path("/api/admin/apps/{app_id}/service-sessions").paged().primary("subject", "user_account", "id")
            .secondary("status", "last_message_at", "created_at")
            .action(itemAction("回复", "POST", "/api/admin/apps/{app_id}/service-sessions/{session_id}/reply", multilineRequired("content", "回复内容")))
            .action(confirmItem("关闭会话", "POST", "/api/admin/apps/{app_id}/service-sessions/{session_id}/close", false)).build());
        result.add(ModuleSpec.builder("chat_rooms", "用户群聊管理", role).group("沟通").requiresApp()
            .path("/api/admin/apps/{app_id}/chat-rooms").paged().searchable("keyword")
            .primary("name", "id").secondary("join_mode", "member_count", "message_count", "status")
            .create(action("创建群聊", "POST", "/api/admin/apps/{app_id}/chat-rooms",
                req("name", "群名称"), field("icon", "图标地址"), multiline("description", "群介绍"),
                field("join_mode", "加入模式 open/approval/invite").withDefault("open"), integer("max_members", "人数上限").withDefault("500"),
                bool("allow_member_invite", "允许成员邀请").withDefault("true"), bool("mute_all", "全员禁言"), multiline("announcement", "群公告")))
            .action(itemAction("编辑群资料", "PUT", "/api/admin/apps/{app_id}/chat-rooms/{room_id}",
                field("name", "群名称"), field("icon", "图标地址"), multiline("description", "群介绍"),
                field("join_mode", "加入模式 open/approval/invite"), integer("max_members", "人数上限"),
                bool("allow_member_invite", "允许成员邀请"), bool("mute_all", "全员禁言"), multiline("announcement", "群公告"), bool("status", "启用")))
            .action(itemAction("添加成员", "POST", "/api/admin/apps/{app_id}/chat-rooms/{room_id}/members",
                integerRequired("user_id", "用户 ID"), field("role", "角色 owner/admin/member").withDefault("member")))
            .action(confirmItem("解散群聊", "DELETE", "/api/admin/apps/{app_id}/chat-rooms/{room_id}", true)).build());

        result.add(ModuleSpec.builder("card_batches", "卡密批次", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/card-batches").paged().primary("name", "batch_no", "id")
            .secondary("card_type", "total_count", "used_count", "status")
            .create(action("生成卡密", "POST", "/api/admin/apps/{app_id}/card-batches",
                req("name", "批次名"), req("card_type", "卡密类型 mixed/direct/login"), integerRequired("total_count", "生成数量"),
                json("value_json", "发放内容，如余额、会员天数、文档额度"), integer("max_use", "每张最大使用次数"),
                date("expired_at", "统一到期时间"), field("prefix", "卡密前缀")))
            .action(itemAction("编辑批次", "PUT", "/api/admin/apps/{app_id}/card-batches/{batch_id}", field("name", "批次名"), integer("status", "状态"))).build());
        result.add(ModuleSpec.builder("cards", "卡密列表", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/cards").paged().primary("card_code", "id")
            .secondary("card_type", "batch_name", "used_count", "max_use", "status", "expired_at", "created_at")
            .action(itemAction("启用卡密", "PUT", "/api/admin/apps/{app_id}/cards/{card_id}", integer("status", "状态").withDefault("1")))
            .action(itemAction("停用卡密", "PUT", "/api/admin/apps/{app_id}/cards/{card_id}", integer("status", "状态").withDefault("0"))).build());
        result.add(simplePagedApp("card_redeem_logs", "卡密兑换记录", role, "资产", "/api/admin/apps/{app_id}/card-redeem-logs",
            new String[]{"card_code", "card_type", "id"}, new String[]{"user_account", "grant_summary", "created_at"}));
        result.add(ModuleSpec.builder("card_login_bindings", "登录卡设备绑定", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/card-login-bindings").paged().searchable("keyword")
            .primary("nickname", "account", "uid", "id")
            .secondary("card_code", "device_label", "status_name", "user_status_name", "bound_at", "last_login_at", "expired_at").build());
        result.add(simplePagedApp("orders", "支付订单", role, "资产", "/api/admin/apps/{app_id}/orders",
            new String[]{"order_no", "title", "id"}, new String[]{"user_account", "pay_amount", "status", "created_at"}));
        result.add(simplePagedApp("payments", "支付流水", role, "资产", "/api/admin/apps/{app_id}/payments",
            new String[]{"trade_no", "order_no", "id"}, new String[]{"channel", "amount", "status", "created_at"}));
        result.add(ModuleSpec.builder("withdrawals", "提现审核", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/withdrawals").paged().primary("account", "nickname", "id")
            .secondary("amount", "channel", "status", "created_at")
            .action(itemAction("审核", "POST", "/api/admin/apps/{app_id}/withdrawals/{withdrawal_id}/review",
                req("decision", "approve 或 reject"), field("remark", "审核说明"))).build());
        result.add(ModuleSpec.builder("payment_channels", "支付渠道", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/payment-channels").dataKey("items")
            .primary("name", "channel_code", "id").secondary("enabled", "updated_at")
            .create(action("保存支付渠道", "PUT", "/api/admin/apps/{app_id}/payment-channels",
                req("channel_code", "渠道编码"), field("name", "渠道名称"), bool("enabled", "启用"), json("config_json", "渠道配置"))).build());
        result.add(crudModule("shop_goods", "商城商品", role, "资产", "/api/admin/apps/{app_id}/shop-goods", "goods_id", true,
            new FieldSpec[]{req("name", "商品名"), field("description", "描述"), integer("price_balance", "余额价格"), decimal("price_money", "现金价格"), integer("stock", "库存")},
            new String[]{"name", "id"}, new String[]{"price_balance", "price_money", "stock", "status"}));
        result.add(simplePagedApp("red_packets", "红包记录", role, "资产", "/api/admin/apps/{app_id}/red-packets",
            new String[]{"title", "packet_no", "id"}, new String[]{"total_amount", "total_count", "claimed_count", "status"}));
        result.add(ModuleSpec.builder("lottery", "抽奖奖品", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/lottery-prizes").dataKey("items").primary("name", "id")
            .secondary("probability", "stock", "status")
            .create(action("新增奖品", "POST", "/api/admin/apps/{app_id}/lottery-prizes",
                req("name", "奖品名"), decimal("probability", "中奖概率"), integer("stock", "库存"), json("grant", "发放内容")))
            .action(confirmItem("删除奖品", "DELETE", "/api/admin/apps/{app_id}/lottery-prizes/{prize_id}", true)).build());
        result.add(ModuleSpec.builder("votes", "投票活动", role).group("资产").requiresApp()
            .path("/api/admin/apps/{app_id}/votes").paged().primary("title", "id").secondary("status", "end_at", "created_at")
            .create(action("创建投票", "POST", "/api/admin/apps/{app_id}/votes",
                req("title", "标题"), field("description", "描述"), json("options", "投票选项"),
                bool("multi_select", "允许多选"), integer("max_select", "最多选择"), date("end_at", "结束时间")))
            .action(confirmItem("结束投票", "POST", "/api/admin/apps/{app_id}/votes/{vote_id}/close", false)).build());

        result.add(ModuleSpec.builder("remote_files", "远程文件", role).group("文件").requiresApp()
            .path("/api/admin/apps/{app_id}/remote-files").paged().searchable("keyword").primary("name", "path", "id")
            .secondary("file_type", "size_bytes", "is_public", "updated_at")
            .create(action("新建文件", "POST", "/api/admin/apps/{app_id}/remote-files",
                req("name", "文件名"), multiline("content", "内容"), field("mime_type", "MIME 类型"), integer("parent_id", "父文件夹 ID"), bool("is_public", "公开")))
            .action(itemAction("编辑", "PUT", "/api/admin/apps/{app_id}/remote-files/{file_id}",
                field("name", "文件名"), multiline("content", "内容"), bool("is_public", "公开")))
            .action(confirmItem("删除", "DELETE", "/api/admin/apps/{app_id}/remote-files/{file_id}", true)).build());
        result.add(ModuleSpec.builder("feedbacks", "用户反馈", role).group("文件").requiresApp()
            .path("/api/admin/apps/{app_id}/feedbacks").paged().primary("title", "type", "id")
            .secondary("user_account", "status", "created_at")
            .action(itemAction("回复", "POST", "/api/admin/apps/{app_id}/feedbacks/{feedback_id}/reply", multilineRequired("reply", "回复内容"))).build());
        result.add(ModuleSpec.builder("uploads", "上传文件管理", role).group("文件").requiresApp()
            .screen(ScreenType.UPLOAD).path("/api/admin/apps/{app_id}/uploads").build());
        String botQaPath = "/api/admin/apps/{app_id}/bot-qa";
        FieldSpec[] botQaFields = new FieldSpec[]{
            req("question", "问题"), multilineRequired("answer", "答案"), field("keywords", "关键词"),
            integer("sort_order", "排序"), bool("status", "启用")
        };
        result.add(ModuleSpec.builder("bot_qa", "固定问答库", role).group("智能")
            .path(botQaPath).requiresApp().dataKey("items")
            .primary("question", "id").secondary("keywords", "status", "updated_at")
            .create(action("新增问答", "POST", botQaPath, botQaFields))
            .action(itemAction("编辑", "POST", botQaPath,
                integerRequired("id", "问答 ID"), req("question", "问题"),
                multilineRequired("answer", "答案"), field("keywords", "关键词"),
                integer("sort_order", "排序"), bool("status", "启用")))
            .action(confirmItem("删除", "DELETE", botQaPath + "/{qa_id}", true)).build());

        String aiKnowledgePath = "/api/admin/apps/{app_id}/ai-knowledge";
        FieldSpec[] aiKnowledgeFields = new FieldSpec[]{
            req("title", "知识标题"), multilineRequired("content", "知识正文"),
            field("keywords", "关键词（逗号分隔）"), field("source_url", "来源链接"),
            integer("priority", "优先级"), bool("status", "启用")
        };
        result.add(ModuleSpec.builder("ai_knowledge", "AI 综合知识库", role).group("智能")
            .path(aiKnowledgePath).requiresApp().paged().searchable("q").dataKey("items")
            .primary("title", "id").secondary("keywords", "priority", "status", "updated_at")
            .create(action("新增 AI 知识", "POST", aiKnowledgePath, aiKnowledgeFields))
            .action(itemAction("编辑", "PUT", aiKnowledgePath + "/{document_id}", aiKnowledgeFields))
            .action(confirmItem("删除", "DELETE", aiKnowledgePath + "/{document_id}", true)).build());

        result.add(simplePagedApp("api_logs", "接口日志", role, "审计", "/api/admin/apps/{app_id}/api-logs",
            new String[]{"method", "path", "id"}, new String[]{"actor_type", "http_status", "duration_ms", "created_at"}));
        result.add(simplePagedApp("operation_logs", "操作日志", role, "审计", "/api/admin/apps/{app_id}/operation-logs",
            new String[]{"module", "action", "id"}, new String[]{"target_type", "target_id", "created_at"}));
        result.add(special("profile", "管理员资料", role, "账户", ScreenType.PROFILE, "/api/admin/me"));
        return Collections.unmodifiableList(result);
    }

    private static List<ModuleSpec> userModules() {
        Role role = Role.USER;
        List<ModuleSpec> result = new ArrayList<>();
        result.add(special("home", "首页", role, "应用", ScreenType.USER_HOME, "/api/public/bootstrap"));
        result.add(special("documents", "我的笔记", role, "应用", ScreenType.DOCUMENTS, "/api/user/notes"));
        result.add(special("wallet", "资产与签到", role, "账户", ScreenType.WALLET, "/api/user/wallet"));
        result.add(simplePaged("wallet_logs", "余额账单", role, "账户", "/api/user/wallet/logs",
            new String[]{"scene_name", "remark", "id"}, new String[]{"asset_name", "change_value", "before_value", "after_value", "created_at"}));

        result.add(ModuleSpec.builder("resources", "资源大厅", role).group("发现")
            .path("/api/user/resources").paged().searchable("keyword").primary("title", "id")
            .secondary("category_name", "price_balance", "rating", "download_count")
            .create(action("投稿资源", "POST", "/api/user/resources",
                req("title", "标题"), multilineRequired("description", "描述"), integerRequired("category_id", "分类 ID"),
                req("download_url", "下载地址"), integer("price_balance", "余额价格")))
            .action(itemAction("购买", "POST", "/api/user/resources/{resource_id}/buy").confirm(false).idempotent())
            .action(itemAction("评论", "POST", "/api/user/resources/{resource_id}/comments", multilineRequired("content", "评论")))
            .action(itemAction("评分", "POST", "/api/user/resources/{resource_id}/rating", integerRequired("score", "评分 1-5")))
            .action(itemAction("点赞或取消点赞", "POST", "/api/user/resources/{resource_id}/reactions").fixed("reaction_type", "like"))
            .action(itemAction("收藏或取消收藏", "POST", "/api/user/resources/{resource_id}/reactions").fixed("reaction_type", "favorite")).build());
        result.add(ModuleSpec.builder("store_apps", "应用商店", role).group("发现")
            .path("/api/user/store-apps").paged().searchable("keyword")
            .primary("name", "version_name", "id").secondary("price_balance", "download_count", "updated_at")
            .action(itemAction("点赞或取消点赞", "POST", "/api/user/store-apps/{store_app_id}/reactions").fixed("reaction_type", "like"))
            .action(itemAction("收藏或取消收藏", "POST", "/api/user/store-apps/{store_app_id}/reactions").fixed("reaction_type", "favorite")).build());
        result.add(ModuleSpec.builder("bounties", "悬赏大厅", role).group("发现")
            .path("/api/user/bounties").paged().searchable("keyword").primary("title", "creator_nickname", "id")
            .secondary("reward_balance", "submission_count", "like_count", "favorite_count", "status")
            .create(action("发布悬赏", "POST", "/api/user/bounties", req("title", "标题"), multilineRequired("description", "说明"),
                integerRequired("reward_balance", "悬赏余额"), json("requirements", "悬赏要求"), date("deadline_at", "截止时间")))
            .action(itemAction("投稿", "POST", "/api/user/bounties/{bounty_id}/submissions", multilineRequired("content", "投稿内容"), json("attachments", "附件数组")))
            .action(itemAction("选中投稿", "POST", "/api/user/bounties/{bounty_id}/award", integerRequired("submission_id", "投稿 ID")))
            .action(itemAction("点赞或取消点赞", "POST", "/api/user/bounties/{bounty_id}/reactions").fixed("reaction_type", "like"))
            .action(itemAction("收藏或取消收藏", "POST", "/api/user/bounties/{bounty_id}/reactions").fixed("reaction_type", "favorite"))
            .action(confirmItem("取消悬赏", "POST", "/api/user/bounties/{bounty_id}/cancel", false)).build());
        result.add(ModuleSpec.builder("hierarchy_activities", "平台活动", role).group("发现")
            .path("/api/user/activities").paged().searchable("keyword")
            .primary("title", "activity_type", "id")
            .secondary("total_balance", "remaining_balance", "remaining_slots", "status", "ends_at")
            .action(itemAction("领取红包", "POST", "/api/user/activities/{activity_id}/claim").confirm(false).idempotent())
            .action(itemAction("参与抽奖", "POST", "/api/user/activities/{activity_id}/draw").confirm(false).idempotent())
            .action(itemAction("提交悬赏", "POST", "/api/user/activities/{activity_id}/submit", multilineRequired("content", "投稿内容"), json("attachments", "附件列表"))).build());
        result.add(ModuleSpec.builder("forum_posts", "论坛社区", role).group("发现")
            .path("/api/user/forum-posts").paged().searchable("keyword").primary("title", "id")
            .secondary("plate_name", "author_name", "like_count", "comment_count", "created_at")
            .create(action("发布帖子", "POST", "/api/user/forum-posts",
                integerRequired("plate_id", "板块 ID"), req("title", "标题"), multilineRequired("content", "内容")))
            .action(itemAction("编辑", "PUT", "/api/user/forum-posts/{post_id}", field("title", "标题"), multiline("content", "内容")))
            .action(itemAction("评论", "POST", "/api/user/forum-posts/{post_id}/comments", multilineRequired("content", "评论")))
            .action(itemAction("点赞或取消", "POST", "/api/user/forum-posts/{post_id}/like"))
            .action(itemAction("收藏或取消", "POST", "/api/user/forum-posts/{post_id}/favorite"))
            .action(itemAction("打赏", "POST", "/api/user/forum-posts/{post_id}/reward", integerRequired("balance", "打赏余额")))
            .action(itemAction("设置付费内容", "PUT", "/api/user/forum-posts/{post_id}/paid-content", integerRequired("price_balance", "余额价格"), multilineRequired("preview_content", "预览内容")))
            .action(itemAction("购买付费内容", "POST", "/api/user/forum-posts/{post_id}/paid-content/buy").idempotent())
            .action(confirmItem("删除", "DELETE", "/api/user/forum-posts/{post_id}", true)).build());
        result.add(simplePaged("my_forum_posts", "我发布的帖子", role, "发现", "/api/user/forum-posts/mine",
            new String[]{"title", "id"}, new String[]{"plate_name", "audit_status", "audit_reason", "like_count", "comment_count", "created_at"}));
        result.add(simplePaged("purchased_forum_posts", "已购买的帖子", role, "发现", "/api/user/forum-posts/purchased",
            new String[]{"title", "id"}, new String[]{"plate_name", "nickname", "like_count", "comment_count", "created_at"}));
        result.add(simplePaged("following_forum_posts", "关注用户的帖子", role, "发现", "/api/user/forum-posts/following",
            new String[]{"title", "id"}, new String[]{"plate_name", "nickname", "like_count", "comment_count", "created_at"}));
        result.add(simplePaged("liked_forum_posts", "点赞过的帖子", role, "发现", "/api/user/forum-posts/liked",
            new String[]{"title", "id"}, new String[]{"plate_name", "nickname", "like_count", "comment_count", "created_at"}));
        result.add(ModuleSpec.builder("forum_report_tags", "举报原因", role).group("发现")
            .path("/api/user/forum-report-tags").dataKey("items").primary("name", "id")
            .secondary("description", "sort_order").build());
        result.add(simplePaged("forum_reports", "我的举报", role, "发现", "/api/user/forum-reports",
            new String[]{"target_summary", "report_tag_name", "id"}, new String[]{"target_type_name", "reason", "status_name", "created_at"}));
        result.add(simple("forum_history", "浏览记录", role, "发现", "/api/user/forum-history",
            new String[]{"title", "id"}, new String[]{"nickname", "my_view_count", "last_viewed_at"}));
        result.add(ModuleSpec.builder("poll_categories", "投票分类", role).group("活动")
            .path("/api/user/poll-categories").dataKey("items").primary("name", "id")
            .secondary("poll_count", "status")
            .create(action("新增分类", "POST", "/api/user/poll-categories", req("name", "分类名"), field("color", "颜色"), integer("sort_order", "排序")))
            .action(itemAction("编辑", "PUT", "/api/user/poll-categories/{category_id}", field("name", "分类名"), field("color", "颜色"), bool("enabled", "启用")))
            .action(confirmItem("删除", "DELETE", "/api/user/poll-categories/{category_id}", true)).build());
        result.add(ModuleSpec.builder("polls", "投票活动", role).group("活动")
            .path("/api/user/polls").paged().primary("title", "creator_name", "id")
            .secondary("category_names", "ballot_count", "status", "ends_at")
            .create(action("创建投票", "POST", "/api/user/polls", req("title", "标题"), multiline("description", "描述"),
                json("category_ids", "分类 ID 数组"), json("options", "选项数组"), bool("multiple_choice", "多选"),
                integer("min_select", "最少选择"), integer("max_select", "最多选择"), bool("allow_change", "允许改票"),
                field("result_visibility", "结果可见 always/after_vote/after_end/creator_only"), date("ends_at", "结束时间")))
            .action(itemAction("提交投票", "POST", "/api/user/polls/{poll_id}/vote", json("option_ids", "选项 ID 数组")))
            .action(confirmItem("关闭", "POST", "/api/user/polls/{poll_id}/close", false))
            .action(confirmItem("删除", "DELETE", "/api/user/polls/{poll_id}", true)).build());

        result.add(special("notifications", "通知中心", role, "消息", ScreenType.NOTIFICATIONS, "/api/user/notifications"));
        result.add(simplePaged("conversations", "私信会话", role, "消息", "/api/user/conversations",
            new String[]{"peer_nickname", "peer_account", "id"}, new String[]{"last_message", "unread_count", "last_message_at"}));
        result.add(ModuleSpec.builder("user_search", "查找用户", role).group("消息")
            .path("/api/user/users/search").paged().searchable("keyword")
            .primary("nickname", "uid", "account").secondary("signature", "relation_name", "profile_visibility_name")
            .action(itemAction("查看公开资料", "GET", "/api/user/profiles/{user_id}"))
            .action(itemAction("发送好友申请", "POST", "/api/user/friends/requests",
                req("to_uid", "用户 UID"), field("message", "验证消息"))).build());
        result.add(ModuleSpec.builder("friends", "好友", role).group("消息")
            .path("/api/user/friends").paged().primary("nickname", "account", "id").secondary("signature", "created_at")
            .create(action("添加好友", "POST", "/api/user/friends/requests", req("to_uid", "用户 UID"), field("message", "验证消息")))
            .action(itemAction("发送私信", "POST", "/api/user/messages/private", integerRequired("to_user_id", "接收用户 ID"), multilineRequired("content", "消息内容")))
            .action(confirmItem("删除好友", "DELETE", "/api/user/friends/{friend_user_id}", true)).build());
        result.add(ModuleSpec.builder("friend_requests", "好友申请", role).group("消息")
            .path("/api/user/friends/requests").dataKey("items").primary("nickname", "account", "id")
            .secondary("direction", "message", "status", "created_at")
            .action(confirmItem("同意", "POST", "/api/user/friends/requests/{request_id}/accept", false))
            .action(confirmItem("拒绝", "POST", "/api/user/friends/requests/{request_id}/reject", false)).build());
        result.add(ModuleSpec.builder("chat_rooms", "聊天室", role).group("消息")
            .path("/api/user/chat-rooms").paged().searchable("keyword").primary("name", "id")
            .secondary("current_role", "join_mode", "member_count", "unread_count")
            .create(action("创建群聊", "POST", "/api/user/chat-rooms",
                req("name", "群名称"), field("icon", "图标地址"), multiline("description", "群介绍"),
                field("join_mode", "加入模式 open/approval/invite").withDefault("approval"), integer("max_members", "人数上限").withDefault("500"),
                bool("allow_member_invite", "允许成员邀请").withDefault("true"), multiline("announcement", "群公告")))
            .action(itemAction("申请或加入", "POST", "/api/user/chat-rooms/{room_id}/join", field("message", "申请说明")))
            .action(itemAction("编辑群资料", "PUT", "/api/user/chat-rooms/{room_id}",
                field("name", "群名称"), field("icon", "图标地址"), multiline("description", "群介绍"),
                field("join_mode", "加入模式 open/approval/invite"), integer("max_members", "人数上限"),
                bool("allow_member_invite", "允许成员邀请"), bool("mute_all", "全员禁言"), multiline("announcement", "群公告")))
            .action(itemAction("邀请成员", "POST", "/api/user/chat-rooms/{room_id}/invitations",
                integerRequired("user_id", "用户 ID"), field("message", "邀请说明"), date("expired_at", "到期时间")))
            .action(itemAction("转让群主", "POST", "/api/user/chat-rooms/{room_id}/transfer", integerRequired("new_owner_user_id", "新群主用户 ID")))
            .action(itemAction("查看群文件", "GET", "/api/user/chat-rooms/{room_id}/files"))
            .action(itemAction("上传群文件", "POST", "/api/user/chat-rooms/{room_id}/files", req("name", "文件名"), req("file_url", "文件地址"), integer("size_bytes", "文件大小")))
            .action(itemAction("查看群相册", "GET", "/api/user/chat-rooms/{room_id}/albums"))
            .action(itemAction("创建群相册", "POST", "/api/user/chat-rooms/{room_id}/albums", req("name", "相册名"), multiline("description", "说明")))
            .action(itemAction("查看群投票", "GET", "/api/user/chat-rooms/{room_id}/votes"))
            .action(itemAction("创建群投票", "POST", "/api/user/chat-rooms/{room_id}/votes", req("title", "标题"), json("options", "选项数组"), bool("multiple_choice", "多选"), integer("min_select", "最少选择"), integer("max_select", "最多选择")))
            .action(itemAction("查看群接龙", "GET", "/api/user/chat-rooms/{room_id}/solitaires"))
            .action(itemAction("创建群接龙", "POST", "/api/user/chat-rooms/{room_id}/solitaires", req("title", "接龙标题"), multiline("description", "说明"), json("fields", "接龙字段")))
            .action(confirmItem("退出群聊", "POST", "/api/user/chat-rooms/{room_id}/leave", false))
            .action(confirmItem("解散群聊", "DELETE", "/api/user/chat-rooms/{room_id}", true)).build());
        result.add(ModuleSpec.builder("dissolved_chat_rooms", "已解散的群聊", role).group("消息")
            .path("/api/user/chat-rooms/dissolved").paged().primary("name", "id")
            .secondary("member_count", "dissolved_at", "restore_until")
            .action(confirmItem("恢复群聊", "POST", "/api/user/chat-rooms/{room_id}/restore", false)).build());
        result.add(ModuleSpec.builder("chat_room_invitations", "群聊邀请", role).group("消息")
            .path("/api/user/chat-room-invitations").dataKey("items")
            .primary("room_name", "id").secondary("inviter_nickname", "inviter_account", "message", "status", "expired_at")
            .action(confirmItem("接受邀请", "POST", "/api/user/chat-room-invitations/{invitation_id}/accept", false))
            .action(confirmItem("拒绝邀请", "POST", "/api/user/chat-room-invitations/{invitation_id}/reject", false)).build());
        result.add(ModuleSpec.builder("service", "联系客服", role).group("消息")
            .path("/api/user/service/messages").paged().primary("content", "id").secondary("sender_type", "created_at")
            .create(action("发送消息", "POST", "/api/user/service/messages", multilineRequired("content", "消息内容"))).build());

        result.add(ModuleSpec.builder("card_redeem", "卡密兑换", role).group("资产")
            .path("/api/user/cards/redeem-logs").paged().primary("card_code_masked", "card_type", "id")
            .secondary("grant_summary", "created_at")
            .create(action("兑换卡密", "POST", "/api/user/cards/redeem", req("card_code", "卡密码")).idempotent()).build());
        result.add(ModuleSpec.builder("orders", "我的订单", role).group("资产")
            .path("/api/user/orders").paged().primary("order_no", "title", "id")
            .secondary("pay_amount", "status", "created_at")
            .action(confirmItem("取消订单", "POST", "/api/user/orders/{order_id}/cancel", false)).build());
        result.add(ModuleSpec.builder("shop_goods", "余额商城", role).group("资产")
            .path("/api/user/shop-goods").paged().primary("name", "id").secondary("price_integral", "price_money", "stock")
            .action(itemAction("购买", "POST", "/api/user/shop-goods/{goods_id}/buy", integer("quantity", "数量").withDefault("1")).confirm(false).idempotent())
            .action(itemAction("收藏或取消收藏", "POST", "/api/user/shop-goods/{goods_id}/reactions").fixed("reaction_type", "favorite")).build());
        result.add(ModuleSpec.builder("red_packets", "红包", role).group("资产")
            .path("/api/user/red-packets").paged().primary("title", "packet_no", "id")
            .secondary("total_amount", "remaining_count", "status")
            .create(action("发红包", "POST", "/api/user/red-packets",
                req("title", "标题"), decimal("total_amount", "总金额"), integerRequired("total_count", "份数")))
            .action(itemAction("领取", "POST", "/api/user/red-packets/{packet_id}/claim").confirm(false).idempotent()).build());
        result.add(ModuleSpec.builder("lottery", "抽奖", role).group("资产")
            .path("/api/user/lottery-prizes").dataKey("items").primary("name", "id").secondary("stock", "status")
            .create(action("立即抽奖", "POST", "/api/user/lottery/draw").idempotent()).build());
        result.add(ModuleSpec.builder("withdrawals", "余额提现", role).group("资产")
            .path("/api/user/withdrawals").dataKey("items").primary("channel", "account_name", "id")
            .secondary("amount", "status", "review_remark", "created_at")
            .create(action("申请提现", "POST", "/api/user/withdrawals", decimal("amount", "提现金额"), req("channel", "渠道"), req("account_name", "收款姓名"), req("account_no", "收款账号")))
            .action(confirmItem("取消提现", "POST", "/api/user/withdrawals/{withdrawal_id}/cancel", false)).build());

        result.add(simplePaged("remote_files", "远程文件", role, "工具", "/api/user/remote-files",
            new String[]{"name", "path", "id"}, new String[]{"file_type", "size_bytes", "updated_at"}));
        result.add(special("upload", "我的上传", role, "工具", ScreenType.UPLOAD, "/api/user/uploads"));
        result.add(ModuleSpec.builder("feedbacks", "意见反馈", role).group("工具")
            .path("/api/user/feedbacks").paged().primary("title", "type", "id").secondary("status", "reply", "created_at")
            .create(action("提交反馈", "POST", "/api/user/feedbacks",
                req("type", "类型"), req("title", "标题"), multilineRequired("content", "内容"), field("image_url", "图片地址"))).build());
        result.add(special("bot", "机器人问答", role, "工具", ScreenType.BOT, "/api/user/bot/ask"));
        result.add(simplePaged("rankings", "排行榜", role, "账户", "/api/user/rankings",
            new String[]{"nickname", "account", "rank"}, new String[]{"score", "experience", "level_code"}));
        result.add(simplePaged("invites", "邀请记录", role, "账户", "/api/user/invites",
            new String[]{"invitee_nickname", "invitee_account", "id"}, new String[]{"reward_integral", "created_at"}));
        result.add(simple("following", "我的关注", role, "账户", "/api/user/following",
            new String[]{"nickname", "account", "user_id"}, new String[]{"signature", "created_at"}));
        result.add(simple("followers", "我的粉丝", role, "账户", "/api/user/followers",
            new String[]{"nickname", "account", "user_id"}, new String[]{"signature", "created_at"}));
        result.add(ModuleSpec.builder("blacklist", "黑名单", role).group("账户")
            .path("/api/user/blacklist").dataKey("items").primary("nickname", "account", "user_id")
            .secondary("created_at")
            .action(confirmItem("移出黑名单", "POST", "/api/user/blacklist/{user_id}", false)).build());
        result.add(special("profile", "个人资料", role, "账户", ScreenType.PROFILE, "/api/user/me"));
        return Collections.unmodifiableList(result);
    }

    private static ModuleSpec special(String id, String title, Role role, String group, ScreenType type, String path) {
        return ModuleSpec.builder(id, title, role).group(group).screen(type).path(path).build();
    }

    private static ModuleSpec simple(String id, String title, Role role, String group, String path, String[] primary, String[] secondary) {
        return ModuleSpec.builder(id, title, role).group(group).path(path).primary(primary).secondary(secondary).build();
    }

    private static ModuleSpec simplePaged(String id, String title, Role role, String group, String path, String[] primary, String[] secondary) {
        return ModuleSpec.builder(id, title, role).group(group).path(path).paged().primary(primary).secondary(secondary).build();
    }

    private static ModuleSpec simplePagedApp(String id, String title, Role role, String group, String path, String[] primary, String[] secondary) {
        return ModuleSpec.builder(id, title, role).group(group).path(path).paged().requiresApp().primary(primary).secondary(secondary).build();
    }

    private static ModuleSpec crudModule(
        String id, String title, Role role, String group, String path, String idName, boolean requiresApp,
        FieldSpec[] fields, String[] primary, String[] secondary
    ) {
        ModuleSpec.Builder builder = ModuleSpec.builder(id, title, role).group(group).path(path)
            .dataKey("items").primary(primary).secondary(secondary)
            .create(action("新增" + title, "POST", path, fields))
            .action(itemAction("编辑", "PUT", path + "/{" + idName + "}", fields))
            .action(confirmItem("删除", "DELETE", path + "/{" + idName + "}", true));
        if (requiresApp) {
            builder.requiresApp();
        }
        return builder.build();
    }

    private static ActionSpec.Builder action(String title, String method, String path, FieldSpec... fields) {
        return ActionSpec.builder(title, method, path).fields(fields);
    }

    private static ActionSpec.Builder itemAction(String title, String method, String path, FieldSpec... fields) {
        return ActionSpec.builder(title, method, path).fields(fields).item();
    }

    private static ActionSpec confirmItem(String title, String method, String path, boolean destructive) {
        return ActionSpec.builder(title, method, path).item().confirm(destructive).build();
    }

    private static FieldSpec field(String key, String label) { return FieldSpec.of(key, label); }
    private static FieldSpec req(String key, String label) { return FieldSpec.required(key, label); }
    private static FieldSpec pwd(String key, String label) { return FieldSpec.typed(key, label, FieldType.PASSWORD, true); }
    private static FieldSpec integer(String key, String label) { return FieldSpec.typed(key, label, FieldType.INTEGER, false); }
    private static FieldSpec integerRequired(String key, String label) { return FieldSpec.typed(key, label, FieldType.INTEGER, true); }
    private static FieldSpec decimal(String key, String label) { return FieldSpec.typed(key, label, FieldType.DECIMAL, false); }
    private static FieldSpec bool(String key, String label) { return FieldSpec.typed(key, label, FieldType.BOOLEAN, false); }
    private static FieldSpec multiline(String key, String label) { return FieldSpec.typed(key, label, FieldType.MULTILINE, false); }
    private static FieldSpec multilineRequired(String key, String label) { return FieldSpec.typed(key, label, FieldType.MULTILINE, true); }
    private static FieldSpec date(String key, String label) { return FieldSpec.typed(key, label, FieldType.DATE_TIME, false); }
    private static FieldSpec json(String key, String label) { return FieldSpec.typed(key, label, FieldType.JSON, false); }
}
