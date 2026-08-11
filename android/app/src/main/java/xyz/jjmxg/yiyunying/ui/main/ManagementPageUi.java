package xyz.jjmxg.yiyunying.ui.main;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Typeface;
import android.view.Gravity;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.DrawableRes;
import androidx.core.content.ContextCompat;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.chip.Chip;

import xyz.jjmxg.yiyunying.R;

final class ManagementPageUi {
    private ManagementPageUi() { }

    static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }

    static TextView heading(Context context, String text) {
        TextView view = new TextView(context);
        view.setText(text);
        view.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
        view.setTextColor(ContextCompat.getColor(context, R.color.on_surface));
        view.setTypeface(view.getTypeface(), Typeface.BOLD);
        view.setPadding(0, dp(context, 12), 0, dp(context, 8));
        return view;
    }

    static TextView title(Context context, String text) {
        TextView view = new TextView(context);
        view.setText(text);
        view.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        view.setTextColor(ContextCompat.getColor(context, R.color.on_surface));
        view.setTypeface(view.getTypeface(), Typeface.BOLD);
        return view;
    }

    static TextView body(Context context, String text) {
        TextView view = new TextView(context);
        view.setText(text);
        view.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyMedium);
        view.setTextColor(ContextCompat.getColor(context, R.color.on_surface_variant));
        view.setLineSpacing(0f, 1.12f);
        return view;
    }

    static MaterialCardView card(Context context, View child) {
        MaterialCardView card = new MaterialCardView(context);
        card.setCardBackgroundColor(ContextCompat.getColor(context, R.color.surface_container));
        card.setStrokeColor(ContextCompat.getColor(context, R.color.outline_variant));
        card.setStrokeWidth(dp(context, 1));
        card.setCardElevation(0f);
        card.setRadius(dp(context, 16));
        card.addView(child, new MaterialCardView.LayoutParams(-1, -2));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
        params.bottomMargin = dp(context, 10);
        card.setLayoutParams(params);
        return card;
    }

    static LinearLayout column(Context context, int padding) {
        LinearLayout column = new LinearLayout(context);
        column.setOrientation(LinearLayout.VERTICAL);
        column.setPadding(padding, padding, padding, padding);
        return column;
    }

    static LinearLayout row(Context context) {
        LinearLayout row = new LinearLayout(context);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        return row;
    }

    static MaterialButton button(Context context, String text, @DrawableRes int icon, boolean primary) {
        int style = primary
            ? com.google.android.material.R.attr.materialButtonStyle
            : com.google.android.material.R.attr.materialButtonOutlinedStyle;
        MaterialButton button = new MaterialButton(context, null, style);
        button.setText(text);
        button.setAllCaps(false);
        button.setMinHeight(dp(context, 48));
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setIconResource(icon);
        button.setIconPadding(dp(context, 8));
        button.setContentDescription(text);
        if (!primary) {
            button.setTextColor(ContextCompat.getColor(context, R.color.primary));
            button.setIconTint(ColorStateList.valueOf(ContextCompat.getColor(context, R.color.primary)));
        }
        return button;
    }

    static Chip chip(Context context, String text) {
        Chip chip = new Chip(context);
        chip.setText(text);
        chip.setCheckable(true);
        chip.setClickable(true);
        chip.setEnsureMinTouchTargetSize(true);
        chip.setTextColor(ContextCompat.getColor(context, R.color.on_surface));
        chip.setContentDescription(text);
        return chip;
    }

    static void addWeighted(LinearLayout row, View view, int marginEnd) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(0, -2, 1f);
        params.setMarginEnd(marginEnd);
        row.addView(view, params);
    }
}
