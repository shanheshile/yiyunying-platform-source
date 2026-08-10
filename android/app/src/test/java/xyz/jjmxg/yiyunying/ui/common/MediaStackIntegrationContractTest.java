package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import org.junit.Test;

public final class MediaStackIntegrationContractTest {
    @Test public void chatUsesIndependentImageAndVideoStackKeysAndLayerAnimator() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatAdapter.java");
        assertTrue(source.contains("List<JsonObject> imageMedia"));
        assertTrue(source.contains("List<JsonObject> videoMedia"));
        assertTrue(source.contains("videos ? \":video\" : \":image\""));
        assertTrue(source.contains("MediaStackTransitionPolicy.transition"));
        assertTrue(source.contains("MediaStackAnimator.animate"));
        assertFalse(source.contains("translationX(direction * dp(context, 54)).alpha"));
    }

    @Test public void commentRendererSeparatesGroupsAndKeepsAudioSeekPlayer() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/MediaViewRenderer.java");
        assertTrue(source.contains("renderCommentMedia"));
        assertTrue(source.contains("List<JsonObject> images"));
        assertTrue(source.contains("List<JsonObject> videos"));
        assertTrue(source.contains("commentMediaStack"));
        assertTrue(source.contains("new InlineAudioPlayerView"));
        assertTrue(source.contains("MediaStackAnimator.animate"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
