package xyz.jjmxg.yiyunying.ui.document;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.app.DatePickerDialog;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.widget.TooltipCompat;
import androidx.recyclerview.widget.LinearLayoutManager;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Calendar;
import java.util.Locale;

import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.FragmentModuleListBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.document.ShareCodeParser;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;
import xyz.jjmxg.yiyunying.ui.common.BaseFragment;
import xyz.jjmxg.yiyunying.ui.common.RecordAdapter;

public final class DocumentsFragment extends BaseFragment {
    private FragmentModuleListBinding binding;
    private RecordAdapter adapter;
    private int page = 1;
    private int totalPages;
    private boolean loaded;
    private String selectedDate = "";
    private final ActivityResultLauncher<Intent> editor = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (binding != null && result.getResultCode() == android.app.Activity.RESULT_OK) load(false);
        });

    public static DocumentsFragment newInstance() { return new DocumentsFragment(); }

    @Nullable
    @Override public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle state) {
        binding = FragmentModuleListBinding.inflate(inflater, container, false);
        Role role = app().session().role();
        boolean admin = role == Role.ADMIN;
        host().setPageTitle(admin ? "文档管理" : "我的笔记");
        ModuleSpec.Builder displayBuilder = ModuleSpec.builder("documents_display", admin ? "文档" : "笔记", role)
            .path(documentBasePath()).idKey("id")
            .primary("title", "id")
            .secondary(admin
                ? new String[]{"owner_type", "account", "word_count", "status", "updated_at"}
                : new String[]{"date_label", "excerpt", "media_summary", "word_count", "updated_at"});
        if (admin) displayBuilder.action(ActionSpec.builder("管理文档", "GET", "").item());
        ModuleSpec display = displayBuilder.build();
        adapter = new RecordAdapter(display, new RecordAdapter.Listener() {
            @Override public void onRecordClick(JsonObject item) { openEditor(Jsons.longValue(item, "id")); }
            @Override public void onRecordActions(View anchor, JsonObject item) {
                if (admin) showAdminActions(item);
                else showUserActions(item);
            }
        });
        binding.recycler.setLayoutManager(new LinearLayoutManager(requireContext()));
        binding.recycler.setHasFixedSize(true);
        binding.recycler.setItemAnimator(null);
        binding.recycler.setAdapter(adapter);
        binding.searchLayout.setVisibility(View.VISIBLE);
        binding.searchInput.setHint(admin ? "搜索文档" : "搜索笔记");
        binding.searchLayout.setEndIconOnClickListener(view -> search());
        binding.searchInput.setOnEditorActionListener((view, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) { search(); return true; }
            return false;
        });
        binding.swipeRefresh.setOnRefreshListener(() -> { page = 1; load(false); });
        binding.retryButton.setOnClickListener(view -> load(true));
        binding.previousButton.setOnClickListener(view -> { if (page > 1) { page--; load(true); } });
        binding.nextButton.setOnClickListener(view -> { if (page < totalPages) { page++; load(true); } });
        if (!admin) {
            binding.clipboardButton.setVisibility(View.VISIBLE);
            binding.clipboardButton.setOnClickListener(view -> openShareCodeManually());
            binding.dateFilterButton.setVisibility(View.VISIBLE);
            binding.dateFilterButton.setOnClickListener(view -> showDateFilter());
            binding.dateFilterButton.setOnLongClickListener(view -> {
                if (selectedDate.isEmpty()) return false;
                selectedDate = "";
                TooltipCompat.setTooltipText(binding.dateFilterButton, "按年月日筛选，长按清除");
                page = 1;
                load(true);
                return true;
            });
        }
        binding.fab.setVisibility(View.VISIBLE);
        binding.fab.setOnClickListener(view -> openEditor(0));
        binding.getRoot().post(() -> {
            if (binding != null && !loaded) load(true);
        });
        return binding.getRoot();
    }

    private void search() { page = 1; load(true); }

    private void load(boolean fullLoading) {
        if (fullLoading && !loaded) binding.progress.setVisibility(View.VISIBLE);
        Map<String, String> query = new LinkedHashMap<>();
        query.put("page", String.valueOf(page));
        query.put("limit", "20");
        String keyword = binding.searchInput.getText() == null ? "" : binding.searchInput.getText().toString().trim();
        if (!keyword.isEmpty()) query.put("keyword", keyword);
        if (!selectedDate.isEmpty()) query.put("date", selectedDate);
        track(app().repository().get(documentBasePath(), query, result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            binding.swipeRefresh.setRefreshing(false);
            if (!result.isSuccessful()) {
                if (result.isAuthenticationFailure()) { host().onAuthenticationExpired(); return; }
                if (!loaded) {
                    binding.errorMessage.setText(result.message().isEmpty() ? "文档加载失败" : result.message());
                    binding.errorState.setVisibility(View.VISIBLE);
                } else handleFailure(result, binding.getRoot());
                return;
            }
            loaded = true;
            binding.errorState.setVisibility(View.GONE);
            List<JsonObject> documents = objects(result.items());
            adapter.submit(documents);
            binding.emptyState.setVisibility(documents.isEmpty() ? View.VISIBLE : View.GONE);
            JsonObject pagination = Jsons.object(result.dataObject(), "pagination");
            totalPages = Jsons.intValue(pagination, "total_pages", 0);
            page = Jsons.intValue(pagination, "page", page);
            int total = Jsons.intValue(pagination, "total", 0);
            binding.pagination.setVisibility(View.VISIBLE);
            binding.pageText.setText((totalPages == 0 ? 0 : page) + " / " + totalPages + " · " + total + " 条");
            binding.previousButton.setEnabled(page > 1);
            binding.nextButton.setEnabled(page < totalPages);
        }));
    }

    private void showDateFilter() {
        Calendar calendar = Calendar.getInstance();
        if (!selectedDate.isEmpty()) {
            String[] parts = selectedDate.split("-");
            if (parts.length == 3) {
                try {
                    calendar.set(Integer.parseInt(parts[0]), Integer.parseInt(parts[1]) - 1, Integer.parseInt(parts[2]));
                } catch (NumberFormatException ignored) { }
            }
        }
        DatePickerDialog dialog = new DatePickerDialog(requireContext(), (picker, year, month, day) -> {
            selectedDate = String.format(Locale.CHINA, "%04d-%02d-%02d", year, month + 1, day);
            TooltipCompat.setTooltipText(binding.dateFilterButton, "当前日期：" + selectedDate + "，长按清除");
            page = 1;
            load(true);
        }, calendar.get(Calendar.YEAR), calendar.get(Calendar.MONTH), calendar.get(Calendar.DAY_OF_MONTH));
        dialog.setTitle("按笔记日期筛选");
        dialog.setButton(DatePickerDialog.BUTTON_NEUTRAL, "清除日期", (ignored, which) -> {
            selectedDate = "";
            TooltipCompat.setTooltipText(binding.dateFilterButton, "按年月日筛选，长按清除");
            page = 1;
            load(true);
        });
        dialog.show();
    }

    private void openEditor(long documentId) {
        Intent intent = new Intent(requireContext(), DocumentEditorActivity.class);
        intent.putExtra(DocumentEditorActivity.EXTRA_DOCUMENT_ID, documentId);
        editor.launch(intent);
    }

    private void openShareCodeManually() {
        String clipboard = clipboardText();
        String code = ShareCodeParser.parse(clipboard, true);
        if (!code.isEmpty()) {
            SharedDocumentActivity.open(requireContext(), code);
            return;
        }
        final android.widget.EditText input = new android.widget.EditText(requireContext());
        input.setHint("粘贴分享码或完整分享链接");
        input.setSingleLine(false);
        input.setMaxLines(3);
        int padding = dp(20);
        android.widget.FrameLayout frame = new android.widget.FrameLayout(requireContext());
        frame.setPadding(padding, 0, padding, 0);
        frame.addView(input, new android.widget.FrameLayout.LayoutParams(-1, -2));
        if (!clipboard.isEmpty() && clipboard.length() <= 4096) input.setText(clipboard);
        androidx.appcompat.app.AlertDialog dialog = new YiyunyingDialogBuilder(requireContext())
            .setTitle("打开分享笔记")
            .setMessage("输入分享码，或粘贴包含分享码的完整链接。")
            .setView(frame)
            .setNegativeButton("取消", null)
            .setPositiveButton("打开", null)
            .create();
        dialog.setOnShowListener(ignored -> dialog.getButton(androidx.appcompat.app.AlertDialog.BUTTON_POSITIVE)
            .setOnClickListener(view -> {
                String parsed = ShareCodeParser.parse(input.getText().toString(), true);
                if (parsed.isEmpty()) {
                    input.setError("没有识别到有效分享码");
                    return;
                }
                dialog.dismiss();
                SharedDocumentActivity.open(requireContext(), parsed);
            }));
        dialog.show();
    }

    private void checkClipboardForShareCode() {
        if (binding == null || app().session().role() != Role.USER) return;
        String clipboard = clipboardText();
        String code = ShareCodeParser.parse(clipboard, true);
        if (code.isEmpty()) return;
        String key = "last_share_clipboard:" + app().session().account() + ":" + app().session().appKey();
        android.content.SharedPreferences preferences = requireContext()
            .getSharedPreferences("yiyunying.share.clipboard", Context.MODE_PRIVATE);
        if (code.equals(preferences.getString(key, ""))) return;
        preferences.edit().putString(key, code).apply();
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("识别到分享码")
            .setMessage(code + "\n\n是否打开这篇分享笔记？")
            .setNegativeButton("暂不打开", null)
            .setPositiveButton("打开", (dialog, which) -> SharedDocumentActivity.open(requireContext(), code))
            .show();
    }

    private String clipboardText() {
        ClipboardManager manager = (ClipboardManager) requireContext().getSystemService(Context.CLIPBOARD_SERVICE);
        if (manager == null || !manager.hasPrimaryClip()) return "";
        ClipData clip = manager.getPrimaryClip();
        if (clip == null || clip.getItemCount() == 0) return "";
        CharSequence value = clip.getItemAt(0).coerceToText(requireContext());
        return value == null ? "" : value.toString().trim();
    }

    private void showUserActions(JsonObject item) {
        long documentId = Jsons.longValue(item, "id");
        boolean favorited = item.has("favorited") && item.get("favorited").getAsBoolean();
        new YiyunyingDialogBuilder(requireContext())
            .setBusinessTitle(Jsons.string(item, "title"))
            .setItems(new String[]{"打开笔记", favorited ? "取消收藏" : "收藏笔记"}, (dialog, which) -> {
                if (which == 0) openEditor(documentId);
                else toggleFavorite(documentId);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void toggleFavorite(long documentId) {
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().post("/api/user/notes/" + documentId + "/favorite", new JsonObject(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (!handleFailure(result, binding.getRoot())) load(false);
        }));
    }
    private void showAdminActions(JsonObject item) {
        long documentId = Jsons.longValue(item, "id");
        boolean deleted = Jsons.intValue(item, "status", 1) == -1;
        String[] actions = deleted ? new String[]{"查看或编辑", "恢复文档"} : new String[]{"编辑文档", "移入回收站"};
        new YiyunyingDialogBuilder(requireContext())
            .setBusinessTitle(Jsons.string(item, "title"))
            .setItems(actions, (dialog, which) -> {
                if (which == 0) openEditor(documentId);
                else if (deleted) restore(documentId);
                else recycle(documentId);
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void recycle(long documentId) {
        new YiyunyingDialogBuilder(requireContext())
            .setTitle("移入回收站")
            .setMessage("文档可由管理员恢复，是否继续？")
            .setNegativeButton("取消", null)
            .setPositiveButton("移入", (dialog, which) -> {
                binding.progress.setVisibility(View.VISIBLE);
                track(app().repository().delete(documentBasePath() + "/" + documentId, new JsonObject(), result -> {
                    if (binding == null) return;
                    binding.progress.setVisibility(View.GONE);
                    if (!handleFailure(result, binding.getRoot())) load(false);
                }));
            }).show();
    }

    private void restore(long documentId) {
        binding.progress.setVisibility(View.VISIBLE);
        track(app().repository().post(documentBasePath() + "/" + documentId + "/restore", new JsonObject(), result -> {
            if (binding == null) return;
            binding.progress.setVisibility(View.GONE);
            if (!handleFailure(result, binding.getRoot())) load(false);
        }));
    }

    private String documentBasePath() {
        if (app().session().role() == Role.ADMIN) {
            return "/api/admin/apps/" + app().session().selectedAppId() + "/documents";
        }
        return "/api/user/notes";
    }

    private static List<JsonObject> objects(JsonArray array) {
        List<JsonObject> result = new ArrayList<>();
        for (JsonElement element : array) if (element.isJsonObject()) result.add(element.getAsJsonObject());
        return result;
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override public void onResume() {
        super.onResume();
        if (binding != null) binding.getRoot().post(this::checkClipboardForShareCode);
    }

    @Override public void onDestroyView() { binding = null; super.onDestroyView(); }
}
