package xyz.jjmxg.yiyunying.ui.forum;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.res.ColorStateList;
import android.graphics.Rect;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.GridLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.EditText;
import android.database.Cursor;
import android.net.Uri;
import android.provider.OpenableColumns;
import android.view.inputmethod.InputMethodManager;
import android.text.Editable;
import android.text.TextWatcher;

import androidx.appcompat.app.AppCompatActivity;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.core.content.ContextCompat;

import com.google.android.material.card.MaterialCardView;
import com.google.android.material.button.MaterialButton;
import com.google.android.material.snackbar.Snackbar;
import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.chip.Chip;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.ArrayList;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Map;
import java.util.Locale;
import java.util.LinkedHashSet;
import java.util.Set;
import java.util.TimeZone;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForumPostBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.forum.ForumPrivateMediaPolicy;
import xyz.jjmxg.yiyunying.domain.forum.ForumUnlockPolicy;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;
import xyz.jjmxg.yiyunying.ui.common.CommentVoiceRecorder;
import xyz.jjmxg.yiyunying.ui.common.ActionIconResolver;
import xyz.jjmxg.yiyunying.ui.common.GlassActionDialog;
import xyz.jjmxg.yiyunying.ui.common.ContentReportDialog;
import xyz.jjmxg.yiyunying.ui.common.MediaViewRenderer;
import xyz.jjmxg.yiyunying.ui.common.RecordDetailDialog;
import xyz.jjmxg.yiyunying.ui.common.SecureMediaClipboard;
import xyz.jjmxg.yiyunying.ui.profile.UserProfileActivity;
import xyz.jjmxg.yiyunying.ui.upload.ContentUriRequestBody;
import xyz.jjmxg.yiyunying.ui.upload.FilePickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.MediaPickerActivity;
import xyz.jjmxg.yiyunying.ui.upload.UploadPolicyStore;

public final class ForumPostActivity extends xyz.jjmxg.yiyunying.ui.common.SystemInsetActivity {
    private static final String EXTRA_POST_ID = "post_id";
    private static final String EXTRA_APP_ID = "app_id";
    private static final String EXTRA_COMMENT_ID = "comment_id";
    private static final long PRIVATE_MEDIA_REFRESH_DEBOUNCE_MS = 1_500L;

