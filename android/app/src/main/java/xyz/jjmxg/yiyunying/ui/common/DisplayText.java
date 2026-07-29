package xyz.jjmxg.yiyunying.ui.common;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;

public final class DisplayText {
    private static final Map<String, String> LABELS;

    static {
        Map<String, String> labels = new LinkedHashMap<>();
        labels.put("id", "记录编号");
        labels.put("account", "账号");
        labels.put("nickname", "昵称");
        labels.put("name", "名称");
        labels.put("title", "标题");
        labels.put("status", "状态");
        labels.put("created_at", "创建时间");
        labels.put("updated_at", "更新时间");
        labels.put("expired_at", "到期时间");
        labels.put("membership_expired_at", "会员到期");
        labels.put("membership_status", "会员状态");
        labels.put("membership_level", "会员等级");
        labels.put("app_key", "应用标识");
        labels.put("platform_key", "平台标识");
        labels.put("integral", "余额");
        labels.put("activity_credit", "活动币");
        labels.put("experience", "经验");
        labels.put("balance", "余额");
        labels.put("document_credit", "笔记额度");
        labels.put("price_balance", "余额价格");
        labels.put("pay_amount", "支付金额");
        labels.put("total_integral", "余额合计");
        labels.put("total_balance", "总余额");
        labels.put("remaining_balance", "剩余余额");
        labels.put("reward_balance", "奖励余额");
        labels.put("activity_type", "活动类型");
        labels.put("funding_mode", "资金方式");
        labels.put("remaining_slots", "剩余名额");
        labels.put("stock", "库存");
        labels.put("user_count", "用户数");
        labels.put("word_count", "字数");
        labels.put("owner_type", "归属");
        labels.put("current_role", "我的角色");
        labels.put("role", "角色");
        labels.put("join_mode", "加入方式");
        labels.put("join_mode_text", "加入方式");
        labels.put("join_label", "当前状态");
        labels.put("status_text", "当前状态");
        labels.put("group_number", "群号");
        labels.put("members", "群成员");
        labels.put("max_members", "群容量");
        labels.put("member_count", "成员数");
        labels.put("message_count", "消息数");
        labels.put("pending_request_count", "待审申请");
        labels.put("allow_member_invite", "成员可邀请");
        labels.put("mute_all", "全员禁言");
        labels.put("announcement", "群公告");
        labels.put("version_no", "文档版本");
        labels.put("version_name", "版本");
        labels.put("version_code", "版本代码");
        labels.put("content", "内容");
        labels.put("description", "描述");
        labels.put("reason", "原因");
        labels.put("reply", "回复");
        labels.put("ip", "IP");
        labels.put("result", "结果");
        labels.put("http_status", "HTTP 状态");
        labels.put("duration_ms", "耗时");
        labels.put("last_login_at", "最近登录");
        labels.put("last_message_at", "最近消息");
        labels.put("last_viewed_at", "最近查看");
        labels.put("favorited_at", "收藏时间");
        labels.put("unread_count", "未读");
        labels.put("is_public", "公开");
        labels.put("is_top", "置顶");
        labels.put("is_essence", "加精");
        labels.put("is_locked", "锁定");
        labels.put("parent_id", "上级编号");
        labels.put("platform_id", "平台编号");
        labels.put("admin_id", "管理员编号");
        labels.put("app_id", "应用编号");
        labels.put("user_id", "用户编号");
        labels.put("public_no", "UID");
        labels.put("uid", "UID");
        labels.put("peer_user_id", "对方用户编号");
        labels.put("actor_id", "操作对象编号");
        labels.put("actor_level", "账号层级");
        labels.put("level", "账号层级");
        labels.put("admin_quota", "管理员额度");
        labels.put("app_quota", "应用额度");
        labels.put("remote_document_quota", "远程文档额度");
        labels.put("unlimited", "总控无限权益");
        labels.put("membership_unlimited", "会员期限");
        labels.put("balance_unlimited", "余额额度");
        labels.put("app_quota_unlimited", "应用额度限制");
        labels.put("document_quota_unlimited", "文档额度限制");
        labels.put("permissions", "权限配置");
        labels.put("settings", "规则配置");
        labels.put("features", "功能开关");
        labels.put("config", "详细配置");
        labels.put("grant", "赠送权益");
        labels.put("counts", "数量统计");
        labels.put("summary", "汇总数据");
        labels.put("scope", "管理范围");
        labels.put("pagination", "分页信息");
        labels.put("items", "内容列表");
        labels.put("type", "类型");
        labels.put("scope_type", "会话类型");
        labels.put("scope_name", "会话名称");
        labels.put("conversation_name", "会话名称");
        labels.put("favorite_type", "收藏类型");
        labels.put("source_type", "来源类型");
        labels.put("source_name", "来源名称");
        labels.put("owner_name", "所属用户");
        labels.put("message_id", "消息编号");
        labels.put("sent_at", "发送时间");
        labels.put("message", "消息");
        labels.put("last_message", "最近消息");
        labels.put("last_message_id", "最近消息编号");
        labels.put("peer_account", "对方账号");
        labels.put("peer_name", "对方昵称");
        labels.put("is_friend", "好友关系");
        labels.put("is_stranger", "陌生人会话");
        labels.put("accept_stranger_messages", "接收陌生人消息");
        labels.put("system_notification_enabled", "系统通知");
        labels.put("private_notification_enabled", "私聊通知");
        labels.put("group_notification_enabled", "群聊通知");
        labels.put("enabled", "是否启用");
        labels.put("forced", "是否强制");
        labels.put("locked", "是否锁定");
        labels.put("source", "规则来源");
        labels.put("remark", "备注");
        labels.put("account_name", "账户名称");
        labels.put("account_no", "账户号码");
        labels.put("phone", "手机号码");
        labels.put("email", "邮箱");
        labels.put("avatar", "头像");
        labels.put("file_url", "文件地址");
        labels.put("preview_url", "预览地址");
        labels.put("original_name", "文件名称");
        labels.put("mime_type", "文件格式");
        labels.put("size_bytes", "文件大小");
        labels.put("file_category", "文件分类");
        labels.put("file_category_name", "文件分类");
        labels.put("scene", "使用场景");
        labels.put("tags", "标签");
        labels.put("tags_json", "标签");
        labels.put("like_count", "点赞数");
        labels.put("comment_count", "评论数");
        labels.put("view_count", "浏览数");
        labels.put("sender_type", "发送者类型");
        labels.put("content_type", "内容类型");
        labels.put("direction", "消息方向");
        labels.put("audit_status", "审核状态");
        labels.put("notification_type", "通知类型");
        labels.put("popup_frequency", "显示频率");
        labels.put("order_type", "订单来源");
        labels.put("profile_visibility", "资料可见范围");
        labels.put("can_delete", "允许删除");
        labels.put("can_preview", "允许预览");
        labels.put("recalled", "已撤回");
        labels.put("voted", "已经投票");
        labels.put("signature", "个性签名");
        labels.put("qq", "QQ");
        labels.put("register_ip", "注册 IP");
        labels.put("last_login_ip", "最近登录 IP");
        labels.put("deleted_at", "删除时间");
        labels.put("start_at", "开始时间");
        labels.put("end_at", "结束时间");
        labels.put("starts_at", "开始时间");
        labels.put("ends_at", "结束时间");
        labels.put("access_start_time", "允许开始时间");
        labels.put("access_end_time", "允许结束时间");
        labels.put("allowed_weekdays", "允许使用星期");
        labels.put("app_count", "应用数量");
        labels.put("admin_count", "管理员数量");
        labels.put("document_count", "文档数量");
        labels.put("total", "总数");
        labels.put("page", "当前页");
        labels.put("limit", "每页数量");
        labels.put("total_pages", "总页数");
        labels.put("target_type", "目标类型");
        labels.put("target_id", "目标编号");
        labels.put("target_level", "目标层级");
        labels.put("effect", "规则效果");
        labels.put("priority", "优先级");
        labels.put("force_update", "强制更新");
        labels.put("download_url", "下载地址");
        labels.put("release_notes", "更新说明");
        labels.put("data", "详细内容");
        labels.put("payload", "详细内容");
        labels.put("metadata", "补充信息");
        labels.put("snapshot", "内容快照");
        labels.put("detail", "详情");
        labels.put("order_id", "订单编号");
        labels.put("order_no", "订单号");
        labels.put("purchase_type", "购买类型");
        labels.put("product_id", "商品编号");
        labels.put("product_name", "商品名称");
        labels.put("goods_id", "商品编号");
        labels.put("goods_name", "商品名称");
        labels.put("unit_price", "商品单价");
        labels.put("price", "价格");
        labels.put("quantity", "数量");
        labels.put("amount", "金额");
        labels.put("total_amount", "总金额");
        labels.put("discount_amount", "优惠金额");
        labels.put("paid_amount", "实付金额");
        labels.put("refund_amount", "退款金额");
        labels.put("currency", "币种");
        labels.put("payment_method", "支付方式");
        labels.put("payment_status", "支付状态");
        labels.put("order_status", "订单状态");
        labels.put("refund_status", "退款状态");
        labels.put("fulfillment_status", "履约状态");
        labels.put("shipping_status", "配送状态");
        labels.put("paid_at", "支付时间");
        labels.put("refunded_at", "退款时间");
        labels.put("cancelled_at", "取消时间");
        labels.put("recipient_name", "收货人");
        labels.put("recipient_phone", "联系电话");
        labels.put("recipient_address", "收货地址");
        labels.put("tracking_no", "物流单号");
        labels.put("packet_id", "红包编号");
        labels.put("packet_type", "红包类型");
        labels.put("packet_label", "红包说明");
        labels.put("claim_mode", "领取方式");
        labels.put("distribution_mode", "分配方式");
        labels.put("total_count", "红包份数");
        labels.put("packet_count", "红包份数");
        labels.put("remaining_count", "剩余份数");
        labels.put("claimed_count", "已领取份数");
        labels.put("returned_count", "已退回份数");
        labels.put("remain_amount", "剩余金额");
        labels.put("claimed_amount", "已领取金额");
        labels.put("claim_amount", "领取金额");
        labels.put("returned_amount", "已退回金额");
        labels.put("can_claim", "可以领取");
        labels.put("can_return", "可以退回");
        labels.put("is_luckiest", "运气王");
        labels.put("claims", "领取记录");
        labels.put("returns", "退回记录");
        labels.put("claimed_at", "领取时间");
        labels.put("returned_at", "退回时间");
        labels.put("sender_id", "发送人编号");
        labels.put("sender_name", "发送人");
        labels.put("receiver_id", "接收人编号");
        labels.put("receiver_name", "接收人");
        labels.put("gift_id", "礼物编号");
        labels.put("gift_name", "礼物名称");
        labels.put("accepted_at", "接收时间");
        labels.put("poll_id", "投票编号");
        labels.put("poll_type", "投票类型");
        labels.put("multiple_choice", "允许多选");
        labels.put("results_visible", "结果可见");
        labels.put("result_visibility", "结果可见范围");
        labels.put("min_select", "最少选择");
        labels.put("max_select", "最多选择");
        labels.put("participant_count", "参与人数");
        labels.put("vote_count", "票数");
        labels.put("ballot_count", "投票人数");
        labels.put("option_id", "选项编号");
        labels.put("option_text", "选项内容");
        labels.put("option_name", "选项名称");
        labels.put("options", "投票选项");
        labels.put("selected_option_ids", "已选选项");
        labels.put("categories", "分类");
        labels.put("category", "分类");
        labels.put("category_id", "分类编号");
        labels.put("category_name", "分类名称");
        labels.put("scene_name", "业务类型");
        labels.put("direction_name", "收支方向");
        labels.put("amount_text", "变动金额");
        labels.put("before_value", "变动前");
        labels.put("after_value", "变动后");
        labels.put("asset_name", "资产类型");
        labels.put("trace_no", "资金流水号");
        labels.put("reference_no", "关联业务");
        labels.put("dimensions", "媒体尺寸");
        labels.put("width", "宽度");
        labels.put("height", "高度");
        labels.put("resource_id", "资源编号");
        labels.put("resource_type", "资源类型");
        labels.put("file_name", "文件名称");
        labels.put("original_name", "原始文件名");
        labels.put("file_category", "文件分类");
        labels.put("mime_type", "文件格式");
        labels.put("size_bytes", "文件大小");
        labels.put("uploader_id", "上传人编号");
        labels.put("uploader_name", "上传人");
        labels.put("uploaded_at", "上传时间");
        labels.put("platform_type", "适用平台");
        labels.put("platform", "适用平台");
        labels.put("language", "开发语言");
        labels.put("package_name", "应用包名");
        labels.put("bundle_id", "应用标识");
        labels.put("publisher", "发布者");
        labels.put("author", "作者");
        labels.put("license", "授权方式");
        labels.put("download_count", "下载次数");
        labels.put("purchase_count", "购买次数");
        labels.put("review_status", "审核状态");
        labels.put("audit_reason", "审核说明");
        labels.put("audit_remark", "审核意见");
        labels.put("review_comment", "审核意见");
        labels.put("reviewed_at", "审核时间");
        labels.put("reviewer_name", "审核人");
        labels.put("audience_type", "展示范围");
        labels.put("display_enabled", "允许展示");
        labels.put("is_popup", "弹窗提醒");
        labels.put("maintenance_status", "维护状态");
        labels.put("maintenance_title", "维护标题");
        labels.put("maintenance_content", "维护内容");
        labels.put("update_status", "更新状态");
        labels.put("old_version", "当前版本");
        labels.put("new_version", "目标版本");
        labels.put("notification_id", "通知编号");
        labels.put("related_type", "关联内容");
        labels.put("related_id", "关联内容编号");
        labels.put("read_at", "已读时间");
        labels.put("is_read", "已经阅读");
        labels.put("comments", "评论");
        labels.put("replies", "回复");
        labels.put("recipients", "接收对象");
        labels.put("participants", "参与用户");
        labels.put("attachments", "附件");
        labels.put("media_attachments", "媒体附件");
        LABELS = Collections.unmodifiableMap(labels);
    }

