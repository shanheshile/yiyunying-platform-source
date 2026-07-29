package xyz.jjmxg.yiyunying.ui.upload;

import android.content.ContentResolver;
import android.net.Uri;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import java.io.IOException;
import java.io.InputStream;
import java.io.FileInputStream;

import okhttp3.MediaType;
import okhttp3.RequestBody;
import okio.BufferedSink;
import okio.Okio;
import okio.Source;

public final class ContentUriRequestBody extends RequestBody {
    private final ContentResolver resolver;
    private final Uri uri;
    private final MediaType mediaType;
    private final long length;

    public ContentUriRequestBody(ContentResolver resolver, Uri uri, String mimeType, long length) {
        this.resolver = resolver;
        this.uri = uri;
        this.mediaType = MediaType.parse(mimeType == null ? "application/octet-stream" : mimeType);
        this.length = length;
    }

    @Nullable @Override public MediaType contentType() { return mediaType; }
    @Override public long contentLength() { return length; }

    @Override public void writeTo(@NonNull BufferedSink sink) throws IOException {
        try (InputStream stream = "file".equalsIgnoreCase(uri.getScheme())
            ? new FileInputStream(uri.getPath()) : resolver.openInputStream(uri)) {
            if (stream == null) throw new IOException("Unable to open selected file");
            try (Source source = Okio.source(stream)) {
                sink.writeAll(source);
            }
        }
    }
}
