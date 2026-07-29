package xyz.jjmxg.yiyunying.ui.social;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import org.junit.Test;

import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class FavoriteCenterActivityTest {
    @Test
    public void favoriteSnapshotOnlyContainsReadableBusinessContent() {
        JsonObject media = new JsonObject();
        media.addProperty("media_type", "image");
        media.addProperty("file_name", "出游照片.jpg");
        media.addProperty("file_url", "https://server.example/private/photo.jpg");
        JsonArray attachments = new JsonArray();
        attachments.add(media);

        JsonObject snapshot = new JsonObject();
        snapshot.addProperty("title", "周末记录");
        snapshot.addProperty("sender_name", "张三");
        snapshot.addProperty("conversation_name", "同学群");
        snapshot.addProperty("content", "周末一起去看展览");
        snapshot.addProperty("sent_at", "2026-07-23 09:30:00");
        snapshot.add("attachments", attachments);

        JsonObject stored = new JsonObject();
        stored.addProperty("id", 16);
        stored.addProperty("target_id", 99);
        stored.addProperty("favorite_type", "message");
        stored.addProperty("scope_type", "group");
        stored.addProperty("access_token", "must-not-leak");
        stored.add("snapshot", snapshot);

        String visible = RecordDetailDialog.readableText(FavoriteCenterActivity.displayItem(stored));

        assertTrue(visible.contains("聊天记录"));
        assertTrue(visible.contains("周末记录"));
        assertTrue(visible.contains("张三"));
        assertTrue(visible.contains("同学群"));
        assertTrue(visible.contains("周末一起去看展览"));
        assertTrue(visible.contains("出游照片.jpg"));
        assertFalse(visible.contains("favorite_type"));
        assertFalse(visible.contains("scope_type"));
        assertFalse(visible.contains("target_id"));
        assertFalse(visible.contains("记录编号"));
        assertFalse(visible.contains("目标编号"));
        assertFalse(visible.contains("must-not-leak"));
        assertFalse(visible.contains("server.example"));
        assertFalse(visible.contains("{\""));
    }
}
