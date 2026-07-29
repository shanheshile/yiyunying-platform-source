package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

import android.content.Context;
import android.os.Looper;
import android.view.ContextThemeWrapper;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;

import androidx.test.core.app.ApplicationProvider;

import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.domain.module.FieldSpec;
import xyz.jjmxg.yiyunying.ui.chat.InlineAudioPlayerView;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class VisualInputRuntimeTest {
    private Context context;

    @Before
    public void setUp() {
        context = new ContextThemeWrapper(
            ApplicationProvider.getApplicationContext(),
            R.style.Theme_Yiyunying
        );
    }

    @Test
    public void pollOptionsUseEditableRowsAndReturnStructuredArray() {
        JsonArray initial = new JsonArray();
        initial.add("选项甲");
        JsonObject second = new JsonObject();
        second.addProperty("option_text", "选项乙");
        initial.add(second);

        StructuredJsonInput input = new StructuredJsonInput(
            context,
            FieldSpec.required("options", "投票选项"),
            initial
        );

        JsonArray value = input.value().getAsJsonArray();
        assertEquals(2, value.size());
        assertEquals("选项甲", value.get(0).getAsString());
        assertEquals("选项乙", value.get(1).getAsString());

        MaterialButton addButton = findButton(input, "添加选项");
        assertNotNull(addButton);
        addButton.performClick();
        List<EditText> editors = descendants(input, EditText.class);
        assertEquals(3, editors.size());
        editors.get(2).setText("选项丙");

        JsonArray expanded = input.value().getAsJsonArray();
        assertEquals(3, expanded.size());
        assertEquals("选项丙", expanded.get(2).getAsString());
    }

    @Test(expected = IllegalArgumentException.class)
    public void pollRequiresAtLeastTwoNonEmptyOptions() {
        StructuredJsonInput input = new StructuredJsonInput(
            context,
            FieldSpec.required("options", "投票选项"),
            new JsonArray()
        );
        List<EditText> editors = descendants(input, EditText.class);
        assertEquals(2, editors.size());
        editors.get(0).setText("只有一个选项");
        input.value();
    }

    @Test
    public void mixedMediaCollapsesAndExpandsImageStack() {
        LinearLayout container = new LinearLayout(context);
        container.setOrientation(LinearLayout.VERTICAL);
        JsonArray attachments = new JsonArray();
        for (int index = 1; index <= 4; index++) {
            attachments.add(attachment("image", "图片" + index + ".jpg", 0));
        }
        attachments.add(attachment("audio", "语音.m4a", 3500));
        attachments.add(attachment("file", "资料.pdf", 0));

        MediaViewRenderer.render(context, container, attachments);

        assertEquals(View.VISIBLE, container.getVisibility());
        assertEquals(3, descendants(container, ImageView.class).size());
        MaterialButton toggle = findButton(container, "展开全部 4 个媒体");
        assertNotNull(toggle);
        toggle.performClick();
        shadowOf(Looper.getMainLooper()).idle();
        assertEquals(4, descendants(container, ImageView.class).size());
        assertNotNull(findButton(container, "收起媒体"));
        assertEquals(1, descendants(container, InlineAudioPlayerView.class).size());
        assertTrue(texts(container).contains("00:00 / 00:03"));
        assertTrue(texts(container).stream().anyMatch(value -> value.startsWith("文件 · 资料.pdf")));

        MediaViewRenderer.render(context, container, new JsonArray());
        assertEquals(View.GONE, container.getVisibility());
        assertEquals(0, container.getChildCount());
    }

    private JsonObject attachment(String type, String name, long durationMs) {
        JsonObject item = new JsonObject();
        item.addProperty("media_type", type);
        item.addProperty("file_name", name);
        item.addProperty("url", "");
        item.addProperty("mime_type", "application/octet-stream");
        item.addProperty("duration_ms", durationMs);
        item.addProperty("size_bytes", 2048);
        return item;
    }

    private MaterialButton findButton(View root, String text) {
        for (MaterialButton button : descendants(root, MaterialButton.class)) {
            if (text.contentEquals(button.getText())) return button;
        }
        return null;
    }

    private List<String> texts(View root) {
        List<String> values = new ArrayList<>();
        for (android.widget.TextView text : descendants(root, android.widget.TextView.class)) {
            values.add(text.getText() == null ? "" : text.getText().toString());
        }
        return values;
    }

    private <T extends View> List<T> descendants(View root, Class<T> type) {
        List<T> result = new ArrayList<>();
        collect(root, type, result);
        return result;
    }

    private <T extends View> void collect(View view, Class<T> type, List<T> result) {
        if (type.isInstance(view)) result.add(type.cast(view));
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            collect(group.getChildAt(index), type, result);
        }
    }
}
