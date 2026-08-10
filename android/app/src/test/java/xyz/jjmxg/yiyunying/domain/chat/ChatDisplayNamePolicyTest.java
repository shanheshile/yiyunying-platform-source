package xyz.jjmxg.yiyunying.domain.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonObject;

import org.junit.Test;

public final class ChatDisplayNamePolicyTest {
    @Test public void usesRemarkThenNicknameThenAccountAndRejectsUserPlaceholder() {
        JsonObject item = new JsonObject();
        item.addProperty("sender_name", "user");
        item.addProperty("sender_nickname", "昵称");
        item.addProperty("sender_account", "account-7");
        item.addProperty("sender_remark", "好友备注");
        assertEquals("好友备注", ChatDisplayNamePolicy.senderName(item, false));

        item.addProperty("sender_remark", "");
        assertEquals("昵称", ChatDisplayNamePolicy.senderName(item, false));
        item.addProperty("sender_nickname", "user");
        assertEquals("account-7", ChatDisplayNamePolicy.senderName(item, false));
    }

    @Test public void recallsUseActorIdentityAndViewerPerspective() {
        JsonObject item = new JsonObject();
        item.addProperty("content_type", "recall");
        item.addProperty("sender_type", "system");
        item.addProperty("sender_id", 17L);
        item.addProperty("sender_name", "系统消息");
        item.addProperty("sender_nickname", "小熊");

        assertTrue(ChatDisplayNamePolicy.isRecalled(item));
        assertEquals("你撤回了一条消息", ChatDisplayNamePolicy.recallNotice(item, 17L));
        assertEquals("小熊撤回了一条消息", ChatDisplayNamePolicy.recallNotice(item, 99L));
    }

    @Test public void recognizesLegacyRecallFlags() {
        JsonObject recalled = new JsonObject();
        recalled.addProperty("recalled", true);
        assertTrue(ChatDisplayNamePolicy.isRecalled(recalled));

        JsonObject normal = new JsonObject();
        normal.addProperty("is_recalled", false);
        assertFalse(ChatDisplayNamePolicy.isRecalled(normal));
    }
}
