package xyz.jjmxg.yiyunying.data.api;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class ApiVisibleMessageTest {

    @Test
    public void visibleContentPreservesNormalLongBusinessCopy() {
        String content = "系统将于今晚二十三点开始维护。维护期间聊天记录仍可离线查看，服务恢复后会自动同步未读消息。";
        assertEquals(content, ApiVisibleMessage.visibleContent(content, "暂无通知正文"));
    }

    @Test
    public void visibleContentHidesSerializedAndRuntimeDiagnostics() {
        assertEquals("暂无通知正文",
            ApiVisibleMessage.visibleContent("{\"debug\":\"SQLSTATE[HY000]\",\"path\":\"/www/wwwroot/app.php\"}", "暂无通知正文"));
        assertEquals("通知暂不可显示",
            ApiVisibleMessage.visibleContent("NOTICE: PHP Warning: proc_open(): Exec failed: Permission denied", "通知暂不可显示"));
    }
    @Test
    public void keepsReadableBusinessMessage() {
        assertEquals("余额不足，请先充值", ApiVisibleMessage.visible("余额不足，请先充值", 422, 0));
        assertEquals("quota exceeded", ApiVisibleMessage.visible("quota exceeded", 422, 0));
    }

    @Test
    public void extractsMessageFromNestedSerializedJson() {
        String raw = "{\"code\":0,\"error\":{\"message\":\"订单已失效\"}}";
        assertEquals("订单已失效", ApiVisibleMessage.visible(raw, 409, 0));
    }

    @Test
    public void hidesObjectsWithoutReadableMessage() {
        String visible = ApiVisibleMessage.visible("{\"id\":7,\"payload\":{\"token\":\"secret\"}}", 500, 0);
        assertEquals("服务器暂时无法处理请求，请稍后重试。", visible);
        assertFalse(visible.contains("token"));
        assertFalse(visible.contains("{"));
    }

    @Test
    public void hidesSerializedPrimitiveArraysAndQuotedValues() {
        String array = ApiVisibleMessage.visible("[\"secret-token\",\"server-path\"]", 500, 0);
        String quoted = ApiVisibleMessage.visible("\"internal-debug-value\"", 500, 0);
        assertEquals("服务器暂时无法处理请求，请稍后重试。", array);
        assertEquals("服务器暂时无法处理请求，请稍后重试。", quoted);
        assertFalse(array.contains("secret"));
        assertFalse(quoted.contains("debug"));
    }

    @Test
    public void extractsExplicitMessageFromObjectInsideArray() {
        String visible = ApiVisibleMessage.visible("[{\"message\":\"投票已结束\"}]", 409, 0);
        assertEquals("投票已结束", visible);
    }

    @Test
    public void hidesPhpWarningsAndServerPaths() {
        String raw = "本地语音转写失败：NOTICE: PHP message: PHP Warning: proc_open(): Exec failed: Permission denied in /www/wwwroot/app/api.php:19";
        String visible = ApiVisibleMessage.visible(raw, 500, 0);
        assertEquals("服务器暂时无法处理请求，请稍后重试。", visible);
        assertFalse(visible.contains("PHP"));
        assertFalse(visible.contains("/www/"));
    }

    @Test
    public void hidesJavaExceptionNames() {
        String visible = ApiVisibleMessage.visible(
            "java.lang.IllegalStateException: Fragment not attached to a context", 500, 0);
        assertEquals("服务器暂时无法处理请求，请稍后重试。", visible);
        assertFalse(visible.contains("IllegalStateException"));
    }

    @Test
    public void givesFriendlyUploadLimitMessage() {
        String visible = ApiVisibleMessage.visible("<html><h1>413 Request Entity Too Large</h1></html>", 413, -1);
        assertTrue(visible.contains("上传内容过大"));
        assertFalse(visible.contains("html"));
    }
}
