package xyz.jjmxg.yiyunying.ui.browser;

import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.text.SpannableString;
import android.text.Spanned;
import android.text.TextPaint;
import android.text.method.LinkMovementMethod;
import android.text.style.ClickableSpan;
import android.text.util.Linkify;
import android.view.View;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;

import xyz.jjmxg.yiyunying.R;

public final class LinkNavigator {
    private LinkNavigator() { }

    public static boolean open(Context context, String rawUrl) {
        String value = rawUrl == null ? "" : rawUrl.trim();
        if (value.isEmpty()) return false;
        Uri uri;
        try {
            uri = Uri.parse(value);
            if (uri.getScheme() == null && value.matches("(?i)^[a-z0-9.-]+\\.[a-z]{2,}([/:?#].*)?$")) {
                uri = Uri.parse("https://" + value);
            }
        } catch (RuntimeException exception) {
            return false;
        }
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase();
        try {
            if ("http".equals(scheme) || "https".equals(scheme)) {
                InAppBrowserActivity.open(context, uri.toString());
                return true;
            }
            context.startActivity(new Intent(Intent.ACTION_VIEW, uri));
            return true;
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    public static void setTextWithLinks(TextView view, String text) {
        SpannableString value = new SpannableString(text == null ? "" : text);
        Linkify.addLinks(value, Linkify.WEB_URLS);
        android.text.style.URLSpan[] links = value.getSpans(0, value.length(), android.text.style.URLSpan.class);
        for (android.text.style.URLSpan link : links) {
            int start = value.getSpanStart(link);
            int end = value.getSpanEnd(link);
            int flags = value.getSpanFlags(link);
            String url = link.getURL();
            value.removeSpan(link);
            value.setSpan(new ClickableSpan() {
                @Override public void onClick(@NonNull View widget) {
                    open(widget.getContext(), url);
                }

                @Override public void updateDrawState(@NonNull TextPaint paint) {
                    paint.setColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(view.getContext()));
                    paint.setUnderlineText(false);
                }
            }, start, end, flags | Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
        }
        view.setText(value);
        view.setMovementMethod(links.length == 0 ? null : LinkMovementMethod.getInstance());
        view.setHighlightColor(Color.TRANSPARENT);
    }
}
