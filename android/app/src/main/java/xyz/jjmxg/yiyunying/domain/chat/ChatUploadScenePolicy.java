package xyz.jjmxg.yiyunying.domain.chat;

import java.util.Locale;

/** Maps locally established picker provenance to the server-persisted upload scene. */
public final class ChatUploadScenePolicy {
    private ChatUploadScenePolicy() { }

    public static String from(String source) {
        String normalized = source == null ? "" : source.trim().toLowerCase(Locale.ROOT);
        if ("camera".equals(normalized)) return "chat_camera";
        if ("album".equals(normalized)) return "chat_album";
        return "message";
    }
}
