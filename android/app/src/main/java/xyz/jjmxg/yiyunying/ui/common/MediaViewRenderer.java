package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.text.TextUtils;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.domain.forum.ForumPrivateMediaPolicy;
import xyz.jjmxg.yiyunying.ui.chat.InlineAudioPlayerView;
import xyz.jjmxg.yiyunying.ui.chat.InlineMediaPreviewDialog;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;

public final class MediaViewRenderer {
    public interface PrivateMediaRefreshListener {
        void onPrivateMediaRefreshRequired(long attachmentId);
    }

    private MediaViewRenderer() { }

    public static void render(Context context, LinearLayout container, JsonArray attachments) {
        render(context, container, attachments, null);
    }

    public static void render(
        Context context,
        LinearLayout container,
        JsonArray attachments,
        PrivateMediaRefreshListener privateMediaRefreshListener
    ) {
        render(context, container, attachments, new boolean[]{false}, privateMediaRefreshListener);
    }

    private static void render(
        Context context,
        LinearLayout container,
        JsonArray attachments,
        boolean[] expanded,
        PrivateMediaRefreshListener privateMediaRefreshListener
    ) {
        container.removeAllViews();
        if (attachments == null || attachments.isEmpty()) {
            container.setVisibility(View.GONE);
            return;
        }
        container.setVisibility(View.VISIBLE);
        List<JsonObject> visualMedia = new ArrayList<>();
        List<JsonObject> stickers = new ArrayList<>();
        List<JsonObject> others = new ArrayList<>();
        for (JsonElement element : attachments) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            if ("sticker".equals(mediaType(item))) stickers.add(item);
            else if (isVisual(item)) visualMedia.add(item);
            else others.add(item);
        }

        int visualCount = expanded[0] ? visualMedia.size() : Math.min(visualMedia.size(), 3);
        for (int index = 0; index < visualCount; index++) {
            container.addView(visualCard(context, visualMedia, index, privateMediaRefreshListener));
        }
        if (visualMedia.size() > 3) {
            MaterialButton toggle = new MaterialButton(
                context, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
            toggle.setMinHeight(0);
            toggle.setMinimumHeight(0);
            toggle.setInsetTop(0);
            toggle.setInsetBottom(0);
            toggle.setTextSize(12);
            toggle.setAllCaps(false);
            toggle.setText(expanded[0] ? "收起媒体" : "展开全部 " + visualMedia.size() + " 个媒体");
            toggle.setContentDescription(expanded[0] ? "收起全部媒体" : "展开全部媒体");
            toggle.setOnClickListener(view -> {
                expanded[0] = !expanded[0];
                container.animate().cancel();
                container.animate().alpha(0.35f).setDuration(70L).withEndAction(() -> {
                    render(context, container, attachments, expanded, privateMediaRefreshListener);
                    container.setAlpha(0.35f);
                    container.animate().alpha(1f).setDuration(150L).start();
                }).start();
            });
            LinearLayout.LayoutParams toggleParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(context, 38));
            toggleParams.bottomMargin = dp(context, 8);
            container.addView(toggle, toggleParams);
        }