    private DisplayText() {
    }

    public static String label(String key) {
        if (key == null) return "";
        String known = LABELS.get(key);
        if (known != null) return known;
        String normalized = key.endsWith("_json") ? key.substring(0, key.length() - 5) : key;
        if (containsCjk(normalized)) return normalized;
        String translated = translateTokens(normalized);
        return translated.isEmpty() ? "其他信息" : translated;
    }

    public static String value(JsonElement element) {
        if (element == null || element.isJsonNull()) return "-";
        if (element.isJsonPrimitive()) {
            if (element.getAsJsonPrimitive().isBoolean()) return element.getAsBoolean() ? "是" : "否";
            String value = element.getAsString();
            return value.isEmpty() ? "-" : value;
        }
        if (element.isJsonArray()) return element.getAsJsonArray().size() + " 项";
        return "查看详情";
    }

    public static String first(JsonObject object, Iterable<String> keys) {
        if (object == null) return "";
        for (String key : keys) {
            if (object.has(key) && !object.get(key).isJsonNull()) {
                String value = value(object.get(key));
                if (!value.isEmpty() && !"-".equals(value)) return value;
            }
        }
        return "";
    }

    public static String status(JsonElement value) {
        if (value == null || value.isJsonNull()) return "";
        String raw = value(value).trim().toLowerCase(Locale.ROOT);
        switch (raw) {
            case "1": case "enabled": return "已启用";
            case "0": case "disabled": case "inactive": return "已停用";
            case "-1": case "deleted": return "已删除";
            case "active": case "open": case "running": return "进行中";
            case "normal": return "正常";
            case "pending": return "待处理";
            case "processing": case "reviewing": return "处理中";
            case "completed": case "finished": case "done": return "已完成";
            case "paid": return "已支付";
            case "unpaid": return "待支付";
            case "approved": case "passed": return "审核通过";
            case "rejected": return "审核未通过";
            case "failed": return "处理失败";
            case "banned": case "blocked": return "已封禁";
            case "cancelled": case "canceled": return "已取消";
            case "expired": return "已过期";
            case "closed": return "已关闭";
            case "locked": return "已锁定";
            case "claimed": case "received": case "accepted": return "已领取";
            case "partially_claimed": return "部分领取";
            case "returned": return "已退回";
            case "refunded": return "已退款";
            case "partially_refunded": return "部分退款";
            case "shipped": return "已发货";
            case "delivered": return "已送达";
            case "ringing": return "呼叫中";
            case "missed": return "未接听";
            case "ended": return "已结束";
            default:
                return value.getAsString();
        }
    }

