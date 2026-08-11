package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import org.junit.Test;

public final class ForumCommentViewUiTest {
    @Test public void detailOffersChineseSortAndRelatedThreadMode() throws Exception {
        String layout = read("src/main/res/layout/activity_forum_post.xml");
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumPostActivity.java");
        assertTrue(layout.contains("@+id/commentSortButton"));
        assertTrue(layout.contains("@+id/commentThreadModeButton"));
        assertTrue(layout.contains("@+id/commentThreadPageButton"));
        assertTrue(source.contains("query.put(\"scope\", \"roots\")"));
        assertTrue(source.contains("query.put(\"scope\", \"thread\")"));
        assertTrue(source.contains("query.put(\"comment_id\""));
        assertTrue(source.contains("query.put(\"page\", Integer.toString(Math.max(1, requestedPage)))"));
        assertTrue(source.contains("ForumThreadPaginationPolicy.label(page, totalPages)"));
        assertTrue(source.contains("query.put(\"comment_sort\", ForumSortPolicy.normalize(commentSort))"));
        assertTrue(source.contains("reply_preview"));
        assertTrue(source.contains("reply_to_name"));
        assertTrue(source.contains("相关评论"));
        assertTrue(source.contains("renderComments(rootComments);\n        if (role == Role.USER) loadCommentRoots(rootCommentPage);"));
        assertTrue(source.contains("nestedContainer.setVisibility(totalReplyCount > 0"));
        assertTrue(source.contains("if (cachedRequest != null) cachedRequest.cancel()"));
        assertTrue(source.contains("STATE_RELATED_COMMENT_PAGE"));
    }

    @Test public void forumListOffersServerBackedSortControl() throws Exception {
        String layout = read("src/main/res/layout/activity_forum_list.xml");
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumListActivity.java");
        assertTrue(layout.contains("@+id/sortButton"));
        assertTrue(source.contains("query.put(\"sort\", ForumSortPolicy.normalize(postSort))"));
        assertTrue(source.contains("setTitle(\"帖子排序\")"));
        assertTrue(source.contains("R.color.on_forum_publish_container"));
        assertTrue(source.contains("if (cachedRequest != null) cachedRequest.cancel()"));
        assertTrue(source.contains("STATE_SCROLL_POSITION"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