        for (JsonObject sticker : stickers) {
            container.addView(stickerCard(context, sticker, privateMediaRefreshListener));
        }
        for (JsonObject attachment : others) {
            container.addView(fileCard(context, attachment, privateMediaRefreshListener));
        }
    }

    private static View visualCard(
        Context context,
        List<JsonObject> media,
        int index,
        PrivateMediaRefreshListener privateMediaRefreshListener
    ) {
        JsonObject attachment = media.get(index);
        boolean video = isVideo(attachment);
        boolean animated = isAnimated(attachment);
        MaterialCardView card = baseCard(context);
        int[] dimensions = mediaSize(context, attachment);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dimensions[0], dimensions[1]);
        params.bottomMargin = dp(context, 8);
        card.setLayoutParams(params);

        FrameLayout frame = new FrameLayout(context);
        ImageView image = previewImage(context, attachment);
        frame.addView(image);

        LinearLayout badges = new LinearLayout(context);
        badges.setOrientation(LinearLayout.HORIZONTAL);
        badges.setGravity(Gravity.CENTER_VERTICAL);
        if (animated) badges.addView(badge(context, "动图"));
        if (video) {
            String duration = durationText(Jsons.longValue(attachment, "duration_ms"));
            badges.addView(badge(context, duration.isEmpty() ? "视频" : duration));
        }
        if (badges.getChildCount() > 0) {
            FrameLayout.LayoutParams badgeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(context, 30), Gravity.START | Gravity.TOP);
            badgeParams.leftMargin = dp(context, 8);
            badgeParams.topMargin = dp(context, 8);
            frame.addView(badges, badgeParams);
        }

        if (video) {
            MaterialButton play = new MaterialButton(
                context, null, com.google.android.material.R.attr.materialIconButtonStyle);
            play.setIconResource(R.drawable.ic_play);
            play.setIconTint(ColorStateList.valueOf(Color.WHITE));
            play.setBackgroundTintList(ColorStateList.valueOf(Color.argb(174, 0, 0, 0)));
            play.setContentDescription("播放视频");
            FrameLayout.LayoutParams playParams = new FrameLayout.LayoutParams(
                dp(context, 58), dp(context, 58), Gravity.CENTER);
            frame.addView(play, playParams);
        }

        String info = mediaMeta(attachment);
        if (!info.isEmpty()) {
            TextView meta = new TextView(context);
            meta.setText(info);
            meta.setTextColor(Color.WHITE);
            meta.setTextSize(11);
            meta.setGravity(Gravity.CENTER_VERTICAL);
            meta.setPadding(dp(context, 9), 0, dp(context, 9), 0);
            meta.setBackgroundColor(Color.argb(150, 0, 0, 0));
            meta.setSingleLine(true);
            meta.setEllipsize(TextUtils.TruncateAt.END);
            FrameLayout.LayoutParams metaParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 30), Gravity.BOTTOM);
            frame.addView(meta, metaParams);
        }

        card.addView(frame);
        card.setContentDescription(video ? "视频，点击在当前页面预览" : (animated ? "动图，点击预览" : "图片，点击预览"));
        card.setOnClickListener(view -> {
            if (requestPrivateMediaRefresh(attachment, privateMediaRefreshListener)) return;
            InlineMediaPreviewDialog.show(context, media, index);
        });
        return card;
    }

    private static View stickerCard(
        Context context,
        JsonObject attachment,
        PrivateMediaRefreshListener privateMediaRefreshListener
    ) {
        FrameLayout frame = new FrameLayout(context);
        int size = dp(context, 148);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(size, size);
        params.bottomMargin = dp(context, 8);
        frame.setLayoutParams(params);
        frame.setBackgroundColor(Color.TRANSPARENT);
        ImageView image = previewImage(context, attachment);
        image.setBackgroundColor(Color.TRANSPARENT);
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        frame.addView(image);
        if (isAnimated(attachment)) {
            TextView tag = badge(context, "动图");
            FrameLayout.LayoutParams tagParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(context, 28), Gravity.START | Gravity.TOP);
            frame.addView(tag, tagParams);
        }
        frame.setContentDescription("表情包");
        List<JsonObject> one = new ArrayList<>();
        one.add(attachment);
        frame.setOnClickListener(view -> {
            if (requestPrivateMediaRefresh(attachment, privateMediaRefreshListener)) return;
            InlineMediaPreviewDialog.show(context, one, 0);
        });
        return frame;
    }

    private static MaterialCardView baseCard(Context context) {
        MaterialCardView card = new MaterialCardView(context);
        card.setRadius(dp(context, 7));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(context.getColor(R.color.outline_variant));
        card.setCardBackgroundColor(context.getColor(R.color.surface_container));
        return card;
    }

    private static ImageView previewImage(Context context, JsonObject attachment) {
        ImageView image = new ImageView(context);
        image.setLayoutParams(new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        image.setBackgroundColor(context.getColor(R.color.surface_container));
        String preview = Jsons.string(attachment, "thumbnail_url");
        if (preview.isEmpty() && !isVideo(attachment)) preview = Jsons.string(attachment, "url");
        if (!preview.isEmpty()) {
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(context, preview), image, R.drawable.ic_file);
        } else {
            image.setImageResource(isVideo(attachment) ? R.drawable.ic_video : R.drawable.ic_file);
            image.setPadding(dp(context, 42), dp(context, 42), dp(context, 42), dp(context, 42));
        }
        return image;
    }

    private static TextView badge(Context context, String value) {
        TextView badge = new TextView(context);
        badge.setText(value);
        badge.setTextColor(Color.WHITE);
        badge.setTextSize(10.5f);
        badge.setGravity(Gravity.CENTER);
        badge.setPadding(dp(context, 8), 0, dp(context, 8), 0);
        GradientDrawable background = new GradientDrawable();
        background.setColor(Color.argb(174, 0, 0, 0));
        background.setCornerRadius(dp(context, 6));
        badge.setBackground(background);
        return badge;
    }

    private static int[] mediaSize(Context context, JsonObject attachment) {
        int screenWidth = context.getResources().getDisplayMetrics().widthPixels;
        int width = Math.min(screenWidth - dp(context, 48), dp(context, 356));
        width = Math.max(dp(context, 220), width);
        long sourceWidth = dimension(attachment, "width");
        long sourceHeight = dimension(attachment, "height");
        float ratio = sourceWidth > 0 && sourceHeight > 0
            ? sourceWidth / (float) sourceHeight : (isVideo(attachment) ? 16f / 9f : 4f / 3f);
        int height;
        if (ratio < 0.82f) height = Math.min(dp(context, 292), Math.round(width / 0.82f));
        else if (ratio > 1.35f) height = Math.max(dp(context, 196), Math.round(width / 1.62f));
        else height = Math.round(width / 1.05f);
        return new int[]{width, height};
    }

    private static long dimension(JsonObject item, String key) {
        long value = Jsons.longValue(item, key);
        if (value <= 0) value = Jsons.longValue(Jsons.object(item, "metadata"), key);
        return value;
    }

    private static View fileCard(
        Context context,
        JsonObject attachment,
        PrivateMediaRefreshListener privateMediaRefreshListener
    ) {
        String type = mediaType(attachment);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        if ("audio".equals(type) || "voice".equals(type) || mime.startsWith("audio/")) {
            boolean voice = "voice".equals(type)
                || "voice".equalsIgnoreCase(Jsons.string(Jsons.object(attachment, "metadata"), "audio_kind"))
                || "recorded_voice".equalsIgnoreCase(Jsons.string(Jsons.object(attachment, "metadata"), "audio_kind"));
            InlineAudioPlayerView player = new InlineAudioPlayerView(
                context,
                ImageLoader.get().absoluteUrl(context, mediaUrl(attachment)),
                Math.max(0L, Jsons.longValue(attachment, "duration_ms")),
                voice,
                () -> !requestPrivateMediaRefresh(attachment, privateMediaRefreshListener));
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(context, 8);
            player.setLayoutParams(params);
            return player;
        }

        MaterialCardView card = baseCard(context);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(context, 8);
        card.setLayoutParams(params);

        LinearLayout row = new LinearLayout(context);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setMinimumHeight(dp(context, 82));
        row.setPadding(dp(context, 12), dp(context, 10), dp(context, 10), dp(context, 10));

        FrameLayout iconPanel = new FrameLayout(context);
        GradientDrawable iconBackground = new GradientDrawable();
        iconBackground.setColor(ThemeColors.primaryContainer(context));
        iconBackground.setCornerRadius(dp(context, 7));
        iconPanel.setBackground(iconBackground);
        LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(context, 52), dp(context, 58));
        iconParams.rightMargin = dp(context, 11);
        row.addView(iconPanel, iconParams);

        ImageView icon = new ImageView(context);
        String fileType = friendlyFileType(attachment);
        icon.setImageResource(fileIcon(fileType));
        icon.setImageTintList(ColorStateList.valueOf(ThemeColors.primary(context)));
        icon.setContentDescription(fileType);
        iconPanel.addView(icon, new FrameLayout.LayoutParams(dp(context, 28), dp(context, 28), Gravity.CENTER));

        LinearLayout labels = new LinearLayout(context);
        labels.setOrientation(LinearLayout.VERTICAL);
        TextView name = new TextView(context);
        name.setText(attachmentName(attachment));
        name.setTextColor(context.getColor(R.color.on_surface));
        name.setTextSize(14);
        name.setMaxLines(2);
        name.setEllipsize(TextUtils.TruncateAt.END);
        TextView meta = new TextView(context);
        meta.setText(fileType + fileSizeSuffix(attachment) + "\n点击在软件内预览");
        meta.setTextColor(context.getColor(R.color.on_surface_variant));
        meta.setTextSize(11.5f);
        meta.setMaxLines(2);
        meta.setPadding(0, dp(context, 4), 0, 0);
        labels.addView(name);
        labels.addView(meta);
        row.addView(labels, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        ImageView open = new ImageView(context);
        open.setImageResource(R.drawable.ic_chevron_right);
        open.setImageTintList(ColorStateList.valueOf(context.getColor(R.color.on_surface_variant)));
        open.setContentDescription("预览文件");
        LinearLayout.LayoutParams openParams = new LinearLayout.LayoutParams(dp(context, 22), dp(context, 22));
        openParams.leftMargin = dp(context, 7);
        row.addView(open, openParams);

        card.addView(row);
        card.setOnClickListener(view -> {
            if (requestPrivateMediaRefresh(attachment, privateMediaRefreshListener)) return;
            previewFile(context, attachment);
        });
        return card;
    }

    private static boolean requestPrivateMediaRefresh(
        JsonObject attachment,
        PrivateMediaRefreshListener listener
    ) {
        if (listener == null
            || !ForumPrivateMediaPolicy.shouldRefresh(attachment, System.currentTimeMillis())) {
            return false;
        }
        listener.onPrivateMediaRefreshRequired(
            ForumPrivateMediaPolicy.privateAttachmentId(attachment));
        return true;
    }

    private static String attachmentName(JsonObject attachment) {
        String name = Jsons.string(attachment, "original_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "file_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "name");
        return name.isEmpty() ? "未命名文件" : name;
    }

    private static String friendlyFileType(JsonObject attachment) {
        String extension = extensionOf(attachmentName(attachment));
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        if ("apk".equals(extension)) return "安卓安装包";
        if ("hap".equals(extension)) return "鸿蒙安装包";
        if ("ipa".equals(extension)) return "苹果安装包";
        if ("exe".equals(extension)) return "Windows 应用";
        if ("pdf".equals(extension)) return "PDF 文档";
        if ("doc".equals(extension) || "docx".equals(extension)) return "Word 文档";
        if ("xls".equals(extension) || "xlsx".equals(extension)) return "Excel 表格";
        if ("ppt".equals(extension) || "pptx".equals(extension)) return "演示文稿";
        if ("txt".equals(extension) || "md".equals(extension)) return "文本文档";
        if ("zip".equals(extension) || "7z".equals(extension) || "rar".equals(extension)
            || "tar".equals(extension) || "gz".equals(extension) || "bz2".equals(extension)
            || "xz".equals(extension)) return extension.toUpperCase(Locale.ROOT) + " 压缩包";
        if (mime.startsWith("video/")) return "视频文件";
        if (mime.startsWith("audio/")) return "音频文件";
        if (mime.startsWith("image/")) return "图片文件";
        if (isSourceExtension(extension)) return extension.toUpperCase(Locale.ROOT) + " 源码";
        return extension.isEmpty() ? "普通文件" : extension.toUpperCase(Locale.ROOT) + " 文件";
    }

    private static int fileIcon(String type) {
        if (type.contains("视频")) return R.drawable.ic_video;
        if (type.contains("压缩包")) return R.drawable.ic_folder;
        if (type.contains("文档") || type.contains("表格") || type.contains("演示") || type.contains("源码")) {
            return R.drawable.ic_document;
        }
        return R.drawable.ic_file;
    }

    private static String fileSizeSuffix(JsonObject attachment) {
        long bytes = Jsons.longValue(attachment, "size_bytes");
        if (bytes <= 0) bytes = Jsons.longValue(Jsons.object(attachment, "metadata"), "size_bytes");
        return bytes > 0 ? "  ·  " + size(bytes) : "";
    }

    private static String mediaMeta(JsonObject attachment) {
        long bytes = Jsons.longValue(attachment, "size_bytes");
        String name = attachmentName(attachment);
        boolean generic = "未命名文件".equals(name);
        StringBuilder text = new StringBuilder();
        if (!generic) text.append(name);
        if (bytes > 0) {
            if (text.length() > 0) text.append("  ·  ");
            text.append(size(bytes));
        }
        return text.toString();
    }

    private static void previewFile(Context context, JsonObject attachment) {
        JsonObject file = attachment.deepCopy();
        file.addProperty("file_url", mediaUrl(attachment));
        file.addProperty("preview_url", previewUrl(attachment));
        file.addProperty("original_name", attachmentName(attachment));
        FilePreviewActivity.open(context, file);
    }

    private static String mediaUrl(JsonObject attachment) {
        return firstText(attachment, "file_url", "media_url", "url", "download_url",
            "original_file_url", "source_url");
    }

    private static String previewUrl(JsonObject attachment) {
        return firstText(attachment, "thumbnail_url", "preview_url", "optimized_file_url",
            "poster_url", "cover_url", "image_url", "url", "file_url");
    }

    private static String firstText(JsonObject attachment, String... keys) {
        if (attachment == null) return "";
        for (String key : keys) {
            String value = Jsons.string(attachment, key);
            if (!value.isEmpty()) return value;
        }
        JsonObject metadata = Jsons.object(attachment, "metadata");
        for (String key : keys) {
            String value = Jsons.string(metadata, key);
            if (!value.isEmpty()) return value;
        }
        return "";
    }

    private static String mediaType(JsonObject attachment) {
        return firstText(attachment, "media_type", "file_category", "type").toLowerCase(Locale.ROOT);
    }

    private static boolean isVisual(JsonObject attachment) {
        String type = mediaType(attachment);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        return "image".equals(type) || "video".equals(type) || "gif".equals(type)
            || "motion_photo".equals(type) || mime.startsWith("image/") || mime.startsWith("video/");
    }

    private static boolean isVideo(JsonObject attachment) {
        return "video".equals(mediaType(attachment))
            || Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT).startsWith("video/");
    }

    private static boolean isAnimated(JsonObject attachment) {
        String type = mediaType(attachment);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        String name = attachmentName(attachment).toLowerCase(Locale.ROOT);
        return "gif".equals(type) || "motion_photo".equals(type) || "image/gif".equals(mime)
            || name.endsWith(".gif");
    }

    private static String extensionOf(String name) {
        int dot = name == null ? -1 : name.lastIndexOf('.');
        return dot < 0 || dot == name.length() - 1
            ? "" : name.substring(dot + 1).toLowerCase(Locale.ROOT);
    }

    private static boolean isSourceExtension(String extension) {
        return "java".equals(extension) || "kt".equals(extension) || "py".equals(extension)
            || "php".equals(extension) || "html".equals(extension) || "css".equals(extension)
            || "js".equals(extension) || "ts".equals(extension) || "sql".equals(extension)
            || "c".equals(extension) || "cpp".equals(extension) || "h".equals(extension)
            || "rs".equals(extension) || "go".equals(extension) || "xml".equals(extension)
            || "json".equals(extension) || "iapp".equals(extension);
    }

    private static String durationText(long millis) {
        if (millis <= 0) return "";
        long seconds = millis / 1000L;
        return String.format(Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
    }

    private static String size(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
        if (bytes < 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
        return String.format(Locale.CHINA, "%.2f GB", bytes / 1024f / 1024f / 1024f);
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
