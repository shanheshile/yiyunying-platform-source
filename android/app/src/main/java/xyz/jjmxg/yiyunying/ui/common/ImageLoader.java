package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.widget.ImageView;

import com.bumptech.glide.Glide;
import com.bumptech.glide.load.DecodeFormat;
import com.bumptech.glide.load.engine.DiskCacheStrategy;
import com.bumptech.glide.signature.ObjectKey;

import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

import xyz.jjmxg.yiyunying.core.AppAccess;

public final class ImageLoader {
    private static final ImageLoader INSTANCE = new ImageLoader();
    private final Map<String, Long> versions = new ConcurrentHashMap<>();

    private ImageLoader() { }

    public static ImageLoader get() { return INSTANCE; }

    public void invalidate(String url) {
        if (url != null && !url.trim().isEmpty()) versions.put(url.trim(), System.currentTimeMillis());
    }

    public void load(String url, ImageView target, int placeholder) {
        if (url == null || !(url.startsWith("http://") || url.startsWith("https://")
            || url.startsWith("content://") || url.startsWith("file://"))) {
            Glide.with(target).clear(target);
            target.setImageResource(placeholder);
            return;
        }
        Glide.with(target)
            .load(url)
            .signature(new ObjectKey(versions.getOrDefault(url, 0L)))
            .diskCacheStrategy(DiskCacheStrategy.AUTOMATIC)
            .placeholder(placeholder)
            .error(placeholder)
            .into(target);
    }

    public void loadThumbnail(String url, ImageView target, int placeholder) {
        if (url == null || !(url.startsWith("http://") || url.startsWith("https://")
            || url.startsWith("content://") || url.startsWith("file://"))) {
            Glide.with(target).clear(target);
            target.setImageResource(placeholder);
            return;
        }
        Glide.with(target)
            .load(url)
            .signature(new ObjectKey(versions.getOrDefault(url, 0L)))
            .format(DecodeFormat.PREFER_RGB_565)
            .diskCacheStrategy(DiskCacheStrategy.AUTOMATIC)
            .dontAnimate()
            .placeholder(placeholder)
            .error(placeholder)
            .into(target);
    }

    public void clear(Context context) {
        Glide.get(context).clearMemory();
        new Thread(() -> Glide.get(context.getApplicationContext()).clearDiskCache(), "image-cache-clear").start();
    }

    public String absoluteUrl(Context context, String url) {
        if (url == null) return "";
        String value = url.trim();
        if (value.startsWith("http://") || value.startsWith("https://")) return value;
        if (!value.startsWith("/")) return "";
        String base = AppAccess.from(context).session().baseUrl();
        return base.endsWith("/") ? base.substring(0, base.length() - 1) + value : base + value;
    }
}
