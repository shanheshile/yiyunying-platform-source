package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import org.junit.Test;

public final class ForumCommentUiContractTest {
    @Test public void repliesStayInsideCompactThreadAndActionsUseSemanticIcons() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumPostActivity.java");
        assertTrue(source.contains("nestedContainer.addView(repliesContainer"));
        assertTrue(source.contains("dp(parentCommentId > 0 ? 34 : 38)"));
        assertTrue(source.contains("ActionIconResolver.resolve"));
        assertTrue(source.contains("R.drawable.ic_reply"));
        assertTrue(source.contains("R.drawable.ic_like"));
        assertTrue(source.contains("R.drawable.ic_favorite"));
        assertTrue(source.contains("MediaViewRenderer.renderCommentMedia"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
