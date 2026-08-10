package xyz.jjmxg.yiyunying.domain.chat;

import static org.junit.Assert.assertEquals;

import org.junit.Test;

public final class ChatUploadScenePolicyTest {
    @Test public void cameraAndAlbumUseServerRecognizedScenes() {
        assertEquals("chat_camera", ChatUploadScenePolicy.from("camera"));
        assertEquals("chat_album", ChatUploadScenePolicy.from(" ALBUM "));
    }

    @Test public void unrelatedAttachmentsRemainGenericMessages() {
        assertEquals("message", ChatUploadScenePolicy.from(""));
        assertEquals("message", ChatUploadScenePolicy.from("file_picker"));
    }
}
