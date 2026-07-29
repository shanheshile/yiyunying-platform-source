package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.os.Build;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.FrameLayout;

import com.google.android.material.bottomsheet.BottomSheetBehavior;
import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.button.MaterialButton;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import xyz.jjmxg.yiyunying.R;

/** Applies the same readable, touch-safe glass treatment to every bottom sheet. */
public final class GlassBottomSheet {
    private static final String FLOATING_PANEL_TAG = "yiyunying:floating-bottom-sheet";

    private GlassBottomSheet() { }

    public static void prepare(BottomSheetDialog dialog, Context context) {
        prepare(dialog, context, 0.92f, false);
    }

    public static void prepare(BottomSheetDialog dialog, Context context, float maxHeightRatio,
                               boolean alwaysExpanded) {
        // All app bottom sheets share the same inset panel treatment. Keeping a second,
        // edge-attached style caused actions such as "立即更新" to lose their bottom corners
        // and made short detail sheets reserve a large empty area.
        prepareInternal(dialog, context, maxHeightRatio, alwaysExpanded, true);
    }

    /**
     * Presents a bottom sheet as an inset floating panel. This keeps every corner visible and
     * avoids the clipped, straight bottom edge produced by the platform dialog button bar.
     */
    public static void prepareFloating(BottomSheetDialog dialog, Context context, float maxHeightRatio,
                                       boolean alwaysExpanded) {
        prepareInternal(dialog, context, maxHeightRatio, alwaysExpanded, true);
    }

    private static void prepareInternal(BottomSheetDialog dialog, Context context, float maxHeightRatio,
                                        boolean alwaysExpanded, boolean floating) {
        Window window = dialog.getWindow();
        if (window != null) {
            window.setDimAmount(0.18f);
            window.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
            if (Build.VERSION.SDK_INT >= 31) {
                window.addFlags(WindowManager.LayoutParams.FLAG_BLUR_BEHIND);
                window.getAttributes().setBlurBehindRadius(dp(context, 24));
            }
        }
        dialog.setOnShowListener(ignored -> {
            FrameLayout sheet = dialog.findViewById(com.google.android.material.R.id.design_bottom_sheet);
            if (sheet == null) return;
            if (floating) {
                sheet.setBackgroundColor(Color.TRANSPARENT);
                sheet.setPadding(0, dp(context, 8), 0, 0);
                sheet.setClipToOutline(false);
                sheet.setClipToPadding(false);
                sheet.setClipChildren(false);
                unclipParent(sheet);
                styleFloatingContent(sheet, context);
            } else {
                GradientDrawable background = new GradientDrawable();
                background.setColor(context.getColor(R.color.glass_surface_strong));
                float radius = dp(context, 18);
                background.setCornerRadii(new float[]{radius, radius, radius, radius, 0, 0, 0, 0});
                background.setStroke(dp(context, 1), context.getColor(R.color.glass_outline));
                sheet.setBackground(background);
                sheet.setClipToOutline(true);
            }

            BottomSheetBehavior<FrameLayout> behavior = BottomSheetBehavior.from(sheet);
            behavior.setSkipCollapsed(true);
            behavior.setFitToContents(true);
            if (floating) {
                installFloatingInsets(
                    sheet,
                    context,
                    () -> sizeToContent(sheet, behavior, context, maxHeightRatio, alwaysExpanded)
                );
            }
            // Material initially measures design_bottom_sheet at almost full-screen height even
            // when its child is short. Comparing that container height left a large blank area
            // under detail cards. Measure the real content with an unconstrained height instead.
            sheet.post(() -> sizeToContent(sheet, behavior, context, maxHeightRatio, alwaysExpanded));
            sheet.setAlpha(0f);
            sheet.setTranslationY(dp(context, 24));
            sheet.animate().alpha(1f).translationY(0f).setDuration(180L).start();
        });
    }

