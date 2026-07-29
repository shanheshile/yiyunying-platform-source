package xyz.jjmxg.yiyunying.ui.poll;

import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.view.View;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;

import com.bumptech.glide.Glide;
import com.google.android.material.chip.Chip;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputLayout;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.Date;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.ActionFeedback;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityPollComposerBinding;
import xyz.jjmxg.yiyunying.databinding.ItemPollOptionEditorBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

/**
 * A dedicated poll publishing flow. Keeping the editor in an activity avoids the
 * keyboard, clipping, and touch-target problems caused by the previous long dialog.
 */
public final class PollComposerActivity extends SystemInsetActivity {
    public static final String EXTRA_FEEDBACK = "feedback";

    private static final String[] RESULT_LABELS = {
        "投票后可见", "截止后可见", "不公开结果"
    };
    private static final String[] RESULT_VALUES = {
        "after_vote", "after_end", "hidden"
    };

    private ActivityPollComposerBinding binding;
    private final List<OptionDraft> options = new ArrayList<>();
    private RequestHandle categoryRequest;
    private RequestHandle submitRequest;
    private RequestHandle uploadRequest;
    private OptionDraft pendingImageOption;
    private boolean working;

    private final ActivityResultLauncher<Intent> imagePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            OptionDraft option = pendingImageOption;
            pendingImageOption = null;
            if (option == null || binding == null || result.getResultCode() != RESULT_OK
                || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected == null || selected.isEmpty()) return;
            option.imageUri = selected.get(0);
            option.imageUrl = "";
            option.binding.optionImage.setVisibility(View.VISIBLE);
            option.binding.optionImageButton.setText("更换选项图片");
            Glide.with(option.binding.optionImage)
                .load(option.imageUri)
                .centerCrop()
                .into(option.binding.optionImage);
        });

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) {
            login();
            return;
        }
        binding = ActivityPollComposerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.resultVisibilityInput.setSimpleItems(RESULT_LABELS);
        binding.resultVisibilityInput.setText(RESULT_LABELS[0], false);
        binding.choiceModeGroup.addOnButtonCheckedListener((group, checkedId, checked) -> {
            if (!checked || binding == null) return;
            binding.choiceModeHint.setText(checkedId == R.id.multipleChoiceButton
                ? "参与者可以选择多个选项" : "每人只能选择一个选项");
            updateSummary();
        });
        binding.deadlineGroup.addOnButtonCheckedListener((group, checkedId, checked) -> {
            if (checked) updateSummary();
        });
        binding.resultVisibilityInput.setOnItemClickListener((parent, view, position, id) ->
            updateSummary());
        binding.addOptionButton.setOnClickListener(view -> {
            if (options.size() >= 20) {
                showMessage("一次投票最多添加 20 个选项");
                return;
            }
            addOption("");
        });
        binding.submitButton.setOnClickListener(view -> submit());
        addOption("");
        addOption("");
        loadCategories();
        updateSummary();
    }

    private void loadCategories() {
        if (categoryRequest != null) categoryRequest.cancel();
        binding.categoryHint.setText("正在加载投票分类");
        categoryRequest = AppAccess.from(this).repository().get(
            "/api/user/poll-categories", new LinkedHashMap<>(), result -> {
                categoryRequest = null;
                if (binding == null) return;
                if (result.isAuthenticationFailure()) {
                    login();
                    return;
                }
                binding.categoryChips.removeAllViews();
                if (!result.isSuccessful()) {
                    binding.categoryHint.setText(ActionFeedback.failure(
                        result, "投票分类加载失败，仍可不选分类直接发布"));
                    return;
                }
                for (JsonElement element : result.items()) {
                    if (!element.isJsonObject()) continue;
                    JsonObject category = element.getAsJsonObject();
                    long categoryId = Jsons.longValue(category, "id");
                    String name = Jsons.string(category, "name");
                    if (categoryId <= 0 || name.isEmpty()) continue;
                    Chip chip = new Chip(this);
                    chip.setText(name);
                    chip.setTag(categoryId);
                    chip.setCheckable(true);
                    chip.setMinHeight(dp(44));
                    binding.categoryChips.addView(chip);
                }
                binding.categoryHint.setText(binding.categoryChips.getChildCount() == 0
                    ? "暂无分类，可不选分类直接发布"
                    : "可选择多个分类，便于搜索和筛选");
            });
    }

    private void addOption(String initialText) {
        ItemPollOptionEditorBinding row = ItemPollOptionEditorBinding.inflate(
            getLayoutInflater(), binding.optionsContainer, false);
        OptionDraft draft = new OptionDraft(row);
        options.add(draft);
        binding.optionsContainer.addView(row.getRoot());
        row.optionInput.setText(initialText);
        row.optionInputLayout.setHint("选项 " + options.size());
        row.removeOptionButton.setOnClickListener(view -> removeOption(draft));
        row.optionImageButton.setOnClickListener(view -> {
            pendingImageOption = draft;
            ArrayList<Uri> selected = new ArrayList<>();
            if (draft.imageUri != null) selected.add(draft.imageUri);
            imagePicker.launch(MediaPickerActivity.imageIntent(this, 1, selected));
        });
        updateOptionLabels();
    }

    private void removeOption(OptionDraft draft) {
        if (options.size() <= 2) {
            showMessage("投票至少保留两个选项");
            return;
        }
        options.remove(draft);
        binding.optionsContainer.removeView(draft.binding.getRoot());
        if (pendingImageOption == draft) pendingImageOption = null;
        updateOptionLabels();
    }

    private void updateOptionLabels() {
        for (int index = 0; index < options.size(); index++) {
            options.get(index).binding.optionInputLayout.setHint("选项 " + (index + 1));
        }
        if (binding != null) {
            binding.optionSummary.setText("已添加 " + options.size() + " 个选项");
            updateSummary();
        }
    }

    private void updateSummary() {
        if (binding == null) return;
        String mode = binding.choiceModeGroup.getCheckedButtonId() == R.id.multipleChoiceButton
            ? "多选" : "单选";
        binding.publishSummary.setText(mode + " · " + deadlineLabel()
            + "截止 · " + selectedResultLabel());
    }

    private void submit() {
        if (working || binding == null) return;
        clearErrors();
        String title = text(binding.titleInput);
        if (title.isEmpty()) {
            focusField(binding.titleLayout, "请填写投票标题");
            return;
        }
        if (title.length() > 120) {
            focusField(binding.titleLayout, "投票标题不能超过 120 个字");
            return;
        }
        String description = text(binding.descriptionInput);
        if (description.length() > 2000) {
            focusField(binding.descriptionLayout, "投票说明不能超过 2000 个字");
            return;
        }
        for (int index = 0; index < options.size(); index++) {
            OptionDraft option = options.get(index);
            option.text = text(option.binding.optionInput);
            if (option.text.isEmpty()) {
                focusField(option.binding.optionInputLayout,
                    "请填写选项 " + (index + 1));
                return;
            }
        }
        setWorking(true, "正在准备投票内容");
        uploadOption(0, title, description);
    }

    private void uploadOption(int index, String title, String description) {
        if (binding == null || !working) return;
        if (index >= options.size()) {
            publish(title, description);
            return;
        }
        OptionDraft option = options.get(index);
        if (option.imageUri == null || !option.imageUrl.isEmpty()) {
            uploadOption(index + 1, title, description);
            return;
        }
        UriFile file = uriFile(option.imageUri);
        binding.publishSummary.setText(
            "正在上传选项图片 " + (index + 1) + " / " + options.size());
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "poll_option");
        uploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads",
            file.name,
            file.mime,
            new ContentUriRequestBody(
                getContentResolver(), option.imageUri, file.mime, file.size),
            fields,
            result -> {
                uploadRequest = null;
                if (binding == null) return;
                if (result.isAuthenticationFailure()) {
                    login();
                    return;
                }
                if (!result.isSuccessful()) {
                    setWorking(false, ActionFeedback.failure(
                        result, "选项 " + (index + 1) + " 的图片上传失败，请重试"));
                    showMessage(binding.publishSummary.getText().toString());
                    return;
                }
                option.imageUrl = Jsons.string(result.dataObject(), "file_url");
                uploadOption(index + 1, title, description);
            });
    }

    private void publish(String title, String description) {
        JsonObject body = new JsonObject();
        boolean multiple = binding.choiceModeGroup.getCheckedButtonId()
            == R.id.multipleChoiceButton;
        body.addProperty("title", title);
        body.addProperty("description", description);
        body.addProperty("multiple_choice", multiple);
        body.addProperty("multi_select", multiple);
        body.addProperty("allow_multiple", multiple);
        body.addProperty("min_select", 1);
        body.addProperty("max_select", multiple ? options.size() : 1);
        body.addProperty("result_visibility", selectedResultValue());
        body.addProperty("ends_at", futureDateTime(deadlineHours()));
        JsonArray categoryIds = new JsonArray();
        for (int index = 0; index < binding.categoryChips.getChildCount(); index++) {
            View child = binding.categoryChips.getChildAt(index);
            if (child instanceof Chip && ((Chip) child).isChecked()
                && child.getTag() instanceof Long) {
                categoryIds.add((Long) child.getTag());
            }
        }
        body.add("category_ids", categoryIds);
        JsonArray values = new JsonArray();
        for (int index = 0; index < options.size(); index++) {
            OptionDraft draft = options.get(index);
            JsonObject option = new JsonObject();
            option.addProperty("option_text", draft.text);
            option.addProperty("image_url", draft.imageUrl);
            option.addProperty("sort_order", index);
            values.add(option);
        }
        body.add("options", values);
        binding.publishSummary.setText("内容已就绪，正在发布投票");
        submitRequest = AppAccess.from(this).repository().post(
            "/api/user/polls", body, result -> {
                submitRequest = null;
                if (binding == null) return;
                if (result.isAuthenticationFailure()) {
                    login();
                    return;
                }
                if (!result.isSuccessful()) {
                    String message = ActionFeedback.failure(
                        result, "投票发布失败，请检查内容后重试");
                    setWorking(false, message);
                    showMessage(message);
                    return;
                }
                String message = ActionFeedback.success(result, "投票已发布");
                setResult(RESULT_OK, new Intent().putExtra(EXTRA_FEEDBACK, message));
                binding.publishSummary.setText(message);
                binding.getRoot().postDelayed(this::finish, 180L);
            });
    }

    private void setWorking(boolean value, String summary) {
        working = value;
        if (binding == null) return;
        binding.progress.setVisibility(value ? View.VISIBLE : View.INVISIBLE);
        binding.submitButton.setEnabled(!value);
        binding.submitButton.setText(value ? "正在发布" : "发布投票");
        binding.addOptionButton.setEnabled(!value);
        binding.choiceModeGroup.setEnabled(!value);
        binding.deadlineGroup.setEnabled(!value);
        binding.resultVisibilityInput.setEnabled(!value);
        binding.publishSummary.setText(summary);
    }

    private void clearErrors() {
        binding.titleLayout.setError(null);
        binding.descriptionLayout.setError(null);
        for (OptionDraft option : options) option.binding.optionInputLayout.setError(null);
    }

    private void focusField(TextInputLayout layout, String message) {
        layout.setError(message);
        View field = layout.getEditText() == null ? layout : layout.getEditText();
        binding.composerScroll.post(() -> {
            binding.composerScroll.smoothScrollTo(
                0, Math.max(0, viewTopInScroll(layout) - dp(20)));
            field.requestFocus();
        });
    }

    private int viewTopInScroll(View view) {
        int top = 0;
        View current = view;
        while (current != null && current != binding.composerScroll) {
            top += current.getTop();
            if (!(current.getParent() instanceof View)) break;
            current = (View) current.getParent();
        }
        return top;
    }

    private long deadlineHours() {
        int checked = binding.deadlineGroup.getCheckedButtonId();
        if (checked == R.id.deadline1Hour) return 1L;
        if (checked == R.id.deadline1Day) return 24L;
        if (checked == R.id.deadline3Days) return 72L;
        if (checked == R.id.deadline30Days) return 720L;
        return 168L;
    }

    private String deadlineLabel() {
        long hours = deadlineHours();
        if (hours == 1L) return "1 小时后";
        if (hours == 24L) return "1 天后";
        if (hours == 72L) return "3 天后";
        if (hours == 720L) return "30 天后";
        return "7 天后";
    }

    private String selectedResultLabel() {
        String value = binding.resultVisibilityInput.getText() == null
            ? "" : binding.resultVisibilityInput.getText().toString();
        for (String label : RESULT_LABELS) if (label.equals(value)) return label;
        return RESULT_LABELS[0];
    }

    private String selectedResultValue() {
        String label = selectedResultLabel();
        for (int index = 0; index < RESULT_LABELS.length; index++) {
            if (RESULT_LABELS[index].equals(label)) return RESULT_VALUES[index];
        }
        return RESULT_VALUES[0];
    }

    private String futureDateTime(long hours) {
        Calendar calendar = Calendar.getInstance();
        calendar.setTime(new Date());
        calendar.add(Calendar.HOUR_OF_DAY, (int) hours);
        return new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            .format(calendar.getTime());
    }

    private UriFile uriFile(Uri uri) {
        String name = "投票选项图片";
        long size = -1L;
        try (Cursor cursor = getContentResolver().query(
            uri,
            new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE},
            null,
            null,
            null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                int sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE);
                if (nameColumn >= 0 && !cursor.isNull(nameColumn)) {
                    name = cursor.getString(nameColumn);
                }
                if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) {
                    size = cursor.getLong(sizeColumn);
                }
            }
        } catch (RuntimeException ignored) {
            // The content can still be uploaded when metadata is unavailable.
        }
        String mime = getContentResolver().getType(uri);
        if (mime == null || !mime.startsWith("image/")) mime = "image/jpeg";
        return new UriFile(name, mime, size);
    }

    private String text(android.widget.TextView view) {
        return view.getText() == null ? "" : view.getText().toString().trim();
    }

    private void showMessage(String message) {
        if (binding != null) {
            Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_LONG).show();
        }
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class)
            .putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    @Override protected void onDestroy() {
        if (categoryRequest != null) categoryRequest.cancel();
        if (submitRequest != null) submitRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        pendingImageOption = null;
        binding = null;
        super.onDestroy();
    }

    private static final class OptionDraft {
        final ItemPollOptionEditorBinding binding;
        Uri imageUri;
        String imageUrl = "";
        String text = "";

        OptionDraft(ItemPollOptionEditorBinding binding) {
            this.binding = binding;
        }
    }

    private static final class UriFile {
        final String name;
        final String mime;
        final long size;

        UriFile(String name, String mime, long size) {
            this.name = name;
            this.mime = mime;
            this.size = size;
        }
    }
}
