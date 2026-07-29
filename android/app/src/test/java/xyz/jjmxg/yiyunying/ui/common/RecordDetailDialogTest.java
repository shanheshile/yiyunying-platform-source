package xyz.jjmxg.yiyunying.ui.common;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.List;
import java.util.Map;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

public final class RecordDetailDialogTest {
    @Test
    public void rendersNestedAndSerializedRecordsAsReadableText() {
        JsonObject item = new JsonObject();
        item.addProperty("title", "收藏的聊天记录");
        item.addProperty("payload", "{\"account_name\":\"测试用户\",\"count\":3}");

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.contains("收藏的聊天记录"));
        assertTrue(visible.contains("测试用户"));
        assertTrue(visible.contains("3"));
        assertFalse(visible.contains("{\""));
        assertFalse(visible.contains("account_name"));
    }

    @Test
    public void neverCopiesPrivateStorageUrlsIntoVisibleDetails() {
        JsonObject attachment = new JsonObject();
        attachment.addProperty("file_name", "示例图片.jpg");
        attachment.addProperty("mime_type", "image/jpeg");
        attachment.addProperty("size_bytes", 2048);
        attachment.addProperty("file_url", "https://server.example/private/token.jpg");

        JsonArray attachments = new JsonArray();
        attachments.add(attachment);
        JsonObject item = new JsonObject();
        item.add("attachments", attachments);
        item.addProperty("storage_path", "/www/wwwroot/private/token.jpg");

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.contains("示例图片.jpg"));
        assertTrue(visible.contains("图片"));
        assertFalse(visible.contains("server.example"));
        assertFalse(visible.contains("/www/"));
        assertFalse(visible.contains("token.jpg"));
    }

    @Test
    public void rendersOrderAsChineseBusinessDetails() {
        JsonObject order = new JsonObject();
        order.addProperty("order_no", "YY20260723001");
        order.addProperty("product_name", "高级会员");
        order.addProperty("purchase_type", "shop_goods");
        order.addProperty("payment_status", "paid");
        order.addProperty("refund_status", "pending");
        order.addProperty("pay_amount", 19.90);

        String visible = RecordDetailDialog.readableText(order);

        assertTrue(visible.contains("订单号：YY20260723001"));
        assertTrue(visible.contains("商品名称：高级会员"));
        assertTrue(visible.contains("购买类型：商城商品"));
        assertTrue(visible.contains("支付状态：已支付"));
        assertTrue(visible.contains("退款状态：退款处理中"));
        assertTrue(visible.contains("支付金额：19.9"));
        assertFalse(visible.contains("shop_goods"));
        assertFalse(visible.contains("paid"));
    }

    @Test
    public void rendersRedPacketClaimsAndReturnStateSemantically() {
        JsonObject claim = new JsonObject();
        claim.addProperty("nickname", "测试用户");
        claim.addProperty("claim_amount", 0.31);
        JsonArray claims = new JsonArray();
        claims.add(claim);

        JsonObject packet = new JsonObject();
        packet.addProperty("distribution_mode", "single_random");
        packet.addProperty("status", "returned");
        packet.addProperty("packet_count", 5);
        packet.addProperty("remaining_count", 2);
        packet.add("claims", claims);

        String visible = RecordDetailDialog.readableText(packet);

        assertTrue(visible.contains("分配方式：一份随机抢"));
        assertTrue(visible.contains("状态：已退回"));
        assertTrue(visible.contains("红包份数：5"));
        assertTrue(visible.contains("剩余份数：2"));
        assertTrue(visible.contains("领取记录 1 · 测试用户"));
        assertTrue(visible.contains("领取金额：0.31"));
        assertFalse(visible.contains("single_random"));
        assertFalse(visible.contains("returned"));
    }

    @Test
    public void rendersPollOptionsAndResourceAuditSemantically() {
        JsonObject option = new JsonObject();
        option.addProperty("option_text", "选项甲");
        option.addProperty("vote_count", 7);
        JsonArray options = new JsonArray();
        options.add(option);

        JsonObject poll = new JsonObject();
        poll.addProperty("poll_type", "multiple_choice");
        poll.addProperty("result_visibility", "after_end");
        poll.add("options", options);

        String pollVisible = RecordDetailDialog.readableText(poll);
        assertTrue(pollVisible.contains("投票类型：多选"));
        assertTrue(pollVisible.contains("结果可见范围：投票结束后可见"));
        assertTrue(pollVisible.contains("选项 1 · 选项甲"));
        assertTrue(pollVisible.contains("票数：7"));

        JsonObject resource = new JsonObject();
        resource.addProperty("resource_type", "app");
        resource.addProperty("platform", "android");
        resource.addProperty("audit_status", "approved");
        resource.addProperty("file_name", "易运盈.apk");
        String resourceVisible = RecordDetailDialog.readableText(resource);
        assertTrue(resourceVisible.contains("资源类型：应用软件"));
        assertTrue(resourceVisible.contains("适用平台：安卓"));
        assertTrue(resourceVisible.contains("审核状态：审核通过"));
        assertTrue(resourceVisible.contains("文件名称：易运盈.apk"));
    }

    @Test
    public void hidesSecretsAndTechnicalDiagnosticsFromEveryRoleDetail() {
        JsonObject item = new JsonObject();
        item.addProperty("account_name", "名字不翻译");
        item.addProperty("password_hash", "secret-hash");
        item.addProperty("authorization", "Bearer secret-token");
        item.addProperty("access_token", "private-access-token");
        item.addProperty("request_body", "{\"password\":\"hidden\"}");
        item.addProperty("http_method", "POST");
        item.addProperty("sql_state", "SQLSTATE[HY000]");
        item.addProperty("stack_trace", "java.lang.IllegalStateException");
        item.addProperty("internal_path", "/www/wwwroot/app/index.php");
        item.addProperty("message", "PHP Warning: proc_open(): Permission denied");

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.contains("名字不翻译"));
        assertFalse(visible.contains("secret"));
        assertFalse(visible.contains("private-access-token"));
        assertFalse(visible.contains("password"));
        assertFalse(visible.contains("POST"));
        assertFalse(visible.contains("SQLSTATE"));
        assertFalse(visible.contains("IllegalStateException"));
        assertFalse(visible.contains("/www/"));
        assertFalse(visible.contains("proc_open"));
        assertFalse(visible.contains("PHP Warning"));
        assertTrue(visible.contains("服务器暂时无法处理请求"));
    }

    @Test
    public void mapsSharedEnumsWithoutChangingAuthoredNames() {
        assertEquals("总控", DisplayText.fieldValue("role", primitive("platform_owner")));
        assertEquals("群主", DisplayText.fieldValue("role", primitive("owner")));
        assertEquals("名字ABC", DisplayText.fieldValue("account_name", primitive("名字ABC")));
    }

    @Test
    public void hidesInternalRelationsAndKeepsPublicBusinessIdentifiers() {
        JsonObject item = new JsonObject();
        item.addProperty("id", 16);
        item.addProperty("user_id", 7);
        item.addProperty("target_id", 9);
        item.addProperty("uid", "10000000001");
        item.addProperty("order_no", "YY20260723002");
        item.addProperty("group_number", "20000000001");

        String visible = RecordDetailDialog.readableText(item);

        assertFalse(visible.contains("记录编号"));
        assertFalse(visible.contains("用户编号"));
        assertFalse(visible.contains("目标编号"));
        assertTrue(visible.contains("UID：10000000001"));
        assertTrue(visible.contains("订单号：YY20260723002"));
        assertTrue(visible.contains("群号：20000000001"));
    }

    @Test
    public void removesDuplicateRawFieldsAndEmptyValues() {
        JsonObject item = new JsonObject();
        item.addProperty("status", "pending");
        item.addProperty("status_text", "等待处理");
        item.addProperty("join_mode", "approval");
        item.addProperty("join_mode_text", "需要审核");
        item.addProperty("summary", "  ");
        item.add("attachments", new JsonArray());

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.contains("等待处理"));
        assertTrue(visible.contains("需要审核"));
        assertFalse(visible.contains("pending"));
        assertFalse(visible.contains("approval"));
        assertFalse(visible.contains("摘要"));
        assertFalse(visible.contains("附件"));
    }

    @Test
    public void ordersBusinessFieldsLogically() {
        JsonObject item = new JsonObject();
        item.addProperty("created_at", "2026-07-23 10:00:00");
        item.addProperty("content", "这是正文");
        item.addProperty("status", "active");
        item.addProperty("title", "业务标题");

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.indexOf("业务标题") < visible.indexOf("状态"));
        assertTrue(visible.indexOf("状态") < visible.indexOf("这是正文"));
        assertTrue(visible.indexOf("这是正文") < visible.indexOf("2026-07-23"));
    }

    @Test
    public void translatesConversationAndFavoriteTypes() {
        JsonObject item = new JsonObject();
        item.addProperty("scope_type", "group");
        item.addProperty("favorite_type", "message");
        item.addProperty("source_type", "forum_post");

        String visible = RecordDetailDialog.readableText(item);

        assertTrue(visible.contains("会话类型：群聊"));
        assertTrue(visible.contains("收藏类型：聊天记录"));
        assertTrue(visible.contains("来源类型：论坛帖子"));
    }

    @Test
    public void preservesChineseLabelsAndNestedForwardVoice() {
        JsonObject forwardedMessage = new JsonObject();
        forwardedMessage.addProperty("sender_name", "发言人甲");
        forwardedMessage.addProperty("content_type", "voice");
        forwardedMessage.addProperty("duration_seconds", 12);
        JsonArray messages = new JsonArray();
        messages.add(forwardedMessage);
        JsonObject bundle = new JsonObject();
        bundle.addProperty("转发说明", "仅供查看");
        bundle.add("messages", messages);

        String visible = RecordDetailDialog.readableText(bundle);

        assertTrue(visible.contains("转发说明：仅供查看"));
        assertTrue(visible.contains("发言人甲"));
        assertTrue(visible.contains("内容类型：语音"));
        assertTrue(visible.contains("12"));
    }

    @Test
    public void keepsOnlyPreferredBusinessAliasInEveryDetailSurface() {
        JsonObject item = new JsonObject();
        item.addProperty("status", "active");
        item.addProperty("status_name", "正常");
        item.addProperty("status_text", "可正常使用");
        item.addProperty("nickname", "备用昵称");
        item.addProperty("actor_name", "实际操作人");
        item.addProperty("user_name", "旧用户名");
        item.addProperty("product_name", "会员服务");
        item.addProperty("goods_name", "重复商品名");

        List<Map.Entry<String, JsonElement>> entries = RecordDetailDialog.visibleEntries(item);
        StringBuilder keys = new StringBuilder();
        for (Map.Entry<String, JsonElement> entry : entries) keys.append(entry.getKey()).append(',');

        assertTrue(keys.toString().contains("status_text"));
        assertFalse(keys.toString().contains("status_name"));
        assertFalse(keys.toString().contains("status,"));
        assertTrue(keys.toString().contains("actor_name"));
        assertFalse(keys.toString().contains("nickname"));
        assertFalse(keys.toString().contains("user_name"));
        assertTrue(keys.toString().contains("product_name"));
        assertFalse(keys.toString().contains("goods_name"));
    }

    @Test
    public void hidesForwardingImplementationFlagsButKeepsVoiceSnapshot() {
        JsonObject item = new JsonObject();
        item.addProperty("anonymity_scope", "current_bundle");
        item.addProperty("identity_view", "anonymous");
        item.addProperty("audit_identity_visible", false);
        item.addProperty("snapshot_read_only", true);
        item.addProperty("source_context_hidden", true);
        item.addProperty("content_type", "voice");
        item.addProperty("duration_seconds", 9);

        String visible = RecordDetailDialog.readableText(item);

        assertFalse(visible.contains("current_bundle"));
        assertFalse(visible.contains("anonymous"));
        assertFalse(visible.contains("审计"));
        assertFalse(visible.contains("只读"));
        assertTrue(visible.contains("内容类型：语音"));
        assertTrue(visible.contains("9"));
    }

    private static com.google.gson.JsonPrimitive primitive(String value) {
        return new com.google.gson.JsonPrimitive(value);
    }
}