    private static void styleFloatingContent(FrameLayout sheet, Context context) {
        if (FLOATING_PANEL_TAG.equals(sheet.getTag())) return;
        sheet.setTag(FLOATING_PANEL_TAG);

        // design_bottom_sheet itself is animated and resized by Material. Styling its direct
        // child still lets some vendor builds clip the child's antialiased bottom edge. Move the
        // real content into an independent card and leave the host completely transparent.
        View[] children = new View[sheet.getChildCount()];
        for (int index = 0; index < children.length; index++) children[index] = sheet.getChildAt(index);
        sheet.removeAllViews();

        android.widget.LinearLayout contentHost = new android.widget.LinearLayout(context);
        contentHost.setOrientation(android.widget.LinearLayout.VERTICAL);
        contentHost.setClipChildren(false);
        contentHost.setClipToPadding(false);
        // Keep the final action row away from the visible outline. Several vendor builds
        // remeasure the sheet after applying navigation insets and otherwise crop the
        // antialiased lower corners of the primary action.
        contentHost.setPadding(0, 0, 0, dp(context, 20));
        contentHost.setOutlineProvider(null);
        contentHost.setLayerType(View.LAYER_TYPE_SOFTWARE, null);
        for (View child : children) {
            if (child.getParent() instanceof ViewGroup) ((ViewGroup) child.getParent()).removeView(child);
            child.setBackgroundColor(Color.TRANSPARENT);
            child.setClipToOutline(false);
            if (child instanceof ViewGroup) {
                ViewGroup content = (ViewGroup) child;
                content.setClipToPadding(false);
                content.setClipChildren(false);
                content.setPadding(
                    content.getPaddingLeft(),
                    content.getPaddingTop(),
                    content.getPaddingRight(),
                    Math.max(content.getPaddingBottom(), dp(context, 16))
                );
            }
            contentHost.addView(child, new android.widget.LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        }

        // Do not use MaterialCardView here. Some Xiaomi/vivo Android 16 builds restore its
        // platform outline during the bottom-sheet's second measure pass and flatten the lower
        // edge of the final action button. A software-drawn FrameLayout keeps all four corners
        // independent from Material's shape/outline lifecycle.
        FrameLayout panel = new FrameLayout(context);
        GradientDrawable panelBackground = new GradientDrawable();
        panelBackground.setColor(context.getColor(R.color.glass_surface_strong));
        panelBackground.setCornerRadius(dp(context, 22));
        panelBackground.setStroke(dp(context, 1), context.getColor(R.color.glass_outline));
        panel.setBackground(panelBackground);
        panel.setClipToOutline(false);
        panel.setClipChildren(false);
        panel.setClipToPadding(false);
        panel.setOutlineProvider(null);
        panel.setLayerType(View.LAYER_TYPE_SOFTWARE, null);
        panel.setElevation(0f);
        panel.setStateListAnimator(null);
        panel.addView(contentHost, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        FrameLayout.LayoutParams panelParams = new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        panelParams.leftMargin = dp(context, 12);
        panelParams.rightMargin = dp(context, 12);
        panelParams.bottomMargin = dp(context, 12);
        sheet.addView(panel, panelParams);
    }

    private static void installFloatingInsets(FrameLayout sheet, Context context, Runnable resize) {
        final int baseSide = dp(context, 12);
        final int baseBottom = dp(context, 12);
        ViewCompat.setOnApplyWindowInsetsListener(sheet, (view, windowInsets) -> {
            Insets safe = windowInsets.getInsets(
                WindowInsetsCompat.Type.navigationBars() | WindowInsetsCompat.Type.displayCutout()
            );
            // BottomSheetBehavior can discard or clamp margins on design_bottom_sheet itself.
            // Keep that host transparent and edge-attached, then inset the independent card.
            // This guarantees that all four rounded corners remain inside the visible host even
            // on vendor Android 16 builds that relayout the sheet after it has been shown.
            View panel = view instanceof ViewGroup && ((ViewGroup) view).getChildCount() > 0
                ? ((ViewGroup) view).getChildAt(0) : null;
            ViewGroup.LayoutParams raw = panel == null ? null : panel.getLayoutParams();
            if (raw instanceof ViewGroup.MarginLayoutParams) {
                ViewGroup.MarginLayoutParams margins = (ViewGroup.MarginLayoutParams) raw;
                int left = baseSide + safe.left;
                int right = baseSide + safe.right;
                int bottom = baseBottom;
                if (margins.leftMargin != left || margins.rightMargin != right
                    || margins.bottomMargin != bottom) {
                    margins.leftMargin = left;
                    margins.rightMargin = right;
                    margins.bottomMargin = bottom;
                    panel.setLayoutParams(margins);
                }
            }
            int safeBottom = Math.max(dp(context, 8), safe.bottom);
            if (view.getPaddingBottom() != safeBottom) {
                view.setPadding(
                    view.getPaddingLeft(),
                    view.getPaddingTop(),
                    view.getPaddingRight(),
                    safeBottom
                );
            }
            view.post(resize);
            return windowInsets;
        });
        ViewCompat.requestApplyInsets(sheet);
    }

    private static void unclipParent(View view) {
        android.view.ViewParent current = view.getParent();
        while (current instanceof ViewGroup) {
            ViewGroup parent = (ViewGroup) current;
            parent.setClipChildren(false);
            parent.setClipToPadding(false);
            current = parent.getParent();
        }
    }

    private static void sizeToContent(FrameLayout sheet, BottomSheetBehavior<FrameLayout> behavior,
                                      Context context, float maxHeightRatio, boolean alwaysExpanded) {
        if (!sheet.isAttachedToWindow()) return;
        int screenHeight = context.getResources().getDisplayMetrics().heightPixels;
        int screenWidth = context.getResources().getDisplayMetrics().widthPixels;
        int horizontalMargins = 0;
        ViewGroup.LayoutParams rawParams = sheet.getLayoutParams();
        if (rawParams instanceof ViewGroup.MarginLayoutParams) {
            ViewGroup.MarginLayoutParams margins = (ViewGroup.MarginLayoutParams) rawParams;
            horizontalMargins = margins.leftMargin + margins.rightMargin;
        }
        int maxHeight = Math.round(screenHeight * Math.max(0.45f, Math.min(0.96f, maxHeightRatio)));
        int desiredHeight = sheet.getPaddingTop() + sheet.getPaddingBottom();
        int childWidth = Math.max(1, screenWidth - horizontalMargins
            - sheet.getPaddingLeft() - sheet.getPaddingRight());
        int heightSpec = View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED);
        for (int index = 0; index < sheet.getChildCount(); index++) {
            View child = sheet.getChildAt(index);
            int childHorizontalMargins = 0;
            int childVerticalMargins = 0;
            ViewGroup.LayoutParams childParams = child.getLayoutParams();
            if (childParams instanceof ViewGroup.MarginLayoutParams) {
                ViewGroup.MarginLayoutParams margins = (ViewGroup.MarginLayoutParams) childParams;
                childHorizontalMargins = margins.leftMargin + margins.rightMargin;
                childVerticalMargins = margins.topMargin + margins.bottomMargin;
            }
            int widthSpec = View.MeasureSpec.makeMeasureSpec(
                Math.max(1, childWidth - childHorizontalMargins), View.MeasureSpec.EXACTLY);
            child.measure(widthSpec, heightSpec);
            desiredHeight += child.getMeasuredHeight() + childVerticalMargins;
        }
        if (desiredHeight <= sheet.getPaddingTop() + sheet.getPaddingBottom()) {
            desiredHeight = sheet.getMeasuredHeight();
        }
        desiredHeight += dp(context, 8);
        boolean limitHeight = alwaysExpanded || desiredHeight > maxHeight;
        int visibleHeight = limitHeight ? maxHeight : Math.max(dp(context, 96), desiredHeight);
        ViewGroup.LayoutParams params = sheet.getLayoutParams();
        params.width = ViewGroup.LayoutParams.MATCH_PARENT;
        params.height = visibleHeight;
        sheet.setLayoutParams(params);
        behavior.setPeekHeight(visibleHeight, false);
        behavior.setState(BottomSheetBehavior.STATE_EXPANDED);
    }

    public static void makeTouchTarget(View view, Context context) {
        if (view == null) return;
        view.setMinimumWidth(dp(context, 48));
        view.setMinimumHeight(dp(context, 48));
        view.setClickable(true);
        view.setFocusable(true);
    }

    /** Styles actions without calling MaterialButton APIs that require its original background. */
    public static void styleActionButton(MaterialButton button, Context context, boolean primary) {
        styleActionButton(button, context, primary, 16);
    }

    public static void styleActionButton(MaterialButton button, Context context, boolean primary,
                                         int radiusDp) {
        if (button == null) return;
        boolean firstStylePass = !Boolean.TRUE.equals(
            button.getTag(R.id.tag_glass_action_button_styled));
        if (firstStylePass) {
            // Insets belong to MaterialButton's managed background. Reset them exactly once,
            // before replacing that background; calling these APIs on later passes is unsafe.
            button.setInsetTop(0);
            button.setInsetBottom(0);
        }
        float radius = dp(context, Math.max(8, radiusDp));
        button.setMinHeight(dp(context, 48));
        button.setMinimumHeight(dp(context, 48));
        button.setMinWidth(dp(context, 48));
        button.setMinimumWidth(dp(context, 48));
        button.setElevation(0f);
        button.setClipToOutline(false);
        // Xiaomi/vivo Android 16 can keep MaterialButton's stale platform outline after its
        // managed background is replaced. The stale outline clips the final few pixels and turns
        // the lower corners into a straight edge. Draw the explicit rounded ripple in software
        // and remove that vendor outline altogether.
        button.setOutlineProvider(null);
        button.setLayerType(View.LAYER_TYPE_SOFTWARE, null);
        button.setTranslationZ(0f);
        button.setStateListAnimator(null);
        button.setGravity(android.view.Gravity.CENTER);
        button.setAllCaps(false);
        int fill = primary ? ThemeColors.primary(context) : context.getColor(R.color.glass_surface);
        int text = primary ? ThemeColors.onPrimary(context) : context.getColor(R.color.on_surface);
        int stroke = primary ? Color.TRANSPARENT : context.getColor(R.color.outline_variant);
        button.setTextColor(text);

        // MaterialButton may rebuild its managed background after attachment on Xiaomi/vivo.
        // An explicit ripple with a full rounded content shape cannot be flattened by that pass.
        GradientDrawable content = roundedDrawable(fill, stroke, radius, context);
        GradientDrawable mask = roundedDrawable(Color.WHITE, Color.TRANSPARENT, radius, context);
        int rippleColor = Color.argb(primary ? 54 : 34, 0, 0, 0);
        button.setBackground(new RippleDrawable(ColorStateList.valueOf(rippleColor), content, mask));
        button.setTag(R.id.tag_glass_action_button_styled, Boolean.TRUE);
        button.setPadding(dp(context, 10), dp(context, 4), dp(context, 10), dp(context, 4));
        makeTouchTarget(button, context);
    }

    private static GradientDrawable roundedDrawable(int fill, int stroke, float radius,
                                                     Context context) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(fill);
        drawable.setCornerRadius(radius);
        if (stroke != Color.TRANSPARENT) drawable.setStroke(dp(context, 1), stroke);
        return drawable;
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
