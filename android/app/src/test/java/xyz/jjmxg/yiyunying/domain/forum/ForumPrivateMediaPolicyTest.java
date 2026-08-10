package xyz.jjmxg.yiyunying.domain.forum;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import org.junit.Test;

public final class ForumPrivateMediaPolicyTest {
    private static final long NOW_MS = 1_800_000_000_000L;

    @Test public void findsStableAttachmentIdsAndTheEarliestExpiryInNestedSections() {
        JsonObject post = new JsonObject();
        JsonArray sections = new JsonArray();
        JsonObject section = new JsonObject();
        JsonArray attachments = new JsonArray();
        attachments.add(privateAttachment(41L, NOW_MS / 1000L + 300L));
        attachments.add(privateAttachment(42L, NOW_MS / 1000L + 180L));
        section.add("attachments", attachments);
        sections.add(section);
        post.add("sections", sections);

        ForumPrivateMediaPolicy.Snapshot snapshot =
            ForumPrivateMediaPolicy.inspect(post, NOW_MS);

        assertTrue(snapshot.hasPrivateMedia());
        assertFalse(snapshot.refreshRequired());
        assertEquals(NOW_MS + 180_000L, snapshot.earliestExpiryMs());
        assertTrue(snapshot.contains(41L));
        assertTrue(snapshot.contains(42L));
        assertEquals(42L, ForumPrivateMediaPolicy.privateAttachmentId(attachments.get(1).getAsJsonObject()));
    }

    @Test public void refreshesInsideTheSafetyWindowButDoesNotExtendServerLifetime() {
        JsonObject attachment = privateAttachment(7L, NOW_MS / 1000L + 60L);
        assertTrue(ForumPrivateMediaPolicy.shouldRefresh(attachment, NOW_MS));

        attachment = privateAttachment(7L, NOW_MS / 1000L + 61L);
        assertFalse(ForumPrivateMediaPolicy.shouldRefresh(attachment, NOW_MS));
    }

    @Test public void malformedCapabilityIsRefreshedAndPublicMediaIsIgnored() {
        JsonObject malformed = new JsonObject();
        malformed.addProperty("id", 99L);
        malformed.addProperty("thumbnail_url",
            "/api/public/forum-media/99?app_id=3&signature=bad");
        ForumPrivateMediaPolicy.Snapshot unsafe =
            ForumPrivateMediaPolicy.inspect(malformed, NOW_MS);
        assertTrue(unsafe.hasPrivateMedia());
        assertTrue(unsafe.refreshRequired());
        assertEquals(99L, ForumPrivateMediaPolicy.privateAttachmentId(malformed));

        JsonObject publicMedia = new JsonObject();
        publicMedia.addProperty("id", 99L);
        publicMedia.addProperty("url", "https://cdn.example.test/forum/99.jpg?expires=1");
        ForumPrivateMediaPolicy.Snapshot publicSnapshot =
            ForumPrivateMediaPolicy.inspect(publicMedia, NOW_MS);
        assertFalse(publicSnapshot.hasPrivateMedia());
        assertFalse(publicSnapshot.refreshRequired());
        assertTrue(publicSnapshot.attachmentIds().isEmpty());
    }

    @Test public void readsCapabilityFromMetadataWithoutTrustingDisplayFilename() {
        JsonObject attachment = new JsonObject();
        attachment.addProperty("id", 123456L);
        attachment.addProperty("file_name", "not-the-id.pdf");
        JsonObject metadata = new JsonObject();
        metadata.addProperty("file_url", privateUrl(73L, NOW_MS / 1000L + 10L));
        attachment.add("metadata", metadata);

        assertEquals(73L, ForumPrivateMediaPolicy.privateAttachmentId(attachment));
        assertTrue(ForumPrivateMediaPolicy.shouldRefresh(attachment, NOW_MS));
    }

    private static JsonObject privateAttachment(long id, long expiresSeconds) {
        JsonObject attachment = new JsonObject();
        attachment.addProperty("id", id);
        attachment.addProperty("url", privateUrl(id, expiresSeconds));
        return attachment;
    }

    private static String privateUrl(long id, long expiresSeconds) {
        return "https://api.example.test/api/public/forum-media/" + id
            + "?app_id=3&expires=" + expiresSeconds + "&signature=abc";
    }
}
