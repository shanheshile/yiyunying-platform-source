package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNull;

import okhttp3.Request;
import org.junit.Test;

public class UpdateRequestPolicyTest {
    @Test public void partialDownloadCarriesExactRangeAndStrongValidator() {
        Request request = UpdateRequestPolicy.build(
            "https://example.test/app.apk", 4096L, "\"v59\"", "yesterday");
        assertEquals("bytes=4096-", request.header("Range"));
        assertEquals("\"v59\"", request.header("If-Range"));
        assertEquals("identity", request.header("Accept-Encoding"));
        assertEquals("no-cache, no-store", request.header("Cache-Control"));
    }

    @Test public void cleanDownloadDoesNotSendRangeHeaders() {
        Request request = UpdateRequestPolicy.build(
            "http://example.test/app.apk", 0L, "\"v59\"", "yesterday");
        assertNull(request.header("Range"));
        assertNull(request.header("If-Range"));
        assertEquals("identity", request.header("Accept-Encoding"));
    }

    @Test public void weakEntityTagFallsBackToLastModified() {
        Request request = UpdateRequestPolicy.build(
            "https://example.test/app.apk", 8L, "W/\"v59\"", "yesterday");
        assertEquals("yesterday", request.header("If-Range"));
    }
}