    public static String eventLabel(String raw) {
        if (raw == null) return "业务记录";
        String value = raw.trim();
        if (value.isEmpty()) return "业务记录";
        String normalized = value.toLowerCase(Locale.ROOT)
            .replace('-', '_').replace(' ', '_').replace("·", "_");
        Map<String, String> events = new LinkedHashMap<>();
        events.put("sign", "每日签到");
        events.put("user_sign", "每日签到");
        events.put("signin", "每日签到");
        events.put("wallet_transfer", "余额转账");
        events.put("wallet_transfer_in", "收到余额转账");
        events.put("wallet_transfer_out", "发出余额转账");
        events.put("transfer_in", "收到余额转账");
        events.put("transfer_out", "发出余额转账");
        events.put("card_redeem", "卡密兑换");
        events.put("redeem", "兑换权益");
        events.put("purchase", "购买记录");
        events.put("document_credit", "笔记额度变动");
        events.put("vip_days", "会员期限变动");
        events.put("membership", "会员权益变动");
        events.put("balance", "余额变动");
        events.put("experience", "经验变动");
        events.put("forum_post", "发布帖子");
        events.put("forum_comment", "论坛评论");
        events.put("forum_like", "论坛点赞");
        events.put("forum_favorite", "收藏帖子");
        events.put("note_create", "发布笔记");
        events.put("note_update", "修改笔记");
        events.put("red_packet", "红包记录");
        events.put("gift", "礼物记录");
        events.put("lottery", "抽奖记录");
        events.put("bounty", "悬赏记录");
        String direct = events.get(normalized);
        if (direct != null) return direct;
        for (Map.Entry<String, String> entry : events.entrySet()) {
            if (normalized.contains(entry.getKey())) return entry.getValue();
        }
        String translated = translateTokens(normalized);
        return translated.isEmpty() ? "业务记录" : translated;
    }

