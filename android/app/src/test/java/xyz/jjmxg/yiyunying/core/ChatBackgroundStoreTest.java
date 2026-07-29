package xyz.jjmxg.yiyunying.core;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.content.Context;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 32, application = YiyunyingApplication.class)
public final class ChatBackgroundStoreTest {
    private Context context;

    @Before
    public void setUp() {
        context = ApplicationProvider.getApplicationContext();
        context.getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .edit().clear().commit();
    }

    @Test
    public void conversationInheritsGlobalBackgroundUntilItHasAnOverride() {
        ChatBackgroundStore.setGlobal(context, "content://backgrounds/global");

        assertEquals(
            "content://backgrounds/global",
            ChatBackgroundStore.resolved(context, "private:10001")
        );
        assertFalse(ChatBackgroundStore.hasConversation(context, "private:10001"));

        ChatBackgroundStore.setConversation(
            context,
            "private:10001",
            "content://backgrounds/private"
        );

        assertTrue(ChatBackgroundStore.hasConversation(context, "private:10001"));
        assertEquals(
            "content://backgrounds/private",
            ChatBackgroundStore.resolved(context, "private:10001")
        );
    }

    @Test
    public void clearingConversationRestoresTheCurrentGlobalBackground() {
        ChatBackgroundStore.setGlobal(context, "content://backgrounds/first");
        ChatBackgroundStore.setConversation(
            context,
            "group:20002",
            "content://backgrounds/group"
        );

        ChatBackgroundStore.setGlobal(context, "content://backgrounds/second");
        assertEquals(
            "content://backgrounds/group",
            ChatBackgroundStore.resolved(context, "group:20002")
        );

        ChatBackgroundStore.clearConversation(context, "group:20002");
        assertEquals(
            "content://backgrounds/second",
            ChatBackgroundStore.resolved(context, "group:20002")
        );
    }

    @Test
    public void conversationCanForceSystemDefaultWhileGlobalImageExists() {
        ChatBackgroundStore.setGlobal(context, "content://backgrounds/global");
        ChatBackgroundStore.setConversationSystemDefault(context, "private:10001");

        assertTrue(ChatBackgroundStore.hasConversation(context, "private:10001"));
        assertTrue(ChatBackgroundStore.usesSystemDefault(context, "private:10001"));
        assertEquals("", ChatBackgroundStore.conversation(context, "private:10001"));
        assertEquals("", ChatBackgroundStore.resolved(context, "private:10001"));

        ChatBackgroundStore.clearConversation(context, "private:10001");
        assertFalse(ChatBackgroundStore.usesSystemDefault(context, "private:10001"));
        assertEquals("content://backgrounds/global", ChatBackgroundStore.resolved(context, "private:10001"));
    }

    @Test
    public void unsafeConversationCharactersResolveToOneStablePreferenceKey() {
        ChatBackgroundStore.setConversation(
            context,
            "room/中文 30003",
            "content://backgrounds/room"
        );

        assertEquals(
            "content://backgrounds/room",
            ChatBackgroundStore.conversation(context, "room/中文 30003")
        );
        assertTrue(ChatBackgroundStore.isBackgroundPreference("chat_background_uri"));
        assertTrue(ChatBackgroundStore.isBackgroundPreference(
            "chat_background_conversation_room_____30003"
        ));
        assertFalse(ChatBackgroundStore.isBackgroundPreference("accent"));
    }
}
