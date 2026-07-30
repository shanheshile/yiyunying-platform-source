package xyz.jjmxg.yiyunying.ui.bounty;

import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;

import android.app.Activity;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.text.InputType;
import android.text.TextUtils;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.LinearLayout;
import android.widget.NumberPicker;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityBountyPublishBinding;
import xyz.jjmxg.yiyunying.ui.common.GlassBottomSheet;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;

/** Dedicated visual flow for bounty publication and category application. */
public final class BountyPublishActivity extends SystemInsetActivity {
    private ActivityBountyPublishBinding binding;
    private final List<Long> categoryIds = new ArrayList<>();
    private final List<String> categoryNames = new ArrayList<>();
    private final List<AttachmentInfo> attachments = new ArrayList<>();
    private long selectedCategoryId;
    private boolean busy;
    private RequestHandle request;

    private final ActivityResultLauncher<Intent> attachmentPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != Activity.RESULT_OK || result.getData() == null) return;
            ArrayList<String> values = result.getData().getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
            if (values == null) return;
            attachments.clear();
            for (String value : values) {
                if (attachments.size() >= 20) break;
                Uri uri = Uri.parse(value);
                attachments.add(fileInfo(uri));
            }
            renderAttachments();
        });

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityBountyPublishBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.chooseAttachmentsButton.setOnClickListener(view ->
            attachmentPicker.launch(FilePickerActivity.pickerIntent(this, 20)));
        binding.requestCategoryButton.setOnClickListener(view -> showCategoryRequest());
        binding.submitButton.setOnClickListener(view -> submit());
        binding.categoryInput.setOnItemClickListener((parent, view, position, id) -> {
            if (position >= 0 && position < categoryIds.size()) {
                selectedCategoryId = categoryIds.get(position);
                binding.categoryLayout.setError(null);
            }
        });
        loadCategories();
        setupDeadlinePicker();
    }

    private void setupDeadlinePicker() {
        binding.deadlineCustomDays.setMinValue(1);
        binding.deadlineCustomDays.setMaxValue(365);
        binding.deadlineCustomDays.setValue(30);
        binding.deadlineCustomDays.setWrapSelectorWheel(false);
        binding.deadlineGroup.addOnButtonCheckedListener((group, checkedId, isChecked) -> {
            boolean custom = checkedId == R.id.deadlineCustom && isChecked;
            binding.deadlineCustomLayout.setVisibility(custom ? View.VISIBLE : View.GONE);
        });
    }

    private void loadCategories() {
        cancelRequest();
        request = AppAccess.from(this).repository().get(
            "/api/user/bounty-categories", new LinkedHashMap<>(), result -> {
                request = null;
                if (binding == null) return;
                categoryIds.clear();
                categoryNames.clear();
                for (JsonElement element : result.items()) {
                    if (!element.isJsonObject()) continue;
                    JsonObject item = element.getAsJsonObject();
                    long id = Jsons.longValue(item, "id");
                    String name = Jsons.string(item, "name").trim();
                    if (id <= 0 || name.isEmpty()) continue;
                    categoryIds.add(id);
                    categoryNames.add(name);
                }
                binding.categoryInput.setAdapter(new ArrayAdapter<>(this,
                    android.R.layout.simple_list_item_1, categoryNames));
                if (!result.isSuccessful()) {
                    binding.categoryLayout.setHelperText(result.message().isEmpty()
                        ? "分类读取失败，请稍后重试" : result.message());
                } else if (categoryNames.isEmpty()) {
                    binding.categoryLayout.setHelperText("暂无分类，可先提交分类申请");
                } else {
                    binding.categoryLayout.setHelperText("选择后可让悬赏更容易被找到");
                }
            });
    }

    private void showCategoryRequest() {
        if (busy) return;
        BottomSheetDialog dialog = new BottomSheetDialog(this);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(20), dp(12), dp(20), dp(24));

        TextView title = new TextView(this);
        title.setText("申请悬赏分类");
        title.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        content.addView(title, matchWrap());

        TextView description = new TextView(this);
        description.setText("填写分类名称和适用范围。审核结果会在通知中心展示。 ");
        description.setTextColor(getColor(R.color.on_surface_variant));
        LinearLayout.LayoutParams descriptionParams = matchWrap();
        descriptionParams.topMargin = dp(6);
        content.addView(description, descriptionParams);

        TextInputLayout nameLayout = new TextInputLayout(this);
        nameLayout.setHint("分类名称");
        nameLayout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        LinearLayout.LayoutParams fieldParams = matchWrap();
        fieldParams.topMargin = dp(16);
        TextInputEditText nameInput = new TextInputEditText(nameLayout.getContext());
        nameInput.setMaxLines(1);
        nameInput.setInputType(InputType.TYPE_CLASS_TEXT);
        SafeTextInput.attach(nameLayout, nameInput);
        content.addView(nameLayout, fieldParams);

        TextInputLayout reasonLayout = new TextInputLayout(this);
        reasonLayout.setHint("申请理由和分类范围");
        reasonLayout.setBoxBackgroundMode(TextInputLayout.BOX_BACKGROUND_OUTLINE);
        LinearLayout.LayoutParams reasonParams = matchWrap();
        reasonParams.topMargin = dp(12);
        TextInputEditText reasonInput = new TextInputEditText(reasonLayout.getContext());
        reasonInput.setMinLines(3);
        reasonInput.setGravity(android.view.Gravity.TOP | android.view.Gravity.START);
        reasonInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        SafeTextInput.attach(reasonLayout, reasonInput);
        content.addView(reasonLayout, reasonParams);

        LinearLayout buttons = new LinearLayout(this);
        buttons.setOrientation(LinearLayout.HORIZONTAL);
        buttons.setGravity(android.view.Gravity.END);
        LinearLayout.LayoutParams buttonsParams = matchWrap();
        buttonsParams.topMargin = dp(16);
        MaterialButton cancel = new MaterialButton(this, null,
            com.google.android.material.R.attr.materialButtonOutlinedStyle);
        cancel.setText("取消");
        cancel.setOnClickListener(view -> dialog.dismiss());
        buttons.addView(cancel, new LinearLayout.LayoutParams(0, dp(50), 1f));
        MaterialButton send = new MaterialButton(this);
        send.setText("提交申请");
        LinearLayout.LayoutParams sendParams = new LinearLayout.LayoutParams(0, dp(50), 1f);
        sendParams.setMarginStart(dp(10));
        buttons.addView(send, sendParams);
        content.addView(buttons, buttonsParams);

        send.setOnClickListener(view -> {
            String name = text(nameInput.getText());
            String reason = text(reasonInput.getText());
            nameLayout.setError(null);
            reasonLayout.setError(null);
            if (name.isEmpty()) { nameLayout.setError("请填写分类名称"); return; }
            if (reason.isEmpty()) { reasonLayout.setError("请说明分类适用范围"); return; }
            send.setEnabled(false);
            JsonObject body = new JsonObject();
            body.addProperty("name", name);
            body.addProperty("reason", reason);
            request = AppAccess.from(this).repository().post(
                "/api/user/bounty-category-requests", body, result -> {
                    request = null;
                    if (binding == null) return;
                    send.setEnabled(true);
                    if (!result.isSuccessful()) {
                        Snackbar.make(binding.getRoot(), result.message().isEmpty()
                            ? "分类申请提交失败" : result.message(), Snackbar.LENGTH_LONG).show();
                        return;
                    }
                    dialog.dismiss();
                    Snackbar.make(binding.getRoot(), "分类申请已提交，审核结果会通过通知中心送达",
                        Snackbar.LENGTH_LONG).show();
                });
        });

        dialog.setContentView(content);
        GlassBottomSheet.prepare(dialog, this, 0.9f, false);
        dialog.show();
    }

    private void submit() {
        if (busy) return;
        String title = text(binding.titleInput.getText());
        String description = text(binding.descriptionInput.getText());
        String rewardText = text(binding.rewardInput.getText());
        binding.categoryLayout.setError(null);
        binding.titleLayout.setError(null);
        binding.descriptionLayout.setError(null);
        binding.rewardLayout.setError(null);
        if (selectedCategoryId <= 0) { binding.categoryLayout.setError("请选择悬赏分类"); return; }
        if (title.isEmpty()) { binding.titleLayout.setError("请填写悬赏标题"); return; }
        if (description.isEmpty()) { binding.descriptionLayout.setError("请填写任务内容与验收标准"); return; }
        long reward;
        try { reward = Long.parseLong(rewardText); }
        catch (NumberFormatException ignored) { reward = 0; }
        if (reward <= 0) { binding.rewardLayout.setError("悬赏余额必须大于0"); return; }
        setBusy(true, attachments.isEmpty() ? "正在发布…" : "正在上传附件 1/" + attachments.size());
        JsonArray uploaded = new JsonArray();
        final long finalReward = reward;
        uploadNext(0, uploaded, () -> publish(title, description, finalReward, uploaded));
    }

    private void uploadNext(int index, JsonArray uploaded, Runnable finished) {
        if (index >= attachments.size()) { finished.run(); return; }
        AttachmentInfo info = attachments.get(index);
        setBusy(true, "正在上传附件 " + (index + 1) + "/" + attachments.size());
        ContentUriRequestBody body = new ContentUriRequestBody(
            getContentResolver(), info.uri, info.mime, info.size);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "bounty_attachment");
        request = AppAccess.from(this).repository().upload(
            "/api/user/uploads", info.name, info.mime, body, fields, result -> {
                request = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    setBusy(false, "");
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? "第 " + (index + 1) + " 个附件上传失败" : result.message(),
                        Snackbar.LENGTH_LONG).show();
                    return;
                }
                String url = Jsons.string(result.dataObject(), "file_url");
                if (url.isEmpty()) {
                    setBusy(false, "");
                    Snackbar.make(binding.getRoot(), "服务器未返回附件地址，请重试", Snackbar.LENGTH_LONG).show();
                    return;
                }
                JsonObject item = new JsonObject();
                item.addProperty("url", url);
                item.addProperty("name", info.name);
                item.addProperty("media_type", info.mediaType());
                item.addProperty("mime_type", info.mime);
                item.addProperty("size", Math.max(0, info.size));
                uploaded.add(item);
                uploadNext(index + 1, uploaded, finished);
            });
    }

    private void publish(String title, String description, long reward, JsonArray uploaded) {
        setBusy(true, "正在发布悬赏…");
        JsonObject body = new JsonObject();
        body.addProperty("category_id", selectedCategoryId);
        body.addProperty("title", title);
        body.addProperty("description", description);
        body.addProperty("reward_balance", reward);
        body.addProperty("deadline_at", deadlineAt());
        body.add("attachments", uploaded);
        body.add("requirements", new JsonArray());
        request = AppAccess.from(this).repository().post("/api/user/bounties", body, result -> {
            request = null;
            if (binding == null) return;
            setBusy(false, "");
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty()
                    ? "悬赏发布失败，请稍后重试" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            boolean pending = "pending".equals(Jsons.string(result.dataObject(), "audit_status"));
            new YiyunyingDialogBuilder(this)
                .setBusinessTitle(pending ? "悬赏已提交审核" : "悬赏发布成功")
                .setBusinessMessage(pending
                    ? "悬赏余额已经冻结。审核通过后会在悬赏列表展示，处理结果可在通知中心查看。"
                    : "悬赏已经发布，余额已冻结。可在悬赏详情中查看投稿和后续结算。")
                .setPositiveButton("完成", (dialog, which) -> {
                    setResult(Activity.RESULT_OK);
                    finish();
                })
                .setCancelable(false)
                .show();
        });
    }

    private String deadlineAt() {
        int days = 7;
        int checked = binding.deadlineGroup.getCheckedButtonId();
        if (checked == R.id.deadline1Day) days = 1;
        else if (checked == R.id.deadline3Days) days = 3;
        else if (checked == R.id.deadline30Days) days = 30;
        else if (checked == R.id.deadlineCustom) days = binding.deadlineCustomDays.getValue();
        Calendar calendar = Calendar.getInstance();
        calendar.add(Calendar.DAY_OF_MONTH, days);
        return new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.CHINA).format(calendar.getTime());
    }

    private void renderAttachments() {
        if (binding == null) return;
        binding.attachmentList.removeAllViews();
        binding.attachmentSummary.setText(attachments.isEmpty()
            ? "支持图片、视频、音频、文档和压缩包，最多20个"
            : "已选择 " + attachments.size() + " 个附件，发布前会依次上传");
        binding.chooseAttachmentsButton.setText(attachments.isEmpty() ? "选择" : "重新选择");
        for (int i = 0; i < attachments.size(); i++) {
            AttachmentInfo info = attachments.get(i);
            LinearLayout row = new LinearLayout(this);
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(android.view.Gravity.CENTER_VERTICAL);
            row.setPadding(0, dp(4), 0, dp(4));
            TextView text = new TextView(this);
            text.setText((i + 1) + ". " + info.name + "  ·  " + sizeText(info.size));
            text.setTextColor(getColor(R.color.on_surface));
            text.setMaxLines(2);
            row.addView(text, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f));
            MaterialButton remove = new MaterialButton(this);
            remove.setBackgroundTintList(android.content.res.ColorStateList.valueOf(
                android.graphics.Color.TRANSPARENT));
            remove.setText("移除");
            remove.setMinWidth(dp(64));
            int position = i;
            remove.setOnClickListener(view -> {
                if (position < attachments.size()) attachments.remove(position);
                renderAttachments();
            });
            row.addView(remove, new LinearLayout.LayoutParams(dp(72), dp(48)));
            binding.attachmentList.addView(row, matchWrap());
        }
    }

    private AttachmentInfo fileInfo(Uri uri) {
        String name = "附件";
        long size = -1;
        try (Cursor cursor = getContentResolver().query(uri,
            new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE}, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameColumn >= 0 && !cursor.isNull(nameColumn)) name = cursor.getString(nameColumn);
                if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) size = cursor.getLong(sizeColumn);
            }
        } catch (RuntimeException ignored) { }
        String mime = getContentResolver().getType(uri);
        if (TextUtils.isEmpty(mime)) mime = "application/octet-stream";
        return new AttachmentInfo(uri, TextUtils.isEmpty(name) ? "附件" : name, mime, size);
    }

    private void setBusy(boolean value, String label) {
        busy = value;
        if (binding == null) return;
        binding.progress.setVisibility(value ? View.VISIBLE : View.INVISIBLE);
        binding.submitButton.setEnabled(!value);
        binding.requestCategoryButton.setEnabled(!value);
        binding.chooseAttachmentsButton.setEnabled(!value);
        binding.categoryInput.setEnabled(!value);
        binding.titleInput.setEnabled(!value);
        binding.descriptionInput.setEnabled(!value);
        binding.rewardInput.setEnabled(!value);
        binding.deadlineGroup.setEnabled(!value);
        binding.submitButton.setText(value && !label.isEmpty() ? label : "发布悬赏");
    }

    private void cancelRequest() {
        if (request != null) request.cancel();
        request = null;
    }

    @Override protected void onDestroy() {
        cancelRequest();
        binding = null;
        super.onDestroy();
    }

    private LinearLayout.LayoutParams matchWrap() {
        return new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static String text(CharSequence value) {
        return value == null ? "" : value.toString().trim();
    }

    private static String sizeText(long bytes) {
        if (bytes < 0) return "大小未知";
        if (bytes >= 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.2f GB", bytes / 1073741824d);
        if (bytes >= 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1048576d);
        if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        return bytes + " B";
    }

    private static final class AttachmentInfo {
        final Uri uri;
        final String name;
        final String mime;
        final long size;

        AttachmentInfo(Uri uri, String name, String mime, long size) {
            this.uri = uri;
            this.name = name;
            this.mime = mime;
            this.size = size;
        }

        String mediaType() {
            if (mime.startsWith("image/")) return "image";
            if (mime.startsWith("video/")) return "video";
            if (mime.startsWith("audio/")) return "audio";
            String lower = name.toLowerCase(Locale.ROOT);
            if (lower.endsWith(".zip") || lower.endsWith(".7z") || lower.endsWith(".rar") || lower.endsWith(".tar.gz")) return "archive";
            if (lower.endsWith(".pdf") || lower.endsWith(".doc") || lower.endsWith(".docx")
                || lower.endsWith(".xls") || lower.endsWith(".xlsx") || lower.endsWith(".ppt") || lower.endsWith(".pptx")) return "document";
            return "file";
        }
    }
}
