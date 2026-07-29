package xyz.jjmxg.yiyunying.domain.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonObject;

import org.junit.Test;

public class ContactCardIdentityTest {
    @Test public void ownCardUsesAccountAndAvatarAsVisibleIdentity() {
        JsonObject profile = new JsonObject();
        profile.addProperty("id", 12L);
        profile.addProperty("account", "account_12");
        profile.addProperty("nickname", "Display nickname");
        profile.addProperty("avatar", "/uploads/avatar-12.jpg");

        JsonObject result = ContactCardIdentity.metadata(profile, 0L, "", true);

        assertEquals("account_12", result.get("display_name").getAsString());
        assertEquals("/uploads/avatar-12.jpg", result.get("avatar").getAsString());
        assertEquals("Display nickname", result.get("nickname").getAsString());
        assertTrue(result.get("is_self").getAsBoolean());
    }

    @Test public void recommendedCardIgnoresConversationRemarkAndPageTitle() {
        JsonObject profile = new JsonObject();
        profile.addProperty("id", 88L);
        profile.addProperty("account", "target_account");
        profile.addProperty("nickname", "Target nickname");
        profile.addProperty("remark", "My private remark");
        profile.addProperty("title", "Conversation title");
        profile.addProperty("avatar_url", "/uploads/target.png");

        JsonObject result = ContactCardIdentity.metadata(profile, 0L, "", false);

        assertEquals("target_account", ContactCardIdentity.displayName(result));
        assertEquals("/uploads/target.png", result.get("avatar").getAsString());
        assertFalse(ContactCardIdentity.displayName(result).contains("remark"));
        assertFalse(result.get("is_self").getAsBoolean());
    }

    @Test public void nestedUserKeepsOuterIdentifierFallback() {
        JsonObject user = new JsonObject();
        user.addProperty("account_name", "nested_account");
        user.addProperty("profile_avatar", "/uploads/nested.jpg");
        JsonObject profile = new JsonObject();
        profile.addProperty("user_id", 25L);
        profile.add("user", user);

        JsonObject result = ContactCardIdentity.metadata(profile, 0L, "", false);

        assertEquals(25L, result.get("user_id").getAsLong());
        assertEquals("25", result.get("uid").getAsString());
        assertEquals("nested_account", result.get("display_name").getAsString());
        assertEquals("/uploads/nested.jpg", result.get("avatar").getAsString());
    }
}
