package xyz.jjmxg.yiyunying.ui.common;

import android.view.View;
import android.widget.LinearLayout;

import com.google.android.material.textfield.TextInputLayout;

/** Keeps Material text fields on the layout-param type expected by TextInputLayout. */
public final class SafeTextInput {
    private SafeTextInput() { }

    public static <T extends View> T attach(TextInputLayout layout, T input) {
        layout.addView(input, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        ));
        return input;
    }
}