    public static String fieldValue(String key, JsonElement value) {
        String normalizedKey = key == null ? "" : key.trim().toLowerCase(Locale.ROOT);
        String raw = value(value);
        String normalizedRaw = raw.trim().toLowerCase(Locale.ROOT);
        if (isStatusField(normalizedKey)) return statusForKey(normalizedKey, value, normalizedRaw);
        if ("sender_type".equals(normalizedKey)) {
            if ("platform".equals(normalizedRaw)) return "平台";
            if ("admin".equals(normalizedRaw)) return "管理员";
            if ("user".equals(normalizedRaw)) return "用户";
            if ("system".equals(normalizedRaw)) return "系统";
        }
        if ("content_type".equals(normalizedKey)) {
            if ("text".equals(normalizedRaw)) return "文字";
            if ("image".equals(normalizedRaw)) return "图片";
            if ("gif".equals(normalizedRaw)) return "GIF 动图";
            if ("motion_photo".equals(normalizedRaw)) return "动态照片";
            if ("video".equals(normalizedRaw)) return "视频";
            if ("audio".equals(normalizedRaw)) return "音频";
            if ("voice".equals(normalizedRaw)) return "语音";
            if ("file".equals(normalizedRaw)) return "文件";
            if ("sticker".equals(normalizedRaw)) return "表情包";
            if ("mixed".equals(normalizedRaw)) return "图文消息";
            if ("forward".equals(normalizedRaw)) return "合并转发";
            if ("recall".equals(normalizedRaw)) return "撤回提示";
        }
        if ("direction".equals(normalizedKey)) {
            if ("incoming".equals(normalizedRaw) || "in".equals(normalizedRaw)) return "收到";
            if ("outgoing".equals(normalizedRaw) || "out".equals(normalizedRaw)) return "发出";
        }
        if ("popup_frequency".equals(normalizedKey)) {
            if ("once".equals(normalizedRaw)) return "仅显示一次";
            if ("daily".equals(normalizedRaw)) return "每天显示一次";
            if ("login".equals(normalizedRaw)) return "每次登录显示";
            if ("always".equals(normalizedRaw)) return "每次进入显示";
            if ("none".equals(normalizedRaw)) return "不主动显示";
        }
        if ("order_type".equals(normalizedKey) || "purchase_type".equals(normalizedKey)) {
            if ("shop_goods".equals(normalizedRaw) || "shop".equals(normalizedRaw)) return "商城商品";
            if ("balance_store".equals(normalizedRaw) || "balance_shop".equals(normalizedRaw)) return "余额商城";
            if ("resource".equals(normalizedRaw) || "source_store".equals(normalizedRaw)) return "源码商城";
            if ("store_app".equals(normalizedRaw) || "app_store".equals(normalizedRaw)) return "应用商店";
            if ("document_credit".equals(normalizedRaw)) return "笔记额度";
            if ("vip".equals(normalizedRaw) || "membership".equals(normalizedRaw)) return "会员购买";
            if ("balance_recharge".equals(normalizedRaw) || "recharge".equals(normalizedRaw)) return "余额充值";
            if ("forum_post".equals(normalizedRaw)) return "付费帖子";
        }
        if ("file_category".equals(normalizedKey) || "resource_type".equals(normalizedKey)) {
            if ("image".equals(normalizedRaw)) return "图片";
            if ("video".equals(normalizedRaw)) return "视频";
            if ("audio".equals(normalizedRaw)) return "音频";
            if ("document".equals(normalizedRaw)) return "文档";
            if ("archive".equals(normalizedRaw)) return "压缩包";
            if ("source".equals(normalizedRaw) || "source_code".equals(normalizedRaw)) return "源码";
            if ("application".equals(normalizedRaw) || "app".equals(normalizedRaw)) return "应用软件";
            if ("other".equals(normalizedRaw)) return "其他文件";
        }
        if ("profile_visibility".equals(normalizedKey)) {
            if ("full".equals(normalizedRaw)) return "展示完整资料";
            if ("basic".equals(normalizedRaw)) return "仅展示基础资料";
            if ("private".equals(normalizedRaw)) return "不对外展示";
        }
        if ("gender".equals(normalizedKey)) {
            if ("male".equals(normalizedRaw) || "1".equals(normalizedRaw)) return "男";
            if ("female".equals(normalizedRaw) || "2".equals(normalizedRaw)) return "女";
            if ("unknown".equals(normalizedRaw) || "0".equals(normalizedRaw)) return "未设置";
        }
        if (isSemanticTypeField(normalizedKey)) {
            String semanticType = semanticType(normalizedRaw);
            if (!semanticType.isEmpty()) return semanticType;
        }
        if ("role".equals(normalizedKey) || "current_role".equals(normalizedKey)) {
            if ("platform_owner".equals(normalizedRaw)) return "总控";
            if ("owner".equals(normalizedRaw) || "group_owner".equals(normalizedRaw)) return "群主";
            if ("authorized_platform".equals(normalizedRaw) || "platform".equals(normalizedRaw)) return "授权平台";
            if ("admin".equals(normalizedRaw)) return "管理员";
            if ("moderator".equals(normalizedRaw)) return "版主";
            if ("member".equals(normalizedRaw) || "user".equals(normalizedRaw)) return "普通用户";
        }
        if ("join_mode".equals(normalizedKey)) {
            if ("open".equals(normalizedRaw)) return "开放加入";
            if ("approval".equals(normalizedRaw)) return "审核加入";
            if ("invite".equals(normalizedRaw)) return "仅限邀请";
        }
        if ("activity_type".equals(normalizedKey)) {
            if ("red_packet".equals(normalizedRaw)) return "红包";
            if ("lottery".equals(normalizedRaw)) return "抽奖";
            if ("bounty".equals(normalizedRaw)) return "悬赏";
            if ("poll".equals(normalizedRaw)) return "投票";
            if ("exchange".equals(normalizedRaw)) return "兑换";
        }
        if (normalizedKey.endsWith("_unlimited")) return booleanValue(raw) ? "无限" : "按规则限制";
        if ("level".equals(normalizedKey) || "actor_level".equals(normalizedKey) || "target_level".equals(normalizedKey)) {
            if ("1".equals(normalizedRaw)) return "一级总控";
            if ("2".equals(normalizedRaw)) return "二级授权平台";
            if ("3".equals(normalizedRaw)) return "三级管理员";
            if ("4".equals(normalizedRaw)) return "四级用户";
        }
        if ("membership_level".equals(normalizedKey)) {
            if ("owner".equals(normalizedRaw)) return "总控永久会员";
            if ("trial".equals(normalizedRaw)) return "试用会员";
            if ("vip".equals(normalizedRaw)) return "VIP 会员";
            if ("svip".equals(normalizedRaw)) return "SVIP 会员";
            if ("authorized".equals(normalizedRaw)) return "授权会员";
        }
        if ("membership_status".equals(normalizedKey)) {
            if ("active".equals(normalizedRaw)) return "有效";
            if ("frozen".equals(normalizedRaw)) return "已冻结";
            if ("expired".equals(normalizedRaw)) return "已到期";
        }
        if ("funding_mode".equals(normalizedKey)) {
            if ("balance".equals(normalizedRaw)) return "余额预付";
            if ("issued".equals(normalizedRaw)) return "平台发放";
        }
        String enumValue = enumValue(normalizedKey, normalizedRaw);
        if (!enumValue.isEmpty()) return enumValue;
        if (isBooleanField(normalizedKey)) {
            return booleanValue(raw) ? "是" : "否";
        }
        if ("size_bytes".equals(normalizedKey)) {
            try {
                long bytes = Long.parseLong(raw);
                if (bytes >= 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.2f GB", bytes / (1024d * 1024d * 1024d));
                if (bytes >= 1024L * 1024L) return String.format(Locale.CHINA, "%.2f MB", bytes / (1024d * 1024d));
                if (bytes >= 1024L) return String.format(Locale.CHINA, "%.2f KB", bytes / 1024d);
                return bytes + " B";
            } catch (NumberFormatException ignored) {
                return raw;
            }
        }
        if (isAmountField(normalizedKey)) return amountValue(raw);
        return raw;
    }

    private static boolean isSemanticTypeField(String key) {
        return "type".equals(key) || "scope_type".equals(key) || "favorite_type".equals(key)
            || "target_type".equals(key) || "related_type".equals(key)
            || "source_type".equals(key) || "owner_type".equals(key);
    }

    private static String semanticType(String raw) {
        switch (raw) {
            case "private": case "direct": case "direct_message": return "私聊";
            case "group": case "group_chat": return "群聊";
            case "room": case "chatroom": case "chat_room": return "聊天室";
            case "system": case "system_message": return "系统消息";
            case "service": case "customer_service": case "support": return "在线客服";
            case "forum": case "forum_board": return "论坛";
            case "post": case "forum_post": return "论坛帖子";
            case "moment": case "life_moment": case "dynamic": return "生活动态";
            case "note": return "笔记";
            case "bounty": return "悬赏";
            case "resource": return "资源";
            case "app": case "application": return "应用";
            case "source": case "source_code": return "源码";
            case "goods": case "product": return "商品";
            case "file": case "upload": return "文件";
            case "document": return "文档";
            case "image": return "图片";
            case "gif": return "GIF 动图";
            case "video": return "视频";
            case "audio": return "音频";
            case "voice": return "语音";
            case "sticker": return "表情包";
            case "link": return "链接";
            case "message": case "chat_record": return "聊天记录";
            case "forward": case "forward_bundle": return "合并转发";
            case "user": case "member": return "用户";
            case "admin": return "管理员";
            case "platform": case "authorized_platform": return "授权平台";
            default: return "";
        }
    }

    private static boolean containsCjk(String value) {
        for (int index = 0; index < value.length(); index++) {
            char character = value.charAt(index);
            if ((character >= '\u3400' && character <= '\u4DBF')
                || (character >= '\u4E00' && character <= '\u9FFF')) return true;
        }
        return false;
    }

    private static boolean isStatusField(String key) {
        return "status".equals(key) || key.endsWith("_status") || "audit_status".equals(key)
            || "review_status".equals(key);
    }

    private static String statusForKey(String key, JsonElement value, String raw) {
        if ("audit_status".equals(key) || "review_status".equals(key)) {
            if ("pending".equals(raw) || "reviewing".equals(raw)) return "待审核";
            if ("approved".equals(raw) || "passed".equals(raw)) return "审核通过";
            if ("rejected".equals(raw)) return "审核未通过";
        }
        if ("membership_status".equals(key)) {
            if ("active".equals(raw)) return "有效";
            if ("frozen".equals(raw)) return "已冻结";
            if ("expired".equals(raw)) return "已到期";
            if ("none".equals(raw)) return "未开通";
        }
        if ("payment_status".equals(key)) {
            if ("pending".equals(raw) || "unpaid".equals(raw)) return "待支付";
            if ("processing".equals(raw)) return "支付处理中";
            if ("paid".equals(raw) || "success".equals(raw)) return "已支付";
            if ("failed".equals(raw)) return "支付失败";
            if ("refunded".equals(raw)) return "已退款";
            if ("closed".equals(raw)) return "支付已关闭";
        }
        if ("order_status".equals(key) || "fulfillment_status".equals(key)) {
            if ("pending".equals(raw)) return "待处理";
            if ("paid".equals(raw)) return "待履约";
            if ("processing".equals(raw)) return "处理中";
            if ("completed".equals(raw) || "fulfilled".equals(raw)) return "已完成";
            if ("cancelled".equals(raw) || "canceled".equals(raw)) return "已取消";
            if ("refunded".equals(raw)) return "已退款";
        }
        if ("refund_status".equals(key)) {
            if ("none".equals(raw) || "not_refunded".equals(raw)) return "未退款";
            if ("pending".equals(raw) || "processing".equals(raw)) return "退款处理中";
            if ("partial".equals(raw) || "partially_refunded".equals(raw)) return "部分退款";
            if ("refunded".equals(raw) || "completed".equals(raw)) return "已退款";
            if ("rejected".equals(raw) || "failed".equals(raw)) return "退款失败";
        }
        if ("shipping_status".equals(key)) {
            if ("pending".equals(raw) || "unshipped".equals(raw)) return "待发货";
            if ("shipped".equals(raw)) return "已发货";
            if ("delivered".equals(raw) || "received".equals(raw)) return "已签收";
            if ("returned".equals(raw)) return "已退回";
        }
        if ("maintenance_status".equals(key)) {
            if ("scheduled".equals(raw) || "pending".equals(raw)) return "等待维护";
            if ("active".equals(raw) || "running".equals(raw)) return "维护中";
            if ("completed".equals(raw) || "ended".equals(raw)) return "维护已结束";
        }
        if ("update_status".equals(key)) {
            if ("available".equals(raw) || "pending".equals(raw)) return "可更新";
            if ("downloading".equals(raw)) return "下载中";
            if ("installing".equals(raw)) return "安装中";
            if ("completed".equals(raw)) return "更新完成";
            if ("failed".equals(raw)) return "更新失败";
        }
        return status(value);
    }

    private static boolean isBooleanField(String key) {
        return key.startsWith("is_") || key.startsWith("can_") || key.startsWith("allow_")
            || key.endsWith("_enabled") || "enabled".equals(key) || "forced".equals(key)
            || "locked".equals(key) || "mute_all".equals(key) || "recalled".equals(key)
            || "voted".equals(key) || "followed".equals(key) || "blocked".equals(key)
            || "multiple_choice".equals(key) || "results_visible".equals(key);
    }

    private static boolean isAmountField(String key) {
        return "amount".equals(key) || "price".equals(key) || key.endsWith("_amount")
            || key.endsWith("_price") || key.endsWith("_balance");
    }

    private static String amountValue(String raw) {
        try {
            java.math.BigDecimal value = new java.math.BigDecimal(raw.trim()).stripTrailingZeros();
            if (value.scale() < 0) value = value.setScale(0);
            if (value.scale() > 2) value = value.setScale(2, java.math.RoundingMode.HALF_UP).stripTrailingZeros();
            return value.toPlainString();
        } catch (RuntimeException ignored) {
            return raw;
        }
    }

    private static String enumValue(String key, String raw) {
        if ("packet_type".equals(key) || "claim_mode".equals(key) || "distribution_mode".equals(key)) {
            if ("fixed".equals(raw) || "equal".equals(raw)) return "按份数平均分配";
            if ("random".equals(raw) || "lucky".equals(raw)) return "随机金额";
            if ("single_random".equals(raw)) return "一份随机抢";
            if ("specified".equals(raw) || "private".equals(raw)) return "指定用户领取";
            if ("public".equals(raw) || "open".equals(raw)) return "公开领取";
            if ("group".equals(raw)) return "群内领取";
            if ("activity".equals(raw)) return "活动红包";
        }
        if ("poll_type".equals(key)) {
            if ("single".equals(raw) || "single_choice".equals(raw)) return "单选";
            if ("multiple".equals(raw) || "multiple_choice".equals(raw)) return "多选";
        }
        if ("result_visibility".equals(key)) {
            if ("immediate".equals(raw)) return "投票后立即可见";
            if ("after_end".equals(raw)) return "投票结束后可见";
            if ("hidden".equals(raw) || "never".equals(raw)) return "不公开结果";
        }
        if ("payment_method".equals(key)) {
            if ("balance".equals(raw)) return "余额支付";
            if ("wechat".equals(raw) || "wechat_pay".equals(raw)) return "微信支付";
            if ("alipay".equals(raw)) return "支付宝";
            if ("free".equals(raw)) return "免费";
            if ("manual".equals(raw)) return "人工处理";
        }
        if ("platform".equals(key) || "platform_type".equals(key)) {
            if ("android".equals(raw) || "apk".equals(raw)) return "安卓";
            if ("harmonyos".equals(raw) || "harmony".equals(raw) || "hap".equals(raw)) return "鸿蒙";
            if ("ios".equals(raw) || "ipa".equals(raw)) return "苹果 iOS";
            if ("windows".equals(raw)) return "Windows";
            if ("linux".equals(raw)) return "Linux";
            if ("web".equals(raw)) return "网页";
            if ("cross_platform".equals(raw) || "all".equals(raw)) return "跨平台";
        }
        if ("audience_type".equals(key) || "scope".equals(key)) {
            if ("all".equals(raw) || "public".equals(raw)) return "全部用户";
            if ("friends".equals(raw)) return "全部好友";
            if ("specified".equals(raw)) return "指定用户";
            if ("level".equals(raw)) return "指定账号层级";
            if ("vip".equals(raw)) return "会员用户";
        }
        if ("currency".equals(key)) {
            if ("balance".equals(raw) || "credit".equals(raw)) return "余额";
            if ("cny".equals(raw) || "rmb".equals(raw)) return "人民币";
        }
        return "";
    }

    /**
     * Values in these fields belong to an account or to authored business content. They must
     * remain byte-for-byte visible when the interface language changes. Only their field labels
     * are localized by the caller.
     */
    public static boolean isBusinessDataField(String key) {
        if (key == null) return false;
        String value = key.trim().toLowerCase(Locale.ROOT);
        return value.equals("account") || value.endsWith("_account")
            || value.equals("nickname") || value.endsWith("_nickname")
            || value.equals("remark") || value.endsWith("_remark")
            || value.equals("display_name") || value.endsWith("_display_name")
            || value.equals("name") || value.endsWith("_name")
            || value.equals("title") || value.endsWith("_title")
            || value.equals("content") || value.endsWith("_content")
            || value.equals("description") || value.endsWith("_description")
            || value.equals("signature") || value.endsWith("_signature")
            || value.equals("subject") || value.endsWith("_subject")
            || value.equals("message") || value.endsWith("_message")
            || value.equals("file_name") || value.equals("original_name")
            || value.equals("tag") || value.equals("tags");
    }

    private static boolean booleanValue(String raw) {
        return "1".equals(raw) || "true".equalsIgnoreCase(raw) || "yes".equalsIgnoreCase(raw);
    }

    private static String translateTokens(String key) {
        Map<String, String> words = new LinkedHashMap<>();
        words.put("id", "编号"); words.put("user", "用户"); words.put("admin", "管理员");
        words.put("platform", "平台"); words.put("app", "应用"); words.put("account", "账号");
        words.put("message", "消息"); words.put("chat", "聊天"); words.put("room", "群聊");
        words.put("friend", "好友"); words.put("group", "群组"); words.put("private", "私聊");
        words.put("system", "系统"); words.put("notification", "通知"); words.put("status", "状态");
        words.put("count", "数量"); words.put("total", "总计"); words.put("used", "已用");
        words.put("remaining", "剩余"); words.put("quota", "额度"); words.put("document", "文档");
        words.put("membership", "会员"); words.put("balance", "余额"); words.put("integral", "余额");
        words.put("created", "创建"); words.put("updated", "更新"); words.put("expired", "到期");
        words.put("start", "开始"); words.put("end", "结束"); words.put("time", "时间");
        words.put("at", ""); words.put("is", "是否"); words.put("enabled", "启用");
        words.put("allow", "允许"); words.put("public", "公开"); words.put("name", "名称");
        words.put("title", "标题"); words.put("content", "内容"); words.put("description", "描述");
        words.put("code", "编码"); words.put("key", "标识"); words.put("value", "值");
        words.put("type", "类型"); words.put("mode", "模式"); words.put("level", "层级");
        words.put("target", "目标"); words.put("owner", "所有者"); words.put("current", "当前");
        words.put("last", "最近"); words.put("first", "首次"); words.put("login", "登录");
        words.put("register", "注册"); words.put("ip", "IP"); words.put("daily", "每日");
        words.put("free", "赠送"); words.put("trial", "试用"); words.put("poll", "投票");
        words.put("interval", "间隔"); words.put("minimum", "最小"); words.put("maximum", "最大");
        words.put("default", "默认"); words.put("forced", "强制"); words.put("by", "来源");
        words.put("source", "来源"); words.put("reason", "原因"); words.put("role", "角色");
        words.put("data", "数据"); words.put("finance", "财务"); words.put("statistics", "统计");
        StringBuilder result = new StringBuilder();
        for (String token : key.split("_")) {
            String word = words.get(token);
            if (word == null) continue;
            result.append(word);
        }
        return result.toString();
    }
}
