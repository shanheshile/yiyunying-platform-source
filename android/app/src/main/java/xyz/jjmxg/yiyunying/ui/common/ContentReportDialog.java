package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.graphics.Typeface;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.RadioButton;
import android.widget.RadioGroup;
import android.widget.TextView;
import android.widget.Toast;

import androidx.core.content.ContextCompat;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.color.MaterialColors;
import com.google.gson.JsonObject;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;

/** A single visual report flow shared by every user-facing content surface. */
public final class ContentReportDialog {
    private static final String[] REASONS = {
        "垃圾广告", "骚扰或辱骂", "违法违规", "虚假或诈骗", "侵犯权益", "其他"
    };

    private ContentReportDialog() { }

    public static void show(Context context, String targetType, long targetId, String targetName) {
        if (context == null || targetId <= 0) {
            if (context != null) Toast.makeText(context, "举报对象无效", Toast.LENGTH_SHORT).show();
            return;
        }

        int padding = dp(context, 20);
        LinearLayout root = new LinearLayout(context);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(padding, dp(context, 10), padding, dp(context, 18));
        root.setBackgroundColor(ContextCompat.getColor(context, R.color.glass_surface_strong));

        View handle = new View(context);
        LinearLayout.LayoutParams handleParams = new LinearLayout.LayoutParams(dp(context, 36), dp(context, 4));
        handleParams.gravity = Gravity.CENTER_HORIZONTAL;
        handleParams.bottomMargin = dp(context, 14);
        handle.setLayoutParams(handleParams);
        handle.setBackgroundResource(R.drawable.bg_sheet_handle);
        root.addView(handle);

        TextView title = new TextView(context);
        title.setText("举报内容");
        title.setTextSize(19);
        title.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        title.setTextColor(MaterialColors.getColor(title, com.google.android.material.R.attr.colorOnSurface));
        root.addView(title, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextView summary = new TextView(context);
        summary.setText(targetName == null || targetName.trim().isEmpty()
            ? "请选择举报原因，平台将核验并保护你的隐私。"
            : "举报“" + targetName.trim() + "”\n平台将核验并保护你的隐私。");
        summary.setTextSize(13);
        summary.setLineSpacing(0, 1.15f);
        summary.setTextColor(MaterialColors.getColor(summary, com.google.android.material.R.attr.colorOnSurfaceVariant));
        LinearLayout.LayoutParams summaryParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        summaryParams.topMargin = dp(context, 6);
        summaryParams.bottomMargin = dp(context, 12);
        root.addView(summary, summaryParams);

        RadioGroup choices = new RadioGroup(context);
        choices.setOrientation(LinearLayout.VERTICAL);
        int onSurface = ThemeColors.resolve(context,
            com.google.android.material.R.attr.colorOnSurface, R.color.on_surface);
        int onSurfaceVariant = ThemeColors.resolve(context,
            com.google.android.material.R.attr.colorOnSurfaceVariant,
            R.color.on_surface_variant);
        for (int index = 0; index < REASONS.length; index++) {
            RadioButton choice = new RadioButton(context);
            choice.setId(View.generateViewId());
            choice.setTag(REASONS[index]);
            choice.setText(REASONS[index]);
            choice.setTextColor(onSurface);
            choice.setTextSize(14);
            choice.setMinHeight(dp(context, 42));
            choice.setGravity(Gravity.CENTER_VERTICAL);
            choices.addView(choice, new RadioGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 42)));
            if (index == 0) choices.check(choice.getId());
        }
        root.addView(choices, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        EditText detail = new EditText(context);
        detail.setHint("补充说明（选填，最多 200 字）");
        detail.setTextColor(onSurface);
        detail.setHintTextColor(onSurfaceVariant);
        detail.setTextSize(14);
        detail.setMinLines(2);
        detail.setMaxLines(4);
        detail.setMaxEms(200);
        detail.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        detail.setBackgroundResource(R.drawable.bg_feature_row);
        detail.setPadding(dp(context, 14), dp(context, 10), dp(context, 14), dp(context, 10));
        LinearLayout.LayoutParams detailParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        detailParams.topMargin = dp(context, 8);
        root.addView(detail, detailParams);

        LinearLayout actions = new LinearLayout(context);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        actions.setGravity(Gravity.END | Gravity.CENTER_VERTICAL);
        LinearLayout.LayoutParams actionsParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 52));
        actionsParams.topMargin = dp(context, 14);
        root.addView(actions, actionsParams);

        MaterialButton cancel = new MaterialButton(context, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        cancel.setText("取消");
        cancel.setTextSize(13);
        MaterialButton submit = new MaterialButton(context);
        submit.setText("提交举报");
        submit.setTextSize(13);
        LinearLayout.LayoutParams buttonParams = new LinearLayout.LayoutParams(0, dp(context, 48), 1f);
        actions.addView(cancel, buttonParams);
        LinearLayout.LayoutParams submitParams = new LinearLayout.LayoutParams(0, dp(context, 48), 1f);
        submitParams.leftMargin = dp(context, 10);
        actions.addView(submit, submitParams);

        BottomSheetDialog dialog = new BottomSheetDialog(context);
        dialog.setContentView(root);
        GlassBottomSheet.prepare(dialog, context, 0.88f, false);
        cancel.setOnClickListener(view -> dialog.dismiss());
        submit.setOnClickListener(view -> {
            RadioButton selected = choices.findViewById(choices.getCheckedRadioButtonId());
            String reason = selected == null ? "其他" : String.valueOf(selected.getTag());
            String note = detail.getText() == null ? "" : detail.getText().toString().trim();
            if (!note.isEmpty()) reason += "：" + (note.length() > 200 ? note.substring(0, 200) : note);
            submit.setEnabled(false);
            JsonObject body = new JsonObject();
            body.addProperty("target_type", targetType);
            body.addProperty("target_id", targetId);
            body.addProperty("reason", reason);
            AppAccess.from(context).repository().post("/api/user/reports", body, result -> {
                submit.setEnabled(true);
                if (result.isSuccessful()) {
                    dialog.dismiss();
                    Toast.makeText(context, "举报已提交，感谢你的反馈", Toast.LENGTH_SHORT).show();
                } else {
                    Toast.makeText(context, result.message().isEmpty() ? "举报提交失败" : result.message(), Toast.LENGTH_LONG).show();
                }
            });
        });
        dialog.show();
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
