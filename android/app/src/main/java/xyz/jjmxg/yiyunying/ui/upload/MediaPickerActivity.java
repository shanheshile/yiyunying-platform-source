package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.content.ContentUris;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.graphics.Color;
import android.net.Uri;
import android.os.Bundle;
import android.provider.MediaStore;
import android.view.Gravity;
import android.view.GestureDetector;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.MediaController;
import android.widget.TextView;
import android.widget.VideoView;

import androidx.activity.OnBackPressedCallback;
import androidx.annotation.NonNull;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.viewpager2.widget.ViewPager2;

import com.bumptech.glide.Glide;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ActivityMediaPickerBinding;
import xyz.jjmxg.yiyunying.ui.common.ZoomableMediaFrame;

public final class MediaPickerActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    public static final String EXTRA_SELECTED_URIS = "selected_uris";
    public static final String EXTRA_ORIGINAL = "original";
    public static final String EXTRA_MEDIA_METADATA = "media_metadata";
    public static final String EXTRA_SELECTION_CONFIRMED = "selection_confirmed";
    private static final String EXTRA_INITIAL_SELECTED_URIS = "initial_selected_uris";
    private static final String EXTRA_INITIAL_ORIGINAL = "initial_original";
    private static final String EXTRA_MEDIA_KIND = "media_kind";
    private static final String EXTRA_MAX_SELECTION = "max_selection";
    private static final String STATE_SELECTED_URIS = "picker.selected_uris";
    private static final String STATE_ORIGINAL = "picker.original";
    private static final String STATE_PREVIEW_VISIBLE = "picker.preview_visible";
    private static final String STATE_PREVIEW_POSITION = "picker.preview_position";
    private static final int REQUEST_MEDIA_PERMISSION = 5401;
    private static final int DEFAULT_MAX_SELECTION = 200;

    private ActivityMediaPickerBinding binding;
    private final List<JsonObject> media = new ArrayList<>();
    private final Set<String> selected = new LinkedHashSet<>();
    private final ExecutorService loader = Executors.newSingleThreadExecutor();
    private MediaGridAdapter gridAdapter;
    private PreviewAdapter previewAdapter;
    private boolean dragSelecting;
    private boolean dragSelectValue;
    private int lastDragPosition = RecyclerView.NO_POSITION;
    private boolean restorePreviewVisible;
    private int restoredPreviewPosition;
    private float dragLastTouchX;
    private float dragLastTouchY;
    private int dragEdgeDirection;
    private boolean dragEdgeScrollPosted;
    private String mediaKind = "all";
    private int maxSelection = DEFAULT_MAX_SELECTION;
    private final Runnable dragEdgeScroller = new Runnable() {
        @Override public void run() {
            dragEdgeScrollPosted = false;
            if (!dragSelecting || dragEdgeDirection == 0 || binding == null) return;
            binding.mediaGrid.scrollBy(0, dragEdgeDirection * dp(18));
            selectDragPosition(binding.mediaGrid, dragLastTouchX, dragLastTouchY);
            scheduleDragEdgeScroll(binding.mediaGrid);
        }
    };

    public static Intent intent(Context context, boolean original) {
        return new Intent(context, MediaPickerActivity.class).putExtra(EXTRA_INITIAL_ORIGINAL, original);
    }

    public static Intent intent(Context context, boolean original, ArrayList<Uri> initialSelection) {
        return intent(context, original)
            .putParcelableArrayListExtra(EXTRA_INITIAL_SELECTED_URIS, initialSelection);
    }

    public static Intent imageIntent(Context context, int maxSelection) {
        return intent(context, true)
            .putExtra(EXTRA_MEDIA_KIND, "image")
            .putExtra(EXTRA_MAX_SELECTION, Math.max(1, maxSelection));
    }

    public static Intent imageIntent(Context context, int maxSelection, ArrayList<Uri> initialSelection) {
        return imageIntent(context, maxSelection)
            .putParcelableArrayListExtra(EXTRA_INITIAL_SELECTED_URIS, initialSelection);
    }

    public static Intent videoIntent(Context context, int maxSelection) {
        return intent(context, true)
            .putExtra(EXTRA_MEDIA_KIND, "video")
            .putExtra(EXTRA_MAX_SELECTION, Math.max(1, maxSelection));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        binding = ActivityMediaPickerBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        mediaKind = getIntent().getStringExtra(EXTRA_MEDIA_KIND);
        if (!"image".equals(mediaKind) && !"video".equals(mediaKind)) mediaKind = "all";
        maxSelection = Math.max(1, Math.min(DEFAULT_MAX_SELECTION,
            getIntent().getIntExtra(EXTRA_MAX_SELECTION, DEFAULT_MAX_SELECTION)));
        binding.toolbar.setTitle("image".equals(mediaKind) ? "选择照片" :
            ("video".equals(mediaKind) ? "选择视频" : "最近照片和视频"));
        binding.toolbar.setSubtitle("点击预览；长按缩略图后滑动可连续多选");
        binding.toolbar.setNavigationOnClickListener(view -> handleBack());
        if (state != null) {
            ArrayList<String> restored = state.getStringArrayList(STATE_SELECTED_URIS);
            if (restored != null) selected.addAll(restored);
        } else {
            ArrayList<Uri> initialSelection = getIntent()
                .getParcelableArrayListExtra(EXTRA_INITIAL_SELECTED_URIS);
            if (initialSelection != null) {
                for (Uri uri : initialSelection) if (uri != null) selected.add(uri.toString());
            }
        }
        binding.originalSwitch.setChecked(state != null
            ? state.getBoolean(STATE_ORIGINAL, false)
            : getIntent().getBooleanExtra(EXTRA_INITIAL_ORIGINAL, false));
        binding.originalSwitch.setOnCheckedChangeListener((button, checked) -> {
            updateSelectionBar();
            updatePreview(binding.previewPager.getCurrentItem());
        });

        gridAdapter = new MediaGridAdapter();
        binding.mediaGrid.setLayoutManager(new GridLayoutManager(this, 4));
        binding.mediaGrid.setHasFixedSize(true);
        binding.mediaGrid.setItemViewCacheSize(20);
        binding.mediaGrid.setAdapter(gridAdapter);
        installDragSelection();

        previewAdapter = new PreviewAdapter();
        binding.previewPager.setAdapter(previewAdapter);
        binding.previewPager.setOffscreenPageLimit(1);
        binding.previewPager.registerOnPageChangeCallback(new ViewPager2.OnPageChangeCallback() {
            @Override public void onPageSelected(int position) {
                if (binding != null && binding.continuousSelectSwitch.isChecked()) {
                    setSelection(position, true);
                }
                updatePreview(position);
            }
        });
        binding.continuousSelectSwitch.setOnCheckedChangeListener((button, checked) -> {
            binding.toolbar.setSubtitle(checked
                ? "滑动到的媒体会自动选中；关闭后可只浏览"
                : "左右滑动只浏览，点击右侧按钮选择");
            if (checked) setSelection(binding.previewPager.getCurrentItem(), true);
        });
        binding.selectCurrent.setOnClickListener(view -> toggleSelection(binding.previewPager.getCurrentItem()));
        binding.confirmSelection.setOnClickListener(view -> finishSelection(true));
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() { handleBack(); }
        });
        restorePreviewVisible = state != null && state.getBoolean(STATE_PREVIEW_VISIBLE, false);
        restoredPreviewPosition = state == null ? 0 : Math.max(0, state.getInt(STATE_PREVIEW_POSITION, 0));
        ensurePermissionAndLoad();
    }

    @Override protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        outState.putStringArrayList(STATE_SELECTED_URIS, new ArrayList<>(selected));
        outState.putBoolean(STATE_ORIGINAL,
            binding != null && binding.originalSwitch.isChecked());
        outState.putBoolean(STATE_PREVIEW_VISIBLE,
            binding != null && binding.previewPanel.getVisibility() == View.VISIBLE);
        outState.putInt(STATE_PREVIEW_POSITION,
            binding == null ? 0 : binding.previewPager.getCurrentItem());
    }

    private void ensurePermissionAndLoad() {
        if (hasReadableMedia()) {
            loadMedia();
            return;
        }
        if (android.os.Build.VERSION.SDK_INT >= 34) {
            List<String> permissions = new ArrayList<>();
            if (!"video".equals(mediaKind)) permissions.add(android.Manifest.permission.READ_MEDIA_IMAGES);
            if (!"image".equals(mediaKind)) permissions.add(android.Manifest.permission.READ_MEDIA_VIDEO);
            permissions.add(android.Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED);
            requestPermissions(permissions.toArray(new String[0]), REQUEST_MEDIA_PERMISSION);
        } else if (android.os.Build.VERSION.SDK_INT >= 33) {
            if ("image".equals(mediaKind)) {
                requestPermissions(new String[]{android.Manifest.permission.READ_MEDIA_IMAGES}, REQUEST_MEDIA_PERMISSION);
            } else if ("video".equals(mediaKind)) {
                requestPermissions(new String[]{android.Manifest.permission.READ_MEDIA_VIDEO}, REQUEST_MEDIA_PERMISSION);
            } else {
                requestPermissions(new String[]{android.Manifest.permission.READ_MEDIA_IMAGES,
                    android.Manifest.permission.READ_MEDIA_VIDEO}, REQUEST_MEDIA_PERMISSION);
            }
        } else {
            requestPermissions(new String[]{android.Manifest.permission.READ_EXTERNAL_STORAGE}, REQUEST_MEDIA_PERMISSION);
        }
    }

    private boolean hasReadableMedia() {
        if (android.os.Build.VERSION.SDK_INT >= 34
            && ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_VISUAL_USER_SELECTED)
                == PackageManager.PERMISSION_GRANTED) return true;
        if (android.os.Build.VERSION.SDK_INT >= 33) {
            boolean images = ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_IMAGES) == PackageManager.PERMISSION_GRANTED;
            boolean videos = ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_MEDIA_VIDEO) == PackageManager.PERMISSION_GRANTED;
            return "image".equals(mediaKind) ? images : ("video".equals(mediaKind) ? videos : images || videos);
        }
        return ContextCompat.checkSelfPermission(this, android.Manifest.permission.READ_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED;
    }

    @Override public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] results) {
        super.onRequestPermissionsResult(requestCode, permissions, results);
        if (requestCode != REQUEST_MEDIA_PERMISSION) return;
        boolean anyGranted = false;
        for (int result : results) if (result == PackageManager.PERMISSION_GRANTED) anyGranted = true;
        if (anyGranted) loadMedia();
        else {
            binding.progress.setVisibility(View.INVISIBLE);
            binding.empty.setText("未获得相册权限，无法读取本机图片和视频");
            binding.empty.setVisibility(View.VISIBLE);
        }
    }

    private void loadMedia() {
        binding.progress.setVisibility(View.VISIBLE);
        loader.execute(() -> {
            List<JsonObject> values = queryMedia();
            runOnUiThread(() -> {
                if (binding == null) return;
                media.clear();
                media.addAll(values);
                gridAdapter.notifyDataSetChanged();
                previewAdapter.notifyDataSetChanged();
                binding.progress.setVisibility(View.INVISIBLE);
                binding.empty.setVisibility(media.isEmpty() ? View.VISIBLE : View.GONE);
                updateSelectionBar();
                if (restorePreviewVisible && !media.isEmpty()) {
                    restorePreviewVisible = false;
                    showPreview(Math.min(restoredPreviewPosition, media.size() - 1));
                }
            });
        });
    }

    private List<JsonObject> queryMedia() {
        List<JsonObject> result = new ArrayList<>();
        if (!"video".equals(mediaKind)) queryCollection(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, false, result);
        if (!"image".equals(mediaKind)) queryCollection(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, true, result);
        result.sort(Comparator.comparingLong(item -> -Jsons.longValue(item, "date_added")));
        if (result.size() > 800) return new ArrayList<>(result.subList(0, 800));
        return result;
    }

    private void queryCollection(Uri collection, boolean video, List<JsonObject> target) {
        List<String> columns = new ArrayList<>();
        columns.add(MediaStore.MediaColumns._ID);
        columns.add(MediaStore.MediaColumns.MIME_TYPE);
        columns.add(MediaStore.MediaColumns.DISPLAY_NAME);
        columns.add(MediaStore.MediaColumns.SIZE);
        columns.add(MediaStore.MediaColumns.WIDTH);
        columns.add(MediaStore.MediaColumns.HEIGHT);
        columns.add(MediaStore.MediaColumns.DATE_ADDED);
        if (video) columns.add(MediaStore.Video.VideoColumns.DURATION);
        String[] projection = columns.toArray(new String[0]);
        try (Cursor cursor = getContentResolver().query(
            collection, projection, null, null, MediaStore.MediaColumns.DATE_ADDED + " DESC"
        )) {
            if (cursor == null) return;
            int idColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID);
            int mimeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE);
            int nameColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DISPLAY_NAME);
            int sizeColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.SIZE);
            int widthColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.WIDTH);
            int heightColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.HEIGHT);
            int dateColumn = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DATE_ADDED);
            int durationColumn = video ? cursor.getColumnIndexOrThrow(MediaStore.Video.VideoColumns.DURATION) : -1;
            int count = 0;
            while (cursor.moveToNext() && count++ < 800) {
                Uri uri = ContentUris.withAppendedId(collection, cursor.getLong(idColumn));
                JsonObject item = new JsonObject();
                item.addProperty("url", uri.toString());
                item.addProperty("media_type", video ? "video" : "image");
                String mime = cursor.isNull(mimeColumn) ? (video ? "video/*" : "image/*") : cursor.getString(mimeColumn);
                String fileName = cursor.isNull(nameColumn) ? (video ? "本地视频" : "本地图片") : cursor.getString(nameColumn);
                item.addProperty("mime_type", mime);
                item.addProperty("file_name", fileName);
                item.addProperty("size_bytes", cursor.isNull(sizeColumn) ? -1 : cursor.getLong(sizeColumn));
                item.addProperty("width", cursor.isNull(widthColumn) ? 0 : cursor.getInt(widthColumn));
                item.addProperty("height", cursor.isNull(heightColumn) ? 0 : cursor.getInt(heightColumn));
                item.addProperty("duration_ms", durationColumn < 0 || cursor.isNull(durationColumn) ? 0 : cursor.getLong(durationColumn));
                item.addProperty("date_added", cursor.isNull(dateColumn) ? 0 : cursor.getLong(dateColumn));
                boolean motionHint = !video && MediaKindDetector.isMotionPhotoNameHint(mime, fileName);
                item.addProperty("is_motion_photo", motionHint);
                item.addProperty("motion_photo_scanned", video || motionHint);
                target.add(item);
            }
        } catch (RuntimeException ignored) {
            // Image and video permissions are independent on Android 13+; keep whichever collection is readable.
        }
    }

    private void showPreview(int position) {
        if (position < 0 || position >= media.size()) return;
        binding.previewPanel.setVisibility(View.VISIBLE);
        binding.toolbar.setSubtitle("左右滑动预览，双指缩放图片");
        binding.previewPager.setCurrentItem(position, false);
        updatePreview(position);
    }

    private void updatePreview(int position) {
        if (position < 0 || position >= media.size() || binding == null) return;
        JsonObject item = media.get(position);
        ensureMotionPhotoDetected(position);
        binding.toolbar.setTitle((position + 1) + " / " + media.size());
        StringBuilder detail = new StringBuilder(Jsons.string(item, "file_name"));
        if (isGif(item)) detail.append("  ·  GIF");
        else if (isMotionPhoto(item)) detail.append("  ·  动态图");
        long bytes = Jsons.longValue(item, "size_bytes");
        if (bytes > 0) detail.append("  ·  ").append(formatSize(bytes));
        long width = Jsons.longValue(item, "width");
        long height = Jsons.longValue(item, "height");
        if (width > 0 && height > 0) detail.append("  ·  ").append(width).append("×").append(height);
        binding.previewName.setText(detail);
        boolean allowed = allowed(item);
        boolean checked = selected.contains(Jsons.string(item, "url"));
        binding.selectCurrent.setEnabled(allowed);
        binding.selectCurrent.setText(allowed
            ? (checked ? "取消选择" : (bytes > 0 ? "选择 · " + formatSize(bytes) : "选择当前"))
            : "文件过大");
    }

    private void toggleSelection(int position) {
        if (position < 0 || position >= media.size()) return;
        JsonObject item = media.get(position);
        if (!allowed(item)) {
            Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(
                this, Jsons.string(item, "media_type"), Jsons.longValue(item, "size_bytes")), Snackbar.LENGTH_LONG).show();
            return;
        }
        String uri = Jsons.string(item, "url");
        setSelection(position, !selected.contains(uri));
    }

    private boolean setSelection(int position, boolean select) {
        return setSelection(position, select, true);
    }

    private boolean setSelection(int position, boolean select, boolean refreshUi) {
        if (position < 0 || position >= media.size()) return false;
        JsonObject item = media.get(position);
        if (!allowed(item)) return false;
        String uri = Jsons.string(item, "url");
        if (select && !selected.contains(uri) && selected.size() >= maxSelection) {
            Snackbar.make(binding.getRoot(), "单次最多选择 " + maxSelection + " 个媒体", Snackbar.LENGTH_LONG).show();
            return false;
        }
        if (select) selected.add(uri); else selected.remove(uri);
        if (select) ensureMotionPhotoDetected(position);
        gridAdapter.notifyItemChanged(position);
        if (refreshUi) {
            if (binding.previewPanel.getVisibility() == View.VISIBLE) updatePreview(position);
            updateSelectionBar();
        }
        return true;
    }

    private void installDragSelection() {
        GestureDetector detector = new GestureDetector(this, new GestureDetector.SimpleOnGestureListener() {
            @Override public boolean onDown(MotionEvent event) { return true; }
            @Override public void onLongPress(MotionEvent event) {
                View child = binding.mediaGrid.findChildViewUnder(event.getX(), event.getY());
                if (child == null) return;
                int position = binding.mediaGrid.getChildAdapterPosition(child);
                if (position == RecyclerView.NO_POSITION || !allowed(media.get(position))) return;
                String uri = Jsons.string(media.get(position), "url");
                dragSelectValue = !selected.contains(uri);
                dragSelecting = setSelection(position, dragSelectValue);
                lastDragPosition = position;
                dragLastTouchX = event.getX();
                dragLastTouchY = event.getY();
                if (dragSelecting) binding.mediaGrid.getParent().requestDisallowInterceptTouchEvent(true);
            }
        });
        binding.mediaGrid.addOnItemTouchListener(new RecyclerView.SimpleOnItemTouchListener() {
            @Override public boolean onInterceptTouchEvent(@NonNull RecyclerView recycler, @NonNull MotionEvent event) {
                detector.onTouchEvent(event);
                if (dragSelecting && event.getActionMasked() == MotionEvent.ACTION_MOVE) selectDragPosition(recycler, event);
                if (event.getActionMasked() == MotionEvent.ACTION_UP || event.getActionMasked() == MotionEvent.ACTION_CANCEL) {
                    endDragSelection(recycler);
                }
                return dragSelecting;
            }
            @Override public void onTouchEvent(@NonNull RecyclerView recycler, @NonNull MotionEvent event) {
                if (event.getActionMasked() == MotionEvent.ACTION_MOVE) selectDragPosition(recycler, event);
                if (event.getActionMasked() == MotionEvent.ACTION_UP || event.getActionMasked() == MotionEvent.ACTION_CANCEL) {
                    endDragSelection(recycler);
                }
            }
        });
    }

    private void selectDragPosition(RecyclerView recycler, MotionEvent event) {
        float delta = dragLastTouchY - event.getY();
        if (Math.abs(delta) >= dp(2)) recycler.scrollBy(0, Math.round(delta));
        dragLastTouchX = event.getX();
        dragLastTouchY = event.getY();
        selectDragPosition(recycler, dragLastTouchX, dragLastTouchY);
        updateDragEdgeDirection(recycler, dragLastTouchY);
    }

    private void selectDragPosition(RecyclerView recycler, float x, float y) {
        float safeX = Math.max(1f, Math.min(recycler.getWidth() - 1f, x));
        float safeY = Math.max(1f, Math.min(recycler.getHeight() - 1f, y));
        View child = recycler.findChildViewUnder(safeX, safeY);
        if (child == null) return;
        int position = recycler.getChildAdapterPosition(child);
        if (position == RecyclerView.NO_POSITION || position == lastDragPosition) return;
        int start = lastDragPosition == RecyclerView.NO_POSITION ? position : lastDragPosition;
        int direction = Integer.compare(position, start);
        if (direction == 0) return;
        boolean changed = false;
        for (int current = start + direction;; current += direction) {
            changed |= setSelection(current, dragSelectValue, false);
            if (current == position) break;
        }
        if (changed) updateSelectionBar();
        lastDragPosition = position;
    }

    private void updateDragEdgeDirection(RecyclerView recycler, float y) {
        int edge = Math.max(dp(42), recycler.getHeight() / 8);
        int direction = y < edge ? -1 : (y > recycler.getHeight() - edge ? 1 : 0);
        if (dragEdgeDirection == direction) return;
        dragEdgeDirection = direction;
        if (direction == 0) {
            recycler.removeCallbacks(dragEdgeScroller);
            dragEdgeScrollPosted = false;
        } else scheduleDragEdgeScroll(recycler);
    }

    private void scheduleDragEdgeScroll(RecyclerView recycler) {
        if (dragEdgeScrollPosted || !dragSelecting || dragEdgeDirection == 0) return;
        dragEdgeScrollPosted = true;
        recycler.postOnAnimation(dragEdgeScroller);
    }

    private void endDragSelection(RecyclerView recycler) {
        dragSelecting = false;
        lastDragPosition = RecyclerView.NO_POSITION;
        dragEdgeDirection = 0;
        recycler.removeCallbacks(dragEdgeScroller);
        dragEdgeScrollPosted = false;
        recycler.getParent().requestDisallowInterceptTouchEvent(false);
    }

    private void ensureMotionPhotoDetected(int position) {
        if (position < 0 || position >= media.size()) return;
        JsonObject item = media.get(position);
        if (isGif(item) || "video".equals(Jsons.string(item, "media_type"))) return;
        if (item.has("motion_photo_scanned") && item.get("motion_photo_scanned").getAsBoolean()) return;
        if (item.has("motion_photo_scanning") && item.get("motion_photo_scanning").getAsBoolean()) return;
        item.addProperty("motion_photo_scanning", true);
        Uri uri = Uri.parse(Jsons.string(item, "url"));
        String mime = Jsons.string(item, "mime_type");
        String name = Jsons.string(item, "file_name");
        loader.execute(() -> {
            boolean motion = MediaKindDetector.isMotionPhoto(this, uri, mime, name);
            runOnUiThread(() -> {
                if (binding == null || position >= media.size() || media.get(position) != item) return;
                item.addProperty("is_motion_photo", motion);
                item.addProperty("motion_photo_scanned", true);
                item.addProperty("motion_photo_scanning", false);
                gridAdapter.notifyItemChanged(position);
                if (binding.previewPanel.getVisibility() == View.VISIBLE
                    && binding.previewPager.getCurrentItem() == position) updatePreview(position);
            });
        });
    }

    private boolean allowed(JsonObject item) {
        return UploadPolicyStore.accepts(this, Jsons.string(item, "media_type"), Jsons.longValue(item, "size_bytes"));
    }

    private void updateSelectionBar() {
        if (selected.isEmpty()) {
            binding.selectedCount.setText("未选择");
        } else {
            long bytes = selectedBytes();
            String mode = binding.originalSwitch.isChecked() ? "原文件" : "发送时压缩";
            binding.selectedCount.setText("已选 " + selected.size() + " 项 · " + formatSize(bytes) + " · " + mode);
        }
        binding.confirmSelection.setEnabled(!selected.isEmpty());
    }

    private long selectedBytes() {
        long bytes = 0;
        for (JsonObject item : media) {
            if (selected.contains(Jsons.string(item, "url"))) {
                bytes += Math.max(0, Jsons.longValue(item, "size_bytes"));
            }
        }
        return bytes;
    }

    private boolean isGif(JsonObject item) {
        return MediaKindDetector.isGif(Jsons.string(item, "mime_type"), Jsons.string(item, "file_name"));
    }

    private boolean isMotionPhoto(JsonObject item) {
        try {
            return item.has("is_motion_photo") && item.get("is_motion_photo").getAsBoolean();
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    private String formatSize(long bytes) {
        if (bytes <= 0) return "大小未知";
        if (bytes < 1024) return bytes + " B";
        double kb = bytes / 1024d;
        if (kb < 1024) return String.format(java.util.Locale.CHINA, "%.1f KB", kb);
        double mb = kb / 1024d;
        if (mb < 1024) return String.format(java.util.Locale.CHINA, "%.1f MB", mb);
        return String.format(java.util.Locale.CHINA, "%.2f GB", mb / 1024d);
    }

    private void finishSelection(boolean confirmed) {
        ArrayList<Uri> uris = new ArrayList<>();
        for (String value : selected) uris.add(Uri.parse(value));
        JsonObject metadata = new JsonObject();
        for (JsonObject item : media) {
            String value = Jsons.string(item, "url");
            if (!selected.contains(value)) continue;
            JsonObject entry = item.deepCopy();
            entry.addProperty("is_gif", isGif(item));
            entry.addProperty("is_motion_photo", isMotionPhoto(item));
            metadata.add(value, entry);
        }
        Intent data = selectionResult(uris, binding.originalSwitch.isChecked(), metadata.toString(), confirmed);
        setResult(RESULT_OK, data);
        finish();
    }

    static Intent selectionResult(ArrayList<Uri> uris, boolean original, String metadata,
                                  boolean confirmed) {
        Intent data = new Intent();
        data.putParcelableArrayListExtra(EXTRA_SELECTED_URIS,
            uris == null ? new ArrayList<>() : new ArrayList<>(uris));
        data.putExtra(EXTRA_ORIGINAL, original);
        data.putExtra(EXTRA_MEDIA_METADATA, metadata == null ? "{}" : metadata);
        data.putExtra(EXTRA_SELECTION_CONFIRMED, confirmed);
        return data;
    }

    private void handleBack() {
        if (binding != null && binding.previewPanel.getVisibility() == View.VISIBLE) {
            binding.previewPanel.setVisibility(View.GONE);
            binding.continuousSelectSwitch.setChecked(false);
            binding.toolbar.setTitle("image".equals(mediaKind) ? "选择照片" :
                ("video".equals(mediaKind) ? "选择视频" : "最近照片和视频"));
            binding.toolbar.setSubtitle("点击预览；长按缩略图后滑动可连续多选");
            return;
        }
        // Returning to the inline album is not the same as closing the album feature.
        // Return the live selection so both views stay in sync, but let the caller know
        // that the user did not confirm these items for sending yet.
        finishSelection(false);
    }

    private final class MediaGridAdapter extends RecyclerView.Adapter<MediaGridAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            int size = Math.max(dp(76), parent.getResources().getDisplayMetrics().widthPixels / 4 - dp(3));
            MaterialCardView card = new MaterialCardView(parent.getContext());
            card.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, size));
            card.setRadius(dp(2));
            FrameLayout frame = new FrameLayout(parent.getContext());
            ImageView image = new ImageView(parent.getContext());
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            image.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            TextView check = new TextView(parent.getContext());
            check.setGravity(Gravity.CENTER);
            check.setTextColor(Color.WHITE);
            check.setTextSize(18);
            FrameLayout.LayoutParams checkParams = new FrameLayout.LayoutParams(dp(34), dp(34), Gravity.TOP | Gravity.END);
            TextView type = new TextView(parent.getContext());
            type.setGravity(Gravity.CENTER);
            type.setTextColor(Color.WHITE);
            type.setTextSize(11);
            type.setBackgroundColor(0x88000000);
            FrameLayout.LayoutParams typeParams = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(26), Gravity.BOTTOM | Gravity.START);
            frame.addView(image);
            frame.addView(check, checkParams);
            frame.addView(type, typeParams);
            card.addView(frame);
            return new Holder(card, image, check, type);
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = media.get(position);
            Uri uri = Uri.parse(Jsons.string(item, "url"));
            boolean allowed = allowed(item);
            boolean checked = selected.contains(uri.toString());
            holder.image.setAlpha(allowed ? 1f : 0.28f);
            Glide.with(holder.image).load(uri).thumbnail(0.15f).override(dp(112), dp(112)).dontAnimate()
                .centerCrop().placeholder(R.drawable.ic_album).into(holder.image);
            holder.check.setText(allowed ? (checked ? "✓" : "○") : "×");
            holder.check.setBackgroundColor(checked
                ? xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(MediaPickerActivity.this) : 0x99000000);
            if (!allowed) {
                holder.type.setText("  文件过大  ");
                holder.type.setVisibility(View.VISIBLE);
            } else if ("video".equals(Jsons.string(item, "media_type"))) {
                long seconds = Math.max(0, Jsons.longValue(item, "duration_ms") / 1000L);
                holder.type.setText("  视频 " + seconds / 60 + ":" + String.format(java.util.Locale.CHINA, "%02d", seconds % 60) + "  ");
                holder.type.setVisibility(View.VISIBLE);
            } else if (isGif(item)) {
                holder.type.setText("  GIF  ");
                holder.type.setVisibility(View.VISIBLE);
            } else if (isMotionPhoto(item)) {
                holder.type.setText("  动态图  ");
                holder.type.setVisibility(View.VISIBLE);
            } else holder.type.setVisibility(View.GONE);
            holder.itemView.setOnClickListener(view -> showPreview(holder.getBindingAdapterPosition()));
            holder.check.setOnClickListener(view -> toggleSelection(holder.getBindingAdapterPosition()));
        }

        @Override public int getItemCount() { return media.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ImageView image;
            final TextView check;
            final TextView type;
            Holder(View item, ImageView image, TextView check, TextView type) {
                super(item);
                this.image = image;
                this.check = check;
                this.type = type;
            }
        }
    }

    private final class PreviewAdapter extends RecyclerView.Adapter<PreviewAdapter.Holder> {
        @NonNull @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            ZoomableMediaFrame frame = new ZoomableMediaFrame(parent.getContext());
            frame.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            ImageView image = new ImageView(parent.getContext());
            image.setScaleType(ImageView.ScaleType.FIT_CENTER);
            image.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            VideoView video = new VideoView(parent.getContext());
            video.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            frame.addView(image);
            frame.addView(video);
            return new Holder(frame, image, video);
        }

        @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
            JsonObject item = media.get(position);
            Uri uri = Uri.parse(Jsons.string(item, "url"));
            boolean video = "video".equals(Jsons.string(item, "media_type"));
            holder.image.setVisibility(video ? View.GONE : View.VISIBLE);
            holder.video.setVisibility(video ? View.VISIBLE : View.GONE);
            holder.frame.setZoomTarget(video ? holder.video : holder.image);
            if (video) {
                MediaController controls = new MediaController(MediaPickerActivity.this);
                controls.setAnchorView(holder.video);
                holder.video.setMediaController(controls);
                holder.video.setVideoURI(uri);
                holder.video.setOnPreparedListener(player -> {
                    if (holder.getBindingAdapterPosition() == binding.previewPager.getCurrentItem()) holder.video.start();
                });
                holder.frame.setDoubleTapAction(() -> {
                    if (holder.video.isPlaying()) holder.video.pause(); else holder.video.start();
                });
            } else {
                Glide.with(holder.image).load(uri).fitCenter().placeholder(R.drawable.ic_album).into(holder.image);
                holder.frame.setDoubleTapAction(null);
            }
        }

        @Override public void onViewRecycled(@NonNull Holder holder) {
            holder.video.stopPlayback();
            super.onViewRecycled(holder);
        }

        @Override public int getItemCount() { return media.size(); }

        final class Holder extends RecyclerView.ViewHolder {
            final ZoomableMediaFrame frame;
            final ImageView image;
            final VideoView video;
            Holder(ZoomableMediaFrame frame, ImageView image, VideoView video) {
                super(frame);
                this.frame = frame;
                this.image = image;
                this.video = video;
            }
        }
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    @Override protected void onDestroy() {
        if (binding != null) binding.mediaGrid.removeCallbacks(dragEdgeScroller);
        loader.shutdownNow();
        binding = null;
        super.onDestroy();
    }
}
