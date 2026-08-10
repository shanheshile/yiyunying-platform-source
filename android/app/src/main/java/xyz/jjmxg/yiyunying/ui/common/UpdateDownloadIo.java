package xyz.jjmxg.yiyunying.ui.common;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.InterruptedIOException;
import java.util.function.BooleanSupplier;

/** File-writing primitive shared by the live downloader and deterministic unit tests. */
final class UpdateDownloadIo {
    interface Progress {
        void onBytes(long downloaded) throws IOException;
    }

    static final class SizeLimitException extends IOException {
        SizeLimitException() { super("download exceeds expected size"); }
    }

    private UpdateDownloadIo() { }

    static long copy(
        InputStream input,
        File target,
        boolean append,
        long start,
        long expectedSize,
        BooleanSupplier cancelled,
        Progress progress
    ) throws IOException {
        if (input == null || target == null || expectedSize <= 0L || start < 0L
            || start > expectedSize || append != (start > 0L)) {
            throw new IOException("invalid update download state");
        }
        long existing = target.isFile() ? target.length() : 0L;
        if (append && existing != start) throw new IOException("partial length changed before append");
        if (!append && start != 0L) throw new IOException("restart must begin at zero");
        File parent = target.getParentFile();
        if (parent != null && !parent.exists() && !parent.mkdirs() && !parent.isDirectory()) {
            throw new IOException("cannot create update package directory");
        }
        long total = start;
        byte[] buffer = new byte[64 * 1024];
        try (FileOutputStream output = new FileOutputStream(target, append)) {
            int read;
            while ((read = input.read(buffer)) != -1) {
                if (cancelled != null && cancelled.getAsBoolean()) {
                    throw new InterruptedIOException("update download paused");
                }
                output.write(buffer, 0, read);
                total += read;
                if (total > expectedSize) throw new SizeLimitException();
                if (progress != null) progress.onBytes(total);
            }
            output.getFD().sync();
        }
        return total;
    }
}
