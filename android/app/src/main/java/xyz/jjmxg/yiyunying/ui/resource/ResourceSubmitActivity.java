package xyz.jjmxg.yiyunying.ui.resource;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.drawable.Drawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.text.TextUtils;
import android.view.View;
import android.widget.ArrayAdapter;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.core.content.FileProvider;

import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityResourceSubmitBinding;
import xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;

/** Visual resource submission flow. The server remains the final permission authority. */
public final class ResourceSubmitActivity extends SystemInsetActivity {
    public static final int MODE_APPS = 1;
    public static final int MODE_SOURCE = 2;
    private static final String EXTRA_MODE = "resource_submit_mode";

    private ActivityResourceSubmitBinding binding;
    private final List<Long> categoryIds = new ArrayList<>();
    private final List<String> categoryNames = new ArrayList<>();
    private long selectedCategoryId;
    private Uri selectedFile;
    private Uri selectedCover;
    private FileInfo selectedFileInfo;
    private FileInfo selectedCoverInfo;
    private boolean submissionEnabled;
    private boolean auditRequired = true;
    private int mode = MODE_SOURCE;
    private RequestHandle policyRequest;
    private RequestHandle categoryRequest;
    private RequestHandle uploadRequest;
    private RequestHandle submitRequest;
    private final ExecutorService packageInspector = Executors.newSingleThreadExecutor();
    private final AtomicInteger packageInspectionGeneration = new AtomicInteger();
    private String recognizedAppSeed = "";

