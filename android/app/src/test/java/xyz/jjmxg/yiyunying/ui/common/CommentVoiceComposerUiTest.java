package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.view.View;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class CommentVoiceComposerUiTest {
    @Test public void momentCommentComposerProvidesVoiceEmojiStickerAndSendControls() {
        ActivityController<Activity> controller = themedActivity();
        View root = controller.get().getLayoutInflater()
            .inflate(R.layout.sheet_moment_comments, null, false);

        assertNotNull(root.findViewById(R.id.commentInput));
        assertNotNull(root.findViewById(R.id.emojiButton));
        assertNotNull(root.findViewById(R.id.stickerButton));
        assertNotNull(root.findViewById(R.id.voiceButton));
        assertNotNull(root.findViewById(R.id.voiceStatusRow));
        assertNotNull(root.findViewById(R.id.sendButton));
        assertEquals(View.GONE, root.findViewById(R.id.voiceStatusRow).getVisibility());

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
        assertNotNull(root.findViewById(R.id.commentVoiceStatus));
        assertNotNull(root.findViewById(R.id.sendCommentButton));
        assertEquals(View.GONE, root.findViewById(R.id.commentVoiceStatus).getVisibility());

        controller.pause().stop().destroy();
    }

    private static ActivityController<Activity> themedActivity() {
        ActivityController<Activity> controller = Robolectric.buildActivity(Activity.class);
        Activity activity = controller.get();
        activity.setTheme(R.style.Theme_Yiyunying);
        controller.setup();
        return controller;
    }
}
