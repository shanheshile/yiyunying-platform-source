package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.view.View;
import android.widget.TextView;

import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.test.core.app.ApplicationProvider;

import com.google.gson.JsonObject;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.util.Collections;
import java.util.concurrent.atomic.AtomicBoolean;

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

    private static void measure(View view, int width) {
        view.measure(
            View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        view.layout(0, 0, width, view.getMeasuredHeight());
    }
}