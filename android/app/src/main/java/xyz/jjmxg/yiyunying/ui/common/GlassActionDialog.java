package xyz.jjmxg.yiyunying.ui.common;

import android.app.Dialog;
import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.os.Build;
import android.view.Gravity;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.GridLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.text.TextUtils;

import androidx.annotation.DrawableRes;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;

import java.util.List;

import xyz.jjmxg.yiyunying.R;

public final class GlassActionDialog {
    private GlassActionDialog() { }

    public static final class Action {
        public final String label;
        public final int icon;
        public final Runnable callback;

        public Action(String label, @DrawableRes int icon, Runnable callback) {
            this.label = label;
            this.icon = icon;
            this.callback = callback;
        }
    }

    public static Dialog show(Context context, String title, List<Action> actions) {
        return showInternal(context, title, actions, false);
    }

    public static Dialog showCompact(Context context, List<Action> actions) {
        return showInternal(context, "", actions, true);
    }

    private static Dialog showInternal(Context context, String title, List<Action> actions, boolean compact) {
        Dialog dialog = new Dialog(context);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setCanceledOnTouchOutside(true);

        MaterialCardView glass = new MaterialCardView(context);
        glass.setRadius(dp(context, 18));
        glass.setStrokeWidth(dp(context, 1));
        glass.setStrokeColor(context.getColor(R.color.glass_outline));
        glass.setCardElevation(dp(context, 10));
        glass.setCardBackgroundColor(context.getColor(R.color.glass_surface_strong));

        LinearLayout body = new LinearLayout(context);
        body.setOrientation(LinearLayout.VERTICAL);
        int bodyPadding = compact ? 6 : 9;
        body.setPadding(dp(context, bodyPadding), dp(context, compact ? 5 : 8),
            dp(context, bodyPadding), dp(context, compact ? 6 : 9));
        if (!compact) {
            TextView heading = new TextView(context);
            heading.setText(title);
            heading.setTextColor(context.getColor(R.color.on_surface));
            heading.setTextSize(13);
            heading.setGravity(Gravity.CENTER);
            heading.setPadding(0, 0, 0, dp(context, 6));
            body.addView(heading, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 30)));
        }

        GridLayout grid = new GridLayout(context);
        int columns = actionColumns(actions, compact);
        grid.setColumnCount(columns);
        boolean dense = compact && columns >= 4;
        for (Action action : actions) {
            MaterialButton button = new MaterialButton(context, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = 0;
            params.height = dp(context, dense ? 66 : 72);
            params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
            params.setMargins(dp(context, 2), dp(context, 2), dp(context, 2), dp(context, 2));
            button.setLayoutParams(params);
            button.setMinWidth(0);
            GlassBottomSheet.styleActionButton(button, context, false, dense ? 11 : 12);
            button.setPadding(dp(context, dense ? 2 : 5), 0,
                dp(context, dense ? 2 : 5), 0);
            button.setText(action.label);
            button.setTextSize(dense ? 10.75f : (compact ? 11.25f : 12f));
            button.setAllCaps(false);
            button.setMaxLines(2);
            button.setGravity(Gravity.CENTER);
            button.setLineSpacing(0f, 1.02f);
            button.setEllipsize(TextUtils.TruncateAt.END);
            button.setTextColor(context.getColor(R.color.on_surface));
            button.setIconResource(action.icon);
            button.setIconSize(dp(context, dense ? 15 : 18));
            button.setIconGravity(MaterialButton.ICON_GRAVITY_TOP);
            button.setIconPadding(dp(context, dense ? 3 : 5));
            button.setIconTint(ColorStateList.valueOf(ThemeColors.primary(context)));
            button.setOnClickListener(view -> {
                dialog.dismiss();
                if (action.callback != null) action.callback.run();
            });
            grid.addView(button);
        }
        body.addView(grid, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        glass.addView(body);
        dialog.setContentView(glass);

        Window window = dialog.getWindow();
        if (window != null) {
            window.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            window.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
            WindowManager.LayoutParams attributes = window.getAttributes();
            attributes.dimAmount = 0.14f;
            attributes.gravity = Gravity.CENTER;
            if (Build.VERSION.SDK_INT >= 31) {
                attributes.setBlurBehindRadius(dp(context, 30));
                window.addFlags(WindowManager.LayoutParams.FLAG_BLUR_BEHIND);
                window.setBackgroundBlurRadius(dp(context, 36));
            }
            window.setAttributes(attributes);
        }
        dialog.show();
        if (window != null) {
            int max = dp(context, compact ? 392 : 380);
            int available = context.getResources().getDisplayMetrics().widthPixels - dp(context, 28);
            window.setLayout(Math.min(max, available), ViewGroup.LayoutParams.WRAP_CONTENT);
            ViewGroup decor = (ViewGroup) window.getDecorView();
            decor.setAlpha(0f);
            decor.setScaleX(0.94f);
            decor.setScaleY(0.94f);
            decor.animate().alpha(1f).scaleX(1f).scaleY(1f).setDuration(170L).start();
        }
        return dialog;
    }

    private static int actionColumns(List<Action> actions, boolean compact) {
        if (actions == null || actions.isEmpty()) return 1;
        if (!compact) return Math.min(3, actions.size());
        int longest = 0;
        for (Action action : actions) {
            String label = action == null || action.label == null ? "" : action.label.trim();
            longest = Math.max(longest, label.codePointCount(0, label.length()));
        }
        // Four columns are only comfortable for short command labels. Longer Chinese
        // actions get three columns so every command remains readable and tappable.
        int preferred = longest <= 4 && actions.size() <= 8 ? 4 : 3;
        return Math.max(1, Math.min(preferred, actions.size()));
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
