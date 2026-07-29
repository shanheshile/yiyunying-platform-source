package xyz.jjmxg.yiyunying.ui.forum;

import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.text.Editable;
import android.text.InputFilter;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.EditText;
import android.text.InputType;
import android.text.TextWatcher;
import android.text.format.Formatter;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.chip.Chip;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import android.view.MenuItem;

import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.ActionFeedback;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForumComposerBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.SecureMediaClipboard;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class ForumComposerActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_MODE = "mode";
    private static final String EXTRA_TARGET_ID = "target_id";
    private static final String EXTRA_CATEGORY_ID = "category_id";
    private static final String MODE_POST = "post";
    private static final String MODE_COMMENT = "comment";

    private ActivityForumComposerBinding binding;
    private final List<Attachment> attachments = new ArrayList<>();
    private final List<SectionDraft> sectionDrafts = new ArrayList<>();
    private final List<JsonObject> categories = new ArrayList<>();
    private final List<JsonObject> recommendedTags = new ArrayList<>();
    private RequestHandle uploadRequest;
    private RequestHandle submitRequest;
    private RequestHandle stickerRequest;
    private RequestHandle paidRequest;
    private RequestHandle taxonomyRequest;
    private long selectedCategoryId;
    private String pickerType = "file";
    private boolean submittedSuccessfully;
    private boolean working;

    private final ActivityResultLauncher<Intent> imagePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (isUiActive()) selectedResult("image", result);
        });
    private final ActivityResultLauncher<Intent> filePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (isUiActive()) selectedResult(pickerType, result);
        });

    private boolean isUiActive() {
        return binding != null && !isFinishing() && !isDestroyed();
    }

    public static void createPost(Context context, long plateId) {
        createPost(context, plateId, 0);
    }

    public static void createPost(Context context, long plateId, long categoryId) {
        context.startActivity(new Intent(context, ForumComposerActivity.class)
            .putExtra(EXTRA_MODE, MODE_POST)
            .putExtra(EXTRA_TARGET_ID, plateId)
            .putExtra(EXTRA_CATEGORY_ID, categoryId));
    }

    public static void comment(Context context, long postId) {
        context.startActivity(new Intent(context, ForumComposerActivity.class)
            .putExtra(EXTRA_MODE, MODE_COMMENT).putExtra(EXTRA_TARGET_ID, postId));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        binding = ActivityForumComposerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        SecureMediaClipboard.attachPaste(binding.contentInput, uris -> selected("file", uris));
        boolean post = MODE_POST.equals(mode());
        selectedCategoryId = getIntent().getLongExtra(EXTRA_CATEGORY_ID, 0);
        binding.toolbar.setTitle(post ? "发布帖子" : "发表评论");
        binding.composerModeLabel.setText(post ? "发布新帖子" : "发表评论");
        binding.contentHeading.setText(post ? "标题与正文" : "评论内容");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.titleLayout.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.taxonomySection.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.tagsLayout.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.paidHeading.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.paidSwitch.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.paidHelper.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.sectionEditorArea.setVisibility(post ? View.VISIBLE : View.GONE);
        binding.paidSwitch.setOnCheckedChangeListener((button, checked) -> {
            if (checked && !sectionDrafts.isEmpty()) {
                button.setChecked(false);
                Snackbar.make(binding.getRoot(), "整帖付费与分节付费只能选择一种", Snackbar.LENGTH_LONG).show();
                return;
            }
            binding.paidSection.setVisibility(checked ? View.VISIBLE : View.GONE);
            updateComposerState();
        });
        binding.titleInput.setFilters(new InputFilter[]{new InputFilter.LengthFilter(120)});
        binding.contentInput.setFilters(new InputFilter[]{new InputFilter.LengthFilter(10000)});
        binding.paidPreviewInput.setFilters(new InputFilter[]{new InputFilter.LengthFilter(1000)});
        watch(binding.titleInput);
        watch(binding.contentInput);
        watch(binding.tagsInput);
        watch(binding.paidPriceInput);
        watch(binding.paidPreviewInput);
        binding.addSectionButton.setOnClickListener(view -> showSectionEditor(-1));
        binding.submitButton.setText(post ? "发布帖子" : "发表评论");
        binding.addAttachmentButton.setOnClickListener(view -> showAttachmentMenu());
        binding.submitButton.setOnClickListener(view -> submit());
        MenuItem draft = binding.toolbar.getMenu().add("保存草稿");
        draft.setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS);
        binding.toolbar.setOnMenuItemClickListener(item -> { saveDraft(true); return true; });
        restoreDraft();
        if (post) loadCategories();
        updateComposerState();
    }

    private void watch(EditText input) {
        input.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                updateComposerState();
            }
            @Override public void afterTextChanged(Editable value) { }
        });
    }

    private void loadCategories() {
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("plate_id", String.valueOf(getIntent().getLongExtra(EXTRA_TARGET_ID, 0)));
        taxonomyRequest = AppAccess.from(this).repository().get("/api/user/forum-categories", query, result -> {
            taxonomyRequest = null;
            if (!isUiActive()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "二级分类加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            categories.clear();
            categories.addAll(objects(result.items()));
            if (selectedCategoryId > 0 && findById(categories, selectedCategoryId) == null) selectedCategoryId = 0;
            renderCategoryChips();
            loadRecommendedTags();
        });
    }

    private void renderCategoryChips() {
        binding.categoryChips.removeAllViews();
        binding.categoryChips.addView(categoryChip("未分类", 0));
        for (JsonObject category : categories) {
            long id = Jsons.longValue(category, "id");
            binding.categoryChips.addView(categoryChip(Jsons.string(category, "name"), id));
        }
    }

    private Chip categoryChip(String label, long categoryId) {
        Chip chip = new Chip(this);
        chip.setText(label);
        chip.setCheckable(true);
        chip.setChecked(selectedCategoryId == categoryId);
        chip.setEnsureMinTouchTargetSize(false);
        chip.setOnClickListener(view -> {
            if (selectedCategoryId == categoryId) return;
            selectedCategoryId = categoryId;
            renderCategoryChips();
            loadRecommendedTags();
            updateComposerState();
        });
        return chip;
    }

    private void loadRecommendedTags() {
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("plate_id", String.valueOf(getIntent().getLongExtra(EXTRA_TARGET_ID, 0)));
        if (selectedCategoryId > 0) query.put("category_id", String.valueOf(selectedCategoryId));
        taxonomyRequest = AppAccess.from(this).repository().get("/api/user/forum-tags", query, result -> {
            taxonomyRequest = null;
            if (!isUiActive() || !result.isSuccessful()) return;
            recommendedTags.clear();
            recommendedTags.addAll(objects(result.items()));
            renderRecommendedTags();
        });
    }

    private void renderRecommendedTags() {
        binding.recommendedTagChips.removeAllViews();
        Set<String> selected = enteredTags();
        for (JsonObject tag : recommendedTags) {
            String name = Jsons.string(tag, "name");
            Chip chip = new Chip(this);
            chip.setText("#" + name);
            chip.setTag(name);
            chip.setCheckable(true);
            chip.setChecked(selected.contains(name));
            chip.setEnsureMinTouchTargetSize(false);
            chip.setOnClickListener(view -> toggleRecommendedTag(name, ((Chip) view).isChecked()));
            binding.recommendedTagChips.addView(chip);
        }
        updateAliasHint();
    }

    private void toggleRecommendedTag(String name, boolean selected) {
        Set<String> values = enteredTags();
        if (selected) values.add(name); else values.remove(name);
        binding.tagsInput.setText(String.join(",", values));
        binding.tagsInput.setSelection(binding.tagsInput.length());
        updateAliasHint();
        updateComposerState();
    }

    private Set<String> enteredTags() {
        Set<String> values = new LinkedHashSet<>();
        String raw = binding.tagsInput.getText() == null ? "" : binding.tagsInput.getText().toString();
        for (String tag : raw.split("[,，#\\s]+")) {
            String value = tag.trim();
            if (!value.isEmpty()) values.add(value);
        }
        return values;
    }

    private void updateAliasHint() {
        List<String> hints = new ArrayList<>();
        Set<String> selected = enteredTags();
        for (JsonObject tag : recommendedTags) {
            String name = Jsons.string(tag, "name");
            if (!selected.contains(name)) continue;
            List<String> aliases = new ArrayList<>();
            for (JsonElement alias : Jsons.array(tag, "aliases")) if (alias.isJsonPrimitive()) aliases.add(alias.getAsString());
            if (!aliases.isEmpty()) hints.add(name + "：" + String.join(" / ", aliases));
        }
        binding.tagAliasHint.setText(hints.isEmpty()
            ? "可直接选择，也可以在下方输入自定义标签"
            : "同义标签会自动归一：" + String.join("；", hints));
    }

    private void showSectionEditor(int position) {
        SectionDraft existing = position >= 0 && position < sectionDrafts.size() ? sectionDrafts.get(position) : null;
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(20), dp(4), dp(20), 0);
        EditText title = editor("内容节标题", false);
        EditText content = editor("本节正文", true);
        EditText tags = editor("标签，使用逗号分隔", false);
        MaterialSwitch paid = new MaterialSwitch(this);
        paid.setText("本节需要余额解锁");
        EditText price = editor("解锁价格（余额）", false);
        price.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL);
        if (existing != null) {
            title.setText(existing.title);
            content.setText(existing.content);
            tags.setText(existing.tags);
            paid.setChecked(existing.paid);
            price.setText(existing.price > 0 ? String.valueOf(existing.price) : "");
        }
        price.setVisibility(paid.isChecked() ? View.VISIBLE : View.GONE);
        paid.setOnCheckedChangeListener((button, checked) -> price.setVisibility(checked ? View.VISIBLE : View.GONE));
        form.addView(title); form.addView(content); form.addView(tags); form.addView(paid); form.addView(price);
        new YiyunyingDialogBuilder(this).setTitle(existing == null ? "添加内容节" : "编辑内容节")
            .setView(form)
            .setPositiveButton("保存", (dialog, which) -> {
                String sectionContent = text(content);
                double sectionPrice = number(text(price));
                if (sectionContent.isEmpty() || (paid.isChecked() && sectionPrice <= 0)) {
                    Snackbar.make(binding.getRoot(), paid.isChecked() ? "付费节需要正文和大于 0 的价格" : "内容节正文不能为空", Snackbar.LENGTH_LONG).show();
                    return;
                }
                SectionDraft value = new SectionDraft(text(title), sectionContent, text(tags), paid.isChecked(), sectionPrice);
                if (existing == null) sectionDrafts.add(value); else sectionDrafts.set(position, value);
                if (!sectionDrafts.isEmpty()) binding.paidSwitch.setChecked(false);
                renderSectionEditors();
                updateComposerState();
            }).setNegativeButton("取消", null).show();
    }

    private EditText editor(String hint, boolean multiline) {
        EditText input = new EditText(this);
        input.setHint(hint);
        input.setTextSize(15);
        input.setSingleLine(!multiline);
        input.setInputType(multiline
            ? InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES
            : InputType.TYPE_CLASS_TEXT);
        if (multiline) { input.setMinLines(4); input.setGravity(Gravity.TOP | Gravity.START); }
        input.setPadding(dp(4), dp(8), dp(4), dp(8));
        return input;
    }

    private void renderSectionEditors() {
        binding.sectionEditorContainer.removeAllViews();
        for (int index = 0; index < sectionDrafts.size(); index++) {
            SectionDraft section = sectionDrafts.get(index);
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            cardParams.bottomMargin = dp(7);
            card.setLayoutParams(cardParams);
            card.setRadius(dp(8));
            card.setCardElevation(0);
            card.setStrokeWidth(dp(1));
            card.setStrokeColor(getColor(R.color.outline));
            LinearLayout body = new LinearLayout(this);
            body.setOrientation(LinearLayout.VERTICAL);
            body.setPadding(dp(12), dp(10), dp(12), dp(8));
            TextView summary = new TextView(this);
            summary.setText("第 " + (index + 1) + " 节 · " + (section.title.isEmpty() ? "未命名" : section.title)
                + "\n" + (section.paid ? "付费 " + section.price + " 余额" : "免费") + " · " + preview(section.content));
            summary.setTextColor(getColor(R.color.on_surface));
            summary.setTextSize(14);
            body.addView(summary);
            LinearLayout actions = new LinearLayout(this);
            actions.setOrientation(LinearLayout.HORIZONTAL);
            int current = index;
            MaterialButton up = sectionAction("上移", view -> moveSection(current, -1));
            up.setEnabled(index > 0);
            MaterialButton down = sectionAction("下移", view -> moveSection(current, 1));
            down.setEnabled(index < sectionDrafts.size() - 1);
            actions.addView(up); actions.addView(down);
            actions.addView(sectionAction("编辑", view -> showSectionEditor(current)));
            actions.addView(sectionAction("删除", view -> {
                sectionDrafts.remove(current);
                renderSectionEditors();
            }));
            body.addView(actions, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(42)));
            card.addView(body);
            binding.sectionEditorContainer.addView(card);
        }
        updateComposerState();
    }

    private MaterialButton sectionAction(String text, View.OnClickListener listener) {
        MaterialButton button = new MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(text);
        button.setTextSize(12);
        button.setMinWidth(0);
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setOnClickListener(listener);
        return button;
    }

    private void moveSection(int position, int direction) {
        int target = position + direction;
        if (position < 0 || position >= sectionDrafts.size() || target < 0 || target >= sectionDrafts.size()) return;
        SectionDraft value = sectionDrafts.remove(position);
        sectionDrafts.add(target, value);
        renderSectionEditors();
        updateComposerState();
    }

    private void showAttachmentMenu() {
        List<String> items = new ArrayList<>();
        items.add("从本地相册选择图片");
        items.add("选择本地视频");
        items.add("选择本地语音");
        items.add("选择本地文件");
        items.add("选择我的表情包");
        if (!attachments.isEmpty()) items.add("清空全部附件");
        new YiyunyingDialogBuilder(this)
            .setTitle("添加到内容")
            .setItems(items.toArray(new String[0]), (dialog, which) -> {
                String selected = items.get(which);
                if (selected.startsWith("从本地相册"))
                    imagePicker.launch(MediaPickerActivity.imageIntent(this, 200));
                else if (selected.startsWith("选择本地视频")) pick("video", "video/*");
                else if (selected.startsWith("选择本地语音")) pick("audio", "audio/*");
                else if (selected.startsWith("选择本地文件")) pick("file", "*/*");
                else if (selected.startsWith("选择我的表情")) loadStickers();
                else { attachments.clear(); renderPending(); }
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void pick(String type, String mime) {
        pickerType = type;
        if ("video".equals(type)) filePicker.launch(MediaPickerActivity.videoIntent(this, 200));
        else filePicker.launch(FilePickerActivity.pickerIntent(this, 50));
    }

    private void selectedResult(String requestedType, ActivityResult result) {
        if (!isUiActive()) return;
        if (result.getResultCode() != RESULT_OK || result.getData() == null) return;
        ArrayList<Uri> uris = result.getData()
            .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
        if (uris == null) {
            ArrayList<String> values = result.getData()
                .getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
            uris = new ArrayList<>();
            if (values != null) for (String value : values) if (value != null) uris.add(Uri.parse(value));
        }
        selected(requestedType, uris);
    }

    private void selected(String requestedType, List<Uri> uris) {
        if (!isUiActive() || uris == null) return;
        for (Uri uri : uris) {
            if (uri == null || attachments.size() >= 200 || contains(uri)) continue;
            String name = "本地文件";
            long size = -1;
            try (Cursor cursor = getContentResolver().query(uri, null, null, null, null)) {
                if (cursor != null && cursor.moveToFirst()) {
                    int nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                    int sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE);
                    if (nameIndex >= 0 && !cursor.isNull(nameIndex)) name = cursor.getString(nameIndex);
                    if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex);
                }
            }
            String mime = getContentResolver().getType(uri);
            if (mime == null) mime = "application/octet-stream";
            String type = "file".equals(requestedType) ? fromMime(mime) : requestedType;
            if (!UploadPolicyStore.accepts(this, type, size)) {
                Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, type, size), Snackbar.LENGTH_LONG).show();
                continue;
            }
            attachments.add(Attachment.local(uri, type, name, mime, size));
        }
        renderPending();
    }

    private boolean contains(Uri uri) {
        for (Attachment item : attachments) if (uri.equals(item.uri)) return true;
        return false;
    }

    private String fromMime(String mime) {
        String value = mime.toLowerCase();
        if (value.startsWith("image/")) return "image";
        if (value.startsWith("audio/")) return "audio";
        if (value.startsWith("video/")) return "video";
        return "file";
    }

    private void loadStickers() {
        if (stickerRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        stickerRequest = AppAccess.from(this).repository().get("/api/user/sticker-packs", new LinkedHashMap<>(), result -> {
            stickerRequest = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "表情包加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> stickers = new ArrayList<>();
            for (JsonElement pack : result.items()) if (pack.isJsonObject()) {
                for (JsonElement sticker : Jsons.array(pack.getAsJsonObject(), "stickers")) if (sticker.isJsonObject()) stickers.add(sticker.getAsJsonObject());
            }
            String[] labels = new String[stickers.size()];
            for (int index = 0; index < stickers.size(); index++) {
                String name = Jsons.string(stickers.get(index), "name");
                labels[index] = name.isEmpty() ? "表情 " + (index + 1) : name;
            }
            new YiyunyingDialogBuilder(this)
                .setTitle("我的表情包")
                .setItems(labels, (dialog, which) -> {
                    JsonObject sticker = stickers.get(which);
                    attachments.add(Attachment.sticker(Jsons.longValue(sticker, "id"), labels[which], Jsons.string(sticker, "image_url")));
                    renderPending();
                })
                .setNegativeButton("关闭", null)
                .show();
        });
    }

    private void renderPending() {
        binding.pendingContainer.removeAllViews();
        binding.attachmentSummary.setText(attachmentSummaryText());
        for (int index = 0; index < attachments.size(); index++) {
            Attachment attachment = attachments.get(index);
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(68));
            cardParams.bottomMargin = dp(6);
            card.setLayoutParams(cardParams);
            card.setRadius(dp(6));
            card.setCardElevation(0);
            card.setCardBackgroundColor(getColor(R.color.surface_container));
            LinearLayout row = new LinearLayout(this);
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(8), dp(6), dp(6), dp(6));
            if ("image".equals(attachment.type) || "sticker".equals(attachment.type)) {
                ImageView preview = new ImageView(this);
                preview.setScaleType(ImageView.ScaleType.CENTER_CROP);
                if (attachment.uri != null) preview.setImageURI(attachment.uri);
                else ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, attachment.previewUrl), preview, R.drawable.ic_file);
                row.addView(preview, new LinearLayout.LayoutParams(dp(52), dp(52)));
            }
            TextView text = new TextView(this);
            text.setText(typeLabel(attachment.type) + "\n" + attachment.name);
            text.setGravity(Gravity.CENTER_VERTICAL);
            LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f);
            textParams.leftMargin = dp(10);
            row.addView(text, textParams);
            MaterialButton remove = new MaterialButton(this);
            remove.setText("移除");
            int target = index;
            remove.setOnClickListener(view -> { attachments.remove(target); renderPending(); });
            row.addView(remove, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(48)));
            card.addView(row);
            binding.pendingContainer.addView(card);
        }
        updateComposerState();
    }

    private void submit() {
        if (submitRequest != null || uploadRequest != null) return;
        String title = binding.titleInput.getText() == null ? "" : binding.titleInput.getText().toString().trim();
        String content = binding.contentInput.getText() == null ? "" : binding.contentInput.getText().toString().trim();
        binding.titleLayout.setError(null);
        binding.contentLayout.setError(null);
        binding.tagsLayout.setError(null);
        binding.paidPriceLayout.setError(null);
        binding.paidPreviewLayout.setError(null);
        if (MODE_POST.equals(mode()) && title.isEmpty()) {
            focusField(binding.titleLayout, "请填写帖子标题");
            return;
        }
        if (title.length() > 120) {
            focusField(binding.titleLayout, "帖子标题不能超过 120 个字");
            return;
        }
        if (content.length() > 10000) {
            focusField(binding.contentLayout, "正文不能超过 10000 个字");
            return;
        }
        if (content.isEmpty() && attachments.isEmpty() && sectionDrafts.isEmpty()) {
            focusField(binding.contentLayout, "请填写正文，或添加附件、内容节");
            return;
        }
        if (MODE_POST.equals(mode()) && enteredTags().size() > 10) {
            focusField(binding.tagsLayout, "标签最多选择或填写 10 个");
            return;
        }
        if (!sectionDrafts.isEmpty() && binding.paidSwitch.isChecked()) {
            Snackbar.make(binding.getRoot(), "整帖付费与内容分节不能同时启用", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (MODE_POST.equals(mode()) && binding.paidSwitch.isChecked()) {
            String priceText = text(binding.paidPriceInput);
            if (!validMoney(priceText)) {
                focusField(binding.paidPriceLayout,
                    "请输入不低于 0.01 且最多两位小数的余额");
                return;
            }
            if (text(binding.paidPreviewInput).isEmpty()) {
                focusField(binding.paidPreviewLayout, "请填写免费预览内容");
                return;
            }
        }
        setEnabled(false);
        upload(content, title, 0, new JsonArray());
    }

    private void upload(String content, String title, int index, JsonArray media) {
        if (index >= attachments.size()) { post(content, title, media); return; }
        Attachment item = attachments.get(index);
        if (item.uri == null) {
            JsonObject attachment = new JsonObject();
            attachment.addProperty("media_type", "sticker");
            attachment.addProperty("sticker_id", item.stickerId);
            media.add(attachment);
            upload(content, title, index + 1, media);
            return;
        }
        binding.progress.setVisibility(View.VISIBLE);
        binding.publishReadiness.setText("正在上传附件 " + (index + 1) + " / " + attachments.size());
        binding.submitButton.setText("上传中 " + (index + 1) + " / " + attachments.size());
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", MODE_POST.equals(mode()) ? "forum_post" : "forum_comment");
        ContentUriRequestBody file = new ContentUriRequestBody(getContentResolver(), item.uri, item.mime, item.size);
        uploadRequest = AppAccess.from(this).repository().upload("/api/user/uploads", item.name, item.mime, file, fields, result -> {
            uploadRequest = null;
            if (!isUiActive()) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                binding.progress.setVisibility(View.INVISIBLE);
                setEnabled(true);
                Snackbar.make(binding.getRoot(), ActionFeedback.failure(
                    result, "论坛附件上传失败，请重试"), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject attachment = new JsonObject();
            attachment.addProperty("media_type", item.type);
            attachment.addProperty("upload_id", Jsons.longValue(result.dataObject(), "upload_id"));
            attachment.addProperty("file_name", item.name);
            attachment.addProperty("mime_type", item.mime);
            if (item.size > 0) attachment.addProperty("size_bytes", item.size);
            media.add(attachment);
            upload(content, title, index + 1, media);
        });
    }

    private void post(String content, String title, JsonArray media) {
        binding.publishReadiness.setText("附件已就绪，正在提交内容");
        binding.submitButton.setText("正在发布");
        JsonObject body = new JsonObject();
        body.addProperty("content", content);
        body.add("attachments", media);
        String path;
        long target = getIntent().getLongExtra(EXTRA_TARGET_ID, 0);
        if (MODE_POST.equals(mode())) {
            path = "/api/user/forum-posts";
            body.addProperty("plate_id", target);
            if (selectedCategoryId > 0) body.addProperty("category_id", selectedCategoryId);
            body.addProperty("title", title);
            JsonArray tags = new JsonArray();
            for (String tag : enteredTags()) if (tags.size() < 10) tags.add(tag);
            body.add("tags", tags);
            if (!sectionDrafts.isEmpty()) body.add("sections", sectionsJson());
        } else path = "/api/user/forum-posts/" + target + "/comments";
        submitRequest = AppAccess.from(this).repository().post(path, body, result -> {
            submitRequest = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            setEnabled(true);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), ActionFeedback.failure(
                    result, MODE_POST.equals(mode())
                        ? "帖子发布失败，请检查内容后重试"
                        : "评论发布失败，请检查内容后重试"),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            if (MODE_POST.equals(mode()) && binding.paidSwitch.isChecked()) {
                configurePaidContent(Jsons.longValue(result.dataObject(), "post_id"));
            } else finishSuccess(result.message());
        });
    }

    private void configurePaidContent(long postId) {
        double price;
        try { price = Double.parseDouble(text(binding.paidPriceInput)); }
        catch (NumberFormatException exception) { price = 0; }
        if (!validMoney(text(binding.paidPriceInput)) || price <= 0 || text(binding.paidPreviewInput).isEmpty()) {
            setEnabled(true);
            Snackbar.make(binding.getRoot(), "付费帖子需要填写大于 0 的余额价格和免费预览内容", Snackbar.LENGTH_LONG).show();
            return;
        }
        binding.publishReadiness.setText("帖子已创建，正在保存付费规则");
        binding.submitButton.setText("保存付费规则");
        JsonObject body = new JsonObject(); body.addProperty("price_balance", price); body.addProperty("preview_content", text(binding.paidPreviewInput));
        paidRequest = AppAccess.from(this).repository().put("/api/user/forum-posts/" + postId + "/paid-content", body, result -> {
            paidRequest = null;
            if (!isUiActive()) return;
            if (!result.isSuccessful()) {
                setEnabled(true);
                Snackbar.make(binding.getRoot(), ActionFeedback.failure(
                    result, "帖子已创建，但付费规则保存失败，请重试"),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            finishSuccess("付费帖子发布成功");
        });
    }

    private void finishSuccess(String message) {
        if (!isUiActive()) return;
        submittedSuccessfully = true; clearDraft(); setResult(RESULT_OK);
        String fallback = MODE_POST.equals(mode()) ? "帖子已发布" : "评论已发布";
        Snackbar.make(binding.getRoot(),
            message == null || message.trim().isEmpty() ? fallback : message,
            Snackbar.LENGTH_SHORT).show();
        binding.getRoot().postDelayed(() -> {
            if (!isFinishing() && !isDestroyed()) finish();
        }, 250L);
    }

    private void focusField(com.google.android.material.textfield.TextInputLayout layout,
                            String message) {
        layout.setError(message);
        View field = layout.getEditText() == null ? layout : layout.getEditText();
        binding.composerScroll.post(() -> {
            if (!isUiActive()) return;
            binding.composerScroll.smoothScrollTo(
                0, Math.max(0, viewTopInComposer(layout) - dp(20)));
            field.requestFocus();
        });
    }

    private int viewTopInComposer(View view) {
        int top = 0;
        View current = view;
        while (current != null && current != binding.composerScroll) {
            top += current.getTop();
            if (!(current.getParent() instanceof View)) break;
            current = (View) current.getParent();
        }
        return top;
    }

    private String draftKey() { return mode() + ":" + getIntent().getLongExtra(EXTRA_TARGET_ID, 0); }

    private void saveDraft(boolean notify) {
        if (binding == null) return;
        JsonObject draft = new JsonObject();
        draft.addProperty("title", text(binding.titleInput)); draft.addProperty("tags", text(binding.tagsInput));
        draft.addProperty("category_id", selectedCategoryId);
        draft.addProperty("content", text(binding.contentInput)); draft.addProperty("paid", binding.paidSwitch.isChecked());
        draft.addProperty("price", text(binding.paidPriceInput)); draft.addProperty("preview", text(binding.paidPreviewInput));
        draft.add("sections", sectionsJson());
        JsonArray savedAttachments = new JsonArray();
        for (Attachment item : attachments) {
            JsonObject value = new JsonObject(); value.addProperty("type", item.type); value.addProperty("name", item.name);
            value.addProperty("mime", item.mime); value.addProperty("size", item.size); value.addProperty("sticker_id", item.stickerId);
            value.addProperty("preview_url", item.previewUrl); value.addProperty("uri", item.uri == null ? "" : item.uri.toString());
            savedAttachments.add(value);
        }
        draft.add("attachments", savedAttachments);
        getSharedPreferences("forum_drafts", 0).edit().putString(draftKey(), draft.toString()).apply();
        if (notify) Snackbar.make(binding.getRoot(), "草稿已保存", Snackbar.LENGTH_SHORT).show();
    }

    private void restoreDraft() {
        String raw = getSharedPreferences("forum_drafts", 0).getString(draftKey(), "");
        if (raw == null || raw.isEmpty()) return;
        try {
            JsonObject draft = JsonParser.parseString(raw).getAsJsonObject();
            if (draft.has("category_id")) selectedCategoryId = Jsons.longValue(draft, "category_id");
            binding.titleInput.setText(Jsons.string(draft, "title")); binding.tagsInput.setText(Jsons.string(draft, "tags"));
            binding.contentInput.setText(Jsons.string(draft, "content")); binding.paidSwitch.setChecked(draft.has("paid") && draft.get("paid").getAsBoolean());
            binding.paidPriceInput.setText(Jsons.string(draft, "price")); binding.paidPreviewInput.setText(Jsons.string(draft, "preview"));
            for (JsonElement element : Jsons.array(draft, "sections")) {
                if (!element.isJsonObject()) continue;
                JsonObject value = element.getAsJsonObject();
                sectionDrafts.add(new SectionDraft(Jsons.string(value, "title"), Jsons.string(value, "content"),
                    joinTags(Jsons.array(value, "tags")), "paid".equals(Jsons.string(value, "section_type")), decimal(value, "price_balance")));
            }
            for (JsonElement element : Jsons.array(draft, "attachments")) {
                if (!element.isJsonObject()) continue; JsonObject value = element.getAsJsonObject();
                String uri = Jsons.string(value, "uri");
                attachments.add(new Attachment(uri.isEmpty() ? null : Uri.parse(uri), Jsons.string(value, "type"), Jsons.string(value, "name"),
                    Jsons.string(value, "mime"), Jsons.longValue(value, "size"), Jsons.longValue(value, "sticker_id"), Jsons.string(value, "preview_url")));
            }
            renderPending();
            renderSectionEditors();
        } catch (RuntimeException ignored) { }
    }

    private void clearDraft() { getSharedPreferences("forum_drafts", 0).edit().remove(draftKey()).apply(); }

    private static String text(android.widget.EditText input) { return input.getText() == null ? "" : input.getText().toString().trim(); }

    private static List<JsonObject> objects(JsonArray values) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement value : values) if (value.isJsonObject()) result.add(value.getAsJsonObject());
        return result;
    }

    private static JsonObject findById(List<JsonObject> values, long id) {
        for (JsonObject value : values) if (Jsons.longValue(value, "id") == id) return value;
        return null;
    }

    private JsonArray sectionsJson() {
        JsonArray result = new JsonArray();
        for (SectionDraft section : sectionDrafts) {
            JsonObject value = new JsonObject();
            value.addProperty("section_type", section.paid ? "paid" : "free");
            value.addProperty("title", section.title);
            value.addProperty("content", section.content);
            if (section.paid) value.addProperty("price_balance", section.price);
            JsonArray tags = new JsonArray();
            for (String tag : section.tags.split("[,，]")) if (!tag.trim().isEmpty()) tags.add(tag.trim());
            value.add("tags", tags);
            result.add(value);
        }
        return result;
    }

    private String joinTags(JsonArray tags) {
        List<String> values = new ArrayList<>();
        for (JsonElement tag : tags) if (tag.isJsonPrimitive()) values.add(tag.getAsString());
        return String.join(",", values);
    }

    private double decimal(JsonObject value, String key) {
        try { return value.has(key) ? value.get(key).getAsDouble() : 0d; }
        catch (RuntimeException ignored) { return 0d; }
    }

    private double number(String value) {
        try { return Double.parseDouble(value); }
        catch (NumberFormatException ignored) { return 0d; }
    }

    private boolean validMoney(String value) {
        return value != null
            && value.matches("^\\d+(\\.\\d{1,2})?$")
            && number(value) >= 0.01d;
    }

    private String attachmentSummaryText() {
        if (attachments.isEmpty()) return "尚未添加附件";
        Map<String, Integer> counts = new LinkedHashMap<>();
        long totalSize = 0L;
        for (Attachment attachment : attachments) {
            counts.put(attachment.type, counts.getOrDefault(attachment.type, 0) + 1);
            totalSize += Math.max(0L, attachment.size);
        }
        List<String> parts = new ArrayList<>();
        for (Map.Entry<String, Integer> entry : counts.entrySet()) {
            parts.add(typeLabel(entry.getKey()) + " " + entry.getValue());
        }
        String size = totalSize > 0 ? " · " + Formatter.formatShortFileSize(this, totalSize) : "";
        return "共 " + attachments.size() + " 个 · " + String.join("、", parts) + size;
    }

    private String selectedCategoryName() {
        if (selectedCategoryId <= 0) return "未分类";
        JsonObject category = findById(categories, selectedCategoryId);
        String name = category == null ? "" : Jsons.string(category, "name");
        return name.isEmpty() ? "已选分类" : name;
    }

    private void updateComposerState() {
        if (binding == null) return;
        boolean post = MODE_POST.equals(mode());
        String title = text(binding.titleInput);
        String content = text(binding.contentInput);
        int tagCount = enteredTags().size();
        boolean hasContent = !content.isEmpty() || !attachments.isEmpty() || !sectionDrafts.isEmpty();

        List<String> summary = new ArrayList<>();
        if (post) summary.add(selectedCategoryName());
        if (tagCount > 0) summary.add(tagCount + " 个标签");
        if (!attachments.isEmpty()) summary.add(attachments.size() + " 个附件");
        if (!sectionDrafts.isEmpty()) summary.add(sectionDrafts.size() + " 个内容节");
        if (post && binding.paidSwitch.isChecked()) summary.add("整篇付费");
        binding.publishSummary.setText(summary.isEmpty()
            ? (post ? "先完善标题和正文，再选择分类、标签或附件。" : "评论支持文字、Emoji 和附件。")
            : String.join(" · ", summary));

        if (working) return;
        List<String> missing = new ArrayList<>();
        if (post && title.isEmpty()) missing.add("标题");
        if (!hasContent) missing.add(post ? "正文或附件" : "评论内容");
        if (post && tagCount > 10) {
            binding.publishReadiness.setText("标签已超过 10 个，请删减后发布");
        } else if (post && binding.paidSwitch.isChecked() && !validMoney(text(binding.paidPriceInput))) {
            binding.publishReadiness.setText("请填写有效的付费余额，最低 0.01");
        } else if (post && binding.paidSwitch.isChecked() && text(binding.paidPreviewInput).isEmpty()) {
            binding.publishReadiness.setText("请填写免费预览内容");
        } else if (!missing.isEmpty()) {
            binding.publishReadiness.setText("还需要填写：" + String.join("、", missing));
        } else {
            binding.publishReadiness.setText(post ? "内容已就绪，发布后按板块规则进入展示或审核" : "评论内容已就绪");
        }
        binding.submitButton.setText(post ? "发布帖子" : "发表评论");
    }

    private String preview(String value) { return value.length() <= 42 ? value : value.substring(0, 42) + "…"; }

    private void setEnabled(boolean enabled) {
        working = !enabled;
        binding.submitButton.setEnabled(enabled);
        binding.addAttachmentButton.setEnabled(enabled);
        binding.addSectionButton.setEnabled(enabled);
        binding.titleInput.setEnabled(enabled);
        binding.contentInput.setEnabled(enabled);
        binding.tagsInput.setEnabled(enabled);
        binding.paidSwitch.setEnabled(enabled);
        binding.paidPriceInput.setEnabled(enabled);
        binding.paidPreviewInput.setEnabled(enabled);
        if (!enabled) {
            binding.publishReadiness.setText("正在准备发布内容");
            binding.submitButton.setText("处理中");
        } else {
            updateComposerState();
        }
    }

    private String mode() {
        String value = getIntent().getStringExtra(EXTRA_MODE);
        return value == null ? MODE_POST : value;
    }

    private String typeLabel(String type) {
        if ("image".equals(type)) return "图片";
        if ("sticker".equals(type)) return "表情包";
        if ("audio".equals(type)) return "语音";
        if ("video".equals(type)) return "视频";
        return "文件";
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    @Override protected void onDestroy() {
        if (binding != null && !submittedSuccessfully && submitRequest == null) saveDraft(false);
        if (uploadRequest != null) uploadRequest.cancel();
        if (submitRequest != null) submitRequest.cancel();
        if (stickerRequest != null) stickerRequest.cancel();
        if (paidRequest != null) paidRequest.cancel();
        if (taxonomyRequest != null) taxonomyRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private static final class Attachment {
        final Uri uri;
        final String type;
        final String name;
        final String mime;
        final long size;
        final long stickerId;
        final String previewUrl;

        private Attachment(Uri uri, String type, String name, String mime, long size, long stickerId, String previewUrl) {
            this.uri = uri; this.type = type; this.name = name; this.mime = mime; this.size = size;
            this.stickerId = stickerId; this.previewUrl = previewUrl;
        }
        static Attachment local(Uri uri, String type, String name, String mime, long size) {
            return new Attachment(uri, type, name, mime, size, 0, "");
        }
        static Attachment sticker(long id, String name, String previewUrl) {
            return new Attachment(null, "sticker", name, "image/*", 0, id, previewUrl);
        }
    }

    private static final class SectionDraft {
        final String title; final String content; final String tags; final boolean paid; final double price;
        SectionDraft(String title, String content, String tags, boolean paid, double price) {
            this.title = title; this.content = content; this.tags = tags; this.paid = paid; this.price = price;
        }
    }
}
