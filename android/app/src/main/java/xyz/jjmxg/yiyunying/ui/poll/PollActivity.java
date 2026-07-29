package xyz.jjmxg.yiyunying.ui.poll;

import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;
import android.text.Editable;
import android.text.TextWatcher;
import android.view.LayoutInflater;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.CompoundButton;
import android.widget.ImageView;
import android.widget.RadioButton;
import android.widget.ScrollView;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.checkbox.MaterialCheckBox;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.common.SafeTextInput;
import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.snackbar.Snackbar;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.bumptech.glide.Glide;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.text.SimpleDateFormat;
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
import xyz.jjmxg.yiyunying.databinding.ActivityForumListBinding;
import xyz.jjmxg.yiyunying.databinding.ItemRecordBinding;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

public final class PollActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable delayedSearch = this::loadPolls;
    private ActivityForumListBinding binding;
    private PollAdapter adapter;
    private RequestHandle listRequest;
    private RequestHandle categoryRequest;
    private RequestHandle actionRequest;
    private RequestHandle optionUploadRequest;
    private JsonArray categories = new JsonArray();
    private PollOptionDraft pendingOptionImage;
    private final ActivityResultLauncher<Intent> pollComposer = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != RESULT_OK || binding == null) return;
            String message = result.getData() == null ? ""
                : result.getData().getStringExtra(PollComposerActivity.EXTRA_FEEDBACK);
            Snackbar.make(binding.getRoot(),
                message == null || message.trim().isEmpty() ? "投票已发布" : message,
                Snackbar.LENGTH_SHORT).show();
            loadCategories();
            loadPolls();
        });
    private final ActivityResultLauncher<Intent> optionImagePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            PollOptionDraft draft = pendingOptionImage;
            pendingOptionImage = null;
            if (draft == null || draft.root.getParent() == null
                || result.getResultCode() != RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> selected = result.getData()
                .getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (selected == null || selected.isEmpty()) return;
            draft.imageUri = selected.get(0);
            draft.imageUrl = "";
            draft.imageButton.setText("更换选项图片");
            draft.imagePreview.setVisibility(View.VISIBLE);
            Glide.with(this).load(draft.imageUri).centerCrop().into(draft.imagePreview);
        });

    public static void open(Context context) {
        context.startActivity(new Intent(context, PollActivity.class));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        binding = ActivityForumListBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        binding.toolbar.setTitle("投票活动");
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        MenuItem categoriesItem = binding.toolbar.getMenu().add("投票分类");
        categoriesItem.setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        categoriesItem.setOnMenuItemClickListener(item -> { showCategories(); return true; });
        binding.searchLayout.setVisibility(View.VISIBLE);
        binding.searchLayout.setHint("搜索投票");
        binding.createButton.setVisibility(View.VISIBLE);
        binding.createButton.setText("发起投票");
        binding.createButton.setContentDescription("发起投票");
        binding.createButton.setOnClickListener(view ->
            pollComposer.launch(new Intent(this, PollComposerActivity.class)));
        adapter = new PollAdapter(this::showPoll);
        binding.recycler.setLayoutManager(new LinearLayoutManager(this));
        binding.recycler.setAdapter(adapter);
        binding.swipeRefresh.setOnRefreshListener(() -> { loadCategories(); loadPolls(); });
        binding.searchInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                handler.removeCallbacks(delayedSearch);
                handler.postDelayed(delayedSearch, 350L);
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        loadCategories();
        loadPolls();
    }

    private void loadCategories() {
        if (categoryRequest != null) categoryRequest.cancel();
        categoryRequest = AppAccess.from(this).repository().get("/api/user/poll-categories", new LinkedHashMap<>(), result -> {
            categoryRequest = null;
            if (binding == null) return;
            if (result.isAuthenticationFailure()) { login(); return; }
            if (result.isSuccessful()) categories = result.items();
        });
    }

    private void loadPolls() {
        handler.removeCallbacks(delayedSearch);
        if (listRequest != null) listRequest.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        listRequest = AppAccess.from(this).repository().get("/api/user/polls", query, result -> {
            listRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(),
                    ActionFeedback.failure(result, "投票列表加载失败，请重试"),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            List<JsonObject> items = objects(result.items());
            adapter.submit(items);
            binding.emptyText.setText("还没有投票活动");
            binding.emptyText.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);
        });
    }

    private void showCreatePoll() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout form = verticalContainer();
        scroll.addView(form);
        TextInputLayout title = textInput("投票标题 *", false);
        TextInputLayout description = textInput("投票说明", true);
        form.addView(title);
        form.addView(description, topParams(8));
        TextView categoryHeading = heading("选择分类");
        form.addView(categoryHeading, topParams(14));
        List<MaterialCheckBox> categoryChecks = new ArrayList<>();
        for (JsonElement element : categories) {
            if (!element.isJsonObject()) continue;
            JsonObject category = element.getAsJsonObject();
            MaterialCheckBox check = new MaterialCheckBox(this);
            check.setText(Jsons.string(category, "name"));
            check.setTag(Jsons.longValue(category, "id"));
            check.setMinHeight(dp(44));
            categoryChecks.add(check);
            form.addView(check);
        }
        if (categoryChecks.isEmpty()) {
            TextView none = new TextView(this);
            none.setText("暂无分类，可通过右上角菜单先创建；不选择分类也能发布。 ");
            none.setTextColor(getColor(R.color.on_surface_variant));
            form.addView(none);
        }
        MaterialSwitch multiple = new MaterialSwitch(this);
        multiple.setText("允许多选");
        multiple.setMinHeight(dp(52));
        form.addView(multiple, topParams(10));
        final long[] durationHours = {24L * 7L};
        MaterialButton duration = new MaterialButton(this);
        duration.setText("截止时间：7 天后");
        duration.setIconResource(R.drawable.ic_calendar);
        duration.setOnClickListener(view -> {
            String[] labels = {"1 小时后", "1 天后", "3 天后", "7 天后", "30 天后"};
            long[] hours = {1L, 24L, 72L, 168L, 720L};
            new YiyunyingDialogBuilder(this)
                .setTitle("选择投票截止时间")
                .setSingleChoiceItems(labels, selectedDurationIndex(hours, durationHours[0]), (dialog, which) -> {
                    durationHours[0] = hours[which];
                    duration.setText("截止时间：" + labels[which]);
                    dialog.dismiss();
                })
                .setNegativeButton("取消", null)
                .show();
        });
        form.addView(duration, topParams(6));
        TextView optionHeading = heading("投票选项");
        form.addView(optionHeading, topParams(10));
        LinearLayout options = new LinearLayout(this);
        options.setOrientation(LinearLayout.VERTICAL);
        form.addView(options);
        addOptionRow(options, "");
        addOptionRow(options, "");
        MaterialButton addOption = new MaterialButton(this);
        addOption.setText("添加选项");
        addOption.setIconResource(R.drawable.ic_add);
        addOption.setOnClickListener(view -> {
            if (options.getChildCount() >= 20) Snackbar.make(binding.getRoot(), "客户端单次最多添加 20 个选项", Snackbar.LENGTH_LONG).show();
            else addOptionRow(options, "");
        });
        form.addView(addOption, topParams(8));

        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setTitle("发起投票")
            .setView(scroll)
            .setNegativeButton("取消", null)
            .setPositiveButton("发布", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            String titleText = input(title);
            if (titleText.isEmpty()) { title.setError("请填写投票标题"); return; }
            List<PollOptionDraft> optionValues = collectOptions(options);
            if (optionValues.size() < 2) {
                Snackbar.make(binding.getRoot(), "至少填写两个投票选项", Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject body = new JsonObject();
            body.addProperty("title", titleText);
            body.addProperty("description", input(description));
            body.addProperty("multiple_choice", multiple.isChecked());
            body.addProperty("multi_select", multiple.isChecked());
            body.addProperty("allow_multiple", multiple.isChecked());
            body.addProperty("min_select", 1);
            body.addProperty("max_select", multiple.isChecked() ? optionValues.size() : 1);
            body.addProperty("result_visibility", "after_vote");
            body.addProperty("ends_at", futureDateTime(durationHours[0]));
            JsonArray categoryIds = new JsonArray();
            for (MaterialCheckBox check : categoryChecks) if (check.isChecked()) categoryIds.add((Long) check.getTag());
            body.add("category_ids", categoryIds);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(false);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setText("正在发布…");
            uploadPollOptionImages(body, optionValues, 0, dialog);
        }));
        dialog.show();
    }

    private void addOptionRow(LinearLayout parent, String initial) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.VERTICAL);
        row.setPadding(dp(8), dp(4), dp(8), dp(8));
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(android.view.Gravity.CENTER_VERTICAL);
        TextInputLayout input = textInput("选项 " + (parent.getChildCount() + 1), false);
        input.getEditText().setText(initial);
        header.addView(input, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        MaterialButton remove = new MaterialButton(this);
        remove.setText("删除");
        remove.setContentDescription("删除这个选项");
        remove.setOnClickListener(view -> {
            if (parent.getChildCount() <= 2) Snackbar.make(binding.getRoot(), "投票至少保留两个选项", Snackbar.LENGTH_SHORT).show();
            else parent.removeView(row);
        });
        LinearLayout.LayoutParams removeParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(52));
        removeParams.leftMargin = dp(6);
        header.addView(remove, removeParams);
        row.addView(header);
        LinearLayout media = new LinearLayout(this);
        media.setOrientation(LinearLayout.HORIZONTAL);
        media.setGravity(android.view.Gravity.CENTER_VERTICAL);
        MaterialButton imageButton = new MaterialButton(this);
        imageButton.setText("添加选项图片（可选）");
        imageButton.setIconResource(R.drawable.ic_album);
        ImageView imagePreview = new ImageView(this);
        imagePreview.setScaleType(ImageView.ScaleType.CENTER_CROP);
        imagePreview.setVisibility(View.GONE);
        LinearLayout.LayoutParams imageParams = new LinearLayout.LayoutParams(dp(64), dp(64));
        imageParams.leftMargin = dp(10);
        media.addView(imageButton, new LinearLayout.LayoutParams(0, dp(48), 1f));
        media.addView(imagePreview, imageParams);
        row.addView(media, topParams(4));
        PollOptionDraft draft = new PollOptionDraft(row, input, imageButton, imagePreview);
        row.setTag(draft);
        imageButton.setOnClickListener(view -> {
            pendingOptionImage = draft;
            ArrayList<Uri> selectedUris = new ArrayList<>();
            if (draft.imageUri != null) selectedUris.add(draft.imageUri);
            optionImagePicker.launch(MediaPickerActivity.imageIntent(this, 1, selectedUris));
        });
        parent.addView(row, topParams(6));
    }

    private List<PollOptionDraft> collectOptions(LinearLayout options) {
        List<PollOptionDraft> result = new ArrayList<>();
        for (int index = 0; index < options.getChildCount(); index++) {
            LinearLayout row = (LinearLayout) options.getChildAt(index);
            Object tag = row.getTag();
            if (!(tag instanceof PollOptionDraft)) continue;
            PollOptionDraft draft = (PollOptionDraft) tag;
            draft.text = input(draft.input);
            if (!draft.text.isEmpty()) result.add(draft);
        }
        return result;
    }

    private void uploadPollOptionImages(JsonObject body, List<PollOptionDraft> options, int index,
                                        AlertDialog dialog) {
        if (binding == null || isFinishing() || isDestroyed()) return;
        if (index >= options.size()) {
            JsonArray values = new JsonArray();
            for (int position = 0; position < options.size(); position++) {
                PollOptionDraft draft = options.get(position);
                JsonObject option = new JsonObject();
                option.addProperty("option_text", draft.text);
                option.addProperty("image_url", draft.imageUrl);
                option.addProperty("sort_order", position);
                values.add(option);
            }
            body.add("options", values);
            dialog.dismiss();
            createPoll(body);
            return;
        }
        PollOptionDraft draft = options.get(index);
        if (draft.imageUri == null || !draft.imageUrl.isEmpty()) {
            uploadPollOptionImages(body, options, index + 1, dialog);
            return;
        }
        UriFile file = uriFile(draft.imageUri);
        binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", "poll_option");
        optionUploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", file.name, file.mime,
            new ContentUriRequestBody(getContentResolver(), draft.imageUri, file.mime, file.size),
            fields, result -> {
                optionUploadRequest = null;
                if (binding == null || isFinishing() || isDestroyed()) return;
                if (result.isAuthenticationFailure()) { dialog.dismiss(); login(); return; }
                if (!result.isSuccessful()) {
                    binding.progress.setVisibility(View.INVISIBLE);
                    dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
                    dialog.getButton(AlertDialog.BUTTON_POSITIVE).setText("发布");
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? "选项图片上传失败" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                draft.imageUrl = Jsons.string(result.dataObject(), "file_url");
                uploadPollOptionImages(body, options, index + 1, dialog);
            });
    }

    private UriFile uriFile(Uri uri) {
        String name = "投票选项图片";
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
        if (mime == null || !mime.startsWith("image/")) mime = "image/jpeg";
        return new UriFile(name, mime, size);
    }

    private void createPoll(JsonObject body) {
        if (actionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post("/api/user/polls", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            Snackbar.make(binding.getRoot(), result.isSuccessful()
                ? (result.message().isEmpty() ? "投票已发布" : result.message())
                : (result.message().isEmpty() ? "投票发布失败" : result.message()), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) loadPolls();
        });
    }

    private void showPoll(JsonObject summary) {
        if (actionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        long id = Jsons.longValue(summary, "id");
        actionRequest = AppAccess.from(this).repository().get("/api/user/polls/" + id, new LinkedHashMap<>(), result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(),
                    ActionFeedback.failure(result, "投票详情加载失败，请重试"),
                    Snackbar.LENGTH_LONG).show();
                return;
            }
            showPollDialog(Jsons.object(result.dataObject(), "poll"));
        });
    }

    private void showPollDialog(JsonObject poll) {
        ScrollView scroll = new ScrollView(this);
        LinearLayout content = verticalContainer();
        scroll.addView(content);
        TextView description = new TextView(this);
        description.setText(Jsons.string(poll, "description"));
        description.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
        if (!Jsons.string(poll, "description").isEmpty()) content.addView(description);
        JsonArray pollCategories = Jsons.array(poll, "categories");
        if (!pollCategories.isEmpty()) {
            StringBuilder names = new StringBuilder("分类：");
            for (JsonElement item : pollCategories) {
                if (!item.isJsonObject()) continue;
                if (names.length() > 3) names.append(" · ");
                names.append(Jsons.string(item.getAsJsonObject(), "name"));
            }
            TextView categoryText = new TextView(this);
            categoryText.setText(names);
            categoryText.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this));
            content.addView(categoryText, topParams(10));
        }
        boolean multiple = bool(poll, "multiple_choice")
            || bool(poll, "multi_select") || bool(poll, "allow_multiple");
        boolean resultsVisible = bool(poll, "results_visible");
        JsonArray selected = Jsons.array(poll, "selected_option_ids");
        List<Long> selectedIds = new ArrayList<>();
        for (JsonElement value : selected) selectedIds.add(value.getAsLong());
        List<CompoundButton> optionControls = new ArrayList<>();
        for (JsonElement element : Jsons.array(poll, "options")) {
            if (!element.isJsonObject()) continue;
            JsonObject option = element.getAsJsonObject();
            long optionId = Jsons.longValue(option, "id");
            String text = Jsons.string(option, "option_text");
            if (resultsVisible) text += "  ·  " + Jsons.longValue(option, "vote_count") + " 票";
            LinearLayout optionBlock = new LinearLayout(this);
            optionBlock.setOrientation(LinearLayout.VERTICAL);
            optionBlock.setPadding(dp(10), dp(8), dp(10), dp(8));
            String imageUrl = Jsons.string(option, "image_url");
            if (!imageUrl.isEmpty()) {
                ImageView preview = new ImageView(this);
                preview.setScaleType(ImageView.ScaleType.CENTER_CROP);
                preview.setContentDescription("投票选项图片");
                optionBlock.addView(preview, new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, dp(132)));
                Glide.with(this).load(imageUrl).centerCrop().into(preview);
            }
            CompoundButton control;
            if (multiple) {
                MaterialCheckBox check = new MaterialCheckBox(this);
                control = check;
            } else {
                RadioButton radio = new RadioButton(this);
                radio.setOnCheckedChangeListener((button, checked) -> {
                    if (!checked) return;
                    for (CompoundButton other : optionControls) {
                        if (other != button && other.isChecked()) other.setChecked(false);
                    }
                });
                control = radio;
            }
            control.setText(text);
            control.setTag(optionId);
            control.setMinHeight(dp(52));
            control.setChecked(selectedIds.contains(optionId));
            optionControls.add(control);
            optionBlock.addView(control);
            content.addView(optionBlock, topParams(8));
        }
        boolean active = "active".equals(Jsons.string(poll, "status"));
        AlertDialog dialog = new YiyunyingDialogBuilder(this)
            .setBusinessTitle(Jsons.string(poll, "title"))
            .setView(scroll)
            .setNegativeButton("关闭", null)
            .setPositiveButton(active ? (bool(poll, "voted") ? "修改投票" : "提交投票") : "已结束", null)
            .create();
        dialog.setOnShowListener(ignored -> {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(active);
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
                JsonArray ids = new JsonArray();
                for (CompoundButton control : optionControls) {
                    if (control.isChecked()) ids.add((Long) control.getTag());
                }
                int min = Math.max(1, (int) Jsons.longValue(poll, "min_select"));
                int max = Math.max(min, (int) Jsons.longValue(poll, "max_select"));
                if (ids.size() < min || ids.size() > max) {
                    Snackbar.make(binding.getRoot(), "请选择 " + min + " 至 " + max + " 个选项", Snackbar.LENGTH_LONG).show();
                    return;
                }
                dialog.dismiss();
                submitVote(Jsons.longValue(poll, "id"), ids);
            });
        });
        dialog.show();
    }

    private void submitVote(long pollId, JsonArray ids) {
        JsonObject body = new JsonObject();
        body.add("option_ids", ids);
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post("/api/user/polls/" + pollId + "/vote", body, result -> {
            actionRequest = null;
            if (binding == null) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            Snackbar.make(binding.getRoot(), ActionFeedback.message(
                result, "投票已提交", "投票提交失败，请重试"), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) loadPolls();
        });
    }

    private void showCategories() {
        List<String> names = new ArrayList<>();
        for (JsonElement element : categories) if (element.isJsonObject()) {
            JsonObject item = element.getAsJsonObject();
            names.add(Jsons.string(item, "name") + " · " + Jsons.longValue(item, "poll_count") + " 个投票");
        }
        new YiyunyingDialogBuilder(this)
            .setTitle("投票分类")
            .setItems(names.toArray(new String[0]), null)
            .setNeutralButton("新增分类", (dialog, which) -> showCreateCategory())
            .setPositiveButton("关闭", null)
            .show();
    }

    private void showCreateCategory() {
        TextInputLayout name = textInput("分类名称 *", false);
        new YiyunyingDialogBuilder(this)
            .setTitle("新增投票分类")
            .setView(name)
            .setNegativeButton("取消", null)
            .setPositiveButton("创建", (dialog, which) -> {
                String value = input(name);
                if (value.isEmpty()) return;
                JsonObject body = new JsonObject();
                body.addProperty("name", value);
                actionRequest = AppAccess.from(this).repository().post("/api/user/poll-categories", body, result -> {
                    actionRequest = null;
                    if (binding == null) return;
                    Snackbar.make(binding.getRoot(), ActionFeedback.message(
                        result, "投票分类已创建", "投票分类创建失败，请重试"),
                        Snackbar.LENGTH_LONG).show();
                    if (result.isSuccessful()) loadCategories();
                });
            })
            .show();
    }

    private LinearLayout verticalContainer() {
        LinearLayout result = new LinearLayout(this);
        result.setOrientation(LinearLayout.VERTICAL);
        result.setPadding(dp(20), dp(8), dp(20), dp(18));
        return result;
    }

    private TextInputLayout textInput(String hint, boolean multiline) {
        TextInputLayout layout = new TextInputLayout(this, null, com.google.android.material.R.attr.textInputOutlinedStyle);
        layout.setHint(hint);
        TextInputEditText input = new TextInputEditText(layout.getContext());
        input.setMinLines(multiline ? 3 : 1);
        input.setMaxLines(multiline ? 8 : 2);
        input.setInputType(android.text.InputType.TYPE_CLASS_TEXT | (multiline ? android.text.InputType.TYPE_TEXT_FLAG_MULTI_LINE : 0));
        SafeTextInput.attach(layout, input);
        return layout;
    }

    private TextView heading(String value) {
        TextView text = new TextView(this);
        text.setText(value);
        text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
        return text;
    }

    private LinearLayout.LayoutParams topParams(int top) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.topMargin = dp(top);
        return params;
    }

    private String input(TextInputLayout layout) {
        return layout.getEditText() == null || layout.getEditText().getText() == null
            ? "" : layout.getEditText().getText().toString().trim();
    }

    private boolean bool(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try {
            String value = object.get(key).getAsString().trim().toLowerCase(Locale.ROOT);
            return "true".equals(value) || "1".equals(value) || "yes".equals(value) || "on".equals(value);
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    private int selectedDurationIndex(long[] choices, long selected) {
        for (int index = 0; index < choices.length; index++) if (choices[index] == selected) return index;
        return 3;
    }

    private String futureDateTime(long hours) {
        Calendar calendar = Calendar.getInstance();
        calendar.setTime(new Date());
        calendar.add(Calendar.HOUR_OF_DAY, (int) Math.min(Integer.MAX_VALUE, hours));
        return new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(calendar.getTime());
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) result.add(element.getAsJsonObject());
        return result;
    }

    private static final class PollOptionDraft {
        final LinearLayout root;
        final TextInputLayout input;
        final MaterialButton imageButton;
        final ImageView imagePreview;
        Uri imageUri;
        String imageUrl = "";
        String text = "";

        PollOptionDraft(LinearLayout root, TextInputLayout input, MaterialButton imageButton,
                        ImageView imagePreview) {
            this.root = root;
            this.input = input;
            this.imageButton = imageButton;
            this.imagePreview = imagePreview;
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

    @Override protected void onDestroy() {
        handler.removeCallbacks(delayedSearch);
        if (listRequest != null) listRequest.cancel();
        if (categoryRequest != null) categoryRequest.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (optionUploadRequest != null) optionUploadRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private static final class PollAdapter extends RecyclerView.Adapter<PollAdapter.Holder> {
        interface Listener { void onClick(JsonObject poll); }
        private final Listener listener;
        private final List<JsonObject> items = new ArrayList<>();
        PollAdapter(Listener listener) { this.listener = listener; setHasStableIds(true); }
        void submit(List<JsonObject> next) {
            List<JsonObject> previous = new ArrayList<>(items);
            if (sameContents(previous, next)) return;
            DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
                @Override public int getOldListSize() { return previous.size(); }
                @Override public int getNewListSize() { return next.size(); }
                @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                    return Jsons.longValue(previous.get(oldPosition), "id")
                        == Jsons.longValue(next.get(newPosition), "id");
                }
                @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                    return previous.get(oldPosition).equals(next.get(newPosition));
                }
            }, false);
            items.clear();
            items.addAll(next);
            diff.dispatchUpdatesTo(this);
        }
        private static boolean sameContents(List<JsonObject> left, List<JsonObject> right) {
            if (left.size() != right.size()) return false;
            for (int index = 0; index < left.size(); index++) {
                if (!left.get(index).equals(right.get(index))) return false;
            }
            return true;
        }
        @Override public long getItemId(int position) { return Jsons.longValue(items.get(position), "id"); }
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            return new Holder(ItemRecordBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
        }
        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = items.get(position);
            holder.binding.title.setText(Jsons.string(item, "title"));
            String description = Jsons.string(item, "description");
            holder.binding.subtitle.setText(description.isEmpty() ? "暂无投票说明" : description);
            String categories = Jsons.string(item, "category_names");
            holder.binding.metadata.setText((categories.isEmpty() ? "未分类" : categories) + " · "
                + Jsons.longValue(item, "ballot_count") + " 人参与 · "
                + ("active".equals(Jsons.string(item, "status")) ? "进行中" : "已结束"));
            String creator = Jsons.string(item, "creator_name");
            holder.binding.avatar.setText(creator.isEmpty() ? "投" : creator.substring(0, 1));
            holder.binding.moreButton.setVisibility(View.GONE);
            holder.binding.selectionCheck.setVisibility(View.GONE);
            holder.binding.getRoot().setOnClickListener(view -> listener.onClick(item));
        }
        @Override public int getItemCount() { return items.size(); }
        static final class Holder extends RecyclerView.ViewHolder {
            final ItemRecordBinding binding;
            Holder(ItemRecordBinding binding) { super(binding.getRoot()); this.binding = binding; }
        }
    }
}
