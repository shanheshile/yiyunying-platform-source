<?php
declare(strict_types=1);

/**
 * Classify a file that remains below public/uploads.
 *
 * The fallback recognizes only the public upload allowlist, and only when a
 * canonical extension agrees with a bounded file signature. Everything else
 * is deliberately fail-closed.
 *
 * @return 'safe'|'svg'|'unknown'
 */
function catalogMigrationAssessPublicUploadFile(string $path, bool $forceSignatureFallback = false): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'svg' || $extension === 'svgz') return 'svg';

    $expectedMimes = catalogMigrationExpectedMimes($extension);

    if (!$forceSignatureFallback) {
        $detectedMime = catalogMigrationFileinfoMime($path);
        if ($detectedMime !== null) {
            if (in_array($detectedMime, ['image/svg+xml', 'image/svg', 'text/svg'], true)) return 'svg';
            if (in_array($detectedMime, $expectedMimes, true)) return 'safe';
            // Some fileinfo databases return application/octet-stream for valid
            // WebP files. Confirm those through the bounded signature path.
        }
    }

    $prefix = catalogMigrationReadFilePrefix($path, 8192);
    if ($prefix === null) return 'unknown';
    if (preg_match('/<svg(?:\s|>)/i', $prefix) === 1) return 'svg';

    return catalogMigrationSignatureMatchesExtension($prefix, $extension) ? 'safe' : 'unknown';
}

/** @return list<string> */
function catalogMigrationExpectedMimes(string $extension): array
{
    return [
        'jpg' => ['image/jpeg', 'image/pjpeg'], 'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'heic' => ['image/heic', 'image/heif'], 'heif' => ['image/heif', 'image/heic'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'], 'md' => ['text/markdown', 'text/plain'],
        'json' => ['application/json', 'text/json', 'text/plain'],
        'csv' => ['text/csv', 'application/csv', 'text/plain'],
        'rtf' => ['application/rtf', 'text/rtf'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed'],
        'tar' => ['application/x-tar'], 'gz' => ['application/gzip', 'application/x-gzip'],
        'bz2' => ['application/x-bzip2'], 'xz' => ['application/x-xz'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'mp3' => ['audio/mpeg'], 'm4a' => ['audio/mp4', 'video/mp4'],
        'aac' => ['audio/aac', 'audio/x-aac'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'opus' => ['audio/opus', 'audio/ogg', 'application/ogg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'mp4' => ['video/mp4'], 'webm' => ['video/webm', 'audio/webm'],
        'mov' => ['video/quicktime'], 'mkv' => ['video/x-matroska'],
        'avi' => ['video/x-msvideo', 'video/avi'],
        '3gp' => ['video/3gpp', 'audio/3gpp'], 'm4v' => ['video/x-m4v', 'video/mp4'],
        'apk' => ['application/vnd.android.package-archive', 'application/zip'],
    ][$extension] ?? [];
}

function catalogMigrationFileinfoMime(string $path): ?string
{
    $mime = false;
    if (class_exists('finfo') && defined('FILEINFO_MIME_TYPE')) {
        try {
            $detector = new finfo(FILEINFO_MIME_TYPE);
            $mime = @$detector->file($path);
        } catch (Throwable) {
            $mime = false;
        }
    }
    if ((!is_string($mime) || trim($mime) === '') && function_exists('mime_content_type')) {
        try {
            $mime = @mime_content_type($path);
        } catch (Throwable) {
            $mime = false;
        }
    }
    if (!is_string($mime)) return null;
    $normalized = strtolower(trim(explode(';', $mime, 2)[0]));
    return $normalized !== '' && strlen($normalized) <= 150 ? $normalized : null;
}

function catalogMigrationReadFilePrefix(string $path, int $maximumBytes): ?string
{
    if ($maximumBytes < 1 || $maximumBytes > 8192 || !is_file($path)) return null;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try {
        $prefix = fread($handle, $maximumBytes);
        return is_string($prefix) ? $prefix : null;
    } finally {
        fclose($handle);
    }
}

function catalogMigrationSignatureMatchesExtension(string $prefix, string $extension): bool
{
    $length = strlen($prefix);
    $zip = $length >= 4 && in_array(substr($prefix, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    $ole = $length >= 8 && substr($prefix, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    $riffType = $length >= 12 && substr($prefix, 0, 4) === 'RIFF' ? substr($prefix, 8, 4) : '';
    $isoBaseMedia = $length >= 12 && substr($prefix, 4, 4) === 'ftyp';
    $isoBrand = $isoBaseMedia ? strtolower(substr($prefix, 8, 4)) : '';

    return match ($extension) {
        'jpg', 'jpeg' => $length >= 3 && substr($prefix, 0, 3) === "\xFF\xD8\xFF",
        'png' => $length >= 24 && substr($prefix, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A"
            && substr($prefix, 12, 4) === 'IHDR',
        'gif' => $length >= 10 && in_array(substr($prefix, 0, 6), ['GIF87a', 'GIF89a'], true),
        'webp' => $length >= 16 && $riffType === 'WEBP'
            && in_array(substr($prefix, 12, 4), ['VP8 ', 'VP8L', 'VP8X'], true),
        'bmp' => $length >= 14 && substr($prefix, 0, 2) === 'BM',
        'heic', 'heif' => $isoBaseMedia
            && in_array($isoBrand, ['heic', 'heix', 'hevc', 'hevx', 'heif', 'mif1', 'msf1'], true),
        'pdf' => str_starts_with($prefix, '%PDF-'),
        'txt', 'md', 'csv' => catalogMigrationLooksLikeText($prefix),
        'json' => catalogMigrationLooksLikeText($prefix)
            && in_array(substr(ltrim($prefix, "\xEF\xBB\xBF\x09\x0A\x0D\x20"), 0, 1), ['{', '['], true),
        'rtf' => str_starts_with(ltrim($prefix), '{\\rtf'),
        'odt', 'ods', 'odp', 'zip', 'docx', 'xlsx', 'pptx', 'apk' => $zip,
        '7z' => str_starts_with($prefix, "7z\xBC\xAF\x27\x1C"),
        'rar' => str_starts_with($prefix, "Rar!\x1A\x07\x00") || str_starts_with($prefix, "Rar!\x1A\x07\x01\x00"),
        'tar' => $length >= 263 && substr($prefix, 257, 5) === 'ustar',
        'gz' => str_starts_with($prefix, "\x1F\x8B"),
        'bz2' => str_starts_with($prefix, 'BZh'),
        'xz' => str_starts_with($prefix, "\xFD7zXZ\x00"),
        'doc', 'xls', 'ppt' => $ole,
        'mp3' => str_starts_with($prefix, 'ID3')
            || ($length >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xE0) === 0xE0),
        'aac' => $length >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xF6) === 0xF0,
        'wav' => $riffType === 'WAVE',
        'ogg', 'opus' => str_starts_with($prefix, 'OggS'),
        'flac' => str_starts_with($prefix, 'fLaC'),
        'mp4', 'm4a', 'm4v', '3gp', 'mov' => $isoBaseMedia,
        'webm', 'mkv' => str_starts_with($prefix, "\x1A\x45\xDF\xA3"),
        'avi' => $riffType === 'AVI ',
        default => false,
    };
}

function catalogMigrationLooksLikeText(string $prefix): bool
{
    if ($prefix === '' || str_contains($prefix, "\x00")) return false;
    return preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $prefix) !== 1;
}