    private ActivityForumPostBinding binding;
    private RequestHandle request;
    private RequestHandle actionRequest;
    private RequestHandle commentRequest;
    private RequestHandle commentUploadRequest;
    private RequestHandle mentionRequest;
    private long postId;
    private long appId;
    private long focusCommentId;
    private Role role;
    private long replyToCommentId;
    private JsonObject post = new JsonObject();
    private final Handler privateMediaHandler = new Handler(Looper.getMainLooper());
    private final Runnable privateMediaRefresh = () -> refreshPrivateMedia(false);
    private long loadGeneration;
    private long networkAppliedGeneration;
    private long lastPrivateMediaRefreshStartedAt;
    private long lastAutomaticPrivateMediaExpiry = Long.MIN_VALUE;
    private boolean privateMediaRefreshInFlight;
    private boolean privateMediaAutoRefreshSuppressed;
    private boolean resumed;
    private final List<CommentAttachment> commentAttachments = new ArrayList<>();
    private final Set<Long> commentMentionIds = new LinkedHashSet<>();
    private final Set<Long> expandedCommentThreads = new LinkedHashSet<>();
    private boolean suppressMentionPicker;
    private String commentPickerType = "file";
    private CommentVoiceRecorder commentVoiceRecorder;
    private final ActivityResultLauncher<Intent> commentPicker = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(), result -> {
            if (isUiActive()) selectedCommentFiles(result);
        });
    private final ActivityResultLauncher<String> commentVoicePermission = registerForActivityResult(
        new ActivityResultContracts.RequestPermission(), granted -> {
            if (!isUiActive()) return;
            if (Boolean.TRUE.equals(granted)) startCommentVoiceRecording();
            else Snackbar.make(binding.getRoot(), "需要麦克风权限才能录制语音评论", Snackbar.LENGTH_LONG).show();
        });

    private boolean isUiActive() {
        return binding != null && !isFinishing() && !isDestroyed();
    }

    public static void open(Context context, long postId) {
        open(context, 0, postId);
    }

    public static Intent intent(Context context, long postId) {
        return new Intent(context, ForumPostActivity.class).putExtra(EXTRA_POST_ID, postId);
    }

    public static Intent mentionIntent(Context context, long postId, long commentId) {
        return intent(context, postId).putExtra(EXTRA_COMMENT_ID, Math.max(0L, commentId));
    }

    public static void open(Context context, long appId, long postId) {
        context.startActivity(new Intent(context, ForumPostActivity.class)
            .putExtra(EXTRA_APP_ID, appId).putExtra(EXTRA_POST_ID, postId));
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (!AppAccess.from(this).session().isAuthenticated()) { login(); return; }
        role = AppAccess.from(this).session().role();
        appId = getIntent().getLongExtra(EXTRA_APP_ID, 0);
        if (appId <= 0 && role == Role.ADMIN) appId = AppAccess.from(this).session().selectedAppId();
        postId = getIntent().getLongExtra(EXTRA_POST_ID, 0);
        focusCommentId = getIntent().getLongExtra(EXTRA_COMMENT_ID, 0L);
        if (postId <= 0) { finish(); return; }
        binding = ActivityForumPostBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());
        SecureMediaClipboard.attachPaste(binding.commentInput, uris -> selectedCommentFiles("file", uris));
        binding.toolbar.setNavigationOnClickListener(view -> finish());
        xyz.jjmxg.yiyunying.ui.common.TopCenterDoubleTap.attach(
            binding.toolbar, binding.scroll);
        binding.authorRow.setOnClickListener(view -> openAuthor(post));
        binding.likeButton.setOnClickListener(view -> toggle("like"));
        binding.favoriteButton.setOnClickListener(view -> toggle("favorite"));
        binding.commentButton.setOnClickListener(view -> comment());
        binding.reportButton.setOnClickListener(view -> ContentReportDialog.show(
            this, "post", postId, Jsons.string(post, "title").isEmpty() ? "这篇帖子" : Jsons.string(post, "title")));
        binding.commentAttachButton.setOnClickListener(view -> showCommentAttachmentMenu());
        binding.commentEmojiButton.setOnClickListener(view -> showEmojiPicker());
        binding.commentVoiceButton.setOnClickListener(view -> toggleCommentVoiceRecording());
        binding.commentVoiceCancelButton.setOnClickListener(view -> cancelCommentVoiceRecording());
        binding.sendCommentButton.setOnClickListener(view -> sendInlineComment());
        configureCommentEmojiPanel();
        boolean managementView = role != Role.USER;
        binding.interactionRow.setVisibility(managementView ? View.GONE : View.VISIBLE);
        binding.commentPendingScroll.setVisibility(View.GONE);
        binding.commentComposerRow.setVisibility(managementView ? View.GONE : View.VISIBLE);
        binding.commentInput.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence value, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence value, int start, int before, int count) {
                if (role != Role.USER || suppressMentionPicker || value == null || count <= 0) return;
                int end = Math.min(value.length(), start + count);
                for (int index = start; index < end; index++) {
                    if (value.charAt(index) == '@') {
                        binding.commentInput.post(() -> {
                            if (isUiActive()) showMentionTypePicker();
                        });
                        break;
                    }
                }
            }
            @Override public void afterTextChanged(Editable value) { }
        });
        load();
    }

    private void load() {
        load(false, false);
    }

    private void load(boolean refreshingPrivateMedia, boolean requestedByUser) {
        if (!isUiActive()) return;
        if (request != null) request.cancel();
        request = null;
        privateMediaRefreshInFlight = refreshingPrivateMedia;
        if (!refreshingPrivateMedia) privateMediaAutoRefreshSuppressed = false;
        long generation = ++loadGeneration;
        if (!refreshingPrivateMedia || requestedByUser) binding.progress.setVisibility(View.VISIBLE);
        String path = postPath();
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        if (refreshingPrivateMedia) {
            query.put("_media_refresh", Long.toString(System.currentTimeMillis()));
        }
        if (!refreshingPrivateMedia) {
            AppAccess.from(this).repository().getCached(path, query, cached -> {
                if (generation != loadGeneration || networkAppliedGeneration >= generation
                    || binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
                JsonObject cachedPost = Jsons.object(cached.dataObject(), "post");
                if (cachedPost.size() == 0) return;
                post = cachedPost;
                render();
                binding.progress.setVisibility(View.INVISIBLE);
            });
        }
        request = AppAccess.from(this).repository().get(path, query, result -> {
            if (generation != loadGeneration) return;
            request = null;
            privateMediaRefreshInFlight = false;
            if (!isUiActive()) return;
            if (!refreshingPrivateMedia || requestedByUser) binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                if (!refreshingPrivateMedia || requestedByUser) {
                    Snackbar.make(binding.getRoot(), result.message().isEmpty()
                        ? (refreshingPrivateMedia ? "媒体访问地址刷新失败，请稍后重试" : "帖子加载失败")
                        : result.message(), Snackbar.LENGTH_LONG).show();
                }
                return;
            }
            boolean offlineFallback = "offline-cache".equals(result.traceId());
            if (refreshingPrivateMedia && offlineFallback) {
                privateMediaAutoRefreshSuppressed = true;
                if (requestedByUser) {
                    Snackbar.make(binding.getRoot(), "网络不可用，无法刷新媒体访问地址", Snackbar.LENGTH_LONG).show();
                }
                return;
            }
            JsonObject freshPost = Jsons.object(result.dataObject(), "post");
            if (freshPost.size() == 0) return;
            networkAppliedGeneration = generation;
            privateMediaAutoRefreshSuppressed = offlineFallback;
            post = freshPost;
            render();
        });
    }

    private String postPath() {
        return role == Role.USER ? "/api/user/forum-posts/" + postId
            : "/api/" + role.wireName() + "/apps/" + appId + "/forum-posts/" + postId;
    }

    private void render() {
        String title = Jsons.string(post, "title");
        String author = Jsons.string(post, "nickname");
        RuntimeLanguage.setDynamicToolbarTitle(binding.toolbar, Jsons.string(post, "plate_name"));
        RuntimeLanguage.setDynamicText(binding.title, title);
        RuntimeLanguage.protectDynamicText(binding.content);
        LinkNavigator.setTextWithLinks(binding.content, Jsons.string(post, "content"));
        binding.authorName.setText(
            author.isEmpty() ? RuntimeLanguage.translate(this, "用户 ").toString()
                + Jsons.longValue(post, "user_id") : author);
        String hotLabel = Jsons.string(post, "hot_label");
        if (Jsons.longValue(post, "comment_count") <= 0 && (hotLabel.contains("讨论") || hotLabel.contains("热议"))) hotLabel = "";
        binding.postMeta.setText(Jsons.string(post, "created_at") + " · "
            + Jsons.longValue(post, "unique_view_count") + " 人阅读 · "
            + Jsons.longValue(post, "comment_count") + " 评论"
            + (hotLabel.isEmpty() ? "" : " · 热度 " + hotLabel));
        String avatar = ImageLoader.get().absoluteUrl(this, Jsons.string(post, "avatar"));
        ImageLoader.get().load(avatar, binding.authorAvatar, R.drawable.ic_person);
        MediaViewRenderer.render(this, binding.mediaContainer, Jsons.array(post, "attachments"),
            this::refreshPrivateMediaForClick);
        renderSections(Jsons.array(post, "sections"));
        binding.likeButton.setText(bool(post, "liked") ? "已点赞 " + Jsons.longValue(post, "like_count") : "点赞 " + Jsons.longValue(post, "like_count"));
        binding.favoriteButton.setText(bool(post, "favorited") ? "已收藏" : "收藏");
        binding.tagContainer.removeAllViews();
        java.util.LinkedHashSet<String> renderedTags = new java.util.LinkedHashSet<>();
        for (JsonElement tag : Jsons.array(post, "tags")) {
            if (!tag.isJsonPrimitive()) continue;
            String tagName = tag.getAsString().trim().replaceFirst("^#+", "");
            if (tagName.isEmpty() || !renderedTags.add(tagName)) continue;
            Chip chip = new Chip(this);
            RuntimeLanguage.setDynamicText(chip, "# " + tagName);
            chip.setCheckable(false);
            chip.setOnClickListener(view -> {
                if (role == Role.USER) ForumListActivity.search(this, tagName);
                else ForumListActivity.search(this, appId, tagName);
            });
            binding.tagContainer.addView(chip);
        }
        renderComments(Jsons.array(post, "comments"));
        schedulePrivateMediaRefresh();
    }

    private ForumPrivateMediaPolicy.Snapshot privateMediaSnapshot() {
        return ForumPrivateMediaPolicy.inspect(post, System.currentTimeMillis());
    }

    private void refreshPrivateMediaForClick(long attachmentId) {
        ForumPrivateMediaPolicy.Snapshot snapshot = privateMediaSnapshot();
        if (!snapshot.hasPrivateMedia()
            || (attachmentId > 0L && !snapshot.contains(attachmentId))) return;
        if (request != null || privateMediaRefreshInFlight) {
            Snackbar.make(binding.getRoot(), "正在刷新媒体访问地址，请稍候", Snackbar.LENGTH_SHORT).show();
            return;
        }
        Snackbar.make(binding.getRoot(), "正在刷新媒体访问地址，请稍候", Snackbar.LENGTH_SHORT).show();
        refreshPrivateMedia(true);
    }

    private void refreshPrivateMedia(boolean requestedByUser) {
        if (!isUiActive() || request != null || privateMediaRefreshInFlight) return;
        ForumPrivateMediaPolicy.Snapshot snapshot = privateMediaSnapshot();
        if (!snapshot.hasPrivateMedia() || !snapshot.refreshRequired()) {
            schedulePrivateMediaRefresh();
            return;
        }
        long now = System.currentTimeMillis();
        long elapsed = now - lastPrivateMediaRefreshStartedAt;
        if (elapsed < PRIVATE_MEDIA_REFRESH_DEBOUNCE_MS) {
            privateMediaHandler.removeCallbacks(privateMediaRefresh);
            privateMediaHandler.postDelayed(privateMediaRefresh,
                Math.max(1L, PRIVATE_MEDIA_REFRESH_DEBOUNCE_MS - elapsed));
            return;
        }
        lastPrivateMediaRefreshStartedAt = now;
        if (!requestedByUser) lastAutomaticPrivateMediaExpiry = refreshToken(snapshot);
        privateMediaHandler.removeCallbacks(privateMediaRefresh);
        load(true, requestedByUser);
    }

    private void schedulePrivateMediaRefresh() {
        privateMediaHandler.removeCallbacks(privateMediaRefresh);
        if (!resumed || !isUiActive() || request != null || privateMediaRefreshInFlight
            || privateMediaAutoRefreshSuppressed) return;
        ForumPrivateMediaPolicy.Snapshot snapshot = privateMediaSnapshot();
        if (!snapshot.hasPrivateMedia()) return;
        long token = refreshToken(snapshot);
        long delay = snapshot.earliestExpiryMs() == Long.MAX_VALUE
            ? 0L
            : Math.max(0L, snapshot.earliestExpiryMs() - System.currentTimeMillis()
                - ForumPrivateMediaPolicy.REFRESH_AHEAD_MS);
        if (delay == 0L && token == lastAutomaticPrivateMediaExpiry) return;
        privateMediaHandler.postDelayed(privateMediaRefresh, delay);
    }

    private long refreshToken(ForumPrivateMediaPolicy.Snapshot snapshot) {
        return snapshot.earliestExpiryMs() == Long.MAX_VALUE ? 0L : snapshot.earliestExpiryMs();
    }

    private void renderSections(JsonArray sections) {
        binding.sectionsContainer.removeAllViews();
        boolean legacyLocked = bool(post, "paid_content") && !bool(post, "purchased");
        binding.sectionsContainer.setVisibility(legacyLocked || !sections.isEmpty() ? View.VISIBLE : View.GONE);
        if (legacyLocked) {
            double price = decimal(post, "paid_price_balance");
            binding.sectionsContainer.addView(lockedSection("完整帖子内容", price,
                "/api/user/forum-posts/" + postId + "/buy"));
        }
        int number = 0;
        for (JsonElement element : sections) {
            if (!element.isJsonObject()) continue;
            number++;
            JsonObject section = element.getAsJsonObject();
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            params.bottomMargin = dp(10);
            card.setLayoutParams(params);
            card.setRadius(dp(8));
            card.setCardElevation(0);
            card.setStrokeWidth(dp(1));
            card.setStrokeColor(getColor(R.color.outline));
            card.setCardBackgroundColor(getColor(R.color.surface_container));
            LinearLayout body = new LinearLayout(this);
            body.setOrientation(LinearLayout.VERTICAL);
            body.setPadding(dp(14), dp(12), dp(14), dp(14));
            String title = Jsons.string(section, "title");
            boolean locked = bool(section, "locked");
            String unlockType = Jsons.string(section, "section_type");
            boolean protectedContent = !"free".equals(unlockType);
            TextView heading = new TextView(this);
            heading.setText("第 " + number + " 节" + (title.isEmpty() ? "" : " · " + title)
                + (protectedContent ? (locked ? "  待解锁" : "  已解锁") : "  公开"));
            heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
            heading.setTextColor(protectedContent ? xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this)
                : getColor(R.color.on_surface));
            body.addView(heading);
            if (locked) {
                TextView hint = new TextView(this);
                String preview = Jsons.string(section, "preview_content");
                String rule = sectionUnlockRule(section);
                hint.setText((preview.isEmpty() ? "本节正文、标签和附件暂时隐藏。" : preview)
                    + (rule.isEmpty() ? "" : "\n" + rule));
                hint.setTextColor(getColor(R.color.on_surface_variant));
                hint.setTextSize(14);
                LinearLayout.LayoutParams hintParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                hintParams.topMargin = dp(8);
                body.addView(hint, hintParams);
                if (bool(section, "can_buy")) {
                    MaterialButton unlock = new MaterialButton(this);
                    unlock.setText("提前支付并查看 · " + money(decimal(section, "price_balance")));
                    long sectionId = Jsons.longValue(section, "id");
                    unlock.setOnClickListener(view -> confirmPurchase(
                        "/api/user/forum-posts/" + postId + "/sections/" + sectionId + "/buy",
                        money(decimal(section, "price_balance"))));
                    LinearLayout.LayoutParams unlockParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(48));
                    unlockParams.topMargin = dp(8);
                    body.addView(unlock, unlockParams);
                }
            } else {
                TextView content = new TextView(this);
                LinkNavigator.setTextWithLinks(content, Jsons.string(section, "content"));
                content.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
                content.setLineSpacing(0f, 1.12f);
                LinearLayout.LayoutParams contentParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                contentParams.topMargin = dp(8);
                body.addView(content, contentParams);
                JsonArray tags = Jsons.array(section, "tags");
                if (!tags.isEmpty()) {
                    TextView tagText = new TextView(this);
                    tagText.setText("# " + join(tags, "   # "));
                    tagText.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this));
                    tagText.setTextSize(13);
                    body.addView(tagText);
                }
                LinearLayout media = new LinearLayout(this);
                media.setOrientation(LinearLayout.VERTICAL);
                body.addView(media, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
                MediaViewRenderer.render(this, media, Jsons.array(section, "attachments"),
                    this::refreshPrivateMediaForClick);
            }
            card.addView(body);
            binding.sectionsContainer.addView(card);
        }
    }

    private String sectionUnlockRule(JsonObject section) {
        String type = Jsons.string(section, "section_type");
        String localTime = localUnlockAt(Jsons.string(section, "unlock_at_iso"));
        return ForumUnlockPolicy.label(type, decimal(section, "price_balance"), localTime);
    }

    private String localUnlockAt(String iso) {
        if (iso == null || iso.trim().isEmpty()) return "";
        try {
            SimpleDateFormat source = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.ROOT);
            source.setLenient(false);
            source.setTimeZone(TimeZone.getTimeZone("UTC"));
            Date value = source.parse(iso.trim());
            return value == null ? "" : new SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault()).format(value);
        } catch (java.text.ParseException ignored) {
            return "";
        }
    }

    private View lockedSection(String title, double price, String path) {
        MaterialCardView card = new MaterialCardView(this);
        card.setRadius(dp(8));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(1));
        card.setStrokeColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this));
        LinearLayout body = new LinearLayout(this);
        body.setOrientation(LinearLayout.VERTICAL);
        body.setPadding(dp(14), dp(12), dp(14), dp(12));
        TextView text = new TextView(this);
        text.setText(title + "尚未解锁\n支付 " + money(price) + " 余额后可查看正文、标签与全部附件。");
        text.setTextColor(getColor(R.color.on_surface));
        text.setTextSize(15);
        body.addView(text);
        MaterialButton button = new MaterialButton(this);
        button.setText("支付并解锁");
        button.setOnClickListener(view -> confirmPurchase(path, money(price)));
        body.addView(button, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(48)));
        card.addView(body);
        return card;
    }

    private void renderComments(JsonArray comments) {
        binding.commentsContainer.removeAllViews();
        binding.emptyComments.setVisibility(comments.isEmpty() ? View.VISIBLE : View.GONE);
        Map<Long, View> commentAnchors = new LinkedHashMap<>();
        Map<Long, CommentThreadView> threadContainers = new LinkedHashMap<>();
        List<JsonObject> commentItems = new ArrayList<>();
        List<ForumCommentThreadOrder.CommentRef> commentRefs = new ArrayList<>();
        for (JsonElement element : comments) {
            if (!element.isJsonObject()) continue;
            JsonObject item = element.getAsJsonObject();
            int sourceIndex = commentItems.size();
            commentItems.add(item);
            commentRefs.add(new ForumCommentThreadOrder.CommentRef(
                sourceIndex,
                Jsons.longValue(item, "id"),
                Jsons.longValue(item, "parent_id"),
                Jsons.longValue(item, "root_comment_id")
            ));
        }
        Map<Integer, Long> resolvedRoots = ForumCommentThreadOrder.resolvedRootIds(commentRefs);
        long requestedFocusId = focusCommentId;
        for (int index = 0; index < commentItems.size(); index++) {
            if (Jsons.longValue(commentItems.get(index), "id") == requestedFocusId) {
                expandedCommentThreads.add(resolvedRoots.getOrDefault(index, requestedFocusId));
                break;
            }
        }
        for (int orderedIndex : ForumCommentThreadOrder.orderedIndexes(commentRefs)) {
            JsonObject comment = commentItems.get(orderedIndex);
            long commentId = Jsons.longValue(comment, "id");
            long parentCommentId = Jsons.longValue(comment, "parent_id");
            long rootCommentId = resolvedRoots.getOrDefault(orderedIndex, commentId);
            CommentThreadView thread = threadContainers.get(rootCommentId);
            if (thread == null) {
                thread = new CommentThreadView(rootCommentId);
                threadContainers.put(rootCommentId, thread);
            }
            MaterialCardView card = new MaterialCardView(this);
            LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            cardParams.bottomMargin = dp(parentCommentId > 0 ? 2 : 6);
            if (parentCommentId > 0) {
                cardParams.leftMargin = dp(2);
                cardParams.rightMargin = dp(2);
            }
            card.setLayoutParams(cardParams);
            card.setRadius(dp(parentCommentId > 0 ? 4 : 6));
            card.setCardElevation(0);
            card.setStrokeWidth(0);
            card.setCardBackgroundColor(parentCommentId > 0
                ? ColorStateList.valueOf(android.graphics.Color.TRANSPARENT).getDefaultColor()
                : getColor(R.color.surface_container));

            LinearLayout row = new LinearLayout(this);
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.TOP);
            int rowPadding = parentCommentId > 0 ? 8 : 12;
            row.setPadding(dp(rowPadding), dp(rowPadding), dp(rowPadding), dp(rowPadding));
            ImageView avatar = new ImageView(this);
            avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, Jsons.string(comment, "avatar")), avatar, R.drawable.ic_person);
            int avatarSize = parentCommentId > 0 ? 30 : 42;
            row.addView(avatar, new LinearLayout.LayoutParams(dp(avatarSize), dp(avatarSize)));

            LinearLayout content = new LinearLayout(this);
            content.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams contentParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            contentParams.leftMargin = dp(parentCommentId > 0 ? 8 : 10);
            TextView name = new TextView(this);
            String nickname = Jsons.string(comment, "nickname");
            name.setText(
                nickname.isEmpty() ? RuntimeLanguage.translate(this, "用户 ").toString()
                    + Jsons.longValue(comment, "user_id") : nickname);
            if (parentCommentId > 0) {
                name.setTextSize(13);
                name.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
                name.setTextColor(getColor(R.color.on_surface));
            } else {
                name.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
            }
            LinearLayout authorRow = new LinearLayout(this);
            authorRow.setOrientation(LinearLayout.HORIZONTAL);
            authorRow.setGravity(Gravity.CENTER_VERTICAL);
            authorRow.addView(name, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            if (parentCommentId > 0) {
                TextView replyTarget = new TextView(this);
                String replyToName = Jsons.string(comment, "reply_to_name");
                if (replyToName.isEmpty()) {
                    long replyToUserId = Jsons.longValue(comment, "reply_to_user_id");
                    replyToName = replyToUserId > 0 ? "用户 " + replyToUserId : "上一条评论";
                }
                replyTarget.setText(" 回复 " + replyToName);
                replyTarget.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this));
                replyTarget.setTextSize(parentCommentId > 0 ? 11.5f : 13f);
                replyTarget.setPadding(dp(4), dp(2), dp(4), dp(2));
                replyTarget.setOnClickListener(view -> {
                    View anchor = commentAnchors.get(parentCommentId);
                    if (anchor != null) {
                        focusCommentCard(anchor, "已定位到被回复的评论");
                    } else {
                        Snackbar.make(binding.getRoot(), "被回复的评论不在当前列表", Snackbar.LENGTH_SHORT).show();
                    }
                });
                authorRow.addView(replyTarget, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            }
            content.addView(authorRow);
            TextView text = new TextView(this);
            RuntimeLanguage.setDynamicText(text, Jsons.string(comment, "content"));
            text.setTextAppearance(parentCommentId > 0
                ? com.google.android.material.R.style.TextAppearance_Material3_BodyMedium
                : com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
            LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            textParams.topMargin = dp(4);
            content.addView(text, textParams);
            LinearLayout media = new LinearLayout(this);
            media.setOrientation(LinearLayout.VERTICAL);
            content.addView(media, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            MediaViewRenderer.renderCommentMedia(this, media, Jsons.array(comment, "attachments"),
                this::refreshPrivateMediaForClick);
            TextView time = new TextView(this);
            time.setText((bool(comment, "is_pinned") ? "作者置顶 · " : "") + Jsons.string(comment, "created_at"));
            time.setTextColor(getColor(R.color.outline));
            time.setTextSize(12);
            content.addView(time);
            LinearLayout actions = new LinearLayout(this);
            actions.setOrientation(LinearLayout.HORIZONTAL);
            boolean liked = bool(comment, "liked");
            boolean favorited = bool(comment, "favorited");
            long likeCount = Jsons.longValue(comment, "like_count");
            String replyLabel = parentCommentId > 0 ? "" : "回复";
            String likeLabel = parentCommentId > 0 ? Long.toString(likeCount)
                : (liked ? "已赞 " : "赞 ") + likeCount;
            String favoriteLabel = parentCommentId > 0 ? "" : (favorited ? "已收藏" : "收藏");
            LinearLayout.LayoutParams actionParams = new LinearLayout.LayoutParams(
                0, ViewGroup.LayoutParams.MATCH_PARENT, 1f);
            actions.addView(commentButton(replyLabel, R.drawable.ic_reply, "回复这条评论",
                view -> beginReply(comment)), actionParams);
            actions.addView(commentButton(likeLabel, R.drawable.ic_like,
                (liked ? "取消点赞，当前 " : "点赞，当前 ") + likeCount + " 个赞",
                view -> commentInteraction(comment, "like")),
                new LinearLayout.LayoutParams(actionParams));
            actions.addView(commentButton(favoriteLabel, R.drawable.ic_favorite,
                favorited ? "取消收藏这条评论" : "收藏这条评论",
                view -> commentInteraction(comment, "favorite")),
                new LinearLayout.LayoutParams(actionParams));
            content.addView(actions, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(parentCommentId > 0 ? 34 : 38)));
            row.addView(content, contentParams);
            card.addView(row);
            View.OnClickListener profile = view -> UserProfileActivity.open(this, Jsons.longValue(comment, "user_id"));
            if (role == Role.USER) {
                avatar.setOnClickListener(profile);
                name.setOnClickListener(profile);
                card.setOnLongClickListener(view -> {
                    showCommentActions(comment);
                    return true;
                });
            } else {
                actions.setVisibility(View.GONE);
                View.OnClickListener detail = view -> RecordDetailDialog.show(this, "评论与发布者资料", comment);
                avatar.setOnClickListener(detail);
                name.setOnClickListener(detail);
                card.setOnLongClickListener(view -> { detail.onClick(view); return true; });
            }
            if (parentCommentId > 0L) {
                thread.addReply(card);
            } else {
                thread.rootContainer.addView(card);
            }
            commentAnchors.put(commentId, card);
            if (focusCommentId > 0L && focusCommentId == commentId) {
                focusCommentId = 0L;
                focusCommentCard(card, "已定位到这条评论");
            }
        }
        for (CommentThreadView thread : threadContainers.values()) thread.refresh();
    }

    private final class CommentThreadView {
        final long rootId;
        final LinearLayout rootContainer;
        final LinearLayout nestedContainer;
        final LinearLayout repliesContainer;
        final LinearLayout toggle;
        final TextView toggleText;
        final ImageView toggleIcon;
        int replyCount;

        CommentThreadView(long rootId) {
            this.rootId = rootId;
            MaterialCardView wrapper = new MaterialCardView(ForumPostActivity.this);
            LinearLayout.LayoutParams wrapperParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT);
            wrapperParams.bottomMargin = dp(12);
            wrapper.setLayoutParams(wrapperParams);
            wrapper.setRadius(dp(8));
            wrapper.setCardElevation(0);
            wrapper.setStrokeWidth(dp(1));
            wrapper.setStrokeColor(getColor(R.color.outline_variant));
            wrapper.setCardBackgroundColor(getColor(R.color.surface_container));

            LinearLayout threadBody = new LinearLayout(ForumPostActivity.this);
            threadBody.setOrientation(LinearLayout.VERTICAL);

            rootContainer = new LinearLayout(ForumPostActivity.this);
            rootContainer.setOrientation(LinearLayout.VERTICAL);
            nestedContainer = new LinearLayout(ForumPostActivity.this);
            nestedContainer.setOrientation(LinearLayout.VERTICAL);
            nestedContainer.setPadding(dp(6), dp(5), dp(6), dp(5));
            nestedContainer.setBackground(commentThreadBackground(
                R.color.surface_container_high,
                R.color.outline_variant,
                8));
            repliesContainer = new LinearLayout(ForumPostActivity.this);
            repliesContainer.setOrientation(LinearLayout.VERTICAL);
            toggle = new LinearLayout(ForumPostActivity.this);
            toggle.setOrientation(LinearLayout.HORIZONTAL);
            toggle.setGravity(Gravity.CENTER);
            toggle.setClickable(true);
            toggle.setFocusable(true);
            toggle.setPadding(dp(7), 0, dp(7), 0);
            toggle.setBackground(commentThreadBackground(
                R.color.surface_container_high,
                R.color.outline_variant,
                16));
            toggleText = new TextView(ForumPostActivity.this);
            toggleText.setTextSize(11);
            toggleText.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
            toggleText.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(ForumPostActivity.this));
            toggleIcon = new ImageView(ForumPostActivity.this);
            toggleIcon.setImageResource(R.drawable.ic_chevron_right);
            toggleIcon.setImageTintList(ColorStateList.valueOf(
                xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(ForumPostActivity.this)));
            toggle.addView(toggleText, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(14), dp(14));
            iconParams.leftMargin = dp(3);
            toggle.addView(toggleIcon, iconParams);
            toggle.setOnClickListener(view -> {
                if (expandedCommentThreads.contains(rootId)) expandedCommentThreads.remove(rootId);
                else expandedCommentThreads.add(rootId);
                refresh();
            });

            threadBody.addView(rootContainer, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            nestedContainer.addView(repliesContainer, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            LinearLayout.LayoutParams toggleParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(28));
            toggleParams.topMargin = dp(2);
            nestedContainer.addView(toggle, toggleParams);
            LinearLayout.LayoutParams nestedParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            nestedParams.leftMargin = dp(62);
            nestedParams.rightMargin = dp(12);
            nestedParams.bottomMargin = dp(10);
            threadBody.addView(nestedContainer, nestedParams);
            wrapper.addView(threadBody);
            binding.commentsContainer.addView(wrapper);
        }

        void addReply(MaterialCardView card) {
            repliesContainer.addView(card);
            replyCount++;
        }

        void refresh() {
            boolean expanded = expandedCommentThreads.contains(rootId);
            repliesContainer.setVisibility(replyCount > 0 ? View.VISIBLE : View.GONE);
            for (int index = 0; index < repliesContainer.getChildCount(); index++) {
                repliesContainer.getChildAt(index).setVisibility(
                    ForumCommentPreviewPolicy.isReplyVisible(expanded, index)
                        ? View.VISIBLE : View.GONE);
            }
            nestedContainer.setVisibility(replyCount > 0 ? View.VISIBLE : View.GONE);
            toggle.setVisibility(ForumCommentPreviewPolicy.showsToggle(replyCount)
                ? View.VISIBLE : View.GONE);
            toggleText.setText(ForumCommentPreviewPolicy.toggleLabel(expanded, replyCount));
            toggleIcon.setRotation(expanded ? -90f : 90f);
            toggle.setContentDescription(toggleText.getText());
        }
    }

    private GradientDrawable commentThreadBackground(int fillColor, int strokeColor, int radiusDp) {
        GradientDrawable background = new GradientDrawable();
        background.setColor(getColor(fillColor));
        background.setCornerRadius(dp(radiusDp));
        background.setStroke(dp(1), getColor(strokeColor));
        return background;
    }

    private void focusCommentCard(View card, String message) {
        binding.scroll.post(() -> {
            if (!isUiActive()) return;
            Rect target = new Rect();
            card.getDrawingRect(target);
            binding.commentsContainer.offsetDescendantRectToMyCoords(card, target);
            binding.scroll.smoothScrollTo(0,
                Math.max(0, binding.commentsContainer.getTop() + target.top - dp(12)));
            card.setAlpha(0.45f);
            card.animate().alpha(1f).setDuration(520L).start();
            Snackbar.make(binding.getRoot(), message, Snackbar.LENGTH_SHORT).show();
        });
    }

    private MaterialButton commentButton(String label, int fallbackIcon, String description,
                                         View.OnClickListener listener) {
        MaterialButton button = new MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(label);
        button.setTextSize(11);
        button.setAllCaps(false);
        button.setMinWidth(0);
        button.setMinimumWidth(0);
        button.setMinHeight(0);
        button.setMinimumHeight(0);
        button.setInsetTop(0);
        button.setInsetBottom(0);
        button.setPadding(dp(6), 0, dp(7), 0);
        button.setCornerRadius(dp(14));
        button.setStrokeWidth(0);
        button.setBackgroundTintList(ColorStateList.valueOf(getColor(R.color.surface_container_high)));
        button.setTextColor(getColor(R.color.on_surface_variant));
        button.setIconResource(ActionIconResolver.resolve(label, fallbackIcon));
        button.setIconTint(ColorStateList.valueOf(getColor(R.color.on_surface_variant)));
        button.setIconSize(dp(16));
        button.setIconPadding(label.isEmpty() ? 0 : dp(3));
        if (label.isEmpty()) button.setIconGravity(MaterialButton.ICON_GRAVITY_TEXT_START);
        button.setContentDescription(description);
        button.setOnClickListener(listener);
        return button;
    }

    private void beginReply(JsonObject comment) {
        if (!isUiActive()) return;
        replyToCommentId = Jsons.longValue(comment, "id");
        String nickname = Jsons.string(comment, "nickname");
        if (nickname.isEmpty()) nickname = "用户 " + Jsons.longValue(comment, "user_id");
        binding.commentInput.setHint("回复 " + nickname);
        comment();
    }

    private void showCommentActions(JsonObject comment) {
        if (!isUiActive()) return;
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action("回复", R.drawable.ic_reply, () -> beginReply(comment)));
        actions.add(new GlassActionDialog.Action(bool(comment, "liked") ? "取消点赞" : "点赞", R.drawable.ic_like,
            () -> commentInteraction(comment, "like")));
        actions.add(new GlassActionDialog.Action(bool(comment, "favorited") ? "取消收藏" : "收藏", R.drawable.ic_favorite,
            () -> commentInteraction(comment, "favorite")));
        if (AppAccess.from(this).session().actorId() == Jsons.longValue(post, "user_id")) {
            actions.add(new GlassActionDialog.Action(bool(comment, "is_pinned") ? "取消置顶" : "置顶", R.drawable.ic_more,
                () -> pinComment(comment)));
        }
        actions.add(new GlassActionDialog.Action("举报", R.drawable.ic_more, () -> reportComment(comment)));
        GlassActionDialog.show(this, "评论操作", actions);
    }

    private void commentInteraction(JsonObject comment, String action) {
        if (!isUiActive() || actionRequest != null) return;
        long commentId = Jsons.longValue(comment, "id");
        String path = "/api/user/forum-content/comment/" + commentId + "/" + action;
        actionRequest = AppAccess.from(this).repository().post(path, new JsonObject(), result -> {
            actionRequest = null;
            if (!isUiActive()) return;
            if (!result.isSuccessful()) Snackbar.make(binding.getRoot(), result.message(), Snackbar.LENGTH_LONG).show();
            else load();
        });
    }

    private void pinComment(JsonObject comment) {
        if (!isUiActive() || actionRequest != null) return;
        JsonObject body = new JsonObject();
        body.addProperty("enabled", !bool(comment, "is_pinned"));
        body.addProperty("sort_order", 0);
        actionRequest = AppAccess.from(this).repository().put(
            "/api/user/forum-posts/" + postId + "/comments/" + Jsons.longValue(comment, "id") + "/pin", body, result -> {
                actionRequest = null;
                if (!isUiActive()) return;
                if (!result.isSuccessful()) Snackbar.make(binding.getRoot(), result.message(), Snackbar.LENGTH_LONG).show();
                else load();
            });
    }

    private void reportComment(JsonObject comment) {
        if (!isUiActive()) return;
        ContentReportDialog.show(this, "comment", Jsons.longValue(comment, "id"), "这条评论");
    }

    private void confirmPurchase(String path, String price) {
        if (!isUiActive()) return;
        new YiyunyingDialogBuilder(this).setTitle("确认解锁")
            .setMessage("本次将从余额中支付 " + price + "。购买成功后当前账号可持续查看该内容。")
            .setPositiveButton("确认支付", (dialog, which) -> purchase(path))
            .setNegativeButton("取消", null).show();
    }

    private void purchase(String path) {
        if (!isUiActive() || actionRequest != null) return;
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post(path, new JsonObject(), result -> {
            actionRequest = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            Snackbar.make(binding.getRoot(), result.isSuccessful() ? "支付成功，内容已解锁" : result.message(), Snackbar.LENGTH_LONG).show();
            if (result.isSuccessful()) load();
        });
    }

    private void openAuthor(JsonObject value) {
        if (!isUiActive()) return;
        long userId = Jsons.longValue(value, "user_id");
        if (userId <= 0) return;
        if (role == Role.USER) UserProfileActivity.open(this, userId);
        else RecordDetailDialog.show(this, "帖子作者资料", value);
    }

    private void toggle(String action) {
        if (!isUiActive() || actionRequest != null) return;
        String path = "/api/user/forum-posts/" + postId + ("like".equals(action) ? "/like" : "/favorite");
        binding.progress.setVisibility(View.VISIBLE);
        actionRequest = AppAccess.from(this).repository().post(path, new JsonObject(), result -> {
            actionRequest = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "操作失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            load();
        });
    }

    private void comment() {
        if (!isUiActive()) return;
        binding.commentInput.requestFocus();
        binding.scroll.post(() -> {
            if (isUiActive()) binding.scroll.fullScroll(View.FOCUS_DOWN);
        });
        InputMethodManager keyboard = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
        if (keyboard != null) keyboard.showSoftInput(binding.commentInput, InputMethodManager.SHOW_IMPLICIT);
    }

    private void showCommentAttachmentMenu() {
        if (!isUiActive()) return;
        hideCommentEmojiPanel();
        List<GlassActionDialog.Action> actions = new ArrayList<>();
        actions.add(new GlassActionDialog.Action("图片", R.drawable.ic_album, () -> pickComment("image", "image/*")));
        actions.add(new GlassActionDialog.Action("视频", R.drawable.ic_video, () -> pickComment("video", "video/*")));
        actions.add(new GlassActionDialog.Action("录制语音", R.drawable.ic_mic, this::toggleCommentVoiceRecording));
        actions.add(new GlassActionDialog.Action("音频、文档与其他文件", R.drawable.ic_file,
            () -> pickComment("file", "*/*")));
        if (!commentAttachments.isEmpty()) {
            actions.add(new GlassActionDialog.Action("清空", R.drawable.ic_close, () -> {
                clearCommentAttachments();
                renderCommentAttachments();
            }));
        }
        GlassActionDialog.show(this, "添加评论附件", actions);
    }

    private void pickComment(String type, String mime) {
        if (!isUiActive()) return;
        commentPickerType = type;
        if ("image".equals(type)) commentPicker.launch(MediaPickerActivity.imageIntent(this, 20));
        else if ("video".equals(type)) commentPicker.launch(MediaPickerActivity.videoIntent(this, 20));
        else commentPicker.launch(FilePickerActivity.pickerIntent(this, 20));
    }

    private void selectedCommentFiles(ActivityResult result) {
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
        selectedCommentFiles(commentPickerType, uris);
    }

    private void selectedCommentFiles(String type, List<Uri> uris) {
        if (!isUiActive() || uris == null) return;
        for (Uri uri : uris) {
            if (uri == null || commentAttachments.size() >= 20) continue;
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
            String effectiveType = "file".equals(type)
                ? (mime.startsWith("image/") ? "image" : (mime.startsWith("video/") ? "video" : (mime.startsWith("audio/") ? "audio" : "file")))
                : type;
            if (!UploadPolicyStore.accepts(this, effectiveType, size)) {
                Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, effectiveType, size), Snackbar.LENGTH_LONG).show();
                continue;
            }
            commentAttachments.add(new CommentAttachment(uri, effectiveType, name, mime, size));
        }
        renderCommentAttachments();
    }

    private void renderCommentAttachments() {
        if (!isUiActive()) return;
        binding.commentPendingContainer.removeAllViews();
        binding.commentPendingScroll.setVisibility(commentAttachments.isEmpty() ? View.GONE : View.VISIBLE);
        for (int index = 0; index < commentAttachments.size(); index++) {
            CommentAttachment attachment = commentAttachments.get(index);
            Chip chip = new Chip(this);
            chip.setText(attachment.label());
            chip.setChipIconResource(attachment.icon());
            chip.setChipIconVisible(true);
            chip.setEnsureMinTouchTargetSize(true);
            chip.setCloseIconVisible(true);
            int target = index;
            chip.setOnCloseIconClickListener(view -> {
                if (target < commentAttachments.size()) {
                    CommentAttachment removed = commentAttachments.remove(target);
                    removed.deleteTemporary();
                }
                renderCommentAttachments();
            });
            binding.commentPendingContainer.addView(chip);
        }
    }

    private void toggleCommentVoiceRecording() {
        if (!isUiActive() || commentRequest != null || commentUploadRequest != null) return;
        if (commentVoiceRecorder != null && commentVoiceRecorder.isRecording()) {
            finishCommentVoiceRecording();
            return;
        }
        if (commentAttachments.size() >= 20) {
            Snackbar.make(binding.getRoot(), "评论附件最多 20 个", Snackbar.LENGTH_LONG).show();
            return;
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
            != PackageManager.PERMISSION_GRANTED) {
            commentVoicePermission.launch(Manifest.permission.RECORD_AUDIO);
            return;
        }
        startCommentVoiceRecording();
    }

    private void startCommentVoiceRecording() {
        if (!isUiActive()) return;
        if (commentVoiceRecorder == null) commentVoiceRecorder = new CommentVoiceRecorder(this);
        hideCommentEmojiPanel();
        binding.commentInput.clearFocus();
        InputMethodManager manager = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
        if (manager != null) manager.hideSoftInputFromWindow(binding.commentInput.getWindowToken(), 0);
        try {
            commentVoiceRecorder.start(new CommentVoiceRecorder.Listener() {
                @Override public void onTick(long elapsedMs) {
                    if (isUiActive()) updateCommentVoiceUi(true, elapsedMs);
                }

                @Override public void onLimitReached() {
                    if (isUiActive()) finishCommentVoiceRecording();
                }
            });
            updateCommentVoiceUi(true, 0L);
        } catch (Exception exception) {
            updateCommentVoiceUi(false, 0L);
            Snackbar.make(binding.getRoot(), "无法启动录音，请检查麦克风是否被其他应用占用", Snackbar.LENGTH_LONG).show();
        }
    }

    private void finishCommentVoiceRecording() {
        if (!isUiActive() || commentVoiceRecorder == null || !commentVoiceRecorder.isRecording()) return;
        CommentVoiceRecorder.Result result = commentVoiceRecorder.stop();
        updateCommentVoiceUi(false, 0L);
        if (result == null) {
            Snackbar.make(binding.getRoot(), "录音时间太短，请重新录制", Snackbar.LENGTH_SHORT).show();
            return;
        }
        if (!UploadPolicyStore.accepts(this, "audio", result.sizeBytes)) {
            Snackbar.make(binding.getRoot(), UploadPolicyStore.rejectionMessage(this, "audio", result.sizeBytes),
                Snackbar.LENGTH_LONG).show();
            result.delete();
            return;
        }
        commentAttachments.add(CommentAttachment.recordedVoice(result));
        renderCommentAttachments();
    }

    private void cancelCommentVoiceRecording() {
        if (commentVoiceRecorder != null) commentVoiceRecorder.cancel();
        if (isUiActive()) updateCommentVoiceUi(false, 0L);
    }

    private void updateCommentVoiceUi(boolean recording, long elapsedMs) {
        if (!isUiActive()) return;
        binding.commentVoiceStatusCard.setVisibility(recording ? View.VISIBLE : View.GONE);
        binding.commentVoiceStatusTitle.setText(recording ? "正在录音" : "");
        binding.commentVoiceStatus.setText(recording
            ? formatVoiceDuration(elapsedMs) + " · 点击麦克风完成"
            : "");
        binding.commentVoiceStatusIcon.setImageResource(recording ? R.drawable.ic_mic_off : R.drawable.ic_mic);
        binding.commentVoiceIcon.setImageResource(recording ? R.drawable.ic_mic_off : R.drawable.ic_mic);
        binding.commentVoiceButton.setSelected(recording);
        binding.commentVoiceCancelButton.setEnabled(recording);
        binding.commentVoiceButton.setContentDescription(recording ? "结束语音录制" : "录制语音评论");
    }

    private String formatVoiceDuration(long durationMs) {
        long totalSeconds = Math.max(0L, durationMs / 1000L);
        return String.format(Locale.CHINA, "%02d:%02d", totalSeconds / 60L, totalSeconds % 60L);
    }

    private void clearCommentAttachments() {
        for (CommentAttachment attachment : commentAttachments) attachment.deleteTemporary();
        commentAttachments.clear();
    }

    private void showEmojiPicker() {
        if (!isUiActive()) return;
        boolean opening = binding.commentEmojiScroll.getVisibility() != View.VISIBLE;
        binding.commentEmojiScroll.setVisibility(opening ? View.VISIBLE : View.GONE);
        binding.commentEmojiButton.setSelected(opening);
        if (opening) {
            binding.commentInput.clearFocus();
            InputMethodManager manager = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (manager != null) manager.hideSoftInputFromWindow(binding.commentInput.getWindowToken(), 0);
        }
    }

    private void configureCommentEmojiPanel() {
        if (!isUiActive()) return;
        String[] emojis = xyz.jjmxg.yiyunying.ui.common.EmojiCatalog.values();
        binding.commentEmojiGrid.removeAllViews();
        for (String emoji : emojis) {
            TextView item = new TextView(this);
            item.setText(emoji);
            item.setTextSize(22);
            item.setGravity(Gravity.CENTER);
            item.setContentDescription("插入 " + emoji);
            GridLayout.LayoutParams params = new GridLayout.LayoutParams();
            params.width = dp(48);
            params.height = dp(44);
            params.setMargins(dp(2), dp(2), dp(2), dp(2));
            item.setLayoutParams(params);
            item.setOnClickListener(view -> {
                int start = Math.max(0, binding.commentInput.getSelectionStart());
                binding.commentInput.getText().insert(start, emoji);
            });
            binding.commentEmojiGrid.addView(item);
        }
        binding.commentInput.setOnFocusChangeListener((view, hasFocus) -> {
            if (hasFocus) hideCommentEmojiPanel();
        });
    }

    private void hideCommentEmojiPanel() {
        if (!isUiActive()) return;
        binding.commentEmojiScroll.setVisibility(View.GONE);
        binding.commentEmojiButton.setSelected(false);
    }

    private void sendInlineComment() {
        if (!isUiActive() || commentRequest != null || commentUploadRequest != null) return;
        if (commentVoiceRecorder != null && commentVoiceRecorder.isRecording()) {
            finishCommentVoiceRecording();
            Snackbar.make(binding.getRoot(), "录音已完成，请确认后再次发送", Snackbar.LENGTH_SHORT).show();
            return;
        }
        String content = binding.commentInput.getText() == null ? "" : binding.commentInput.getText().toString().trim();
        if (content.isEmpty() && commentAttachments.isEmpty()) {
            binding.commentInput.setError("请输入评论或添加附件");
            return;
        }
        setCommentComposerEnabled(false);
        uploadCommentAttachments(content, 0, new JsonArray());
    }

    private void uploadCommentAttachments(String content, int index, JsonArray media) {
        if (!isUiActive()) return;
        if (index >= commentAttachments.size()) { postInlineComment(content, media); return; }
        CommentAttachment item = commentAttachments.get(index);
        ContentUriRequestBody body = new ContentUriRequestBody(getContentResolver(), item.uri, item.mime, item.size);
        Map<String, String> fields = new LinkedHashMap<>(); fields.put("scene", "论坛评论");
        binding.progress.setVisibility(View.VISIBLE);
        commentUploadRequest = AppAccess.from(this).repository().upload("/api/user/uploads", item.name, item.mime, body, fields, result -> {
            commentUploadRequest = null;
            if (!isUiActive()) return;
            if (!result.isSuccessful()) {
                binding.progress.setVisibility(View.INVISIBLE);
                setCommentComposerEnabled(true);
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "评论附件上传失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            JsonObject value = new JsonObject();
            value.addProperty("media_type", item.type);
            value.addProperty("upload_id", Jsons.longValue(result.dataObject(), "upload_id"));
            value.addProperty("file_name", item.name);
            value.addProperty("mime_type", item.mime);
            if (item.size > 0) value.addProperty("size_bytes", item.size);
            if (item.durationMs > 0L) value.addProperty("duration_ms", item.durationMs);
            if (item.recordedVoice != null) {
                JsonObject metadata = new JsonObject();
                metadata.addProperty("audio_kind", "voice");
                JsonArray waveform = new JsonArray();
                for (Integer amplitude : item.recordedVoice.waveform) waveform.add(amplitude);
                metadata.add("waveform", waveform);
                value.add("metadata", metadata);
            }
            media.add(value);
            uploadCommentAttachments(content, index + 1, media);
        });
    }

    private void postInlineComment(String content, JsonArray media) {
        if (!isUiActive()) return;
        JsonObject body = new JsonObject(); body.addProperty("content", content); body.add("attachments", media);
        if (!commentMentionIds.isEmpty()) {
            JsonArray mentions = new JsonArray();
            for (Long userId : commentMentionIds) mentions.add(userId);
            body.add("mentions", mentions);
        }
        if (replyToCommentId > 0) body.addProperty("parent_id", replyToCommentId);
        commentRequest = AppAccess.from(this).repository().post("/api/user/forum-posts/" + postId + "/comments", body, result -> {
            commentRequest = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            setCommentComposerEnabled(true);
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "评论发送失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            binding.commentInput.setText("");
            binding.commentInput.setHint(null);
            replyToCommentId = 0;
            commentMentionIds.clear();
            clearCommentAttachments();
            renderCommentAttachments();
            Snackbar.make(binding.getRoot(), "评论已发布", Snackbar.LENGTH_SHORT).show();
            load();
        });
    }

    private void showMentionTypePicker() {
        if (!isUiActive() || mentionRequest != null) return;
        new YiyunyingDialogBuilder(this)
            .setTitle("选择提醒对象")
            .setItems(new String[]{"好友", "群聊成员"}, (dialog, which) -> {
                if (which == 0) loadMentionFriends(); else loadMentionGroups();
            })
            .setNegativeButton("取消", null)
            .show();
    }

    private void loadMentionFriends() {
        if (!isUiActive() || mentionRequest != null) return;
        mentionRequest = AppAccess.from(this).repository().get("/api/user/friends", new LinkedHashMap<>(), result -> {
            mentionRequest = null;
            if (!isUiActive() || !result.isSuccessful()) return;
            showMentionUsers(result.objectItems(), "选择好友");
        });
    }

    private void loadMentionGroups() {
        if (!isUiActive() || mentionRequest != null) return;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "100");
        mentionRequest = AppAccess.from(this).repository().get("/api/user/chat-rooms", query, result -> {
            mentionRequest = null;
            if (!isUiActive() || !result.isSuccessful()) return;
            List<JsonObject> rooms = result.objectItems();
            String[] labels = new String[rooms.size()];
            for (int index = 0; index < rooms.size(); index++) labels[index] = Jsons.string(rooms.get(index), "name");
            if (labels.length == 0) return;
            new YiyunyingDialogBuilder(this).setTitle("选择群聊")
                .setItems(labels, (dialog, which) -> loadMentionGroupMembers(Jsons.longValue(rooms.get(which), "id")))
                .setNegativeButton("取消", null).show();
        });
    }

    private void loadMentionGroupMembers(long roomId) {
        if (!isUiActive() || roomId <= 0 || mentionRequest != null) return;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        query.put("limit", "200");
        mentionRequest = AppAccess.from(this).repository().get(
            "/api/user/chat-rooms/" + roomId + "/members", query, result -> {
                mentionRequest = null;
                if (!isUiActive() || !result.isSuccessful()) return;
                showMentionUsers(result.objectItems(), "选择群成员");
            });
    }

    private void showMentionUsers(List<JsonObject> users, String title) {
        if (!isUiActive()) return;
        List<JsonObject> valid = new ArrayList<>();
        List<String> labels = new ArrayList<>();
        long selfId = AppAccess.from(this).session().actorId();
        for (JsonObject user : users) {
            long userId = Jsons.longValue(user, "user_id");
            if (userId <= 0) userId = Jsons.longValue(user, "friend_user_id");
            if (userId <= 0 || userId == selfId) continue;
            JsonObject choice = user.deepCopy();
            choice.addProperty("resolved_user_id", userId);
            String name = first(user, "group_nickname", "remark", "nickname", "account");
            choice.addProperty("resolved_name", name);
            valid.add(choice);
            labels.add(name + "  ·  UID " + first(user, "uid", "account"));
        }
        if (valid.isEmpty()) return;
        new YiyunyingDialogBuilder(this).setTitle(title)
            .setItems(labels.toArray(new String[0]), (dialog, which) -> insertMention(valid.get(which)))
            .setNegativeButton("取消", null).show();
    }

    private void insertMention(JsonObject choice) {
        if (!isUiActive()) return;
        Editable editable = binding.commentInput.getText();
        if (editable == null) return;
        int cursor = Math.max(0, binding.commentInput.getSelectionStart());
        int at = editable.toString().lastIndexOf('@', Math.max(0, cursor - 1));
        if (at < 0) at = cursor;
        String value = "@" + Jsons.string(choice, "resolved_name") + " ";
        suppressMentionPicker = true;
        editable.replace(at, cursor, value);
        suppressMentionPicker = false;
        commentMentionIds.add(Jsons.longValue(choice, "resolved_user_id"));
        binding.commentInput.requestFocus();
        binding.commentInput.setSelection(Math.min(editable.length(), at + value.length()));
    }

    private void setCommentComposerEnabled(boolean enabled) {
        if (!isUiActive()) return;
        binding.commentInput.setEnabled(enabled);
        binding.commentAttachButton.setEnabled(enabled);
        binding.commentEmojiButton.setEnabled(enabled);
        binding.commentVoiceButton.setEnabled(enabled);
        binding.commentVoiceCancelButton.setEnabled(enabled && commentVoiceRecorder != null
            && commentVoiceRecorder.isRecording());
        binding.sendCommentButton.setEnabled(enabled);
    }

    private boolean bool(JsonObject object, String key) {
        try {
            if (!object.has(key) || object.get(key).isJsonNull()) return false;
            String value = object.get(key).getAsString();
            return "1".equals(value) || "true".equalsIgnoreCase(value) || "yes".equalsIgnoreCase(value);
        }
        catch (RuntimeException ignored) { return false; }
    }

    private String first(JsonObject object, String... keys) {
        for (String key : keys) {
            String value = Jsons.string(object, key);
            if (!value.trim().isEmpty()) return value.trim();
        }
        return "用户";
    }

    private double decimal(JsonObject object, String key) {
        try { return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsDouble() : 0d; }
        catch (RuntimeException ignored) { return 0d; }
    }

    private String money(double value) { return String.format(Locale.CHINA, "%.2f", value); }

    private String join(JsonArray values, String separator) {
        List<String> result = new ArrayList<>();
        for (JsonElement value : values) if (value.isJsonPrimitive()) result.add(value.getAsString());
        return String.join(separator, result);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    private void login() {
        AppAccess.from(this).session().clearAuthentication();
        startActivity(new Intent(this, LoginActivity.class).putExtra(LoginActivity.EXTRA_FORCE_LOGIN, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK));
        finish();
    }

    @Override protected void onResume() {
        super.onResume();
        resumed = true;
        lastAutomaticPrivateMediaExpiry = Long.MIN_VALUE;
        ForumPrivateMediaPolicy.Snapshot snapshot = privateMediaSnapshot();
        if (snapshot.refreshRequired()) refreshPrivateMedia(false);
        else schedulePrivateMediaRefresh();
    }

    @Override protected void onPause() {
        resumed = false;
        privateMediaHandler.removeCallbacks(privateMediaRefresh);
        super.onPause();
    }

    @Override protected void onDestroy() {
        privateMediaHandler.removeCallbacksAndMessages(null);
        if (request != null) request.cancel();
        if (actionRequest != null) actionRequest.cancel();
        if (commentRequest != null) commentRequest.cancel();
        if (commentUploadRequest != null) commentUploadRequest.cancel();
        if (mentionRequest != null) mentionRequest.cancel();
        cancelCommentVoiceRecording();
        if (commentVoiceRecorder != null) commentVoiceRecorder.release();
        clearCommentAttachments();
        binding = null;
        super.onDestroy();
    }

    private static final class CommentAttachment {
        final Uri uri; final String type; final String name; final String mime; final long size;
        final long durationMs; final CommentVoiceRecorder.Result recordedVoice;
        CommentAttachment(Uri uri, String type, String name, String mime, long size) {
            this(uri, type, name, mime, size, 0L, null);
        }
        private CommentAttachment(Uri uri, String type, String name, String mime, long size,
                                  long durationMs, CommentVoiceRecorder.Result recordedVoice) {
            this.uri = uri;
            this.type = type;
            this.name = name;
            this.mime = mime;
            this.size = size;
            this.durationMs = durationMs;
            this.recordedVoice = recordedVoice;
        }
        static CommentAttachment recordedVoice(CommentVoiceRecorder.Result result) {
            return new CommentAttachment(result.uri, "audio", result.file.getName(), "audio/mp4",
                result.sizeBytes, result.durationMs, result);
        }
        void deleteTemporary() {
            if (recordedVoice != null) recordedVoice.delete();
        }
        String label() {
            if (recordedVoice != null) return "语音 · " + formatDuration(durationMs) + " · " + size(size);
            String label = "image".equals(type) ? "图片" : ("video".equals(type) ? "视频" : ("audio".equals(type) ? "音频" : "文件"));
            return label + " · " + name + (size > 0 ? " · " + size(size) : "");
        }
        int icon() {
            return "image".equals(type) ? R.drawable.ic_album
                : ("video".equals(type) ? R.drawable.ic_video
                : ("audio".equals(type) ? R.drawable.ic_voice : R.drawable.ic_file));
        }
        private static String size(long bytes) {
            if (bytes < 1024L) return bytes + " B";
            if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
            return String.format(Locale.CHINA, "%.1f MB", bytes / 1024d / 1024d);
        }
        private static String formatDuration(long durationMs) {
            long seconds = Math.max(0L, durationMs / 1000L);
            return String.format(Locale.CHINA, "%02d:%02d", seconds / 60L, seconds % 60L);
        }
    }
}
