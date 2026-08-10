package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.graphics.drawable.ColorDrawable;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.TextView;

import androidx.core.widget.NestedScrollView;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class CommentVoiceComposerUiTest {
    @Test public void momentCommentComposerProvidesVoiceEmojiStickerAndSendControls() {
        ActivityController<Activity> controller = themedActivity();
        View root = controller.get().getLayoutInflater()
            .inflate(R.layout.sheet_moment_comments, null, false);

        assertNotNull(root.findViewById(R.id.commentInput));
        View stickyHeader = root.findViewById(R.id.commentsStickyHeader);
        TextView commentCount = root.findViewById(R.id.commentCountText);
        assertNotNull(stickyHeader);
        assertNotNull(commentCount);
        assertEquals(root, stickyHeader.getParent());
        assertTrue(((ViewGroup) root).getClipChildren());
        assertTrue(((ViewGroup) root).getClipToPadding());
        assertTrue(stickyHeader.getBackground() instanceof ColorDrawable);
        int headerColor = ((ColorDrawable) stickyHeader.getBackground()).getColor();
        assertEquals(0xFF, android.graphics.Color.alpha(headerColor));
        assertEquals("加载中", commentCount.getText().toString());
        assertNotNull(root.findViewById(R.id.emojiButton));
        assertNotNull(root.findViewById(R.id.stickerButton));
        assertNotNull(root.findViewById(R.id.voiceButton));
        assertNotNull(root.findViewById(R.id.voiceStatusRow));
        assertNotNull(root.findViewById(R.id.voiceStatusTitle));
        assertNotNull(root.findViewById(R.id.voiceStatusIcon));
        assertNotNull(root.findViewById(R.id.voiceClearButton));
        assertNotNull(root.findViewById(R.id.sendButton));
        assertEquals(View.GONE, root.findViewById(R.id.voiceStatusRow).getVisibility());
        assertTouchSafeHeight(root.findViewById(R.id.voiceButton));
        assertTouchSafeHeight(root.findViewById(R.id.voiceClearButton));
        NestedScrollView comments = root.findViewById(R.id.commentsScroll);
        FrameLayout viewport = root.findViewById(R.id.commentsViewport);
        assertNotNull(comments);
        assertNotNull(viewport);
        assertEquals(viewport, comments.getParent());
        assertTrue(viewport.getClipChildren());
        assertTrue(viewport.getClipToPadding());
        assertTrue(comments.getClipChildren());
        assertTrue(comments.getClipToPadding());
        assertEquals(ViewGroup.LayoutParams.WRAP_CONTENT, comments.getLayoutParams().height);
        assertFalse(comments.isFillViewport());

        controller.pause().stop().destroy();
    }

    @Test public void momentAndCommentActionsStaySingleLineWithTouchSafeHeight() {
        ActivityController<Activity> controller = themedActivity();
        View moment = controller.get().getLayoutInflater()
            .inflate(R.layout.item_moment_timeline, null, false);
        assertSingleLineAction(moment.findViewById(R.id.momentLikeButton));
        assertSingleLineAction(moment.findViewById(R.id.momentCommentButton));
        assertSingleLineAction(moment.findViewById(R.id.momentFavoriteButton));
        assertSingleLineAction(moment.findViewById(R.id.momentForwardButton));

        View comment = controller.get().getLayoutInflater()
            .inflate(R.layout.item_moment_comment, null, false);
        assertSingleLineAction(comment.findViewById(R.id.likeButton));
        assertSingleLineAction(comment.findViewById(R.id.replyButton));
        assertNotNull(comment.findViewById(R.id.replyThreadContainer));
        assertNotNull(comment.findViewById(R.id.nestedRepliesContainer));
        View replyToggle = comment.findViewById(R.id.replyThreadToggle);
        assertNotNull(replyToggle);
        assertEquals(View.GONE, replyToggle.getVisibility());
        assertEquals(Math.round(32f * replyToggle.getResources().getDisplayMetrics().density),
            replyToggle.getLayoutParams().height);

        controller.pause().stop().destroy();
    }

    @Test public void forumCommentComposerProvidesDedicatedVoiceStateAndButton() {
        ActivityController<Activity> controller = themedActivity();
        View root = controller.get().getLayoutInflater()
            .inflate(R.layout.activity_forum_post, null, false);

        assertNotNull(root.findViewById(R.id.commentInput));
        assertNotNull(root.findViewById(R.id.commentEmojiButton));
        assertNotNull(root.findViewById(R.id.commentAttachButton));
        assertNotNull(root.findViewById(R.id.commentVoiceButton));
        assertNotNull(root.findViewById(R.id.commentVoiceStatusCard));
        assertNotNull(root.findViewById(R.id.commentVoiceStatusTitle));
        assertNotNull(root.findViewById(R.id.commentVoiceStatusIcon));
        assertNotNull(root.findViewById(R.id.commentVoiceStatus));
        assertNotNull(root.findViewById(R.id.commentVoiceCancelButton));
        assertNotNull(root.findViewById(R.id.sendCommentButton));
        assertEquals(View.GONE, root.findViewById(R.id.commentVoiceStatusCard).getVisibility());
        assertTouchSafeHeight(root.findViewById(R.id.commentVoiceButton));
        assertTouchSafeHeight(root.findViewById(R.id.commentVoiceCancelButton));

        controller.pause().stop().destroy();
    }

    private static ActivityController<Activity> themedActivity() {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        Activity activity = controller.get();
        activity.setTheme(R.style.Theme_Yiyunying);
        controller.setup();
        return controller;
    }

    private static void assertSingleLineAction(TextView view) {
        assertNotNull(view);
        assertEquals(1, view.getMaxLines());
        assertFalse(view.getIncludeFontPadding());
        int expectedHeight = Math.round(48f * view.getResources().getDisplayMetrics().density);
        assertEquals(expectedHeight, view.getLayoutParams().height);
    }

    private static void assertTouchSafeHeight(View view) {
        assertNotNull(view);
        int expectedHeight = Math.round(48f * view.getResources().getDisplayMetrics().density);
        assertEquals(expectedHeight, view.getLayoutParams().height);
    }
}
