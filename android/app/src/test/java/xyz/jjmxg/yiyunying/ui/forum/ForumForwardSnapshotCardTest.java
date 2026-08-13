package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import org.junit.Test;

import java.util.List;

public final class ForumForwardSnapshotCardTest {
    @Test public void structuredPreviewShowsSafeChatFactsWithoutLiveFields() {
        JsonObject attachment = new JsonObject();
        attachment.addProperty("label", "图片");
        attachment.addProperty("name", "现场.jpg");
        attachment.addProperty("url", "https://private.example.test/secret.jpg");
        JsonArray attachments = new JsonArray();
        attachments.add(attachment);

        JsonObject item = new JsonObject();
        item.addProperty("sender", "群成员甲");
        item.addProperty("time", "2026-08-14 09:30:00");
        item.addProperty("content", "现场照片");
        item.addProperty("reference_summary", "引用了一条消息（原文不提供跳转）");
        item.add("attachments", attachments);
        item.addProperty("source_message_id", 99);
        JsonArray previews = new JsonArray();
        previews.add(item);

        JsonObject bundle = new JsonObject();
        bundle.addProperty("source_kind", "group");
        bundle.addProperty("title", "项目讨论群聊天记录");
        bundle.addProperty("item_count", 1);
        bundle.add("preview_items", previews);

        List<String> lines = ForumForwardSnapshotCard.safeDisplayLines(bundle, "");
        String joined = String.join("\n", lines);
        assertTrue(joined.contains("群聊快照"));
        assertTrue(joined.contains("群成员甲"));
        assertTrue(joined.contains("现场照片"));
        assertTrue(joined.contains("[图片] 现场.jpg") || joined.contains("图片 现场.jpg"));
        assertTrue(joined.contains("原文不提供跳转"));
        assertFalse(joined.contains("private.example.test"));
        assertFalse(joined.contains("source_message_id"));
    }

    @Test public void legacyForwardTextStillProducesDetachedCard() {
        assertTrue(ForumForwardSnapshotCard.isLegacyForwardSummary(
            "【合并转发 · 4 条聊天记录】\n点击查看只读聊天快照"));
        assertTrue(ForumForwardSnapshotCard.isLegacyForwardSummary(
            "【匿名合并转发 · 2 条聊天记录】\n点击查看只读聊天快照"));
        assertFalse(ForumForwardSnapshotCard.isLegacyForwardSummary("普通评论里提到了聊天记录"));
    }
}
