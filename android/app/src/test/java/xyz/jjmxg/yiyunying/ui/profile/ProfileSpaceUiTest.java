package xyz.jjmxg.yiyunying.ui.profile;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.view.ContextThemeWrapper;
import android.view.LayoutInflater;
import android.view.View;

import androidx.test.core.app.ApplicationProvider;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.imageview.ShapeableImageView;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.domain.module.ActionSpec;
import xyz.jjmxg.yiyunying.domain.module.ModuleRegistry;
import xyz.jjmxg.yiyunying.domain.module.ModuleSpec;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class ProfileSpaceUiTest {
    private Context context;

    @Before public void setUp() {
        context = new ContextThemeWrapper(
            ApplicationProvider.getApplicationContext(),
            R.style.Theme_Yiyunying
        );
    }

    @Test public void groupAndChatroomProfileHasAvatarManagementSurface() {
        View root = LayoutInflater.from(context).inflate(R.layout.activity_group_space, null, false);
        assertTrue(root.findViewById(R.id.groupAvatar) instanceof ShapeableImageView);
        MaterialButton upload = root.findViewById(R.id.groupAvatarButton);
        assertNotNull(upload);
        assertEquals(View.GONE, upload.getVisibility());
        assertTrue(upload.getText().toString().contains("头像"));
        assertNotNull(root.findViewById(R.id.groupEditButton));
        assertNotNull(root.findViewById(R.id.groupQrButton));
        assertNotNull(root.findViewById(R.id.tabs));
    }

    @Test public void personalFriendAndConversationProfilesUseConsistentCards() {
        View personal = LayoutInflater.from(context).inflate(R.layout.fragment_profile, null, false);
        assertTrue(personal.findViewById(R.id.avatar) instanceof ShapeableImageView);
        assertNotNull(personal.findViewById(R.id.avatarButton));
        assertNotNull(personal.findViewById(R.id.avatarHistoryButton));

        View friend = LayoutInflater.from(context).inflate(R.layout.activity_user_profile, null, false);
        assertTrue(friend.findViewById(R.id.avatar) instanceof ShapeableImageView);
        assertNotNull(friend.findViewById(R.id.followButton));
        assertNotNull(friend.findViewById(R.id.friendButton));
        assertNotNull(friend.findViewById(R.id.messageButton));

        View conversation = LayoutInflater.from(context)
            .inflate(R.layout.activity_conversation_permission, null, false);
        assertNotNull(conversation.findViewById(R.id.profile_entry));
        assertNotNull(conversation.findViewById(R.id.pinned_switch));
        assertNotNull(conversation.findViewById(R.id.friend_section));
        assertNotNull(conversation.findViewById(R.id.choose_background_button));
    }

    @Test public void forumPlateAdminModuleOffersLocalAvatarUpload() {
        ModuleSpec plates = new ModuleRegistry().find(Role.ADMIN, "forum_plates");
        assertNotNull(plates);
        ActionSpec avatar = plates.itemActions().stream()
            .filter(action -> "UPLOAD_IMAGE".equals(action.method()))
            .findFirst()
            .orElse(null);
        assertNotNull(avatar);
        assertTrue(avatar.title().contains("头像"));
        assertTrue(avatar.pathTemplate().endsWith("/{plate_id}/avatar"));
    }
}
