package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import org.junit.Test;

public final class ForumComposerUnlockUiTest {
    @Test public void composerExposesIndependentPaymentAndScheduleControls() throws Exception {
        String layout = read("src/main/res/layout/activity_forum_composer.xml");
        assertTrue(layout.contains("@+id/scheduledSwitch"));
        assertTrue(layout.contains("@+id/unlockAtInput"));
        assertTrue(layout.contains("正文、章节和附件都可分别设置"));
        assertTrue(layout.contains("@color/forum_chapter_container"));
        assertTrue(layout.contains("锁定时显示的预览（可选）"));
    }

    @Test public void publishingBuildsProtectedContentBlocksAtomicallyAndOmitsPublicOriginalName() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumComposerActivity.java");
        assertTrue(source.contains("protectedAttachmentSections"));
        assertTrue(source.contains("body.add(\"sections\", allSections)"));
        assertTrue(source.contains("ForumUnlockPolicy.PAID_OR_SCHEDULED")
            || source.contains("ForumUnlockPolicy.from"));
        assertFalse(source.contains("attachment.addProperty(\"file_name\", item.name)"));
        assertFalse(source.contains("configurePaidContent("));
        assertFalse(source.contains("attachmentUnlockEnabled && ForumUnlockPolicy.protectedContent"));
        assertFalse(source.contains("binding.paidSwitch.setChecked(false)"));
        assertFalse(source.contains("binding.scheduledSwitch.setChecked(false)"));
        assertTrue(source.contains("unsupportedProtectionReason()"));
        assertTrue(source.contains("? \"forum_section\" : \"forum_post\""));
        assertTrue(source.contains("attachment.stickerId > 0"));
        assertTrue(source.contains("表情包属于公共素材，不能作为付费或定时附件"));
        assertTrue(source.contains("body.addProperty(\"client_draft_id\", ensureClientDraftId())"));
        assertTrue(source.contains("draft.addProperty(\"client_draft_id\", ensureClientDraftId())"));
        assertTrue(source.contains("UUID.randomUUID().toString()"));
        assertTrue(source.contains("草稿保护设置仍被保留，当前禁止发布"));
        assertTrue(source.contains("isFutureUnlockAt"));
        assertTrue(source.contains("自动解锁时间必须晚于当前时间"));
    }

    @Test public void draftAndPublishedButtonsUseDistinctColorFamilies() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumComposerActivity.java");
        String colors = read("src/main/res/values/colors.xml");
        assertTrue(source.contains("R.color.forum_draft_container"));
        assertTrue(colors.contains("name=\"forum_draft_container\""));
        assertTrue(colors.contains("name=\"forum_chapter_container\""));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
