package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import org.junit.Test;

public final class ForumPrivateMediaRuntimeContractTest {
    @Test public void postRefreshUsesFreshNetworkGenerationAndLifecycleScheduling() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumPostActivity.java");
        assertTrue(source.contains("networkAppliedGeneration >= generation"));
        assertTrue(source.contains("if (!refreshingPrivateMedia)"));
        assertTrue(source.contains("query.put(\"_media_refresh\""));
        assertTrue(source.contains("\"offline-cache\".equals(result.traceId())"));
        assertTrue(source.contains("schedulePrivateMediaRefresh()"));
        assertTrue(source.contains("@Override protected void onResume()"));
        assertTrue(source.contains("privateMediaHandler.removeCallbacksAndMessages(null)"));
    }

    @Test public void rendererChecksTheCapabilityAgainBeforeEveryInteractiveOpen() throws Exception {
        String renderer = read("src/main/java/xyz/jjmxg/yiyunying/ui/common/MediaViewRenderer.java");
        assertTrue(renderer.contains("ForumPrivateMediaPolicy.shouldRefresh"));
        assertTrue(renderer.contains("onPrivateMediaRefreshRequired"));
        assertTrue(renderer.contains("() -> !requestPrivateMediaRefresh"));
    }

    @Test public void audioPlayerNeverUsesMediaPlayerUntilPreparedAndReleasesErrors() throws Exception {
        String player = read("src/main/java/xyz/jjmxg/yiyunying/ui/chat/InlineAudioPlayerView.java");
        assertTrue(player.contains("private boolean prepared;"));
        assertTrue(player.contains("if (preparing || !prepared) return;"));
        assertTrue(player.contains("player != null && prepared && !preparing"));
        assertTrue(player.contains("if (player == null || !prepared || preparing) return;"));
        assertTrue(player.contains("player = null;"));
        assertTrue(player.contains("failPlayer(value);"));
        assertTrue(player.contains("release(current);"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
