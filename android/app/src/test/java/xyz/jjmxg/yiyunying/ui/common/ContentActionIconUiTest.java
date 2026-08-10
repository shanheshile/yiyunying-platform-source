package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import org.junit.Test;

public final class ContentActionIconUiTest {
    @Test public void primaryChatForumAndMomentActionsHaveSemanticIcons() throws Exception {
        String chat = read("src/main/res/layout/activity_chat.xml");
        assertTrue(chat.contains("@drawable/ic_forward"));
        assertTrue(chat.contains("@drawable/ic_favorite"));
        assertTrue(chat.contains("@drawable/ic_delete"));

        String forum = read("src/main/res/layout/activity_forum_post.xml");
        assertTrue(forum.contains("@drawable/ic_like"));
        assertTrue(forum.contains("@drawable/ic_favorite"));
        assertTrue(forum.contains("@drawable/ic_comment"));

        String moment = read("src/main/res/layout/item_moment_timeline.xml");
        assertTrue(moment.contains("@drawable/ic_like"));
        assertTrue(moment.contains("@drawable/ic_comment"));
        assertTrue(moment.contains("@drawable/ic_favorite"));
        assertTrue(moment.contains("@drawable/ic_forward"));

        String comment = read("src/main/res/layout/item_moment_comment.xml");
        assertTrue(comment.contains("@drawable/ic_like"));
        assertTrue(comment.contains("@drawable/ic_reply"));
    }

    @Test public void reusableActionSurfacesApplyTheSameResolver() throws Exception {
        String glass = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/GlassActionDialog.java");
        String detail = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/RecordDetailDialog.java");
        String modal = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/ModalLayerGuard.java");
        String form = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/DynamicFormDialog.java");
        String module = read("src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java");
        String records = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/RecordAdapter.java");
        assertTrue(glass.contains("ActionIconResolver.apply(button, action.label"));
        assertTrue(detail.contains("ActionIconResolver.apply(button, action.label"));
        assertTrue(modal.contains("ActionIconResolver.apply(row, label, 0)"));
        assertTrue(form.contains("ActionIconResolver.apply((MaterialButton) positive, action.title(), 0, true)"));
        assertTrue(module.contains("ActionIconResolver.resolve(value, 0)"));
        assertTrue(records.contains("的操作菜单"));
    }

    @Test public void remainingStaticActionButtonsHaveIconsAndAccessibleLabels() throws Exception {
        String documents = read("src/main/res/layout/activity_document_editor.xml");
        assertTrue(documents.contains("android:contentDescription=\"删除这篇文档\""));
        assertTrue(documents.contains("android:contentDescription=\"创建文档分享码\""));

        String cache = read("src/main/res/layout/activity_cache_management.xml");
        assertTrue(cache.contains("android:contentDescription=\"清除全部应用数据\""));
        assertTrue(cache.contains("app:icon=\"@drawable/ic_delete\""));

        String poll = read("src/main/res/layout/item_poll_option_editor.xml");
        assertTrue(poll.contains("android:contentDescription=\"删除这个选项\""));
        assertTrue(poll.contains("app:icon=\"@drawable/ic_delete\""));

        String chat = read("src/main/res/layout/activity_chat.xml");
        assertTrue(chat.contains("android:contentDescription=\"清空聊天搜索历史\""));
    }

    @Test public void noRequestedMaterialButtonActionIsLeftTextOnly() throws Exception {
        Path layoutRoot = locate("src/main/res/layout");
        Pattern button = Pattern.compile(
            "<com\\.google\\.android\\.material\\.button\\.MaterialButton\\b.*?/>",
            Pattern.DOTALL);
        Pattern requested = Pattern.compile(
            "android:text=\\\"[^\\\"]*(删除|收藏|转发|点赞|评论|回复|移除|清空|清理)[^\\\"]*\\\"");
        try (java.util.stream.Stream<Path> files = Files.list(layoutRoot)) {
            files.filter(path -> path.getFileName().toString().endsWith(".xml")).forEach(path -> {
                try {
                    Matcher matcher = button.matcher(new String(Files.readAllBytes(path), StandardCharsets.UTF_8));
                    while (matcher.find()) {
                        String block = matcher.group();
                        if (requested.matcher(block).find()) {
                            assertTrue(path.getFileName() + " has a text-only requested action: " + block,
                                block.contains("app:icon=\""));
                            assertTrue(path.getFileName() + " has an unlabeled requested action: " + block,
                                block.contains("android:contentDescription=\""));
                        }
                    }
                } catch (java.io.IOException error) {
                    throw new AssertionError(error);
                }
            });
        }
    }

    private static String read(String relative) throws Exception {
        Path path = locate(relative);
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }

    private static Path locate(String relative) {
        Path direct = Path.of(relative);
        return Files.exists(direct) ? direct : Path.of("app").resolve(relative);
    }
}
