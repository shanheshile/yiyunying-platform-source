package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.text.TextUtils;
import android.view.Gravity;
import android.view.GestureDetector;
import android.view.LayoutInflater;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewConfiguration;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.core.graphics.ColorUtils;
import androidx.recyclerview.widget.DiffUtil;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.card.MaterialCardView;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.HashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.databinding.ItemChatMessageBinding;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.ImageLoader;
import xyz.jjmxg.yiyunying.ui.common.ThemeColors;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;
import xyz.jjmxg.yiyunying.ui.upload.FilePreviewActivity;
import xyz.jjmxg.yiyunying.ui.chat.ForwardSnapshotActivity;

public final class ChatAdapter extends RecyclerView.Adapter<ChatAdapter.Holder> {
    public interface Listener {
        void onLongPress(JsonObject message);
        default void onSelectionChanged(JsonObject message, boolean selected) { }
        default void onAvatarClick(JsonObject message) { }
        default void onAttachmentClick(JsonObject message, JsonObject attachment) { }
        default void onEditHistory(JsonObject message) { }
        default void onDeleteSystem(JsonObject message) { }
        default void onReplyClick(long messageId) { }
        default void onMessageHeightWillChange() { }
    }

    private final List<JsonObject> items = new ArrayList<>();
    private final Set<Long> expandedImages = new HashSet<>();
    private final Set<Long> collapsedTranscripts = new HashSet<>();
    private final Map<Long, Integer> stackedPositions = new HashMap<>();
    private final Set<Long> selectedIds = new LinkedHashSet<>();
    private final long actorId;
    private final Role role;
    private final Listener listener;
    private boolean selectionMode;
    private long managedAppId;

    public ChatAdapter(long actorId, Role role, Listener listener) {
        this.actorId = actorId;
        this.role = role;
        this.listener = listener;
        setHasStableIds(true);
    }

    public void submit(List<JsonObject> messages) {
        List<JsonObject> previous = new ArrayList<>(items);
        List<JsonObject> next = new ArrayList<>(messages);
        List<Integer> changedPositions = changedPositionsForSameOrder(previous, next);
        if (changedPositions != null) {
            if (changedPositions.isEmpty()) return;
            items.clear();
            items.addAll(next);
            if (changedPositions.size() > 24) {
                notifyItemRangeChanged(0, items.size());
            } else {
                for (int position : changedPositions) notifyItemChanged(position);
            }
            return;
        }
        if (samePrefix(previous, next)) {
            int inserted = next.size() - previous.size();
            items.addAll(next.subList(previous.size(), next.size()));
            if (inserted > 0) notifyItemRangeInserted(previous.size(), inserted);
            return;
        }
        if (previous.size() + next.size() > 600) {
            items.clear();
            items.addAll(next);
            notifyDataSetChanged();
            return;
        }
        DiffUtil.DiffResult diff = DiffUtil.calculateDiff(new DiffUtil.Callback() {
            @Override public int getOldListSize() { return previous.size(); }
            @Override public int getNewListSize() { return next.size(); }
            @Override public boolean areItemsTheSame(int oldPosition, int newPosition) {
                return itemId(previous.get(oldPosition)) == itemId(next.get(newPosition));
            }
            @Override public boolean areContentsTheSame(int oldPosition, int newPosition) {
                return previous.get(oldPosition).equals(next.get(newPosition));
            }
        }, false);
        items.clear();
        items.addAll(next);
        diff.dispatchUpdatesTo(this);
    }

    @Nullable
    private static List<Integer> changedPositionsForSameOrder(List<JsonObject> left, List<JsonObject> right) {
        if (left.size() != right.size()) return null;
        List<Integer> changed = new ArrayList<>();
        for (int index = 0; index < left.size(); index++) {
            if (itemId(left.get(index)) != itemId(right.get(index))) return null;
            if (!left.get(index).equals(right.get(index))) changed.add(index);
        }
        return changed;
    }

    private static boolean samePrefix(List<JsonObject> prefix, List<JsonObject> full) {
        if (prefix.size() > full.size()) return false;
        for (int index = 0; index < prefix.size(); index++) {
            if (itemId(prefix.get(index)) != itemId(full.get(index))
                || !prefix.get(index).equals(full.get(index))) return false;
        }
        return true;
    }

    public void setSelectionMode(boolean enabled, Set<Long> selected) {
        selectionMode = enabled;
        selectedIds.clear();
        if (selected != null) selectedIds.addAll(selected);
        notifyDataSetChanged();
    }


    public void setManagedAppId(long appId) {
        managedAppId = Math.max(0, appId);
    }

    public List<JsonObject> messages() { return new ArrayList<>(items); }

    public int positionOf(long messageId) {
        for (int index = 0; index < items.size(); index++) if (itemId(items.get(index)) == messageId) return index;
        return -1;
    }

    public void expandTranscript(long messageId) {
        if (messageId <= 0) return;
        collapsedTranscripts.remove(messageId);
        int position = positionOf(messageId);
        if (position >= 0) notifyItemChanged(position);
    }

    @Override public long getItemId(int position) {
        return itemId(items.get(position));
    }

