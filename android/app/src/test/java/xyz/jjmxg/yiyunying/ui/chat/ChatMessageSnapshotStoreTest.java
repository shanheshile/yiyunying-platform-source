package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import android.content.Context;

import androidx.test.core.app.ApplicationProvider;

import com.google.gson.JsonObject;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.util.Arrays;
import java.util.Collections;
import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class ChatMessageSnapshotStoreTest {
    @Test public void savesNewestMessagesInStableOrderAcrossAliasKeys() throws Exception {
        Context context = ApplicationProvider.getApplicationContext();
        String token = Long.toString(System.nanoTime());
        List<String> keys = Arrays.asList("conversation|" + token, "peer|" + token);
        ChatMessageSnapshotStore.saveAsync(
            context, "account-a-" + token, keys,
            Arrays.asList(message(9), message(3), message(7)));

        List<JsonObject> loaded = load(context, "account-a-" + token,
            Collections.singletonList("peer|" + token));

        assertEquals(3, loaded.size());
        assertEquals(3L, loaded.get(0).get("id").getAsLong());
        assertEquals(7L, loaded.get(1).get("id").getAsLong());
        assertEquals(9L, loaded.get(2).get("id").getAsLong());
    }

    @Test public void neverLoadsAnotherAccountsConversationSnapshot() throws Exception {
        Context context = ApplicationProvider.getApplicationContext();
        String token = Long.toString(System.nanoTime());
        List<String> keys = Collections.singletonList("conversation|" + token);
        ChatMessageSnapshotStore.saveAsync(
            context, "account-owner-" + token, keys, Collections.singletonList(message(42)));

        List<JsonObject> loaded = load(context, "account-other-" + token, keys);

        assertTrue(loaded.isEmpty());
    }

    private static List<JsonObject> load(Context context, String account, List<String> keys) throws Exception {
        CountDownLatch latch = new CountDownLatch(1);
        AtomicReference<List<JsonObject>> result = new AtomicReference<>(Collections.emptyList());
        ChatMessageSnapshotStore.loadAsync(context, account, keys, values -> {
            result.set(values);
            latch.countDown();
        });
        assertTrue("snapshot callback timed out", latch.await(5, TimeUnit.SECONDS));
        return result.get();
    }

    private static JsonObject message(long id) {
        JsonObject value = new JsonObject();
        value.addProperty("id", id);
        value.addProperty("content_type", "text");
        value.addProperty("content", "message-" + id);
        return value;
    }
}
