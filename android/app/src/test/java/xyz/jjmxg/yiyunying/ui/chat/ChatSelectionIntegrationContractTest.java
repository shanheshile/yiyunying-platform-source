package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import org.junit.Test;

public final class ChatSelectionIntegrationContractTest {
    @Test public void variableHeightHistoryAndDirectionalRangeHandlesStayEnabled() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatActivity.java");
        assertTrue(source.contains("binding.recycler.setHasFixedSize(false)"));
        assertTrue(source.contains("binding.recycler.setItemViewCacheSize(6)"));
        assertTrue(source.contains("selectionScrollDirection < 0"));
        assertTrue(source.contains("selectionScrollDirection > 0"));
        assertTrue(source.contains("scheduleViewportAnchorRestore(anchor, 0L, 80L, 180L)"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path fromAndroidRoot = Path.of("app").resolve(relative);
        Path path = Files.isRegularFile(direct) ? direct : fromAndroidRoot;
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
