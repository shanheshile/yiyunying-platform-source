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
import java.util.List;
import java.util.Map;
import java.util.Locale;
import java.util.LinkedHashSet;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.databinding.ActivityForumPostBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;
import xyz.jjmxg.yiyunying.ui.common.CommentVoiceRecorder;
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
        if (!isUiActive()) return;
        if (request != null) request.cancel();
        binding.progress.setVisibility(View.VISIBLE);
        String path = role == Role.USER ? "/api/user/forum-posts/" + postId
            : "/api/" + role.wireName() + "/apps/" + appId + "/forum-posts/" + postId;
        LinkedHashMap<String, String> query = new LinkedHashMap<>();
        AppAccess.from(this).repository().getCached(path, query, cached -> {
            if (binding == null || isFinishing() || isDestroyed() || !cached.isSuccessful()) return;
            JsonObject cachedPost = Jsons.object(cached.dataObject(), "post");
            if (cachedPost.size() == 0) return;
            post = cachedPost;
            render();
            binding.progress.setVisibility(View.INVISIBLE);
        });
        request = AppAccess.from(this).repository().get(path, query, result -> {
            request = null;
            if (!isUiActive()) return;
            binding.progress.setVisibility(View.INVISIBLE);
            if (result.isAuthenticationFailure()) { login(); return; }
            if (!result.isSuccessful()) {
                Snackbar.make(binding.getRoot(), result.message().isEmpty() ? "帖子加载失败" : result.message(), Snackbar.LENGTH_LONG).show();
                return;
            }
            post = Jsons.object(result.dataObject(), "post");
            render();
        });
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
        MediaViewRenderer.render(this, binding.mediaContainer, Jsons.array(post, "attachments"));
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
            boolean paid = "paid".equals(Jsons.string(section, "section_type"));
            TextView heading = new TextView(this);
            heading.setText("第 " + number + " 节" + (title.isEmpty() ? "" : " · " + title)
                + (paid ? (locked ? "  付费内容" : "  已解锁") : "  免费"));
            heading.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleMedium);
            heading.setTextColor(paid ? xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(this)
                : getColor(R.color.on_surface));
            body.addView(heading);
            if (locked) {
                TextView hint = new TextView(this);
                hint.setText("本节正文、标签和附件已隐藏，支付 " + money(decimal(section, "price_balance")) + " 余额后仅当前账号可查看。");
                hint.setTextColor(getColor(R.color.on_surface_variant));
                hint.setTextSize(14);
                LinearLayout.LayoutParams hintParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
                hintParams.topMargin = dp(8);
                body.addView(hint, hintParams);
                MaterialButton unlock = new MaterialButton(this);
                unlock.setText("支付并查看 · " + money(decimal(section, "price_balance")));
                long sectionId = Jsons.longValue(section, "id");
                unlock.setOnClickListener(view -> confirmPurchase(
                    "/api/user/forum-posts/" + postId + "/sections/" + sectionId + "/buy",
                    money(decimal(section, "price_balance"))));
                LinearLayout.LayoutParams unlockParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(48));
                unlockParams.topMargin = dp(8);
                body.addView(unlock, unlockParams);
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
                MediaViewRenderer.render(this, media, Jsons.array(section, "attachments"));
            }
            card.addView(body);
            binding.sectionsContainer.addView(card);
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
            cardParams.bottomMargin = dp(6);
            if (parentCommentId > 0) {
                cardParams.leftMargin = dp(18);
                cardParams.rightMargin = dp(4);
            }
            card.setLayoutParams(cardParams);
            card.setRadius(dp(6));
            card.setCardElevation(0);
            card.setCardBackgroundColor(getColor(R.color.surface_container));

            LinearLayout row = new LinearLayout(this);
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.TOP);
            row.setPadding(dp(12), dp(12), dp(12), dp(12));
            ImageView avatar = new ImageView(this);
            avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
            ImageLoader.get().load(ImageLoader.get().absoluteUrl(this, Jsons.string(comment, "avatar")), avatar, R.drawable.ic_person);
            row.addView(avatar, new LinearLayout.LayoutParams(dp(42), dp(42)));

            LinearLayout content = new LinearLayout(this);
            content.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams contentParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            contentParams.leftMargin = dp(10);
            TextView name = new TextView(this);
            String nickname = Jsons.string(comment, "nickname");
            name.setText(
                nickname.isEmpty() ? RuntimeLanguage.translate(this, "用户 ").toString()
                    + Jsons.longValue(comment, "user_id") : nickname);
            name.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleSmall);
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
                replyTarget.setTextSize(13);
                replyTarget.setPadding(dp(4), dp(4), dp(6), dp(4));
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
            text.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_BodyLarge);
            LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            textParams.topMargin = dp(4);
            content.addView(text, textParams);
            LinearLayout media = new LinearLayout(this);
            media.setOrientation(LinearLayout.VERTICAL);
            content.addView(media, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            MediaViewRenderer.render(this, media, Jsons.array(comment, "attachments"));
            TextView time = new TextView(this);
            time.setText((bool(comment, "is_pinned") ? "作者置顶 · " : "") + Jsons.string(comment, "created_at"));
            time.setTextColor(getColor(R.color.outline));
            time.setTextSize(12);
            content.addView(time);
            LinearLayout actions = new LinearLayout(this);
            actions.setOrientation(LinearLayout.HORIZONTAL);
            actions.addView(commentButton("回复", view -> beginReply(comment)));
            actions.addView(commentButton((bool(comment, "liked") ? "已赞 " : "点赞 ") + Jsons.longValue(comment, "like_count"),
                view -> commentInteraction(comment, "like")));
            actions.addView(commentButton(bool(comment, "favorited") ? "已收藏" : "收藏",
                view -> commentInteraction(comment, "favorite")));
            content.addView(actions, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(40)));
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
                thread.addReply(card, comment);
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
        final LinearLayout previewContainer;
        final LinearLayout repliesContainer;
        final LinearLayout toggle;
        final TextView toggleText;
        final ImageView toggleIcon;
        int replyCount;

        CommentThreadView(long rootId) {
            this.rootId = rootId;
            LinearLayout wrapper = new LinearLayout(ForumPostActivity.this);
            wrapper.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams wrapperParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT);
            wrapperParams.bottomMargin = dp(12);
            wrapper.setLayoutParams(wrapperParams);

            rootContainer = new LinearLayout(ForumPostActivity.this);
            rootContainer.setOrientation(LinearLayout.VERTICAL);
            previewContainer = new LinearLayout(ForumPostActivity.this);
            previewContainer.setOrientation(LinearLayout.VERTICAL);
            previewContainer.setPadding(dp(8), dp(5), dp(8), dp(5));
            previewContainer.setBackground(commentThreadBackground(
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
            toggle.setPadding(dp(12), 0, dp(12), 0);
            toggle.setBackground(commentThreadBackground(
                R.color.surface_container_high,
                R.color.outline_variant,
                8));
            toggleText = new TextView(ForumPostActivity.this);
            toggleText.setTextSize(13);
            toggleText.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
            toggleText.setTextColor(xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(ForumPostActivity.this));
            toggleIcon = new ImageView(ForumPostActivity.this);
            toggleIcon.setImageResource(R.drawable.ic_chevron_right);
            toggleIcon.setImageTintList(ColorStateList.valueOf(
                xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(ForumPostActivity.this)));
            toggle.addView(toggleText, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(18), dp(18));
            iconParams.leftMargin = dp(4);
            toggle.addView(toggleIcon, iconParams);
            toggle.setOnClickListener(view -> {
                if (expandedCommentThreads.contains(rootId)) expandedCommentThreads.remove(rootId);
                else expandedCommentThreads.add(rootId);
                refresh();
            });

            wrapper.addView(rootContainer, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            LinearLayout.LayoutParams previewParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            previewParams.leftMargin = dp(62);
            previewParams.rightMargin = dp(12);
            wrapper.addView(previewContainer, previewParams);
            LinearLayout.LayoutParams toggleParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(44));
            toggleParams.leftMargin = dp(62);
            toggleParams.rightMargin = dp(12);
            toggleParams.topMargin = dp(6);
            wrapper.addView(toggle, toggleParams);
            wrapper.addView(repliesContainer, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            binding.commentsContainer.addView(wrapper);
        }

        void addReply(MaterialCardView card, JsonObject comment) {
            repliesContainer.addView(card);
            int replyIndex = replyCount++;
            if (!ForumCommentPreviewPolicy.includesPreview(replyIndex)) return;
            if (previewContainer.getChildCount() > 0) {
                View divider = new View(ForumPostActivity.this);
                divider.setBackgroundColor(getColor(R.color.outline_variant));
                previewContainer.addView(divider, new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, dp(1)));
            }
            TextView preview = new TextView(ForumPostActivity.this);
            String author = Jsons.string(comment, "nickname");
            if (author.isEmpty()) author = "用户 " + Jsons.longValue(comment, "user_id");
            String target = Jsons.string(comment, "reply_to_name");
            String prefix = author + (target.isEmpty() ? "" : " 回复 " + target) + "：";
            android.text.SpannableStringBuilder summary = new android.text.SpannableStringBuilder(
                prefix + commentPreviewContent(comment));
            summary.setSpan(new android.text.style.StyleSpan(Typeface.BOLD), 0, author.length(),
                android.text.Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
            summary.setSpan(new android.text.style.ForegroundColorSpan(
                    xyz.jjmxg.yiyunying.ui.common.ThemeColors.primary(ForumPostActivity.this)),
                0, author.length(), android.text.Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
            preview.setText(summary);
            preview.setTextColor(getColor(R.color.on_surface_variant));
            preview.setTextSize(13);
            preview.setSingleLine(true);
            preview.setEllipsize(android.text.TextUtils.TruncateAt.END);
            preview.setGravity(Gravity.CENTER_VERTICAL);
            preview.setPadding(dp(4), dp(3), dp(4), dp(3));
            preview.setOnClickListener(view -> {
                expandedCommentThreads.add(rootId);
                refresh();
                repliesContainer.post(() -> focusCommentCard(card, "已展开并定位到这条回复"));
            });
            previewContainer.addView(preview, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(36)));
        }

        void refresh() {
            boolean expanded = expandedCommentThreads.contains(rootId);
            previewContainer.setVisibility(replyCount > 0 && !expanded ? View.VISIBLE : View.GONE);
            repliesContainer.setVisibility(replyCount > 0 && expanded ? View.VISIBLE : View.GONE);
            toggle.setVisibility(replyCount > 0 ? View.VISIBLE : View.GONE);
            toggleText.setText(ForumCommentPreviewPolicy.toggleLabel(expanded, replyCount));
            toggleIcon.setRotation(expanded ? -90f : 90f);
            toggle.setContentDescription(toggleText.getText());
        }
    }

    private String commentPreviewContent(JsonObject comment) {
        String content = Jsons.string(comment, "content").trim().replace('\n', ' ');
        if (!content.isEmpty()) return content;
        JsonArray attachments = Jsons.array(comment, "attachments");
        if (attachments.isEmpty()) return "[空回复]";
        JsonObject first = attachments.get(0).isJsonObject()
            ? attachments.get(0).getAsJsonObject() : new JsonObject();
        String label = ForumCommentPreviewPolicy.attachmentLabel(
            Jsons.string(first, "media_type"), Jsons.string(first, "mime_type"));
        return attachments.size() > 1
            ? "[" + label + "等 " + attachments.size() + " 项]"
            : "[" + label + "]";
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

    private MaterialButton commentButton(String label, View.OnClickListener listener) {
        MaterialButton button = new MaterialButton(this, null, com.google.android.material.R.attr.materialButtonOutlinedStyle);
        button.setText(label);
        button.setTextSize(12);
        button.setMinWidth(0);
        button.setInsetTop(0);
        button.setInsetBottom(0);
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
        actions.add(new GlassActionDialog.Action("回复", R.drawable.ic_chat, () -> beginReply(comment)));
        actions.add(new GlassActionDialog.Action(bool(comment, "liked") ? "取消点赞" : "点赞", R.drawable.ic_content,
            () -> commentInteraction(comment, "like")));
        actions.add(new GlassActionDialog.Action(bool(comment, "favorited") ? "取消收藏" : "收藏", R.drawable.ic_file,
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

    @Override protected void onDestroy() {
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