    private final ActivityResultLauncher<Intent> filePicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != Activity.RESULT_OK || result.getData() == null) return;
            ArrayList<String> values = result.getData().getStringArrayListExtra(FilePickerActivity.EXTRA_SELECTED_URIS);
            if (values == null || values.isEmpty()) return;
            selectedFile = Uri.parse(values.get(0));
            selectedFileInfo = fileInfo(selectedFile, "资源文件");
            renderFileSelection();
            if (mode == MODE_APPS) inspectSelectedAppPackage();
        });

    private final ActivityResultLauncher<Intent> coverPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (result.getResultCode() != Activity.RESULT_OK || result.getData() == null) return;
            ArrayList<Uri> values = result.getData().getParcelableArrayListExtra(MediaPickerActivity.EXTRA_SELECTED_URIS);
            if (values == null || values.isEmpty()) return;
            selectedCover = values.get(0);
            selectedCoverInfo = fileInfo(selectedCover, "资源封面");
            renderCoverSelection();
        });

    public static Intent intent(Context context, int mode) {
        return new Intent(context, ResourceSubmitActivity.class)
            .putExtra(EXTRA_MODE, mode == MODE_APPS ? MODE_APPS : MODE_SOURCE);
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityResourceSubmitBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        mode = getIntent().getIntExtra(EXTRA_MODE, MODE_SOURCE) == MODE_APPS ? MODE_APPS : MODE_SOURCE;
        configureMode();
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        binding.chooseFileButton.setOnClickListener(view ->
            filePicker.launch(FilePickerActivity.pickerIntent(this, 1)));
        binding.chooseCoverButton.setOnClickListener(view ->
            coverPicker.launch(MediaPickerActivity.imageIntent(this, 1)));
        binding.submitButton.setOnClickListener(view -> submit());
        binding.categoryInput.setOnItemClickListener((parent, view, position, id) -> {
            if (position >= 0 && position < categoryIds.size()) {
                selectedCategoryId = categoryIds.get(position);
                binding.categoryLayout.setError(null);
            }
        });
        loadPolicy();
        loadCategories();
    }

    private void configureMode() {
        boolean appMode = mode == MODE_APPS;
        binding.toolbar.setTitle(appMode ? "投稿应用" : "投稿源码");
        binding.formTitle.setText(appMode ? "应用商店投稿" : "源码商城投稿");
        binding.categoryLayout.setHint(appMode ? "应用分类" : "源码分类");
        binding.titleLayout.setHint(appMode ? "应用名称" : "源码名称");
        binding.descriptionLayout.setHint(appMode ? "应用介绍" : "源码介绍");
        binding.appFieldsContainer.setVisibility(appMode ? View.VISIBLE : View.GONE);
        binding.fileTitle.setText(appMode ? "应用安装包" : "源码文件");
        binding.fileSummary.setText(appMode
            ? "请选择 APK、HAP、IPA 等应用安装包"
            : "请选择源码、工程压缩包或相关文档");
        binding.coverTitle.setText(appMode ? "应用图标或封面（选填）" : "展示封面（选填）");
        binding.submitButton.setText(appMode ? "提交应用" : "提交源码");
    }

    private void loadPolicy() {
        if (policyRequest != null) policyRequest.cancel();
        String path = mode == MODE_APPS
            ? "/api/user/store-submission-policy"
            : "/api/user/resource-submission-policy";
        policyRequest = AppAccess.from(this).repository().get(
            path, new LinkedHashMap<>(), result -> {
                policyRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    submissionEnabled = false;
                    binding.policyText.setText(result.message().isEmpty()
                        ? "投稿权限暂时无法读取，请稍后重试。" : result.message());
                    updateEnabledState();
                    return;
                }
                JsonObject data = result.dataObject();
                submissionEnabled = booleanValue(data, "enabled");
                auditRequired = booleanValue(data, "audit_required");
                if (!submissionEnabled) {
                    binding.policyText.setText(mode == MODE_APPS
                        ? "当前应用暂未开放用户应用投稿。开放后可在这里直接上传安装包。"
                        : "当前应用暂未开放用户源码投稿。开放后可在这里直接上传源码。 ");
                } else if (auditRequired) {
                    binding.policyText.setText(mode == MODE_APPS
                        ? "应用投稿已开放。提交后进入审核，通过后在应用商店展示。"
                        : "源码投稿已开放。提交后进入审核，通过后在源码商城展示。 ");
                } else {
                    binding.policyText.setText(mode == MODE_APPS
                        ? "应用投稿已开放。提交成功后会直接在应用商店展示。"
                        : "源码投稿已开放。提交成功后会直接在源码商城展示。 ");
                }
                updateEnabledState();
            });
    }

    private void loadCategories() {
        if (categoryRequest != null) categoryRequest.cancel();
        String path = mode == MODE_APPS ? "/api/user/store-categories" : "/api/user/resource-categories";
        categoryRequest = AppAccess.from(this).repository().get(
            path, new LinkedHashMap<>(), result -> {
                categoryRequest = null;
                if (binding == null) return;
                categoryIds.clear();
                categoryNames.clear();
                if (result.isSuccessful()) {
                    for (JsonElement element : result.items()) {
                        if (!element.isJsonObject()) continue;
                        JsonObject item = element.getAsJsonObject();
                        long id = Jsons.longValue(item, "id");
                        String name = Jsons.string(item, "name").trim();
                        if (id <= 0 || name.isEmpty()) continue;
                        categoryIds.add(id);
                        categoryNames.add(name);
                    }
                }
                binding.categoryInput.setAdapter(new ArrayAdapter<>(this,
                    android.R.layout.simple_list_item_1, categoryNames));
                if (categoryNames.isEmpty()) {
                    binding.categoryLayout.setHelperText(result.message().isEmpty()
                        ? "暂无可投稿分类，请联系管理员创建后再提交。" : result.message());
                } else {
                    binding.categoryLayout.setHelperText(mode == MODE_APPS
                        ? "请选择应用所属分类" : "请选择源码所属分类");
                }
                suggestCategory(recognizedAppSeed);
                updateEnabledState();
            });
    }

    private void submit() {
        if (uploadRequest != null || submitRequest != null) return;
        String title = text(binding.titleInput.getText());
        String description = text(binding.descriptionInput.getText());
        binding.categoryLayout.setError(null);
        binding.titleLayout.setError(null);
        binding.descriptionLayout.setError(null);
        binding.packageLayout.setError(null);
        binding.versionNameLayout.setError(null);
        if (!submissionEnabled) {
            Snackbar.make(binding.getRoot(), "当前应用暂未开放资源投稿", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (selectedCategoryId <= 0) {
            binding.categoryLayout.setError("请选择资源分类");
            return;
        }
        if (title.isEmpty()) {
            binding.titleLayout.setError("请填写资源名称");
            return;
        }
        if (description.isEmpty()) {
            binding.descriptionLayout.setError("请填写资源介绍");
            return;
        }
        String packageName = text(binding.packageInput.getText());
        String versionName = text(binding.versionNameInput.getText());
        if (mode == MODE_APPS && packageName.isEmpty()) {
            binding.packageLayout.setError("请填写应用包名");
            return;
        }
        if (mode == MODE_APPS && versionName.isEmpty()) {
            binding.versionNameLayout.setError("请填写版本名称");
            return;
        }
        if (selectedFile == null || selectedFileInfo == null) {
            Snackbar.make(binding.getRoot(), "请选择需要投稿的资源文件", Snackbar.LENGTH_LONG).show();
            return;
        }
        int price;
        try { price = Math.max(0, Integer.parseInt(text(binding.priceInput.getText()))); }
        catch (NumberFormatException ignored) { price = 0; }
        setBusy(true, mode == MODE_APPS ? "正在上传应用安装包…" : "正在上传源码文件…");
        final int finalPrice = price;
        String fileScene = mode == MODE_APPS ? "store_app_package" : "resource_source";
        upload(selectedFile, selectedFileInfo, fileScene, fileUrl -> {
            if (selectedCover == null || selectedCoverInfo == null) {
                postSubmission(title, description, packageName, versionName, finalPrice, fileUrl, "");
                return;
            }
            setBusy(true, "正在上传展示封面…");
            String coverScene = mode == MODE_APPS ? "store_app_icon" : "resource_cover";
            upload(selectedCover, selectedCoverInfo, coverScene,
                coverUrl -> postSubmission(title, description, packageName, versionName,
                    finalPrice, fileUrl, coverUrl));
        });
    }

    private void upload(Uri uri, FileInfo info, String scene, UploadCallback callback) {
        Map<String, String> fields = new LinkedHashMap<>();
        fields.put("scene", scene);
        ContentUriRequestBody body = new ContentUriRequestBody(
            getContentResolver(), uri, info.mime, info.size);
        uploadRequest = AppAccess.from(this).repository().upload(
            "/api/user/uploads", info.name, info.mime, body, fields, result -> {
                uploadRequest = null;
                if (binding == null) return;
                if (!result.isSuccessful()) {
                    setBusy(false, "");
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? "文件上传失败，请检查网络后重试" : result.message(), Snackbar.LENGTH_LONG).show();
                    return;
                }
                String fileUrl = Jsons.string(result.dataObject(), "file_url");
                if (fileUrl.isEmpty()) {
                    setBusy(false, "");
                    Snackbar.make(binding.getRoot(), "服务器未返回可用文件地址，请重试", Snackbar.LENGTH_LONG).show();
                    return;
                }
                callback.onUploaded(fileUrl);
            });
    }

    private void postSubmission(String title, String description, String packageName,
                                String versionName, int price, String fileUrl, String coverUrl) {
        setBusy(true, auditRequired ? "正在提交审核…" : "正在发布资源…");
        JsonObject body = new JsonObject();
        body.addProperty("category_id", selectedCategoryId);
        body.addProperty("description", description);
        body.addProperty("price_balance", price);
        String path;
        if (mode == MODE_APPS) {
            body.addProperty("name", title);
            body.addProperty("package_name", packageName);
            body.addProperty("version_name", versionName);
            body.addProperty("version_code", Math.max(1, intValue(binding.versionCodeInput.getText(), 1)));
            body.addProperty("apk_url", fileUrl);
            body.addProperty("icon_url", coverUrl);
            body.addProperty("size_bytes", Math.max(0L, selectedFileInfo == null ? 0L : selectedFileInfo.size));
            path = "/api/user/store-apps";
        } else {
            body.addProperty("title", title);
            body.addProperty("download_url", fileUrl);
            body.addProperty("cover_url", coverUrl);
            path = "/api/user/resources";
        }
        submitRequest = AppAccess.from(this).repository().post(path, body, result -> {
            submitRequest = null;
            if (binding == null) return;
            setBusy(false, "");
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty()
                    ? "资源投稿失败，请稍后重试" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            boolean pending = "pending".equals(Jsons.string(result.dataObject(), "audit_status"));
            new YiyunyingDialogBuilder(this)
                .setBusinessTitle(pending ? "投稿已进入审核" : "资源已发布")
                .setBusinessMessage(pending
                    ? (mode == MODE_APPS
                        ? "应用安装包已经安全上传。审核通过后会出现在应用商店。"
                        : "源码文件已经安全上传。审核通过后会出现在源码商城。")
                    : (mode == MODE_APPS
                        ? "应用已经发布到应用商店，其他用户现在可以浏览和获取。"
                        : "源码已经发布到源码商城，其他用户现在可以浏览和获取。"))
                .setPositiveButton("完成", (dialog, which) -> {
                    setResult(Activity.RESULT_OK);
                    finish();
                })
                .setCancelable(false)
                .show();
        });
    }

    private void setBusy(boolean busy, String message) {
        if (binding == null) return;
        binding.progress.setVisibility(busy ? View.VISIBLE : View.INVISIBLE);
        binding.submitButton.setEnabled(!busy && submissionEnabled && !categoryNames.isEmpty());
        binding.chooseFileButton.setEnabled(!busy && submissionEnabled);
        binding.chooseCoverButton.setEnabled(!busy && submissionEnabled);
        binding.categoryInput.setEnabled(!busy && submissionEnabled);
        binding.titleInput.setEnabled(!busy && submissionEnabled);
        binding.descriptionInput.setEnabled(!busy && submissionEnabled);
        binding.priceInput.setEnabled(!busy && submissionEnabled);
        binding.packageInput.setEnabled(!busy && submissionEnabled);
        binding.versionNameInput.setEnabled(!busy && submissionEnabled);
        binding.versionCodeInput.setEnabled(!busy && submissionEnabled);
        binding.submitButton.setText(busy && !message.isEmpty()
            ? message : (mode == MODE_APPS ? "提交应用" : "提交源码"));
    }

    private void updateEnabledState() {
        if (binding == null) return;
        setBusy(false, "");
        binding.submitButton.setEnabled(submissionEnabled && !categoryNames.isEmpty());
        binding.submitButton.setText(submissionEnabled
            ? (mode == MODE_APPS ? "提交应用" : "提交源码") : "投稿暂未开放");
    }

    private void renderFileSelection() {
        if (binding == null || selectedFileInfo == null) return;
        binding.fileSummary.setText(selectedFileInfo.name + "  ·  " + sizeText(selectedFileInfo.size));
        binding.chooseFileButton.setText("更换");
    }

    private void inspectSelectedAppPackage() {
        if (binding == null || selectedFile == null || selectedFileInfo == null) return;
        int generation = packageInspectionGeneration.incrementAndGet();
        recognizedAppSeed = baseName(selectedFileInfo.name);
        if (text(binding.titleInput.getText()).isEmpty()) binding.titleInput.setText(recognizedAppSeed);
        suggestCategory(recognizedAppSeed);
        if (!selectedFileInfo.name.toLowerCase(Locale.ROOT).endsWith(".apk")) {
            binding.fileSummary.setText(selectedFileInfo.name + "  ·  " + sizeText(selectedFileInfo.size)
                + "  ·  请核对包名和版本");
            return;
        }
        binding.fileSummary.setText(selectedFileInfo.name + "  ·  " + sizeText(selectedFileInfo.size)
            + "  ·  正在识别应用信息…");
        Uri packageUri = selectedFile;
        FileInfo packageFileInfo = selectedFileInfo;
        packageInspector.execute(() -> {
            try {
                File archive = copyPackageToCache(packageUri, generation);
                PackageManager manager = getPackageManager();
                PackageInfo packageInfo = manager.getPackageArchiveInfo(archive.getAbsolutePath(), 0);
                if (packageInfo == null || packageInfo.applicationInfo == null) {
                    throw new IOException("安装包不包含可识别的应用信息");
                }
                ApplicationInfo applicationInfo = packageInfo.applicationInfo;
                applicationInfo.sourceDir = archive.getAbsolutePath();
                applicationInfo.publicSourceDir = archive.getAbsolutePath();
                CharSequence labelValue = applicationInfo.loadLabel(manager);
                String label = labelValue == null ? "" : labelValue.toString().trim();
                String packageName = packageInfo.packageName == null ? "" : packageInfo.packageName.trim();
                String versionName = packageInfo.versionName == null ? "" : packageInfo.versionName.trim();
                long versionCode = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                    ? packageInfo.getLongVersionCode() : packageInfo.versionCode;
                Drawable icon = applicationInfo.loadIcon(manager);
                File iconFile = savePackageIcon(icon, generation);
                Uri iconUri = iconFile == null ? null : FileProvider.getUriForFile(
                    this, getPackageName() + ".capture-files", iconFile);
                FileInfo iconInfo = iconFile == null ? null
                    : new FileInfo("应用图标.png", "image/png", iconFile.length());
                runOnUiThread(() -> applyPackageMetadata(generation, packageFileInfo, label,
                    packageName, versionName, versionCode, iconUri, iconInfo));
            } catch (IOException | RuntimeException error) {
                runOnUiThread(() -> {
                    if (!isCurrentInspection(generation)) return;
                    binding.fileSummary.setText(packageFileInfo.name + "  ·  "
                        + sizeText(packageFileInfo.size) + "  ·  未能自动识别，可手动填写");
                });
            }
        });
    }

    private void applyPackageMetadata(int generation, FileInfo packageFileInfo, String label,
                                      String packageName, String versionName, long versionCode,
                                      Uri iconUri, FileInfo iconInfo) {
        if (!isCurrentInspection(generation)) return;
        if (!label.isEmpty()) binding.titleInput.setText(label);
        if (!packageName.isEmpty()) binding.packageInput.setText(packageName);
        if (!versionName.isEmpty()) binding.versionNameInput.setText(versionName);
        binding.versionCodeInput.setText(String.valueOf(Math.max(1L, versionCode)));
        recognizedAppSeed = label + " " + packageName;
        suggestCategory(recognizedAppSeed);
        if (selectedCover == null && iconUri != null && iconInfo != null) {
            selectedCover = iconUri;
            selectedCoverInfo = iconInfo;
            renderCoverSelection();
        }
        String recognizedName = label.isEmpty() ? baseName(packageFileInfo.name) : label;
        binding.fileSummary.setText("已识别：" + recognizedName + "  ·  " + packageName
            + "  ·  " + (versionName.isEmpty() ? "版本待核对" : versionName)
            + "  ·  " + sizeText(packageFileInfo.size));
    }

    private boolean isCurrentInspection(int generation) {
        return binding != null && !isFinishing() && !isDestroyed()
            && packageInspectionGeneration.get() == generation;
    }

    private File copyPackageToCache(Uri uri, int generation) throws IOException {
        File directory = new File(getCacheDir(), "resource_packages");
        if (!directory.exists() && !directory.mkdirs()) throw new IOException("无法创建识别缓存目录");
        File target = new File(directory, "inspect-" + generation + ".apk");
        try (InputStream input = getContentResolver().openInputStream(uri);
             FileOutputStream output = new FileOutputStream(target, false)) {
            if (input == null) throw new IOException("无法读取安装包");
            byte[] buffer = new byte[64 * 1024];
            int count;
            while ((count = input.read(buffer)) >= 0) output.write(buffer, 0, count);
        }
        return target;
    }

    private File savePackageIcon(Drawable icon, int generation) throws IOException {
        if (icon == null) return null;
        int size = 192;
        Bitmap bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(bitmap);
        icon.setBounds(0, 0, size, size);
        icon.draw(canvas);
        File directory = new File(getCacheDir(), "resource_packages");
        if (!directory.exists() && !directory.mkdirs()) throw new IOException("无法创建图标缓存目录");
        File target = new File(directory, "icon-" + generation + ".png");
        try (FileOutputStream output = new FileOutputStream(target, false)) {
            if (!bitmap.compress(Bitmap.CompressFormat.PNG, 100, output)) {
                throw new IOException("无法保存应用图标");
            }
        } finally {
            bitmap.recycle();
        }
        return target;
    }

    private void suggestCategory(String seed) {
        if (binding == null || selectedCategoryId > 0 || TextUtils.isEmpty(seed) || categoryNames.isEmpty()) return;
        String normalized = seed.toLowerCase(Locale.ROOT);
        String hint = normalized.matches(".*(game|游戏|minecraft|mc|王者).*" ) ? "游戏"
            : normalized.matches(".*(tool|工具|助手|utility).*" ) ? "工具"
            : normalized.matches(".*(social|chat|聊天|社交).*" ) ? "社交"
            : normalized.matches(".*(office|办公|文档).*" ) ? "办公" : "";
        for (int index = 0; index < categoryNames.size(); index++) {
            String name = categoryNames.get(index);
            String category = name.toLowerCase(Locale.ROOT);
            if (normalized.contains(category) || (!hint.isEmpty() && category.contains(hint))) {
                selectedCategoryId = categoryIds.get(index);
                binding.categoryInput.setText(name, false);
                binding.categoryLayout.setError(null);
                break;
            }
        }
    }

    private static String baseName(String name) {
        if (TextUtils.isEmpty(name)) return "";
        int dot = name.lastIndexOf('.');
        return dot > 0 ? name.substring(0, dot) : name;
    }

    private void renderCoverSelection() {
        if (binding == null || selectedCoverInfo == null) return;
        binding.coverSummary.setText(selectedCoverInfo.name + "  ·  " + sizeText(selectedCoverInfo.size));
        binding.coverPreview.setPadding(0, 0, 0, 0);
        binding.coverPreview.setImageTintList(null);
        binding.coverPreview.setImageURI(selectedCover);
        binding.chooseCoverButton.setText("更换");
    }

    private FileInfo fileInfo(Uri uri, String fallback) {
        String name = fallback;
        long size = -1L;
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
        return new FileInfo(TextUtils.isEmpty(name) ? fallback : name, mime, size);
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    private static String text(CharSequence value) {
        return value == null ? "" : value.toString().trim();
    }

    private static int intValue(CharSequence value, int fallback) {
        try { return Integer.parseInt(text(value)); }
        catch (NumberFormatException ignored) { return fallback; }
    }

    private static String sizeText(long bytes) {
        if (bytes < 0) return "大小未知";
        if (bytes >= 1024L * 1024L * 1024L) return String.format(Locale.CHINA, "%.2f GB", bytes / 1073741824d);
        if (bytes >= 1024L * 1024L) return String.format(Locale.CHINA, "%.1f MB", bytes / 1048576d);
        if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        return bytes + " B";
    }

    @Override protected void onDestroy() {
        packageInspectionGeneration.incrementAndGet();
        packageInspector.shutdownNow();
        if (policyRequest != null) policyRequest.cancel();
        if (categoryRequest != null) categoryRequest.cancel();
        if (uploadRequest != null) uploadRequest.cancel();
        if (submitRequest != null) submitRequest.cancel();
        binding = null;
        super.onDestroy();
    }

    private interface UploadCallback { void onUploaded(String fileUrl); }

    private static final class FileInfo {
        final String name;
        final String mime;
        final long size;
        FileInfo(String name, String mime, long size) {
            this.name = name;
            this.mime = mime;
            this.size = size;
        }
    }
}
