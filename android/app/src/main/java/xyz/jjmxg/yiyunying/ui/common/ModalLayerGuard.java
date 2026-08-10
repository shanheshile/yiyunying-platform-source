package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.graphics.drawable.ColorDrawable;
import android.view.View;
import android.view.ViewGroup;
import android.widget.HorizontalScrollView;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.core.content.ContextCompat;
import androidx.core.view.ViewCompat;
import androidx.core.widget.NestedScrollView;
import androidx.recyclerview.widget.RecyclerView;

import xyz.jjmxg.yiyunying.R;

/**
 * Enforces the drawing boundary shared by alert dialogs and bottom sheets.
 *
 * Several vendor renderers continue drawing a scrolling child outside its viewport when an
 * ancestor disables clipping for rounded-corner shadows. The result looks like content passing
 * through a fixed title or action row. Keeping the outer card free to draw its shadow while
 * restoring clipping at every actual scrolling viewport prevents that class of overlap without
 * requiring each feature screen to duplicate z-order workarounds.
 */
public final class ModalLayerGuard {
    private ModalLayerGuard() { }

    /** Applies readable theme colors and separates fixed alert chrome from scrolling content. */
    public static void protectAlertDialog(View decor, Context context) {
        if (decor == null || context == null) return;

        View topPanel = findAlertPanel(decor, "topPanel");
        View contentPanel = findAlertPanel(decor, "contentPanel");
        View customPanel = findAlertPanel(decor, "customPanel");
        View buttonPanel = findAlertPanel(decor, "buttonPanel");

        protectFixedRegion(topPanel, context, 4);
        protectFixedRegion(buttonPanel, context, 4);
        protectContentRegion(contentPanel);
        protectContentRegion(customPanel);

        TextView title = asText(findAlertPanel(decor, "alertTitle"));
        if (title != null) title.setTextColor(ContextCompat.getColor(context, R.color.on_surface));

        TextView message = decor.findViewById(android.R.id.message);
        if (message != null) {
            message.setTextColor(ContextCompat.getColor(context, R.color.on_surface_variant));
        }

        // setItems() uses android.R.id.text1. Cover every row because a dialog can contain a
        // recycled list and findViewById() would otherwise style only the first attached row.
        styleKnownDialogRows(decor, context);
        clipScrollableDescendants(decor);
        installDialogRowRefresh(decor, context);
    }

    /** Restores hard clipping at every real bottom-sheet viewport. */
    public static void protectBottomSheet(View content, Context context) {
        if (content == null || context == null) return;
        if (content instanceof ViewGroup) {
            ViewGroup root = (ViewGroup) content;
            root.setClipChildren(true);
            root.setClipToPadding(true);
        }
        clipScrollableDescendants(content);
    }

    private static void protectFixedRegion(View region, Context context, int elevationDp) {
        if (region == null) return;
        // The fixed panel must be opaque. A translucent title still exposes a list item even if
        // z-order is correct, which users reasonably perceive as content penetration.
        if (region.getBackground() == null
            || region.getBackground() instanceof ColorDrawable
            && ((ColorDrawable) region.getBackground()).getAlpha() < 255) {
            region.setBackgroundColor(ContextCompat.getColor(context, R.color.surface_container_high));
        }
        ViewCompat.setElevation(region, dp(context, elevationDp));
        region.setTranslationZ(dp(context, 1));
        if (region instanceof ViewGroup) {
            ViewGroup group = (ViewGroup) region;
            group.setClipChildren(true);
            group.setClipToPadding(true);
        }
    }

    private static void protectContentRegion(View region) {
        if (!(region instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) region;
        group.setClipChildren(true);
        group.setClipToPadding(true);
        group.setTranslationZ(0f);
    }

    private static void clipScrollableDescendants(View view) {
        if (isScrollingViewport(view) && view instanceof ViewGroup) {
            ViewGroup viewport = (ViewGroup) view;
            viewport.setClipChildren(true);
            viewport.setClipToPadding(true);
            viewport.setOverScrollMode(View.OVER_SCROLL_IF_CONTENT_SCROLLS);
        }
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            clipScrollableDescendants(group.getChildAt(index));
        }
    }

    private static boolean isScrollingViewport(View view) {
        return view instanceof ScrollView
            || view instanceof HorizontalScrollView
            || view instanceof NestedScrollView
            || view instanceof RecyclerView;
    }

    private static void styleKnownDialogRows(View view, Context context) {
        if (view instanceof TextView && view.getId() == android.R.id.text1) {
            TextView row = (TextView) view;
            String label = row.getText() == null ? "" : row.getText().toString();
            int expected = ContextCompat.getColor(context,
                ActionIconResolver.destructive(label) ? R.color.error : R.color.on_surface);
            if (row.getTextColors().getDefaultColor() != expected) row.setTextColor(expected);
            ActionIconResolver.apply(row, label, 0);
        }
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            styleKnownDialogRows(group.getChildAt(index), context);
        }
    }

    private static void installDialogRowRefresh(View decor, Context context) {
        if (Boolean.TRUE.equals(decor.getTag(R.id.tag_modal_layer_guard_observer))) return;
        decor.setTag(R.id.tag_modal_layer_guard_observer, Boolean.TRUE);
        // ListView rows are often created only during the first layout and are recycled later.
        // A cheap id/color check before drawing keeps every newly attached share/action row in
        // sync with the active theme without replacing feature-owned listeners or adapters.
        decor.getViewTreeObserver().addOnPreDrawListener(() -> {
            if (decor.isAttachedToWindow()) styleKnownDialogRows(decor, context);
            return true;
        });
    }

    private static TextView asText(View view) {
        return view instanceof TextView ? (TextView) view : null;
    }

    static View findAlertPanel(View root, String name) {
        if (root == null || name == null) return null;
        int id;
        switch (name) {
            case "parentPanel": id = androidx.appcompat.R.id.parentPanel; break;
            case "topPanel": id = androidx.appcompat.R.id.topPanel; break;
            case "contentPanel": id = androidx.appcompat.R.id.contentPanel; break;
            case "customPanel": id = androidx.appcompat.R.id.customPanel; break;
            case "buttonPanel": id = androidx.appcompat.R.id.buttonPanel; break;
            case "alertTitle": id = androidx.appcompat.R.id.alertTitle; break;
            default: return null;
        }
        return root.findViewById(id);
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