    @NonNull
    @Override public Holder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        return new Holder(ItemChatMessageBinding.inflate(LayoutInflater.from(parent.getContext()), parent, false));
    }

    @Override public void onBindViewHolder(@NonNull Holder holder, int position) {
        JsonObject item = items.get(position);
        boolean mine = isMine(item);
        boolean system = "system".equals(Jsons.string(item, "sender_type"));
        boolean recalled = "recall".equals(Jsons.string(item, "content_type")) || booleanValue(item, "is_recalled");
        boolean selectable = !system && !recalled;
        holder.binding.messageRow.setGravity(system ? Gravity.CENTER_HORIZONTAL : (mine ? Gravity.END | Gravity.TOP : Gravity.START | Gravity.TOP));
        holder.binding.messageColumn.setGravity(system ? Gravity.CENTER_HORIZONTAL : (mine ? Gravity.END : Gravity.START));
        boolean searchMatch = booleanValue(item, "is_search_match");
        boolean searchContext = booleanValue(item, "search_context");
        holder.itemView.setAlpha(searchContext && !searchMatch ? 0.72f : 1f);
        holder.binding.bubble.setStrokeWidth(searchMatch ? dp(holder.itemView.getContext(), 2) : 0);
        holder.binding.bubble.setStrokeColor(ThemeColors.primary(holder.itemView.getContext()));
        holder.binding.bubble.setRadius(dp(holder.itemView.getContext(), 8));
        String sender = Jsons.string(item, "sender_name");
        if (sender.isEmpty()) sender = Jsons.string(item, "nickname");
        if (sender.isEmpty()) sender = Jsons.string(item, "account");
        if (sender.isEmpty()) sender = mine ? "我" : senderName(Jsons.string(item, "sender_type"));
        holder.binding.sender.setText(sender);
        holder.binding.senderHeader.setGravity(system ? Gravity.CENTER_HORIZONTAL : (mine ? Gravity.END : Gravity.START));
        bindSenderBadge(holder, item);
        String content = Jsons.string(item, "content");
        boolean forwarded = Jsons.longValue(item, "forward_bundle_id") > 0 || !Jsons.object(item, "forward_bundle").entrySet().isEmpty();
        boolean hasReply = Jsons.longValue(item, "reply_to_message_id") > 0
            && !"recall".equals(Jsons.string(item, "content_type"));
        boolean attachmentOnly = content.isEmpty() && !Jsons.array(item, "attachments").isEmpty() && !forwarded && !hasReply;
        boolean borderless = attachmentOnly || system || recalled;
        holder.binding.bubble.setCardBackgroundColor(borderless ? Color.TRANSPARENT
            : (mine ? ThemeColors.primaryContainer(holder.itemView.getContext())
            : holder.itemView.getContext().getColor(system ? R.color.surface_container_high : R.color.surface_container)));
        int padding = borderless ? 0 : dp(holder.itemView.getContext(), 10);
        holder.binding.bubbleContent.setPadding(padding, padding, padding, padding);
        holder.binding.bubbleContent.setMinimumWidth(borderless ? 0 : dp(holder.itemView.getContext(), 72));
        LinearLayout.LayoutParams mediaParams = (LinearLayout.LayoutParams) holder.binding.mediaContainer.getLayoutParams();
        mediaParams.topMargin = borderless ? 0 : dp(holder.itemView.getContext(), 6);
        holder.binding.mediaContainer.setLayoutParams(mediaParams);
        holder.binding.mediaContainer.setGravity(mine ? Gravity.END : Gravity.START);
        LinkNavigator.setTextWithLinks(holder.binding.content, content);
        holder.binding.content.setVisibility(content.isEmpty() || forwarded ? View.GONE : View.VISIBLE);
        bindReply(holder, item);
        holder.binding.content.setTextSize(system || recalled ? 12 : 15);
        holder.binding.senderHeader.setVisibility(system || recalled ? View.GONE : View.VISIBLE);
        renderMedia(holder, item);
        renderTags(holder, item);
        String time = Jsons.string(item, "created_at");
        holder.binding.time.setText(time.length() > 16 ? time.substring(5, 16) : time);
        holder.binding.time.setGravity(mine ? Gravity.END : Gravity.START);
        boolean edited = Jsons.longValue(item, "edit_count") > 0 && !system && !recalled;
        holder.binding.editedLabel.setVisibility(edited ? View.VISIBLE : View.GONE);
        holder.binding.editedLabel.setText(edited && Jsons.longValue(item, "edit_count") > 1
            ? "已编辑 " + Jsons.longValue(item, "edit_count") + " 次" : "已编辑");
        holder.binding.editedLabel.setOnClickListener(edited ? view -> listener.onEditHistory(item) : null);
        boolean deletableSystem = role == Role.USER && (system || recalled);
        holder.binding.systemDelete.setVisibility(deletableSystem ? View.VISIBLE : View.GONE);
        holder.binding.systemDelete.setOnClickListener(deletableSystem ? view -> listener.onDeleteSystem(item) : null);
        bindAvatar(holder, item, mine, system, sender);
        long messageId = itemId(item);
        holder.binding.selectionRail.setVisibility(selectionMode && selectable ? View.VISIBLE : View.GONE);
        holder.binding.selectCheck.setChecked(selectedIds.contains(messageId));
        holder.binding.selectCheck.setOnClickListener(view -> listener.onSelectionChanged(item, holder.binding.selectCheck.isChecked()));
        holder.binding.root.setOnClickListener(view -> {
            if (selectionMode && selectable) listener.onSelectionChanged(item, !selectedIds.contains(messageId));
        });
        holder.binding.bubble.setOnLongClickListener(selectable ? view -> {
            listener.onLongPress(item); return true;
        } : null);
    }

    private void bindReply(Holder holder, JsonObject item) {
        long replyId = Jsons.longValue(item, "reply_to_message_id");
        if (replyId <= 0 || "recall".equals(Jsons.string(item, "content_type"))) {
            holder.binding.replyBlock.setVisibility(View.GONE);
            holder.binding.replyBlock.setOnClickListener(null);
            return;
        }
        JsonObject source = null;
        for (JsonObject candidate : items) {
            if (itemId(candidate) == replyId) { source = candidate; break; }
        }
        String sender = Jsons.string(item, "reply_sender_name");
        String summary = Jsons.string(item, "reply_content");
        if (source != null) {
            sender = Jsons.string(source, "sender_name");
            if (sender.isEmpty()) sender = Jsons.string(source, "nickname");
            if (sender.isEmpty()) sender = Jsons.string(source, "account");
            summary = Jsons.string(source, "content");
            if (summary.isEmpty()) summary = attachmentSummary(source);
        }
        if (sender.isEmpty()) sender = "原消息";
        if (summary.isEmpty()) summary = "点击查看被引用的消息";
        holder.binding.replySender.setText(
            RuntimeLanguage.translate(holder.itemView.getContext(), "回复") + " " + sender);
        holder.binding.replyContent.setText(summary);
        holder.binding.replyBlock.setVisibility(View.VISIBLE);
        holder.binding.replyBlock.setContentDescription("定位到被引用的消息");
        holder.binding.replyBlock.setOnClickListener(view -> listener.onReplyClick(replyId));
    }

    private String attachmentSummary(JsonObject message) {
        JsonArray attachments = Jsons.array(message, "attachments");
        if (attachments.isEmpty()) return "";
        JsonObject first = attachments.get(0).isJsonObject() ? attachments.get(0).getAsJsonObject() : new JsonObject();
        String type = Jsons.string(first, "media_type");
        if ("sticker".equals(type)) return "[表情包]";
        if (isVideoAttachment(first)) return "[视频]";
        if (isDynamicImage(first)) return "[动图]";
        if (isImageAttachment(first)) return "[图片]";
        if (isAudioAttachment(first)) return isRecordedVoice(first) ? "[语音]" : "[音频]";
        return "[附件]";
    }

    private void renderMedia(Holder holder, JsonObject item) {
        JsonArray attachments = Jsons.array(item, "attachments");
        holder.binding.mediaContainer.animate().cancel();
        holder.binding.mediaContainer.setAlpha(1f);
        holder.binding.mediaContainer.setScaleX(1f);
        holder.binding.mediaContainer.setScaleY(1f);
        holder.binding.mediaContainer.setTranslationX(0f);
        holder.binding.mediaContainer.setTranslationY(0f);
        holder.binding.mediaContainer.removeAllViews();
        holder.binding.mediaToggle.animate().cancel();
        holder.binding.mediaToggle.setEnabled(true);
        holder.binding.mediaToggle.setVisibility(View.GONE);
        JsonObject forward = Jsons.object(item, "forward_bundle");
        long forwardId = Jsons.longValue(item, "forward_bundle_id");
        if (forwardId == 0) forwardId = Jsons.longValue(forward, "id");
        if (forwardId > 0) holder.binding.mediaContainer.addView(forwardCard(holder, item, forward, forwardId));
        if (attachments.isEmpty() && forwardId == 0) {
            holder.binding.mediaContainer.setVisibility(View.GONE);
            return;
        }
        holder.binding.mediaContainer.setVisibility(View.VISIBLE);
        List<JsonObject> visualMedia = new ArrayList<>();
        List<JsonObject> stickers = new ArrayList<>();
        List<JsonObject> others = new ArrayList<>();
        for (JsonElement element : attachments) {
            if (!element.isJsonObject()) continue;
            JsonObject attachment = element.getAsJsonObject();
            String type = Jsons.string(attachment, "media_type");
            if ("sticker".equals(type)) stickers.add(attachment);
            else if (isVisualAttachment(attachment)) visualMedia.add(attachment);
            else others.add(attachment);
        }
        long messageId = itemId(item);
        boolean expanded = expandedImages.contains(messageId);
        if (!visualMedia.isEmpty()) {
            if (visualMedia.size() > 1 && !expanded) {
                holder.binding.mediaContainer.addView(stackedMedia(holder, item, messageId, visualMedia));
            } else if (visualMedia.size() > 1) {
                holder.binding.mediaContainer.addView(expandedMedia(holder, item, messageId, visualMedia));
            } else {
                holder.binding.mediaContainer.addView(mediaView(holder, item, visualMedia.get(0), false));
            }
        }
        for (JsonObject sticker : stickers) holder.binding.mediaContainer.addView(mediaView(holder, item, sticker, true));
        for (JsonObject attachment : others) holder.binding.mediaContainer.addView(fileRow(holder, item, attachment));
    }

    private View stackedMedia(Holder holder, JsonObject message, long messageId, List<JsonObject> media) {
        Context context = holder.itemView.getContext();
        FrameLayout root = new AccessibleStackFrame(context);
        boolean mine = isMine(message);
        int current = Math.max(0, Math.min(media.size() - 1, stackedPositions.getOrDefault(messageId, 0)));
        stackedPositions.put(messageId, current);
        int depth = Math.min(3, media.size());
        int railWidth = dp(context, 58);
        int railGap = dp(context, 8);
        int layerOffsetX = dp(context, 17);
        int layerOffsetY = dp(context, 15);
        int stageWidth = dp(context, 196);
        int stageHeight = dp(context, 212);
        LinearLayout.LayoutParams rootParams = new LinearLayout.LayoutParams(
            railWidth + railGap + stageWidth + (depth - 1) * layerOffsetX,
            stageHeight + (depth - 1) * layerOffsetY + dp(context, 4)
        );
        rootParams.gravity = mine ? Gravity.END : Gravity.START;
        root.setLayoutParams(rootParams);

        FrameLayout stage = new FrameLayout(context);
        FrameLayout.LayoutParams stageParams = new FrameLayout.LayoutParams(
            stageWidth + (depth - 1) * layerOffsetX,
            stageHeight + (depth - 1) * layerOffsetY,
            Gravity.TOP | Gravity.START
        );
        stageParams.leftMargin = mine ? railWidth + railGap : 0;
        stage.setLayoutParams(stageParams);
        renderStackLayers(stage, context, media, current, stageWidth, stageHeight,
            layerOffsetX, layerOffsetY);
        root.addView(stage);

        LinearLayout rail = new LinearLayout(context);
        rail.setOrientation(LinearLayout.VERTICAL);
        rail.setGravity(Gravity.CENTER);
        TextView expand = stackRailControl(context, "展开", railWidth, true);
        TextView positionLabel = stackRailControl(context,
            "第 " + (current + 1) + "/" + media.size() + " 张", railWidth, false);
        LinearLayout.LayoutParams positionParams = (LinearLayout.LayoutParams) positionLabel.getLayoutParams();
        positionParams.topMargin = dp(context, 7);
        positionLabel.setLayoutParams(positionParams);
        expand.setContentDescription("展开全部 " + media.size() + " 个媒体");
        positionLabel.setContentDescription("当前第 " + (current + 1) + " 张，共 " + media.size() + " 张");
        expand.setOnClickListener(view -> {
            listener.onMessageHeightWillChange();
            expandedImages.add(messageId);
            int adapterPosition = holder.getBindingAdapterPosition();
            if (adapterPosition != RecyclerView.NO_POSITION
                && adapterPosition >= 0 && adapterPosition < getItemCount()) {
                notifyItemChanged(adapterPosition);
            }
        });
        rail.addView(expand);
        rail.addView(positionLabel);
        FrameLayout.LayoutParams railParams = new FrameLayout.LayoutParams(
            railWidth, ViewGroup.LayoutParams.WRAP_CONTENT,
            (mine ? Gravity.START : Gravity.END) | Gravity.CENTER_VERTICAL
        );
        root.addView(rail, railParams);

        root.setAlpha(0.72f);
        root.setScaleX(0.975f);
        root.setScaleY(0.975f);
        root.animate().alpha(1f).scaleX(1f).scaleY(1f).setDuration(150L).start();
        root.setContentDescription("当前第 " + (current + 1) + " 张，共 " + media.size() + " 张；左右滑动切换");
        boolean[] longPressed = {false};
        GestureDetector gestures = new GestureDetector(context, new GestureDetector.SimpleOnGestureListener() {
            @Override public boolean onDown(@NonNull MotionEvent event) { return true; }
            @Override public void onLongPress(@NonNull MotionEvent event) {
                longPressed[0] = true;
                listener.onLongPress(message);
            }
        });
        int touchSlop = ViewConfiguration.get(context).getScaledTouchSlop();
        float[] down = new float[2];
        boolean[] horizontalDrag = {false};
        stage.setOnClickListener(view -> {
            int index = Math.max(0, Math.min(media.size() - 1,
                stackedPositions.getOrDefault(messageId, 0)));
            openConversationGallery(context, media.get(index));
        });
        stage.setOnTouchListener((view, event) -> {
            gestures.onTouchEvent(event);
            int action = event.getActionMasked();
            if (action == MotionEvent.ACTION_DOWN) {
                down[0] = event.getX();
                down[1] = event.getY();
                horizontalDrag[0] = false;
                longPressed[0] = false;
                stage.animate().cancel();
                stage.setTranslationX(0f);
                if (view.getParent() != null) view.getParent().requestDisallowInterceptTouchEvent(false);
                return true;
            }
            float deltaX = event.getX() - down[0];
            float deltaY = event.getY() - down[1];
            if (action == MotionEvent.ACTION_MOVE) {
                if (!horizontalDrag[0] && Math.abs(deltaX) > touchSlop
                    && Math.abs(deltaX) > Math.abs(deltaY) * 1.08f) {
                    horizontalDrag[0] = true;
                }
                if (horizontalDrag[0]) {
                    if (view.getParent() != null) view.getParent().requestDisallowInterceptTouchEvent(true);
                    float maximum = dp(context, 62);
                    stage.setTranslationX(Math.max(-maximum, Math.min(maximum, deltaX * 0.46f)));
                    return true;
                }
                if (Math.abs(deltaY) > touchSlop && view.getParent() != null) {
                    view.getParent().requestDisallowInterceptTouchEvent(false);
                }
                return true;
            }
            if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                if (view.getParent() != null) view.getParent().requestDisallowInterceptTouchEvent(false);
                if (!horizontalDrag[0] || action == MotionEvent.ACTION_CANCEL) {
                    stage.animate().translationX(0f).setDuration(110L).start();
                    if (action == MotionEvent.ACTION_UP && !longPressed[0]
                        && Math.abs(deltaX) <= touchSlop && Math.abs(deltaY) <= touchSlop) {
                        view.performClick();
                    }
                    return true;
                }
                int value = Math.max(0, Math.min(media.size() - 1,
                    stackedPositions.getOrDefault(messageId, 0)));
                boolean next = deltaX < 0;
                int target = Math.max(0, Math.min(media.size() - 1, value + (next ? 1 : -1)));
                if (Math.abs(deltaX) < dp(context, 32)) target = value;
                if (target == value) {
                    stage.animate().translationX(next ? -dp(context, 10) : dp(context, 10))
                        .setDuration(70L)
                        .withEndAction(() -> stage.animate().translationX(0f).setDuration(90L).start())
                        .start();
                    return true;
                }
                float direction = target > value ? -1f : 1f;
                int finalTarget = target;
                stage.animate().translationX(direction * dp(context, 54)).alpha(0.16f)
                    .setDuration(105L).withEndAction(() -> {
                        stackedPositions.put(messageId, finalTarget);
                        renderStackLayers(stage, context, media, finalTarget, stageWidth, stageHeight,
                            layerOffsetX, layerOffsetY);
                        positionLabel.setText("第 " + (finalTarget + 1) + "/" + media.size() + " 张");
                        positionLabel.setContentDescription("当前第 " + (finalTarget + 1)
                            + " 张，共 " + media.size() + " 张");
                        root.setContentDescription("当前第 " + (finalTarget + 1)
                            + " 张，共 " + media.size() + " 张；左右滑动切换");
                        stage.setTranslationX(-direction * dp(context, 34));
                        stage.setAlpha(0.24f);
                        stage.animate().translationX(0f).alpha(1f).setDuration(145L).start();
                    }).start();
                return true;
            }
            return true;
        });
        return root;
    }

    private void renderStackLayers(FrameLayout stage, Context context, List<JsonObject> media,
                                   int current, int stageWidth, int stageHeight,
                                   int layerOffsetX, int layerOffsetY) {
        stage.removeAllViews();
        if (media.isEmpty()) return;
        List<Integer> stackIndexes = MediaStackOrder.resolve(media.size(), current, 3);
        for (int layer = stackIndexes.size() - 1; layer >= 0; layer--) {
            int index = stackIndexes.get(layer);
            // The backing attachment list can change while the transition animation is ending.
            if (index < 0 || index >= media.size()) {
                continue;
            }
            MaterialCardView layerCard = new MaterialCardView(context);
            layerCard.setRadius(dp(context, 8));
            layerCard.setStrokeWidth(dp(context, 1));
            layerCard.setStrokeColor(layer == 0 ? ThemeColors.primary(context) : context.getColor(R.color.outline));
            layerCard.setCardElevation(dp(context, layer == 0 ? 7 : 3));
            layerCard.setScaleX(1f - layer * 0.03f);
            layerCard.setScaleY(1f - layer * 0.03f);
            layerCard.setRotation(layer == 0 ? 0f : (layer % 2 == 0 ? -2.2f : 2.2f));
            ImageView image = new ImageView(context);
            image.setScaleType(ImageView.ScaleType.FIT_CENTER);
            image.setBackgroundColor(context.getColor(R.color.surface_container));
            FrameLayout.LayoutParams params = new FrameLayout.LayoutParams(stageWidth, stageHeight);
            params.leftMargin = layer * layerOffsetX;
            params.topMargin = layer * layerOffsetY;
            layerCard.setLayoutParams(params);
            image.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            JsonObject attachment = media.get(index);
            String preview = Jsons.string(attachment, "thumbnail_url");
            if (preview.isEmpty()) preview = Jsons.string(attachment, "url");
            if (!preview.isEmpty()) ImageLoader.get().load(absolute(context, preview), image, R.drawable.ic_file);
            layerCard.addView(image);
            if (layer == 0 && isVideoAttachment(attachment)) layerCard.addView(playOverlay(context));
            stage.addView(layerCard);
        }
    }

    private TextView stackRailControl(Context context, String text, int width, boolean emphasized) {
        TextView control = new TextView(context);
        control.setText(text);
        control.setTextColor(context.getColor(R.color.on_surface));
        control.setTextSize(emphasized ? 11 : 10);
        control.setGravity(Gravity.CENTER);
        control.setMaxLines(2);
        control.setPadding(dp(context, 5), dp(context, 8), dp(context, 5), dp(context, 8));
        control.setLayoutParams(new LinearLayout.LayoutParams(width, ViewGroup.LayoutParams.WRAP_CONTENT));
        control.setClickable(emphasized);
        control.setFocusable(emphasized);
        applyGlassBackground(control);
        return control;
    }

    private View expandedMedia(Holder holder, JsonObject message, long messageId, List<JsonObject> media) {
        Context context = holder.itemView.getContext();
        boolean mine = isMine(message);
        int mediaColumnWidth = dp(context, 222);

        LinearLayout root = new LinearLayout(context);
        root.setOrientation(LinearLayout.HORIZONTAL);
        root.setGravity(Gravity.TOP);
        LinearLayout.LayoutParams rootParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        rootParams.gravity = mine ? Gravity.END : Gravity.START;
        root.setLayoutParams(rootParams);

        LinearLayout mediaColumn = new LinearLayout(context);
        mediaColumn.setOrientation(LinearLayout.VERTICAL);
        mediaColumn.setGravity(mine ? Gravity.END : Gravity.START);
        mediaColumn.setLayoutParams(new LinearLayout.LayoutParams(
            mediaColumnWidth, ViewGroup.LayoutParams.WRAP_CONTENT));
        for (JsonObject attachment : media) {
            mediaColumn.addView(mediaView(holder, message, attachment, false, mediaColumnWidth));
        }

        int railWidth = dp(context, 58);
        LinearLayout controlRail = new LinearLayout(context);
        controlRail.setOrientation(LinearLayout.VERTICAL);
        controlRail.setGravity(Gravity.CENTER);
        TextView collapse = stackRailControl(context, "收起", railWidth, true);
        collapse.setContentDescription("收起全部 " + media.size() + " 个媒体");
        collapse.setOnClickListener(view -> {
            listener.onMessageHeightWillChange();
            expandedImages.remove(messageId);
            int position = holder.getBindingAdapterPosition();
            if (position != RecyclerView.NO_POSITION && position >= 0 && position < getItemCount()) {
                notifyItemChanged(position);
            }
        });
        TextView total = stackRailControl(context, "共 " + media.size() + " 张", railWidth, false);
        LinearLayout.LayoutParams totalParams = (LinearLayout.LayoutParams) total.getLayoutParams();
        totalParams.topMargin = dp(context, 7);
        total.setLayoutParams(totalParams);
        total.setContentDescription("共 " + media.size() + " 个媒体");
        controlRail.addView(collapse);
        controlRail.addView(total);

        View gap = new View(context);
        gap.setLayoutParams(new LinearLayout.LayoutParams(dp(context, 8), 1));
        if (mine) {
            root.addView(controlRail);
            root.addView(gap);
            root.addView(mediaColumn);
        } else {
            root.addView(mediaColumn);
            root.addView(gap);
            root.addView(controlRail);
        }
        root.setAlpha(0.8f);
        root.setTranslationY(dp(context, 5));
        root.animate().alpha(1f).translationY(0f).setDuration(170L).start();
        return root;
    }

    private View mediaView(Holder holder, JsonObject message, JsonObject attachment, boolean sticker) {
        return mediaView(holder, message, attachment, sticker, 0);
    }

    private View mediaView(Holder holder, JsonObject message, JsonObject attachment, boolean sticker,
                           int maximumWidth) {
        Context context = holder.itemView.getContext();
        FrameLayout frame = new FrameLayout(context);
        int[] dimensions = mediaSize(context, attachment, sticker);
        if (maximumWidth > 0 && dimensions[0] > maximumWidth) {
            float scale = maximumWidth / (float) dimensions[0];
            dimensions = new int[]{maximumWidth, Math.max(dp(context, 112), Math.round(dimensions[1] * scale))};
        }
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dimensions[0], dimensions[1]);
        params.bottomMargin = dp(context, 6);
        params.gravity = isMine(message) ? Gravity.END : Gravity.START;
        frame.setLayoutParams(params);
        GradientDrawable frameBackground = new GradientDrawable();
        frameBackground.setColor(context.getColor(R.color.surface_container));
        frameBackground.setCornerRadius(dp(context, 8));
        frame.setBackground(frameBackground);
        frame.setClipToOutline(true);
        ImageView image = new ImageView(context);
        image.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        image.setBackgroundColor(context.getColor(R.color.surface_container));
        String preview = Jsons.string(attachment, "thumbnail_url");
        boolean video = isVideoAttachment(attachment);
        if (preview.isEmpty()) preview = Jsons.string(attachment, "url");
        if (!preview.isEmpty()) ImageLoader.get().load(absolute(context, preview), image, R.drawable.ic_file);
        frame.addView(image);
        if (video) frame.addView(playOverlay(context));
        String badgeText = mediaBadge(attachment);
        if (!badgeText.isEmpty() && !sticker) {
            TextView badge = new TextView(context);
            badge.setText(badgeText);
            badge.setTextSize(10f);
            badge.setTextColor(context.getColor(R.color.on_surface));
            badge.setGravity(Gravity.CENTER);
            badge.setPadding(dp(context, 7), dp(context, 3), dp(context, 7), dp(context, 3));
            applyGlassBackground(badge);
            FrameLayout.LayoutParams badgeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.START | Gravity.TOP);
            badgeParams.setMargins(dp(context, 8), dp(context, 8), dp(context, 8), dp(context, 8));
            frame.addView(badge, badgeParams);
        }
        frame.setContentDescription(sticker ? "表情包" : (video ? "聊天视频"
            : (isDynamicImage(attachment) ? "聊天动图" : "聊天图片")));
        frame.setOnClickListener(view -> {
            openConversationGallery(context, attachment);
        });
        frame.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
        return frame;
    }

    private void applyGlassBackground(View view) {
        Context context = view.getContext();
        GradientDrawable glass = new GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            new int[]{
                ColorUtils.setAlphaComponent(context.getColor(R.color.surface_container_high), 206),
                ColorUtils.setAlphaComponent(context.getColor(R.color.surface), 170)
            });
        glass.setCornerRadius(dp(context, 12));
        glass.setStroke(dp(context, 1), ColorUtils.setAlphaComponent(
            context.getColor(R.color.outline), 132));
        glass.setDither(true);
        view.setBackground(glass);
        view.setElevation(dp(context, 5));
    }

    private int[] mediaSize(Context context, JsonObject attachment, boolean sticker) {
        if (sticker) return new int[]{dp(context, 148), dp(context, 148)};
        int screenWidth = context.getResources().getDisplayMetrics().widthPixels;
        int maxWidth = Math.min(dp(context, 248),
            Math.max(dp(context, 184), Math.round(screenWidth * 0.62f)));
        long sourceWidth = Jsons.longValue(attachment, "width");
        long sourceHeight = Jsons.longValue(attachment, "height");
        JsonObject metadata = Jsons.object(attachment, "metadata");
        if (sourceWidth <= 0) sourceWidth = Jsons.longValue(metadata, "width");
        if (sourceHeight <= 0) sourceHeight = Jsons.longValue(metadata, "height");
        if (sourceWidth > 0 && sourceHeight > sourceWidth * 1.15d) {
            return new int[]{Math.min(maxWidth, dp(context, 210)), dp(context, 252)};
        }
        if (sourceWidth > sourceHeight * 1.15d) {
            return new int[]{maxWidth, dp(context, 198)};
        }
        int square = Math.min(maxWidth, dp(context, 232));
        return new int[]{square, square};
    }

    private View fileRow(Holder holder, JsonObject message, JsonObject attachment) {
        Context context = holder.itemView.getContext();
        String type = Jsons.string(attachment, "media_type");
        if (isAudioAttachment(attachment)) {
            LinearLayout audioBlock = new LinearLayout(context);
            audioBlock.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams blockParams = new LinearLayout.LayoutParams(
                dp(context, isMine(message) ? 292 : 268), ViewGroup.LayoutParams.WRAP_CONTENT);
            blockParams.bottomMargin = dp(context, 6);
            audioBlock.setLayoutParams(blockParams);
            boolean voice = isRecordedVoice(attachment);
            InlineAudioPlayerView player = new InlineAudioPlayerView(
                context, absolute(context, Jsons.string(attachment, "url")),
                Jsons.longValue(attachment, "duration_ms"), voice);
            player.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            player.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
            audioBlock.addView(player);
            String transcript = Jsons.string(attachment, "transcript");
            if (transcript.isEmpty()) transcript = Jsons.string(Jsons.object(attachment, "metadata"), "transcript");
            if (!transcript.isEmpty()) {
                long messageId = itemId(message);
                boolean collapsed = collapsedTranscripts.contains(messageId);
                if (!collapsed) {
                    TextView text = new TextView(context);
                    text.setText("转写：" + transcript);
                    text.setTextSize(12);
                    text.setTextColor(context.getColor(R.color.on_surface_variant));
                    text.setPadding(dp(context, 10), dp(context, 5), dp(context, 10), dp(context, 4));
                    text.setMaxLines(Integer.MAX_VALUE);
                    text.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
                    audioBlock.addView(text, new LinearLayout.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
                }
                TextView toggle = new TextView(context);
                toggle.setText(collapsed ? "展开转写" : "收起转写");
                toggle.setTextSize(11);
                toggle.setTextColor(ThemeColors.primary(context));
                toggle.setGravity(Gravity.END);
                toggle.setPadding(dp(context, 10), collapsed ? dp(context, 5) : 0, dp(context, 10), dp(context, 5));
                toggle.setOnClickListener(view -> {
                    listener.onMessageHeightWillChange();
                    if (!collapsedTranscripts.add(messageId)) collapsedTranscripts.remove(messageId);
                    int position = positionOf(messageId);
                    if (position >= 0) notifyItemChanged(position);
                });
                audioBlock.addView(toggle, new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            }
            return audioBlock;
        }
        if (isBusinessType(type)) return businessCard(holder, message, attachment, type);
        MaterialCardView card = new MaterialCardView(context);
        LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(
            dp(context, isMine(message) ? 292 : 276), ViewGroup.LayoutParams.WRAP_CONTENT);
        cardParams.bottomMargin = dp(context, 6);
        card.setLayoutParams(cardParams);
        card.setRadius(dp(context, 8));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(context.getColor(R.color.outline_variant));
        card.setCardBackgroundColor(context.getColor(R.color.surface));

        LinearLayout row = new LinearLayout(context);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setMinimumHeight(dp(context, 82));
        row.setPadding(dp(context, 12), dp(context, 10), dp(context, 10), dp(context, 10));

        FrameLayout iconPanel = new FrameLayout(context);
        GradientDrawable iconBackground = new GradientDrawable();
        iconBackground.setColor(ThemeColors.primaryContainer(context));
        iconBackground.setCornerRadius(dp(context, 8));
        iconPanel.setBackground(iconBackground);
        LinearLayout.LayoutParams iconPanelParams = new LinearLayout.LayoutParams(dp(context, 52), dp(context, 58));
        iconPanelParams.rightMargin = dp(context, 11);
        row.addView(iconPanel, iconPanelParams);

        ImageView fileIcon = new ImageView(context);
        fileIcon.setImageResource(fileIcon(attachment));
        fileIcon.setImageTintList(ColorStateList.valueOf(ThemeColors.primary(context)));
        fileIcon.setContentDescription(friendlyFileType(attachment));
        FrameLayout.LayoutParams fileIconParams = new FrameLayout.LayoutParams(dp(context, 28), dp(context, 28), Gravity.CENTER);
        iconPanel.addView(fileIcon, fileIconParams);

        LinearLayout labels = new LinearLayout(context);
        labels.setOrientation(LinearLayout.VERTICAL);
        labels.setGravity(Gravity.CENTER_VERTICAL);
        TextView name = new TextView(context);
        name.setText(attachmentName(attachment));
        name.setTextColor(context.getColor(R.color.on_surface));
        name.setTextSize(14);
        name.setMaxLines(2);
        name.setEllipsize(TextUtils.TruncateAt.END);
        TextView metadata = new TextView(context);
        metadata.setText(fileMetadata(attachment));
        metadata.setTextColor(context.getColor(R.color.on_surface_variant));
        metadata.setTextSize(11.5f);
        metadata.setMaxLines(2);
        metadata.setEllipsize(TextUtils.TruncateAt.END);
        metadata.setPadding(0, dp(context, 4), 0, 0);
        labels.addView(name, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        labels.addView(metadata, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        row.addView(labels, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        ImageView open = new ImageView(context);
        open.setImageResource(R.drawable.ic_chevron_right);
        open.setImageTintList(ColorStateList.valueOf(context.getColor(R.color.on_surface_variant)));
        open.setContentDescription("预览文件");
        LinearLayout.LayoutParams openParams = new LinearLayout.LayoutParams(dp(context, 22), dp(context, 22));
        openParams.leftMargin = dp(context, 7);
        row.addView(open, openParams);
        card.addView(row);
        card.setOnClickListener(view -> previewAttachment(context, attachment));
        card.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
        return card;
    }

    private String attachmentName(JsonObject attachment) {
        String name = Jsons.string(attachment, "original_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "file_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "name");
        return name.isEmpty() ? "未命名文件" : name;
    }

    private String fileMetadata(JsonObject attachment) {
        long bytes = Jsons.longValue(attachment, "size_bytes");
        if (bytes <= 0L) bytes = Jsons.longValue(Jsons.object(attachment, "metadata"), "size_bytes");
        StringBuilder value = new StringBuilder(friendlyFileType(attachment));
        if (bytes > 0L) value.append("  ·  ").append(sizeText(bytes));
        value.append("\n点击可在软件内预览");
        return value.toString();
    }

    private String friendlyFileType(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type");
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        String extension = extensionOf(attachmentName(attachment));
        if ("apk".equals(extension)) return "安卓安装包";
        if ("hap".equals(extension)) return "鸿蒙安装包";
        if ("ipa".equals(extension)) return "苹果安装包";
        if ("pdf".equals(extension)) return "PDF 文档";
        if ("doc".equals(extension) || "docx".equals(extension)) return "Word 文档";
        if ("xls".equals(extension) || "xlsx".equals(extension)) return "Excel 表格";
        if ("ppt".equals(extension) || "pptx".equals(extension)) return "演示文稿";
        if ("zip".equals(extension) || "7z".equals(extension) || "rar".equals(extension)
            || "tar".equals(extension) || "gz".equals(extension) || "bz2".equals(extension)
            || "xz".equals(extension)) return extension.toUpperCase(Locale.ROOT) + " 压缩包";
        if (isVideoAttachment(attachment)) return "视频文件";
        if (isRecordedVoice(attachment)) return "语音消息";
        if (isAudioAttachment(attachment)) return "音频文件";
        if (isDynamicImage(attachment)) return "动态图片";
        if (isImageAttachment(attachment)) return "图片文件";
        if (isSourceExtension(extension)) return extension.toUpperCase(Locale.ROOT) + " 源码";
        return extension.isEmpty() ? "文件" : extension.toUpperCase(Locale.ROOT) + " 文件";
    }

    private int fileIcon(JsonObject attachment) {
        String type = friendlyFileType(attachment);
        if (type.contains("视频")) return R.drawable.ic_video;
        if (type.contains("压缩包")) return R.drawable.ic_folder;
        if (type.contains("文档") || type.contains("表格") || type.contains("演示")
            || type.contains("源码")) return R.drawable.ic_document;
        return R.drawable.ic_file;
    }

    private boolean isVisualAttachment(JsonObject attachment) {
        return isImageAttachment(attachment) || isVideoAttachment(attachment);
    }

    private boolean isImageAttachment(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type").toLowerCase(Locale.ROOT);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        return "image".equals(type) || "gif".equals(type) || "motion_photo".equals(type)
            || mime.startsWith("image/");
    }

    private boolean isVideoAttachment(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type").toLowerCase(Locale.ROOT);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        return "video".equals(type) || mime.startsWith("video/");
    }

    private boolean isAudioAttachment(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type").toLowerCase(Locale.ROOT);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        return "audio".equals(type) || "voice".equals(type) || "recorded_voice".equals(type)
            || mime.startsWith("audio/");
    }

    private boolean isDynamicImage(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type").toLowerCase(Locale.ROOT);
        String mime = Jsons.string(attachment, "mime_type").toLowerCase(Locale.ROOT);
        String name = Jsons.string(attachment, "original_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "file_name");
        return "gif".equals(type) || "motion_photo".equals(type) || "image/gif".equals(mime)
            || name.toLowerCase(Locale.ROOT).endsWith(".gif");
    }

    private String mediaBadge(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type").toLowerCase(Locale.ROOT);
        if ("motion_photo".equals(type)) return "动态照片";
        if (isDynamicImage(attachment)) return "GIF";
        if (isVideoAttachment(attachment)) return "视频";
        return "";
    }
    private String extensionOf(String name) {
        int dot = name == null ? -1 : name.lastIndexOf('.');
        return dot < 0 || dot == name.length() - 1
            ? "" : name.substring(dot + 1).toLowerCase(Locale.ROOT);
    }

    private boolean isSourceExtension(String extension) {
        return "java".equals(extension) || "kt".equals(extension) || "py".equals(extension)
            || "php".equals(extension) || "html".equals(extension) || "css".equals(extension)
            || "js".equals(extension) || "ts".equals(extension) || "sql".equals(extension)
            || "c".equals(extension) || "cpp".equals(extension) || "h".equals(extension)
            || "rs".equals(extension) || "go".equals(extension) || "xml".equals(extension)
            || "json".equals(extension) || "iapp".equals(extension);
    }

    private boolean isRecordedVoice(JsonObject attachment) {
        JsonObject metadata = Jsons.object(attachment, "metadata");
        String kind = Jsons.string(metadata, "audio_kind");
        if ("voice".equalsIgnoreCase(kind) || "recorded_voice".equalsIgnoreCase(kind)) return true;
        String name = Jsons.string(attachment, "original_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "file_name");
        if (name.isEmpty()) name = Jsons.string(attachment, "name");
        return name.toLowerCase(Locale.ROOT).startsWith("voice_");
    }

    private View businessCard(Holder holder, JsonObject message, JsonObject attachment, String type) {
        if ("contact_card".equals(type)) return contactCard(holder, message, attachment);
        Context context = holder.itemView.getContext();
        JsonObject metadata = Jsons.object(attachment, "metadata");
        MaterialCardView card = new MaterialCardView(context);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dp(context, 268), ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(context, 6);
        card.setLayoutParams(params);
        card.setRadius(dp(context, 7));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(context, 1));
        boolean inactive = businessInactive(type, metadata);
        card.setStrokeColor(context.getColor(inactive ? R.color.outline_variant : R.color.outline));
        card.setCardBackgroundColor(inactive
            ? context.getColor(R.color.surface_container_high)
            : ("red_packet".equals(type) ? ThemeColors.primaryContainer(context) : context.getColor(R.color.surface)));
        LinearLayout content = new LinearLayout(context);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(context, 14), dp(context, 12), dp(context, 14), dp(context, 10));
        TextView title = new TextView(context);
        title.setText(businessTitle(type, attachment, metadata));
        title.setTextSize(16);
        title.setTextColor(context.getColor(R.color.on_surface));
        TextView detail = new TextView(context);
        detail.setText(businessDetail(type, attachment, metadata));
        detail.setTextSize(13);
        detail.setTextColor(context.getColor(R.color.on_surface_variant));
        detail.setPadding(0, dp(context, 5), 0, 0);
        TextView action = new TextView(context);
        action.setText(businessActionLabel(type, metadata));
        action.setTextSize(12);
        action.setTextColor(inactive ? context.getColor(R.color.on_surface_variant) : ThemeColors.primary(context));
        action.setGravity(Gravity.END);
        action.setPadding(0, dp(context, 8), 0, 0);
        content.addView(title);
        content.addView(detail);
        content.addView(action);
        card.addView(content);
        card.setOnClickListener(view -> listener.onAttachmentClick(message, attachment));
        card.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
        return card;
    }

    private View contactCard(Holder holder, JsonObject message, JsonObject attachment) {
        Context context = holder.itemView.getContext();
        JsonObject metadata = Jsons.object(attachment, "metadata");
        MaterialCardView card = new MaterialCardView(context);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dp(context, 268), ViewGroup.LayoutParams.WRAP_CONTENT);
        params.bottomMargin = dp(context, 6);
        card.setLayoutParams(params);
        card.setRadius(dp(context, 8));
        card.setCardElevation(0);
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(context.getColor(R.color.surface_container_high));
        card.setCardBackgroundColor(context.getColor(R.color.surface));

        LinearLayout body = new LinearLayout(context);
        body.setOrientation(LinearLayout.VERTICAL);
        LinearLayout person = new LinearLayout(context);
        person.setOrientation(LinearLayout.HORIZONTAL);
        person.setGravity(Gravity.CENTER_VERTICAL);
        person.setPadding(dp(context, 14), dp(context, 12), dp(context, 14), dp(context, 12));
        ImageView avatar = new ImageView(context);
        avatar.setScaleType(ImageView.ScaleType.CENTER_CROP);
        avatar.setBackgroundResource(R.drawable.bg_avatar);
        person.addView(avatar, new LinearLayout.LayoutParams(dp(context, 52), dp(context, 52)));
        String avatarUrl = value(metadata, "avatar", value(metadata, "avatar_url", ""));
        ImageLoader.get().loadThumbnail(absolute(context, avatarUrl), avatar, R.drawable.ic_person);
        TextView name = new TextView(context);
        name.setText(value(metadata, "display_name", value(metadata, "account", "用户")));
        name.setTextAppearance(com.google.android.material.R.style.TextAppearance_Material3_TitleLarge);
        name.setTextColor(context.getColor(R.color.on_surface));
        name.setMaxLines(1);
        name.setEllipsize(android.text.TextUtils.TruncateAt.END);
        LinearLayout.LayoutParams nameParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        nameParams.leftMargin = dp(context, 14);
        person.addView(name, nameParams);
        body.addView(person);

        View divider = new View(context);
        divider.setBackgroundColor(context.getColor(R.color.surface_container_high));
        body.addView(divider, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 1)));
        TextView label = new TextView(context);
        label.setText("推荐好友");
        label.setTextSize(13);
        label.setTextColor(context.getColor(R.color.on_surface_variant));
        label.setGravity(Gravity.CENTER_VERTICAL);
        label.setPadding(dp(context, 14), 0, dp(context, 14), 0);
        body.addView(label, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(context, 40)));
        card.addView(body);
        card.setContentDescription("推荐好友 " + name.getText());
        card.setOnClickListener(view -> listener.onAttachmentClick(message, attachment));
        card.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
        return card;
    }

    private boolean isBusinessType(String type) {
        return "favorite".equals(type) || "moment_share".equals(type)
            || "red_packet".equals(type) || "transfer".equals(type)
            || "contact_card".equals(type) || "gift".equals(type) || "location".equals(type);
    }

    private String businessTitle(String type, JsonObject attachment, JsonObject metadata) {
        if ("red_packet".equals(type)) return "红包 · " + value(metadata, "message", "恭喜发财");
        if ("transfer".equals(type)) return "转账 · 余额 " + value(metadata, "amount", "0");
        if ("contact_card".equals(type)) return "个人名片 · " + value(metadata, "display_name", value(metadata, "account", "用户"));
        if ("gift".equals(type)) return "礼物 · " + value(Jsons.object(metadata, "gift"), "gift_name", "礼物");
        if ("location".equals(type)) {
            return "位置 · " + value(metadata, "location_name", value(attachment, "file_name", "所选位置"));
        }
        if ("moment_share".equals(type) || isMomentFavorite(metadata)) {
            return "动态 · " + value(metadata, "author_name", "用户");
        }
        return "收藏 · " + value(metadata, "title", value(attachment, "file_name", "收藏内容"));
    }

    private String businessDetail(String type, JsonObject attachment, JsonObject metadata) {
        String state = commerceState(type, metadata);
        if ("red_packet".equals(type)) {
            long total = Jsons.longValue(metadata, "total_count");
            if (metadata.has("remain_count")) {
                long claimed = Math.max(0, total - Jsons.longValue(metadata, "remain_count"));
                return businessStateLabel(type, state) + " · 已领取 " + claimed + "/" + total;
            }
            return "共 " + total + " 个 · 点击领取或查看明细";
        }
        if ("transfer".equals(type)) {
            String message = value(metadata, "message", "");
            return businessStateLabel(type, state) + (message.isEmpty() ? "" : " · " + message);
        }
        if ("contact_card".equals(type)) return "UID " + value(metadata, "uid", value(metadata, "user_id", "-"));
        if ("gift".equals(type)) {
            String message = value(metadata, "message", "");
            return businessStateLabel(type, state) + (message.isEmpty() ? "" : " · " + message);
        }
        if ("location".equals(type)) {
            String address = value(metadata, "address", "");
            if (!address.isEmpty()) return address;
            return "点击查看发送位置";
        }
        if ("moment_share".equals(type) || isMomentFavorite(metadata)) {
            String content = value(metadata, "content", value(metadata, "summary", value(metadata, "media_summary", "分享了一条动态")));
            String location = value(metadata, "location_name", "");
            return content + (location.isEmpty() ? "" : " · " + location);
        }
        return value(metadata, "summary", "点击打开收藏内容");
    }

    private boolean businessInactive(String type, JsonObject metadata) {
        String state = commerceState(type, metadata);
        return "completed".equals(state) || "accepted".equals(state) || "refunded".equals(state)
            || "claimed".equals(state) || "returned".equals(state)
            || "expired".equals(state) || "cancelled".equals(state);
    }

    private String businessActionLabel(String type, JsonObject metadata) {
        String state = commerceState(type, metadata);
        if ("red_packet".equals(type)) {
            if ("completed".equals(state)) return "已领完 · 查看领取记录";
            if ("claimed".equals(state)) return "已领取 · 查看领取记录";
            if ("refunded".equals(state)) return "已退回 · 查看详情";
            if ("returned".equals(state)) return "已退回给发送人 · 查看详情";
            if ("expired".equals(state)) return "已过期 · 查看详情";
        }
        if ("transfer".equals(type)) {
            if ("accepted".equals(state)) return "已收款 · 查看详情";
            if ("refunded".equals(state)) return "已退回 · 查看详情";
            if ("expired".equals(state)) return "已过期 · 查看详情";
        }
        if ("gift".equals(type)) {
            if ("accepted".equals(state)) return "已收下 · 查看详情";
            if ("refunded".equals(state)) return "已退回 · 查看详情";
            if ("expired".equals(state)) return "已过期 · 查看详情";
        }
        if ("moment_share".equals(type) || isMomentFavorite(metadata)) return "查看动态";
        if ("location".equals(type)) return "查看位置";
        return "查看详情";
    }

    private boolean isMomentFavorite(JsonObject metadata) {
        return "moment".equalsIgnoreCase(value(metadata, "content_kind", ""))
            || "moment".equalsIgnoreCase(value(metadata, "favorite_type", ""));
    }

    private String businessStateLabel(String type, String state) {
        if ("completed".equals(state)) return "已领完";
        if ("claimed".equals(state)) return "已领取";
        if ("accepted".equals(state)) return "gift".equals(type) ? "已收下" : "已收款";
        if ("refunded".equals(state)) return "已退回";
        if ("returned".equals(state)) return "已退回给发送人";
        if ("expired".equals(state)) return "已过期";
        if ("cancelled".equals(state)) return "已取消";
        return "red_packet".equals(type) ? "待领取" : "等待确认";
    }

    private String commerceState(String type, JsonObject metadata) {
        String state = Jsons.string(metadata, "commerce_state").trim().toLowerCase(Locale.ROOT);
        if (!state.isEmpty()) return state;
        if ("red_packet".equals(type) && booleanValue(metadata, "claimed")) {
            return "claimed";
        }
        if ("red_packet".equals(type)
            && (booleanValue(metadata, "returned")
                || "returned".equalsIgnoreCase(Jsons.string(metadata, "settlement_status")))) {
            return "returned";
        }
        if ("red_packet".equals(type) && metadata.has("status")) {
            long status = Jsons.longValue(metadata, "status");
            if (status == 0) return "completed";
            if (status == 2) return "refunded";
        }
        if (("transfer".equals(type) || "gift".equals(type)) && metadata.has("status")) {
            state = Jsons.string(metadata, "status").trim().toLowerCase(Locale.ROOT);
            if (!state.isEmpty()) return state;
        }
        return "pending";
    }

    private String value(JsonObject item, String key, String fallback) {
        String value = Jsons.string(item, key);
        return value.isEmpty() ? fallback : value;
    }

    private View forwardCard(Holder holder, JsonObject message, JsonObject forward, long forwardId) {
        Context context = holder.itemView.getContext();
        MaterialCardView card = new MaterialCardView(context);
        card.setRadius(dp(context, 6));
        card.setStrokeWidth(dp(context, 1));
        card.setStrokeColor(context.getColor(R.color.outline));
        card.setCardElevation(0);
        TextView text = new TextView(context);
        String title = Jsons.string(forward, "title");
        if (title.isEmpty()) title = "合并转发的聊天记录";
        long count = Jsons.longValue(forward, "item_count");
        text.setText(title + (count > 0 ? "\n共 " + count + " 条 · 点击查看只读快照" : "\n点击查看只读快照"));
        text.setTextColor(context.getColor(R.color.on_surface));
        text.setTextSize(15);
        text.setPadding(dp(context, 14), dp(context, 12), dp(context, 14), dp(context, 12));
        card.addView(text);
        long targetForwardId = forwardId;
        card.setOnClickListener(view -> {
            if (!Jsons.array(forward, "items").isEmpty()) {
                ForwardSnapshotActivity.openEmbedded(context, forward, managedAppId);
            } else {
                ForwardSnapshotActivity.open(context, targetForwardId, managedAppId);
            }
        });
        card.setOnLongClickListener(view -> { listener.onLongPress(message); return true; });
        return card;
    }


    private View playOverlay(Context context) {
        TextView play = new TextView(context);
        play.setText("▶");
        play.setTextColor(Color.WHITE);
        play.setTextSize(28);
        play.setGravity(Gravity.CENTER);
        play.setBackgroundColor(Color.argb(72, 0, 0, 0));
        play.setLayoutParams(new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        return play;
    }

    private void renderTags(Holder holder, JsonObject item) {
        holder.binding.tagContainer.removeAllViews();
        JsonArray tags = Jsons.array(item, "tags");
        if (tags.isEmpty()) tags = Jsons.array(item, "tags_json");
        for (JsonElement element : tags) {
            if (!element.isJsonPrimitive()) continue;
            String value = element.getAsString().trim();
            if (value.isEmpty()) continue;
            TextView tag = new TextView(holder.itemView.getContext());
            tag.setText("#" + value);
            tag.setTextSize(11);
            tag.setTextColor(ThemeColors.primary(holder.itemView.getContext()));
            tag.setPadding(dp(holder.itemView.getContext(), 4), 0, dp(holder.itemView.getContext(), 4), 0);
            holder.binding.tagContainer.addView(tag);
        }
        for (JsonElement element : Jsons.array(item, "search_match_fields")) {
            if (!element.isJsonPrimitive()) continue;
            String value = element.getAsString().trim();
            if (value.isEmpty()) continue;
            TextView match = new TextView(holder.itemView.getContext());
            match.setText("命中：" + value);
            match.setTextSize(11);
            match.setTextColor(holder.itemView.getContext().getColor(R.color.success));
            match.setPadding(dp(holder.itemView.getContext(), 5), 0, dp(holder.itemView.getContext(), 5), 0);
            holder.binding.tagContainer.addView(match);
        }
        holder.binding.tagContainer.setVisibility(holder.binding.tagContainer.getChildCount() == 0 ? View.GONE : View.VISIBLE);
    }

    private void bindSenderBadge(Holder holder, JsonObject item) {
        String badge = Jsons.string(item, "sender_badge");
        if (badge.isEmpty()) {
            String roleValue = Jsons.string(item, "sender_role");
            if (roleValue.isEmpty()) roleValue = Jsons.string(item, "role");
            if ("owner".equals(roleValue)) badge = "群主";
            else if ("admin".equals(roleValue)) badge = "版主";
            else if ("system".equals(Jsons.string(item, "sender_type"))) badge = "系统";
        }
        holder.binding.senderBadge.setText(badge);
        holder.binding.senderBadge.setVisibility(badge.isEmpty() ? View.GONE : View.VISIBLE);
        String tone = Jsons.string(item, "sender_badge_tone");
        int background = ThemeColors.primaryContainer(holder.itemView.getContext());
        int foreground = holder.itemView.getContext().getColor(R.color.on_primary_container);
        if ("warning".equals(tone)) {
            // Keep semantic warning text, but resolve the container from day/night
            // resources so the badge remains readable after a live theme switch.
            background = holder.itemView.getContext().getColor(R.color.surface_container_high);
            foreground = holder.itemView.getContext().getColor(R.color.warning);
        } else if ("secondary".equals(tone)) {
            background = holder.itemView.getContext().getColor(R.color.secondary_container);
            foreground = holder.itemView.getContext().getColor(R.color.on_secondary_container);
        }
        holder.binding.senderBadge.getBackground().mutate().setTint(background);
        holder.binding.senderBadge.setTextColor(foreground);
    }

    private void bindAvatar(Holder holder, JsonObject item, boolean mine, boolean system, String sender) {
        holder.binding.leftAvatarBox.setVisibility(!system && !mine ? View.VISIBLE : View.GONE);
        holder.binding.rightAvatarBox.setVisibility(!system && mine ? View.VISIBLE : View.GONE);
        if (system) return;
        String avatar = Jsons.string(item, "sender_avatar");
        if (avatar.isEmpty()) avatar = Jsons.string(item, "avatar");
        ImageView image = mine ? holder.binding.rightAvatar : holder.binding.leftAvatar;
        TextView fallback = mine ? holder.binding.rightAvatarText : holder.binding.leftAvatarText;
        if (!avatar.isEmpty()) {
            image.setVisibility(View.VISIBLE);
            fallback.setVisibility(View.GONE);
            ImageLoader.get().loadThumbnail(absolute(holder.itemView.getContext(), avatar), image, R.drawable.ic_person);
        } else {
            image.setVisibility(View.GONE);
            fallback.setVisibility(View.VISIBLE);
            fallback.setText(mine ? "我" : firstCharacter(sender));
        }
        View avatarBox = mine ? holder.binding.rightAvatarBox : holder.binding.leftAvatarBox;
        avatarBox.setOnClickListener(view -> listener.onAvatarClick(item));
    }

    private void openConversationGallery(Context context, JsonObject selected) {
        List<JsonObject> gallery = new ArrayList<>();
        int selectedIndex = 0;
        String selectedUrl = Jsons.string(selected, "url");
        boolean stickerGallery = "sticker".equals(Jsons.string(selected, "media_type"));
        for (JsonObject message : items) {
            for (JsonElement element : Jsons.array(message, "attachments")) {
                if (!element.isJsonObject()) continue;
                JsonObject attachment = element.getAsJsonObject();
                String type = Jsons.string(attachment, "media_type");
                if (stickerGallery ? !"sticker".equals(type)
                    : ("sticker".equals(type) || !isVisualAttachment(attachment))) continue;
                if (Jsons.string(attachment, "url").equals(selectedUrl)) selectedIndex = gallery.size();
                gallery.add(attachment);
            }
        }
        if (gallery.isEmpty()) gallery.add(selected);
        InlineMediaPreviewDialog.show(context, gallery, Math.min(selectedIndex, gallery.size() - 1));
    }

    private String mediaLabel(JsonObject attachment) {
        String type = Jsons.string(attachment, "media_type");
        String name = Jsons.string(attachment, "file_name");
        if (isAudioAttachment(attachment)) {
            long seconds = Math.max(1, Jsons.longValue(attachment, "duration_ms") / 1000L);
            return (isRecordedVoice(attachment) ? "语音" : "音频") + "  " + seconds + " 秒\n点击播放";
        }
        if (isVideoAttachment(attachment)) return "视频" + (name.isEmpty() ? "" : "  " + name) + "\n点击播放";
        long bytes = Jsons.longValue(attachment, "size_bytes");
        return "文件  " + (name.isEmpty() ? "未命名文件" : name) + (bytes > 0 ? "\n" + sizeText(bytes) : "");
    }

    private void previewAttachment(Context context, JsonObject attachment) {
        JsonObject file = attachment.deepCopy();
        file.addProperty("file_url", Jsons.string(attachment, "url"));
        file.addProperty("original_name", attachmentName(attachment));
        FilePreviewActivity.open(context, file);
    }

    private String absolute(Context context, String url) {
        return ImageLoader.get().absoluteUrl(context, url);
    }

    @Override public int getItemCount() { return items.size(); }

    private boolean isMine(JsonObject item) {
        if (item.has("snapshot_mine")) {
            try { return item.get("snapshot_mine").getAsBoolean(); } catch (RuntimeException ignored) { }
        }
        String type = Jsons.string(item, "sender_type");
        if (!type.isEmpty()) {
            if (role == Role.PLATFORM) {
                if (!"platform".equals(type)) return false;
                long senderPlatform = Jsons.longValue(item, "sender_platform_id");
                if (senderPlatform == 0) senderPlatform = Jsons.longValue(item, "sender_id");
                return actorId > 0 && senderPlatform > 0 && actorId == senderPlatform;
            }
            if (role == Role.ADMIN) {
                if (!"admin".equals(type)) return false;
                long senderAdmin = Jsons.longValue(item, "sender_admin_id");
                return senderAdmin == 0 || actorId == senderAdmin;
            }
            if (role == Role.USER && !"user".equals(type)) return false;
        }
        long sender = Jsons.longValue(item, "sender_id");
        if (sender == 0) sender = Jsons.longValue(item, "user_id");
        return actorId > 0 && sender > 0 && actorId == sender;
    }

    private static long itemId(JsonObject item) {
        long id = Jsons.longValue(item, "id");
        return id > 0 ? id : item.toString().hashCode();
    }

    private static boolean booleanValue(JsonObject item, String key) {
        try { return item.has(key) && !item.get(key).isJsonNull() && item.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private String senderName(String type) {
        if ("admin".equals(type)) return "管理员";
        if ("system".equals(type)) return "系统";
        return "用户";
    }

    private static String firstCharacter(String value) {
        if (value == null || value.isEmpty()) return "?";
        return value.substring(0, value.offsetByCodePoints(0, 1)).toUpperCase(Locale.getDefault());
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }

    private static String sizeText(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024f);
        return String.format(Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
    }

    private static final class AccessibleStackFrame extends FrameLayout {
        AccessibleStackFrame(Context context) { super(context); }

        @Override public boolean performClick() {
            super.performClick();
            return true;
        }
    }

    static final class Holder extends RecyclerView.ViewHolder {
        final ItemChatMessageBinding binding;
        Holder(ItemChatMessageBinding binding) { super(binding.getRoot()); this.binding = binding; }
    }
}
