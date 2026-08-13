package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.test.core.app.ApplicationProvider;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.domain.Role;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class ChatAdapterRenderingTest {
    @Test
    public void textMessageRemainsVisibleMeasuredAndLongPressable() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        AtomicBoolean longPressed = new AtomicBoolean(false);
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> longPressed.set(true));
        adapter.submit(Collections.singletonList(message("content", "visible message")));

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
        adapter.onBindViewHolder(holder, 0);
        measure(holder.itemView, 1080);

        TextView content = holder.itemView.findViewById(R.id.content);
        View bubble = holder.itemView.findViewById(R.id.bubble);
        View messageBody = holder.itemView.findViewById(R.id.messageBody);
        assertEquals(View.VISIBLE, content.getVisibility());
        assertEquals("visible message", content.getText().toString());
        assertTrue(content.getMeasuredWidth() > 0);
        assertTrue(bubble.getMeasuredWidth() > 0);
        assertTrue(messageBody.getMeasuredWidth() > 0);
        assertTrue(messageBody.performLongClick());
        assertTrue(longPressed.get());
    }

    @Test
    public void cachedLegacyTextFieldStillRenders() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        adapter.submit(Collections.singletonList(message("message", "cached message")));

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
        adapter.onBindViewHolder(holder, 0);
        measure(holder.itemView, 1080);

        TextView content = holder.itemView.findViewById(R.id.content);
        assertEquals(View.VISIBLE, content.getVisibility());
        assertEquals("cached message", content.getText().toString());
        assertTrue(content.getMeasuredWidth() > 0);
    }

    @Test
    public void shortOwnTextBubbleWrapsItsContentWithoutFixedBlankWidth() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        JsonObject item = message("content", "11");
        item.addProperty("sender_id", 1L);
        adapter.submit(Collections.singletonList(item));

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
        adapter.onBindViewHolder(holder, 0);
        measure(holder.itemView, 1080);

        TextView content = holder.itemView.findViewById(R.id.content);
        View bubble = holder.itemView.findViewById(R.id.bubble);
        LinearLayout bubbleContent = holder.itemView.findViewById(R.id.bubbleContent);
        int expectedContentWidth = content.getMeasuredWidth()
            + bubbleContent.getPaddingLeft() + bubbleContent.getPaddingRight();
        assertEquals(0, bubbleContent.getMinimumWidth());
        assertTrue(bubble.getMeasuredWidth() <= expectedContentWidth + 2);
    }

    @Test
    public void callCardKeepsItsExistingMinimumWidth() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        JsonObject item = message("content", "已取消");
        item.addProperty("content_type", "call");
        adapter.submit(Collections.singletonList(item));

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
        adapter.onBindViewHolder(holder, 0);

        LinearLayout bubbleContent = holder.itemView.findViewById(R.id.bubbleContent);
        assertEquals(dp(context, 72), bubbleContent.getMinimumWidth());
    }

    @Test
    public void administratorPolicyCanHideOnlyTheCallRecordTag() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        JsonObject item = message("content", "通话时间：0分18秒");
        item.addProperty("content_type", "call");
        JsonArray tags = new JsonArray();
        tags.add("通话记录");
        tags.add("重要");
        item.add("tags", tags);
        adapter.submit(Collections.singletonList(item));
        adapter.setCallRecordLabelVisible(false);

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
        adapter.onBindViewHolder(holder, 0);

        LinearLayout tagContainer = holder.itemView.findViewById(R.id.tagContainer);
        assertEquals(1, tagContainer.getChildCount());
        assertEquals("#重要", ((TextView) tagContainer.getChildAt(0)).getText().toString());
        assertEquals(View.VISIBLE, holder.itemView.findViewById(R.id.content).getVisibility());
    }

    @Test
    public void messageIndexStaysCorrectAcrossAppendAndReorder() {
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        JsonObject first = message("content", "first");
        first.addProperty("id", 1L);
        JsonObject second = message("content", "second");
        second.addProperty("id", 2L);
        adapter.submit(Arrays.asList(first, second));
        assertEquals(0, adapter.positionOf(1L));
        assertEquals(1, adapter.positionOf(2L));

        JsonObject third = message("content", "third");
        third.addProperty("id", 3L);
        adapter.submit(Arrays.asList(first, second, third));
        assertEquals(2, adapter.positionOf(3L));

        adapter.submit(Arrays.asList(third, second));
        assertEquals(-1, adapter.positionOf(1L));
        assertEquals(0, adapter.positionOf(3L));
        assertEquals(1, adapter.positionOf(2L));
    }

    @Test
    public void repeatedSelectionChangesUsePayloadInsteadOfRebindingEveryMediaRow() {
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, message -> { });
        JsonObject first = message("content", "first");
        JsonObject second = message("content", "second");
        second.addProperty("id", 102L);
        adapter.submit(Arrays.asList(first, second));
        adapter.setSelectionMode(true, Collections.singleton(101L));

        AtomicInteger fullRefreshes = new AtomicInteger();
        AtomicInteger selectionPayloadRows = new AtomicInteger();
        adapter.registerAdapterDataObserver(new RecyclerView.AdapterDataObserver() {
            @Override public void onChanged() { fullRefreshes.incrementAndGet(); }
            @Override public void onItemRangeChanged(int positionStart, int itemCount,
                                                     Object payload) {
                if (ChatAdapter.PAYLOAD_SELECTION_STATE.equals(payload)) {
                    selectionPayloadRows.addAndGet(itemCount);
                }
            }
        });

        LinkedHashSet<Long> selected = new LinkedHashSet<>();
        selected.add(101L);
        selected.add(102L);
        adapter.setSelectionMode(true, selected);

        assertEquals(0, fullRefreshes.get());
        assertEquals(1, selectionPayloadRows.get());
    }

    @Test
    public void narrowSelectionKeepsBothPerspectivesMediaCompleteAndSelectable() {
        Context context = ApplicationProvider.getApplicationContext();
        context.setTheme(R.style.Theme_Yiyunying);
        AtomicInteger selectionClicks = new AtomicInteger();
        ChatAdapter adapter = new ChatAdapter(1L, Role.USER, new ChatAdapter.Listener() {
            @Override public void onLongPress(JsonObject message) { }
            @Override public void onSelectionChanged(JsonObject message, boolean selected) {
                selectionClicks.incrementAndGet();
            }
        });
        JsonObject mine = mediaMessage(101L, 1L);
        JsonObject theirs = mediaMessage(102L, 2L);
        adapter.submit(Arrays.asList(mine, theirs));
        adapter.setSelectionMode(true, Collections.singleton(101L));

        RecyclerView parent = new RecyclerView(context);
        parent.setLayoutManager(new LinearLayoutManager(context));
        for (int position = 0; position < 2; position++) {
            ChatAdapter.Holder holder = adapter.onCreateViewHolder(parent, 0);
            adapter.onBindViewHolder(holder, position);
            measure(holder.itemView, dp(context, 360));

            View selectionRail = holder.itemView.findViewById(R.id.selectionRail);
            LinearLayout messageColumn = holder.itemView.findViewById(R.id.messageColumn);
            LinearLayout mediaContainer = holder.itemView.findViewById(R.id.mediaContainer);
            assertEquals(View.VISIBLE, selectionRail.getVisibility());
            assertEquals(1, mediaContainer.getChildCount());
            assertTrue(mediaContainer.getChildAt(0).getMeasuredWidth()
                <= messageColumn.getMeasuredWidth());
            assertTrue(mediaContainer.getChildAt(0).getMeasuredHeight() > 0);

            ViewGroup stack = (ViewGroup) mediaContainer.getChildAt(0);
            assertTrue(stack.getChildCount() >= 1);
            assertTrue(stack.getChildAt(0).performClick());
        }
        assertEquals(2, selectionClicks.get());
    }

    private static JsonObject message(String field, String value) {
        JsonObject item = new JsonObject();
        item.addProperty("id", 101L);
        item.addProperty("sender_id", 2L);
        item.addProperty("sender_type", "user");
        item.addProperty("sender_name", "tester");
        item.addProperty("content_type", "text");
        item.addProperty(field, value);
        item.addProperty("created_at", "2026-08-01 08:00:00");
        return item;
    }

    private static JsonObject mediaMessage(long id, long senderId) {
        JsonObject item = message("content", "");
        item.addProperty("id", id);
        item.addProperty("sender_id", senderId);
        JsonArray attachments = new JsonArray();
        for (int index = 0; index < 4; index++) {
            JsonObject attachment = new JsonObject();
            attachment.addProperty("media_type", "image");
            attachment.addProperty("width", 1200);
            attachment.addProperty("height", 900);
            attachment.addProperty("file_name", "photo-" + index + ".jpg");
            attachments.add(attachment);
        }
        item.add("attachments", attachments);
        return item;
    }

    private static void measure(View view, int width) {
        view.measure(
            View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        view.layout(0, 0, width, view.getMeasuredHeight());
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
