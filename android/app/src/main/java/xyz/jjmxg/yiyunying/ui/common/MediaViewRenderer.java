package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.graphics.Color;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.ui.chat.InlineAudioPlayerView;
import xyz.jjmxg.yiyunying.ui.chat.InlineMediaPreviewDialog;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;

public final class MediaViewRenderer {
    private MediaViewRenderer() { }

    public static void render(Context context, LinearLayout container, JsonArray attachments) {
        boolean[] expanded = {false};
        render(context, container, attachments, expanded);
    }

    private static void render(Context context, LinearLayout container, JsonArray attachments, boolean[] expanded) {
        container.removeAllViews();
        if (attachments == null || attachments.isEmpty()) {
            container.setVisibility(View.GONE);
            return;
        }
        container.setVisibility(View.VISIBLE);
        List<JsonObject> visualMedia = new ArrayList<>();
        List<JsonObject> others = new ArrayList<>();
        for (JsonElement element : attachments) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            String type = Jsons.string(item, "media_type");
            if ("image".equals(type) || "sticker".equals(type) || "video".equals(type)) visualMedia.add(item);
            else others.add(item);
        }
        int visualCount = expanded[0] ? visualMedia.size() : Math.min(visualMedia.size(), 3);
        for (int index = 0; index < visualCount; index++) {
            container.addView(visualCard(context, visualMedia, index));
        }
        if (visualMedia.size() > 3) {
            MaterialButton toggle = new MaterialButton(context);
            toggle.setText(expanded[0] ? "收起媒体" : "展开全部 " + visualMedia.size() + " 个媒体");
            toggle.setOnClickListener(view -> {
                expanded[0] = !expanded[0];
                container.animate().alpha(0.25f).setDuration(70L).withEndAction(() -> {
                    render(context, container, attachments, expanded);
                    container.setAlpha(0.25f);
                    container.animate().alpha(1f).setDuration(150L).start();
                }).start();
            });
            container.addView(toggle, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(context, 48)));
        }
        for (JsonObject attachment : others) container.addView(fileCard(context, attachment));
    }

    private static View visualCard(Context context, List<JsonObject> visualMedia, int index) {
        JsonObject attachment = visualMedia.get(index);
        String type = Jsons.string(attachment, "media_type");
        boolean sticker = "sticker".equals(type);
        boolean video = "video".equals(type) || Jsons.string(attachment, "mime_type").startsWith("video/");
        MaterialCardView card = new MaterialCardView(context);
        int[] dimensions = mediaSize(context, attachment, sticker);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            sticker ? dimensions[0] : ViewGroup.LayoutParams.MATCH_PARENT, dimensions[1]);
        params.bottomMargin = dp(context, 8);
        card.setLayoutParams(params);
        card.setRadius(dp(context, 8));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(context.getColor(R.color.outline));
        FrameLayout frame = new FrameLayout(context);
        ImageView image = new ImageView(context);
        image.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        image.setBackgroundColor(context.getColor(R.color.surface_container));
        String preview = Jsons.string(attachment, "thumbnail_url");
        if (preview.isEmpty() && !video) preview = Jsons.string(attachment, "url");
        if (!preview.isEmpty()) ImageLoader.get().load(ImageLoader.get().absoluteUrl(context, preview), image, R.drawable.ic_file);
        frame.addView(image);
        if (video) {
            TextView play = new TextView(context);
            play.setText("▶\n视频预览");
            play.setTextColor(Color.WHITE);
            play.setTextSize(14);
            play.setGravity(Gravity.CENTER);
            play.setBackgroundColor(Color.argb(76, 0, 0, 0));
            frame.addView(play, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        }
        card.addView(frame);
        card.setContentDescription(sticker ? "表情包" : (video ? "视频预览" : "内容图片"));
        card.setOnClickListener(view -> InlineMediaPreviewDialog.show(context, visualMedia, index));
        return card;
    }

    private static int[] mediaSize(Context context, JsonObject attachment, boolean sticker) {
        if (sticker) return new int[]{dp(context, 160), dp(context, 160)};
        int availableWidth = Math.max(dp(context, 220), context.getResources().getDisplayMetrics().widthPixels - dp(context, 48));
        int maximumHeight = dp(context, 380);
        long sourceWidth = Jsons.longValue(attachment, "width");
        long sourceHeight = Jsons.longValue(attachment, "height");
        boolean video = "video".equals(Jsons.string(attachment, "media_type"));
        float ratio = sourceWidth > 0 && sourceHeight > 0
            ? Math.max(0.2f, Math.min(5f, sourceWidth / (float) sourceHeight))
            : (video ? 16f / 9f : 4f / 3f);
        int height = Math.round(availableWidth / ratio);
        height = Math.max(dp(context, 160), Math.min(maximumHeight, height));
        return new int[]{availableWidth, height};
    }

    private static View fileCard(Context context, JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type");
        String mime = Jsons.string(attachment, "mime_type");
        if ("audio".equals(type) || mime.startsWith("audio/")) {
            String source = ImageLoader.get().absoluteUrl(context, Jsons.string(attachment, "url"));
            InlineAudioPlayerView player = new InlineAudioPlayerView(
                context, source, Math.min(60_000L, Math.max(0L, Jsons.longValue(attachment, "duration_ms"))));
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(context, 8);
            player.setLayoutParams(params);
            return player;
        }
        MaterialCardView card = new MaterialCardView(context);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(context, 8);
        card.setLayoutParams(params);
        card.setRadius(dp(context, 6));
        card.setCardElevation(0);
        card.setCardBackgroundColor(context.getColor(R.color.surface_container));
        TextView text = new TextView(context);
        text.setMinHeight(dp(context, 60));
        text.setGravity(Gravity.CENTER_VERTICAL);
        text.setPadding(dp(context, 14), dp(context, 10), dp(context, 14), dp(context, 10));
        text.setTextColor(context.getColor(R.color.on_surface));
        text.setTextSize(15);
        RuntimeLanguage.setDynamicText(text, label(attachment));
        card.addView(text);
        card.setOnClickListener(view -> previewFile(context, attachment));
        return card;
    }

    private static String label(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type");
        String name = Jsons.string(attachment, "file_name");
        if ("audio".equals(type)) {
            long seconds = Math.max(1, Jsons.longValue(attachment, "duration_ms") / 1000L);
            return "语音 · " + seconds + " 秒\n点击播放";
        }
        if ("video".equals(type)) return "视频" + (name.isEmpty() ? "" : " · " + name) + "\n点击播放";
        long bytes = Jsons.longValue(attachment, "size_bytes");
        return "文件 · " + (name.isEmpty() ? "未命名文件" : name) + (bytes > 0 ? "\n" + size(bytes) : "");
    }

    private static void previewFile(Context context, JsonObject attachment) {
        JsonObject file = attachment.deepCopy();
        file.addProperty("file_url", Jsons.string(attachment, "url"));
        file.addProperty("original_name", Jsons.string(attachment, "file_name"));
        FilePreviewActivity.open(context, file);
    }

    private static String size(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
        return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
