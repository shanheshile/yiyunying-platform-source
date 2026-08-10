package xyz.jjmxg.yiyunying.ui.common;

import okhttp3.CacheControl;
import okhttp3.Request;

/** Builds the exact no-transform HTTP request used by resumable APK downloads. */
final class UpdateRequestPolicy {
    private UpdateRequestPolicy() { }

    static Request build(String url, long offset, String etag, String lastModified) {
        Request.Builder request = new Request.Builder()
            .url(url)
            .cacheControl(new CacheControl.Builder().noCache().noStore().build())
            .header("Accept", "application/vnd.android.package-archive,*/*")
            .header("Accept-Encoding", "identity");
        if (offset > 0L) {
            request.header("Range", "bytes=" + offset + "-");
            String ifRange = UpdateTransportPolicy.ifRange(etag, lastModified);
            if (!ifRange.isEmpty()) request.header("If-Range", ifRange);
        }
        return request.build();
    }
}
