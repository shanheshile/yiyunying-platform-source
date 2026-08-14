<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class MediaOptimizationService
{
    public const MAX_JSON_BYTES = 16777216;
    public const MAX_IMAGE_BYTES = 33554432;

    private const MAX_PREFIX_BYTES = 8192;
    private const MAX_IMAGE_INPUT_BYTES = self::MAX_IMAGE_BYTES;
    private const MAX_IMAGE_SIDE = 6144;
    private const MAX_IMAGE_PIXELS = 12000000;
    private const MAX_ARCHIVE_EXPANDED_BYTES = 536870912;
    private const MAX_RTF_BYTES = 16777216;
    private const PROCESS_OUTPUT_BYTES = 65536;
    private const PROBE_TIMEOUT_SECONDS = 15.0;
    private const TRANSCODE_TIMEOUT_SECONDS = 120.0;
    private const FFMPEG_BINARY = '/opt/yiyunying/media-runtime/current/ffmpeg';
    private const FFPROBE_BINARY = '/opt/yiyunying/media-runtime/current/ffprobe';
    private const FALLBACK_STATUSES = [
        'original', 'not_required', 'animated_preserved', 'optimizer_unavailable',
        'already_efficient', 'output_validation_failed',
    ];
    private const FATAL_STATUSES = ['read_failed', 'decode_failed'];

    /** @return array{kind:string,category:string} */
    public static function probeClientUpload(string $path): array
    {
        if (!is_file($path) || is_link($path)) return ['kind' => 'unknown', 'category' => 'file'];
        $prefix = self::readPrefix($path);
        if ($prefix === null || $prefix === '') return ['kind' => 'unknown', 'category' => 'file'];
        $kind = self::signatureKind($prefix);
        return ['kind' => $kind, 'category' => self::categoryForKind($kind)];
    }

    /**
     * Inspect bytes independently of every client-provided MIME value.
     *
     * @return array{accepted:bool,reason:string,kind:string,mime_type:string,width:int,height:int,duration_ms:int,is_animated:bool}
     */
    public static function inspectClientUpload(string $path, string $extension): array
    {
        $extension = strtolower(trim($extension));
        if ($extension === 'svg' || $extension === 'svgz') {
            return self::rejectedInspection('svg_not_allowed', 'svg');
        }
        $expectedKind = self::expectedKind($extension);
        if ($expectedKind === null) {
            return self::rejectedInspection('unsupported_extension', 'unknown');
        }
        if (!is_file($path) || is_link($path)) {
            return self::rejectedInspection('missing_or_linked_file', 'unknown');
        }
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return self::rejectedInspection('unreadable_or_empty_file', 'unknown');
        }
        if ($extension === 'json' && $size > self::MAX_JSON_BYTES) {
            return self::rejectedInspection('json_too_large', 'text');
        }

        $prefix = self::readPrefix($path);
        if ($prefix === null || $prefix === '') {
            return self::rejectedInspection('unreadable_or_empty_file', 'unknown');
        }
        $detectedKind = self::signatureKind($prefix);
        if ($detectedKind === 'svg') {
            return self::rejectedInspection('svg_not_allowed', 'svg');
        }
        if ($detectedKind !== $expectedKind) {
            return self::rejectedInspection('content_extension_mismatch', $detectedKind);
        }

        $mime = self::mimeForExtension($extension);
        if ($mime === '') return self::rejectedInspection('unsupported_extension', $detectedKind);

        $animated = false;
        $structureReason = self::structureFailureReason($path, $extension, $detectedKind, (int) $size, $animated);
        if ($structureReason !== '') return self::rejectedInspection($structureReason, $detectedKind);
        // GD decodes only the first frame of APNG/GIF. Until every frame is
        // independently bounded and decoded, any animation marker/frame count
        // must fail closed before a first-frame decode can make it look safe.
        if ($detectedKind === 'png' && $animated) {
            return self::rejectedInspection('animated_png_not_supported', $detectedKind);
        }
        if ($detectedKind === 'gif' && $animated) {
            return self::rejectedInspection('animated_gif_not_supported', $detectedKind);
        }

        if ($extension === 'json') {
            $jsonBytes = @file_get_contents($path);
            if (!is_string($jsonBytes) || strlen($jsonBytes) !== (int) $size) {
                return self::rejectedInspection('unreadable_or_empty_file', $detectedKind);
            }
            try {
                json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return self::rejectedInspection('invalid_json', $detectedKind);
            }
        }

        if (str_starts_with($mime, 'image/')) {
            if ($size > self::MAX_IMAGE_INPUT_BYTES) {
                return self::rejectedInspection('image_input_too_large', $detectedKind);
            }
            $image = @getimagesize($path);
            if (!is_array($image)) return self::rejectedInspection('image_decoder_unavailable', $detectedKind);
            $actualMime = strtolower(trim((string) ($image['mime'] ?? '')));
            $width = (int) ($image[0] ?? 0);
            $height = (int) ($image[1] ?? 0);
            if ($actualMime !== $mime) return self::rejectedInspection('content_extension_mismatch', $detectedKind);
            if (!self::safeDimensions($width, $height)) {
                return self::rejectedInspection('image_dimensions_unsafe', $detectedKind);
            }
            if (!self::decoderMemoryAvailable((int) $size, $width, $height)) {
                return self::rejectedInspection('image_memory_budget_exceeded', $detectedKind);
            }
            if ($detectedKind === 'webp' && $animated) {
                // GD only decodes the first WebP frame and our structural parser
                // cannot prove that every ANMF payload is a decodable image. Until
                // the fixed production media runtime performs a bounded full
                // animation decode, animated WebP is deliberately fail-closed.
                return self::rejectedInspection('animated_webp_not_supported', $detectedKind);
            }
            if (!function_exists('imagecreatefromstring') || !function_exists('imagesx')
                || !function_exists('imagesy') || !function_exists('imagedestroy')) {
                return self::rejectedInspection('image_decoder_unavailable', $detectedKind);
            }
            $bytes = @file_get_contents($path);
            if (!is_string($bytes) || strlen($bytes) !== (int) $size) {
                return self::rejectedInspection('unreadable_or_empty_file', $detectedKind);
            }
            try {
                $decoded = @imagecreatefromstring($bytes);
            } catch (\Throwable) {
                $decoded = false;
            }
            if ($decoded === false) return self::rejectedInspection('image_decoder_unavailable', $detectedKind);
            $decodedWidth = imagesx($decoded);
            $decodedHeight = imagesy($decoded);
            imagedestroy($decoded);
            if ($decodedWidth !== $width || $decodedHeight !== $height) {
                return self::rejectedInspection('image_dimensions_mismatch', $detectedKind);
            }
            return self::acceptedInspection($detectedKind, $mime, $width, $height, 0, $animated);
        }

        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'audio';
            $probe = self::probeMediaFile($path, $mediaType, $extension);
            if (($probe['accepted'] ?? false) !== true) {
                return self::rejectedInspection((string) ($probe['reason'] ?? $mediaType . '_probe_failed'), $detectedKind);
            }
            return self::acceptedInspection(
                $detectedKind,
                $mime,
                $mediaType === 'video' ? (int) $probe['width'] : 0,
                $mediaType === 'video' ? (int) $probe['height'] : 0,
                (int) $probe['duration_ms'],
                false
            );
        }

        return self::acceptedInspection($detectedKind, $mime, 0, 0, 0, false);
    }

    /** @param array<string,mixed> $optimization */
    public static function optimizationDisposition(string $originalPath, array $optimization): array
    {
        $status = strtolower(trim((string) ($optimization['status'] ?? '')));
        $path = (string) ($optimization['path'] ?? '');
        if (self::isFatalOptimizationStatus($status)) {
            return ['accepted' => false, 'reason' => $status, 'upload_mode' => '', 'publish_optimized_url' => false];
        }
        if ($status === 'optimized') {
            $accepted = $path !== '' && $path !== $originalPath;
            return [
                'accepted' => $accepted,
                'reason' => $accepted ? 'optimized' : 'invalid_optimized_result',
                'upload_mode' => $accepted ? 'optimized' : '',
                'publish_optimized_url' => $accepted,
            ];
        }
        $accepted = in_array($status, self::FALLBACK_STATUSES, true) && $path === $originalPath;
        return [
            'accepted' => $accepted,
            'reason' => $accepted ? 'validated_original' : 'unexpected_optimizer_result',
            'upload_mode' => $accepted ? 'original' : '',
            'publish_optimized_url' => false,
        ];
    }

    public static function isFatalOptimizationStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::FATAL_STATUSES, true);
    }

    public static function optimize(
        string $absolutePath,
        string $mime,
        string $scene,
        int $targetBytes,
        ?array $trustedInspection = null
    ): array
    {
        $mime = strtolower(trim($mime));
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $inspection = is_array($trustedInspection)
            && ($trustedInspection['accepted'] ?? false) === true
            && strtolower((string) ($trustedInspection['mime_type'] ?? '')) === $mime
            ? $trustedInspection
            : self::inspectClientUpload($absolutePath, $extension);
        if (($inspection['accepted'] ?? false) !== true) {
            return self::unchanged($absolutePath, 'decode_failed', false, $mime);
        }
        if (str_starts_with($mime, 'image/')) {
            if (($inspection['is_animated'] ?? false) === true) {
                $result = self::unchanged(
                    $absolutePath,
                    'animated_preserved',
                    true,
                    $mime,
                    (int) $inspection['width'],
                    (int) $inspection['height']
                );
                $result['inspection'] = $inspection;
                return $result;
            }
            if ($mime === 'image/webp') {
                $result = self::unchanged(
                    $absolutePath,
                    'already_efficient',
                    false,
                    $mime,
                    (int) $inspection['width'],
                    (int) $inspection['height']
                );
                $result['inspection'] = $inspection;
                return $result;
            }
            $result = self::image($absolutePath, $mime, $scene, $targetBytes);
            if ((string) ($result['path'] ?? '') === $absolutePath) $result['inspection'] = $inspection;
            return $result;
        }
        if (str_starts_with($mime, 'video/')) return self::video($absolutePath, $mime, $inspection);
        $result = self::unchanged(
            $absolutePath,
            'not_required',
            false,
            $mime,
            (int) ($inspection['width'] ?? 0),
            (int) ($inspection['height'] ?? 0),
            (int) ($inspection['duration_ms'] ?? 0)
        );
        $result['inspection'] = $inspection;
        return $result;
    }

    /** @return array{ready:bool,missing:list<string>} */
    public static function runtimeReadiness(): array
    {
        $missing = [];
        foreach (['getimagesize', 'imagecreatefromstring', 'imagejpeg', 'imagepng', 'imagewebp',
                     'imagecreatetruecolor', 'imagesx', 'imagesy', 'imagedestroy'] as $function) {
            if (!function_exists($function)) $missing[] = 'php-function:' . $function;
        }
        if (!extension_loaded('gd')) $missing[] = 'php-extension:gd';
        if (function_exists('imagetypes')) {
            $types = imagetypes();
            foreach (['jpeg' => IMG_JPG, 'png' => IMG_PNG, 'webp' => IMG_WEBP] as $name => $flag) {
                if (($types & $flag) !== $flag) $missing[] = 'gd-codec:' . $name;
            }
        } else {
            $missing[] = 'php-function:imagetypes';
        }
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!self::processFunctionAvailable($function)) $missing[] = 'php-function:' . $function;
        }
        if (!extension_loaded('zlib')) $missing[] = 'php-extension:zlib';
        foreach (['inflate_init', 'inflate_add', 'inflate_get_status', 'inflate_get_read_len'] as $function) {
            if (!function_exists($function)) $missing[] = 'php-function:' . $function;
        }
        foreach (['ffprobe' => self::FFPROBE_BINARY, 'ffmpeg' => self::FFMPEG_BINARY] as $tool => $binary) {
            $result = self::runProcess([$binary, '-version'], 5.0, 8192);
            if (($result['started'] ?? false) !== true || ($result['exit_code'] ?? -1) !== 0
                || ($result['timed_out'] ?? true) === true || ($result['output_limited'] ?? true) === true) {
                $missing[] = 'executable:' . $tool;
            }
        }
        return ['ready' => $missing === [], 'missing' => array_values(array_unique($missing))];
    }

    private static function image(string $path, string $mime, string $scene, int $targetBytes): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return self::unchanged($path, 'optimizer_unavailable', false, $mime);
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') return self::unchanged($path, 'read_failed', false, $mime);
        $source = @imagecreatefromstring($bytes);
        if ($source === false) return self::unchanged($path, 'decode_failed', false, $mime);
        $width = imagesx($source);
        $height = imagesy($source);
        if (!self::safeDimensions($width, $height)) {
            imagedestroy($source);
            return self::unchanged($path, 'decode_failed', false, $mime);
        }
        $maxSide = str_contains(mb_strtolower($scene), '表情') || str_contains(strtolower($scene), 'sticker') ? 512 : 2560;
        $scale = min(1.0, $maxSide / max(1, $width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($source);
            return self::unchanged($path, 'optimizer_unavailable', false, $mime, $width, $height);
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        $resampled = imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );
        imagedestroy($source);
        if (!$resampled) {
            imagedestroy($canvas);
            return self::unchanged($path, 'output_validation_failed', false, $mime, $width, $height);
        }

        $output = preg_replace('/\.[^.]+$/', '', $path) . '.optimized.webp';
        $quality = 82;
        $saved = false;
        do {
            $saved = @imagewebp($canvas, $output, $quality);
            if (!$saved || !is_file($output)) break;
            if (filesize($output) <= $targetBytes || $quality <= 48) break;
            $quality -= 8;
        } while (true);
        imagedestroy($canvas);
        if (!$saved || !is_file($output) || filesize($output) <= 0 || filesize($output) >= filesize($path)) {
            @unlink($output);
            return self::unchanged($path, 'already_efficient', false, $mime, $width, $height);
        }
        $inspection = self::inspectClientUpload($output, 'webp');
        if (($inspection['accepted'] ?? false) !== true
            || ($inspection['mime_type'] ?? '') !== 'image/webp'
            || (int) ($inspection['width'] ?? 0) !== $targetWidth
            || (int) ($inspection['height'] ?? 0) !== $targetHeight
            || ($inspection['is_animated'] ?? true) !== false) {
            @unlink($output);
            return self::unchanged($path, 'output_validation_failed', false, $mime, $width, $height);
        }
        return [
            'path' => $output, 'mime_type' => 'image/webp', 'size_bytes' => (int) filesize($output),
            'status' => 'optimized', 'is_animated' => false, 'thumbnail_path' => $output,
            'width' => $targetWidth, 'height' => $targetHeight, 'duration_ms' => 0,
            'inspection' => $inspection,
        ];
    }

    private static function video(string $path, string $mime, array $inputProbe): array
    {
        $output = preg_replace('/\.[^.]+$/', '', $path) . '.optimized.mp4';
        $result = self::runProcess([
            self::FFMPEG_BINARY, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path,
            '-vf', "scale='min(1920,iw)':-2", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $output,
        ], self::TRANSCODE_TIMEOUT_SECONDS, self::PROCESS_OUTPUT_BYTES);
        if (($result['started'] ?? false) !== true) {
            @unlink($output);
            return self::unchangedFromInspection($path, 'optimizer_unavailable', $mime, $inputProbe);
        }
        if (($result['exit_code'] ?? -1) !== 0 || ($result['timed_out'] ?? true) === true
            || ($result['output_limited'] ?? true) === true || !is_file($output)
            || filesize($output) <= 0 || filesize($output) >= filesize($path)) {
            @unlink($output);
            return self::unchangedFromInspection($path, 'output_validation_failed', $mime, $inputProbe);
        }
        $inspection = self::inspectClientUpload($output, 'mp4');
        if (($inspection['accepted'] ?? false) !== true || ($inspection['mime_type'] ?? '') !== 'video/mp4'
            || (int) ($inspection['width'] ?? 0) <= 0 || (int) ($inspection['height'] ?? 0) <= 0
            || (int) ($inspection['duration_ms'] ?? 0) <= 0) {
            @unlink($output);
            return self::unchangedFromInspection($path, 'output_validation_failed', $mime, $inputProbe);
        }
        return [
            'path' => $output, 'mime_type' => 'video/mp4', 'size_bytes' => (int) filesize($output),
            'status' => 'optimized', 'is_animated' => false, 'thumbnail_path' => '',
            'width' => (int) $inspection['width'], 'height' => (int) $inspection['height'],
            'duration_ms' => (int) $inspection['duration_ms'],
            'inspection' => $inspection,
        ];
    }

    private static function unchanged(
        string $path,
        string $status,
        bool $animated,
        string $mime,
        int $width = 0,
        int $height = 0,
        int $durationMs = 0
    ): array {
        return [
            'path' => $path, 'mime_type' => $mime,
            'size_bytes' => is_file($path) ? (int) filesize($path) : 0,
            'status' => $status, 'is_animated' => $animated, 'thumbnail_path' => '',
            'width' => max(0, $width), 'height' => max(0, $height),
            'duration_ms' => max(0, $durationMs),
        ];
    }

    /** @param array<string,mixed> $inspection */
    private static function unchangedFromInspection(
        string $path,
        string $status,
        string $mime,
        array $inspection
    ): array {
        $result = self::unchanged(
            $path,
            $status,
            false,
            $mime,
            (int) ($inspection['width'] ?? 0),
            (int) ($inspection['height'] ?? 0),
            (int) ($inspection['duration_ms'] ?? 0)
        );
        $result['inspection'] = $inspection;
        return $result;
    }

    private static function structureFailureReason(
        string $path,
        string $extension,
        string $kind,
        int $size,
        bool &$animated
    ): string {
        if ($kind === 'pdf' && !self::validPdf($path, $size)) return 'invalid_pdf_structure';
        if ($kind === 'zip' && !self::validZip($path, $size, $extension)) return 'invalid_zip_structure';
        if ($kind === 'wav' && !self::validRiff($path, $size, 'WAVE', true)) return 'invalid_wav_structure';
        if ($kind === 'avi' && !self::validRiff($path, $size, 'AVI ', false)) return 'invalid_avi_structure';
        if ($kind === 'ogg' && !self::validOgg($path, $size, $extension === 'opus')) return 'invalid_ogg_structure';
        if ($kind === 'iso_media' && !self::validIsoMedia($path, $size)) return 'invalid_iso_media_structure';
        if ($kind === 'gz' && !self::validGzip($path, $size)) return 'invalid_gzip_structure';
        if ($kind === 'tar' && !self::validTar($path, $size)) return 'invalid_tar_structure';
        if ($kind === 'rtf' && !self::validRtf($path, $size)) return 'invalid_rtf_structure';
        if ($kind === 'png') {
            $state = self::pngStructure($path, $size);
            if (!$state['valid']) return 'invalid_png_structure';
            $animated = $state['animated'];
        }
        if ($kind === 'gif') {
            $state = self::gifStructure($path, $size);
            if (!$state['valid']) return 'invalid_gif_structure';
            $animated = $state['animated'];
        }
        if ($kind === 'webp') {
            $state = self::webpStructure($path, $size);
            if (!$state['valid']) return 'invalid_webp_structure';
            $animated = $state['animated'];
        }
        return '';
    }

    private static function validPdf(string $path, int $size): bool
    {
        if ($size < 16) return false;
        $tail = self::readAt($path, max(0, $size - min($size, 8192)), min($size, 8192));
        if (!is_string($tail)
            || preg_match('/startxref\s+(\d+)\s+%%EOF[\x00\x09\x0A\x0C\x0D\x20]*\z/s', $tail, $match) !== 1) {
            return false;
        }
        $xrefOffset = (int) $match[1];
        if ($xrefOffset <= 0 || $xrefOffset >= $size) return false;
        $xref = self::readAt($path, $xrefOffset, min(512, $size - $xrefOffset));
        return is_string($xref) && (str_starts_with($xref, 'xref')
            || (preg_match('/^\d+\s+\d+\s+obj\b/', $xref) === 1
                && preg_match('#/Type\s*/XRef\b#', $xref) === 1));
    }

    private static function validZip(string $path, int $size, string $extension): bool
    {
        if ($size < 22) return false;
        $tailLength = min($size, 65557);
        $tail = self::readAt($path, $size - $tailLength, $tailLength);
        if (!is_string($tail)) return false;
        $position = strrpos($tail, "PK\x05\x06");
        if ($position === false || $position + 22 > strlen($tail)) return false;
        $fields = unpack('vdisk/vstart/ventries_disk/ventries/Vdirectory_size/Vdirectory_offset/vcomment_length',
            substr($tail, $position + 4, 18));
        if (!is_array($fields)) return false;
        $absolute = $size - $tailLength + $position;
        $entries = (int) $fields['entries'];
        $directorySize = (int) $fields['directory_size'];
        $directoryOffset = (int) $fields['directory_offset'];
        if ((int) $fields['disk'] !== 0 || (int) $fields['start'] !== 0
            || (int) $fields['entries_disk'] !== $entries
            || $entries === 0xFFFF || $directorySize === 0xFFFFFFFF || $directoryOffset === 0xFFFFFFFF
            || $absolute + 22 + (int) $fields['comment_length'] !== $size
            || $directoryOffset + $directorySize !== $absolute) return false;
        if ($entries === 0) {
            return $extension === 'zip' && $directorySize === 0 && $directoryOffset === 0;
        }
        if ($entries > 100000 || $directorySize < $entries * 46) return false;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $centralOffset = $directoryOffset;
        $centralEnd = $directoryOffset + $directorySize;
        $entryNames = [];
        $localRegions = [];
        $expandedTotal = 0;
        try {
            for ($entry = 0; $entry < $entries; $entry++) {
                if ($centralOffset + 46 > $centralEnd || fseek($handle, $centralOffset) !== 0) return false;
                $central = fread($handle, 46);
                if (!is_string($central) || strlen($central) !== 46 || substr($central, 0, 4) !== "PK\x01\x02") {
                    return false;
                }
                $centralFields = unpack(
                    'vversion_made/vversion_needed/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/'
                    . 'Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/'
                    . 'Vexternal/Vlocal_offset',
                    substr($central, 4)
                );
                if (!is_array($centralFields) || (int) $centralFields['disk_start'] !== 0
                    || (int) $centralFields['compressed'] === 0xFFFFFFFF
                    || (int) $centralFields['uncompressed'] === 0xFFFFFFFF
                    || (int) $centralFields['local_offset'] === 0xFFFFFFFF
                    || ((int) $centralFields['flags'] & 0x41) !== 0
                    || !in_array((int) $centralFields['compression'], [0, 8], true)) return false;
                $nameLength = (int) $centralFields['name_length'];
                $extraLength = (int) $centralFields['extra_length'];
                $commentLength = (int) $centralFields['comment_length'];
                $centralEntryEnd = $centralOffset + 46 + $nameLength + $extraLength + $commentLength;
                if ($nameLength <= 0 || $centralEntryEnd > $centralEnd) return false;
                $centralName = fread($handle, $nameLength);
                if (!is_string($centralName) || strlen($centralName) !== $nameLength) return false;
                $normalizedName = str_replace('\\', '/', $centralName);
                if (str_contains($normalizedName, "\0") || str_starts_with($normalizedName, '/')
                    || preg_match('/^[A-Za-z]:\//', $normalizedName) === 1
                    || in_array('..', explode('/', $normalizedName), true)
                    || isset($entryNames[$normalizedName])) return false;
                $entryNames[$normalizedName] = true;
                $creatorSystem = ((int) $centralFields['version_made'] >> 8) & 0xFF;
                $unixMode = ((int) $centralFields['external'] >> 16) & 0xFFFF;
                $unixType = $unixMode & 0170000;
                if ($creatorSystem === 3 && !in_array($unixType, [0, 0040000, 0100000], true)) return false;
                $directoryEntry = str_ends_with($normalizedName, '/');
                if (($directoryEntry && ((int) $centralFields['compressed'] !== 0
                            || (int) $centralFields['uncompressed'] !== 0))
                    || ($creatorSystem === 3 && $unixType === 0040000 && !$directoryEntry)
                    || ($creatorSystem === 3 && $unixType === 0100000 && $directoryEntry)) return false;

                $localOffset = (int) $centralFields['local_offset'];
                if ($localOffset + 30 > $directoryOffset || fseek($handle, $localOffset) !== 0) return false;
                $local = fread($handle, 30);
                if (!is_string($local) || strlen($local) !== 30 || substr($local, 0, 4) !== "PK\x03\x04") return false;
                $localFields = unpack(
                    'vversion/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/'
                    . 'vname_length/vextra_length',
                    substr($local, 4)
                );
                if (!is_array($localFields)
                    || (int) $localFields['flags'] !== (int) $centralFields['flags']
                    || (int) $localFields['compression'] !== (int) $centralFields['compression']) return false;
                $localNameLength = (int) $localFields['name_length'];
                $localExtraLength = (int) $localFields['extra_length'];
                $dataOffset = $localOffset + 30 + $localNameLength + $localExtraLength;
                $dataEnd = $dataOffset + (int) $centralFields['compressed'];
                if ($localNameLength !== $nameLength || $dataEnd > $directoryOffset || $dataEnd < $dataOffset
                    || fseek($handle, $localOffset + 30) !== 0
                    || fread($handle, $localNameLength) !== $centralName) return false;
                if (((int) $localFields['flags'] & 0x08) === 0
                    && ((int) $localFields['crc'] !== (int) $centralFields['crc']
                        || (int) $localFields['compressed'] !== (int) $centralFields['compressed']
                        || (int) $localFields['uncompressed'] !== (int) $centralFields['uncompressed'])) {
                    return false;
                }
                if (!self::verifyZipEntryPayload(
                    $handle,
                    $dataOffset,
                    (int) $centralFields['compressed'],
                    (int) $centralFields['uncompressed'],
                    (int) $centralFields['crc'],
                    (int) $centralFields['compression'],
                    $expandedTotal
                )) return false;
                $recordEnd = $dataEnd;
                if (((int) $centralFields['flags'] & 0x08) !== 0) {
                    if ($recordEnd + 12 > $directoryOffset || fseek($handle, $recordEnd) !== 0) return false;
                    $descriptor = fread($handle, 4);
                    if (!is_string($descriptor) || strlen($descriptor) !== 4) return false;
                    if ($descriptor === "PK\x07\x08") {
                        $rest = fread($handle, 12);
                        if (!is_string($rest) || strlen($rest) !== 12) return false;
                        $descriptor .= $rest;
                        $values = unpack('Vcrc/Vcompressed/Vuncompressed', substr($descriptor, 4));
                        $recordEnd += 16;
                    } else {
                        $rest = fread($handle, 8);
                        if (!is_string($rest) || strlen($rest) !== 8) return false;
                        $descriptor .= $rest;
                        $values = unpack('Vcrc/Vcompressed/Vuncompressed', $descriptor);
                        $recordEnd += 12;
                    }
                    if (!is_array($values) || (int) $values['crc'] !== (int) $centralFields['crc']
                        || (int) $values['compressed'] !== (int) $centralFields['compressed']
                        || (int) $values['uncompressed'] !== (int) $centralFields['uncompressed']) return false;
                }
                $localRegions[] = ['start' => $localOffset, 'end' => $recordEnd];
                $centralOffset = $centralEntryEnd;
            }
            usort($localRegions, static fn(array $left, array $right): int => $left['start'] <=> $right['start']);
            $covered = 0;
            foreach ($localRegions as $region) {
                if ((int) $region['start'] !== $covered || (int) $region['end'] <= $covered) return false;
                $covered = (int) $region['end'];
            }
            return $centralOffset === $centralEnd && $covered === $directoryOffset
                && self::zipRequiredEntriesPresent($extension, $entryNames);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function verifyZipEntryPayload(
        $handle,
        int $offset,
        int $compressedSize,
        int $expectedSize,
        int $expectedCrc,
        int $method,
        int &$expandedTotal
    ): bool {
        if ($compressedSize < 0 || $expectedSize < 0
            || $expandedTotal + $expectedSize > self::MAX_ARCHIVE_EXPANDED_BYTES
            || fseek($handle, $offset) !== 0) return false;
        $hash = hash_init('crc32b');
        $actualSize = 0;
        $remaining = $compressedSize;
        $inflate = null;
        if ($method === 8) {
            if (!function_exists('inflate_init') || !function_exists('inflate_add')) return false;
            try { $inflate = inflate_init(ZLIB_ENCODING_RAW); } catch (\Throwable) { return false; }
            if ($inflate === false) return false;
        }
        try {
            do {
                $take = min(8192, $remaining);
                $chunk = $take > 0 ? fread($handle, $take) : '';
                if (!is_string($chunk) || strlen($chunk) !== $take) return false;
                $remaining -= $take;
                if ($method === 0) {
                    $output = $chunk;
                } else {
                    $flush = $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
                    $output = inflate_add($inflate, $chunk, $flush);
                    if (!is_string($output)) return false;
                }
                $actualSize += strlen($output);
                if ($actualSize > $expectedSize || $expandedTotal + $actualSize > self::MAX_ARCHIVE_EXPANDED_BYTES) {
                    return false;
                }
                if ($output !== '') hash_update($hash, $output);
            } while ($remaining > 0);
        } catch (\Throwable) {
            return false;
        }
        if ($method === 8 && function_exists('inflate_get_status')
            && inflate_get_status($inflate) !== ZLIB_STREAM_END) return false;
        $actualCrc = strtolower(hash_final($hash));
        $expectedCrcHex = strtolower(sprintf('%08x', $expectedCrc));
        if ($actualSize !== $expectedSize || !hash_equals($expectedCrcHex, $actualCrc)) return false;
        $expandedTotal += $actualSize;
        return true;
    }

    /** @param array<string,bool> $entries */
    private static function zipRequiredEntriesPresent(string $extension, array $entries): bool
    {
        $required = match ($extension) {
            'docx' => ['[Content_Types].xml', '_rels/.rels', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml'],
            'pptx' => ['[Content_Types].xml', '_rels/.rels', 'ppt/presentation.xml'],
            'odt', 'ods', 'odp' => ['mimetype', 'META-INF/manifest.xml', 'content.xml'],
            'apk' => ['AndroidManifest.xml'],
            default => [],
        };
        foreach ($required as $name) if (!isset($entries[$name])) return false;
        if ($extension === 'apk') {
            foreach (array_keys($entries) as $name) {
                if ($name === 'resources.arsc' || preg_match('#^(?:classes\d*\.dex|lib/[^/]+/[^/]+\.so)$#', $name) === 1) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private static function validGzip(string $path, int $size): bool
    {
        if ($size < 20 || !function_exists('inflate_init') || !function_exists('inflate_add')
            || !function_exists('inflate_get_status') || !function_exists('inflate_get_read_len')) return false;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        try {
            $header = fread($handle, 10);
            if (!is_string($header) || strlen($header) !== 10 || substr($header, 0, 3) !== "\x1F\x8B\x08") return false;
            $flags = ord($header[3]);
            if (($flags & 0xE0) !== 0) return false;
            $headerBytes = $header;
            $offset = 10;
            if (($flags & 0x04) !== 0) {
                $lengthBytes = fread($handle, 2);
                if (!is_string($lengthBytes) || strlen($lengthBytes) !== 2) return false;
                $length = unpack('vsize', $lengthBytes);
                $extraSize = is_array($length) ? (int) $length['size'] : -1;
                if ($extraSize < 0 || $offset + 2 + $extraSize > $size - 8) return false;
                $extra = $extraSize > 0 ? fread($handle, $extraSize) : '';
                if (!is_string($extra) || strlen($extra) !== $extraSize) return false;
                $headerBytes .= $lengthBytes . $extra;
                $offset += 2 + $extraSize;
            }
            foreach ([0x08, 0x10] as $flag) {
                if (($flags & $flag) === 0) continue;
                $field = '';
                do {
                    if (++$offset > $size - 8 || strlen($field) > 65535) return false;
                    $byte = fread($handle, 1);
                    if (!is_string($byte) || $byte === '') return false;
                    $field .= $byte;
                } while ($byte !== "\0");
                $headerBytes .= $field;
            }
            if (($flags & 0x02) !== 0) {
                $crcBytes = fread($handle, 2);
                if (!is_string($crcBytes) || strlen($crcBytes) !== 2) return false;
                $expectedHeaderCrc = unpack('vcrc', $crcBytes);
                $actualHeaderCrc = hexdec(substr(hash('crc32b', $headerBytes), -4));
                if (!is_array($expectedHeaderCrc) || (int) $expectedHeaderCrc['crc'] !== $actualHeaderCrc) return false;
                $offset += 2;
            }
            if ($offset >= $size - 8) return false;
            if (fseek($handle, $size - 8) !== 0) return false;
            $trailer = fread($handle, 8);
            $trailerFields = is_string($trailer) && strlen($trailer) === 8
                ? unpack('Vcrc/Visize', $trailer)
                : false;
            if (!is_array($trailerFields) || fseek($handle, $offset) !== 0) return false;
            $remaining = $size - 8 - $offset;
            $inflate = inflate_init(ZLIB_ENCODING_RAW);
            if ($inflate === false) return false;
            $hash = hash_init('crc32b');
            $expanded = 0;
            while ($remaining > 0) {
                $take = min(8192, $remaining);
                $chunk = fread($handle, $take);
                if (!is_string($chunk) || strlen($chunk) !== $take) return false;
                $remaining -= $take;
                $output = inflate_add($inflate, $chunk, $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if (!is_string($output)) return false;
                $expanded += strlen($output);
                if ($expanded > self::MAX_ARCHIVE_EXPANDED_BYTES) return false;
                if ($output !== '') hash_update($hash, $output);
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END
                || inflate_get_read_len($inflate) !== $size - 8 - $offset) return false;
            return $expanded === (int) $trailerFields['isize']
                && hash_equals(strtolower(sprintf('%08x', (int) $trailerFields['crc'])), strtolower(hash_final($hash)));
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($handle);
        }
    }

    private static function validTar(string $path, int $size): bool
    {
        if ($size < 1024 || $size % 512 !== 0) return false;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $offset = 0;
        $entries = 0;
        $zeroBlocks = 0;
        try {
            while ($offset < $size) {
                if (fseek($handle, $offset) !== 0) return false;
                $header = fread($handle, 512);
                if (!is_string($header) || strlen($header) !== 512) return false;
                if ($header === str_repeat("\0", 512)) {
                    $zeroBlocks++;
                    $offset += 512;
                    continue;
                }
                if ($zeroBlocks > 0) return false;
                $checksumText = trim(substr($header, 148, 8), " \0");
                $sizeText = trim(substr($header, 124, 12), " \0");
                if ($checksumText === '' || preg_match('/^[0-7]+$/', $checksumText) !== 1
                    || ($sizeText !== '' && preg_match('/^[0-7]+$/', $sizeText) !== 1)) return false;
                $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
                $actualChecksum = array_sum(unpack('C*', $checksumHeader));
                if ($actualChecksum !== octdec($checksumText)) return false;
                $entrySize = $sizeText === '' ? 0 : octdec($sizeText);
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $name = ($prefix !== '' ? $prefix . '/' : '') . rtrim(substr($header, 0, 100), "\0");
                $name = ltrim(str_replace('\\', '/', $name), '/');
                if ($name === '' || in_array('..', explode('/', $name), true)) return false;
                $next = $offset + 512 + (int) (ceil($entrySize / 512) * 512);
                if ($next <= $offset || $next > $size) return false;
                $offset = $next;
                if (++$entries > 100000) return false;
            }
            return $entries > 0 && $zeroBlocks >= 2;
        } finally {
            fclose($handle);
        }
    }

    private static function validRtf(string $path, int $size): bool
    {
        if ($size < 6 || $size > self::MAX_RTF_BYTES) return false;
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) return false;
        $start = strspn($bytes, " \t\r\n");
        if (substr($bytes, $start, 5) !== '{\\rtf') return false;
        $depth = 0;
        $binary = 0;
        for ($index = $start; $index < $size; $index++) {
            if ($binary > 0) { $binary--; continue; }
            $byte = $bytes[$index];
            if ($byte === "\0") return false;
            if ($byte === '{') { $depth++; continue; }
            if ($byte === '}') {
                if (--$depth < 0) return false;
                if ($depth === 0) return trim(substr($bytes, $index + 1)) === '';
                continue;
            }
            if ($byte !== '\\') continue;
            if (++$index >= $size) return false;
            $control = $bytes[$index];
            if (in_array($control, ['\\', '{', '}'], true)) continue;
            if ($control === "'") {
                if ($index + 2 >= $size || preg_match('/^[0-9a-fA-F]{2}$/', substr($bytes, $index + 1, 2)) !== 1) return false;
                $index += 2;
                continue;
            }
            if (!ctype_alpha($control)) continue;
            $wordStart = $index;
            while ($index + 1 < $size && ctype_alpha($bytes[$index + 1])) $index++;
            $word = strtolower(substr($bytes, $wordStart, $index - $wordStart + 1));
            $numberStart = $index + 1;
            if ($numberStart < $size && ($bytes[$numberStart] === '-' || $bytes[$numberStart] === '+')) $numberStart++;
            $numberEnd = $numberStart;
            while ($numberEnd < $size && ctype_digit($bytes[$numberEnd])) $numberEnd++;
            $hasNumber = $numberEnd > $numberStart;
            if ($hasNumber) $index = $numberEnd - 1;
            if ($index + 1 < $size && $bytes[$index + 1] === ' ') $index++;
            if ($word === 'bin') {
                if (!$hasNumber) return false;
                $binary = (int) substr($bytes, $numberStart, $numberEnd - $numberStart);
                if ($binary < 0 || $index + $binary >= $size) return false;
            }
        }
        return false;
    }

    private static function validRiff(string $path, int $size, string $form, bool $requireWaveChunks): bool
    {
        $header = self::readAt($path, 0, 12);
        if (!is_string($header) || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF'
            || substr($header, 8, 4) !== $form) return false;
        $declared = unpack('Vsize', substr($header, 4, 4));
        if (!is_array($declared) || (int) $declared['size'] + 8 !== $size) return false;
        if (!$requireWaveChunks) return $size >= 20;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $offset = 12;
        $hasFormat = false;
        $hasData = false;
        $chunks = 0;
        try {
            while ($offset + 8 <= $size && ++$chunks <= 100000) {
                if (fseek($handle, $offset) !== 0) return false;
                $chunk = fread($handle, 8);
                if (!is_string($chunk) || strlen($chunk) !== 8) return false;
                $length = unpack('Vsize', substr($chunk, 4, 4));
                if (!is_array($length)) return false;
                $chunkSize = (int) $length['size'];
                $next = $offset + 8 + $chunkSize + ($chunkSize % 2);
                if ($next > $size || $next <= $offset) return false;
                $type = substr($chunk, 0, 4);
                if ($type === 'fmt ' && $chunkSize >= 16) $hasFormat = true;
                if ($type === 'data' && $chunkSize > 0) $hasData = true;
                $offset = $next;
            }
            return $offset === $size && $hasFormat && $hasData;
        } finally {
            fclose($handle);
        }
    }

    private static function validOgg(string $path, int $size, bool $requireOpus): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $offset = 0;
        $pages = 0;
        $lastHeaderType = 0;
        try {
            while ($offset < $size && ++$pages <= 100000) {
                if ($offset + 27 > $size || fseek($handle, $offset) !== 0) return false;
                $header = fread($handle, 27);
                if (!is_string($header) || strlen($header) !== 27 || substr($header, 0, 4) !== 'OggS'
                    || ord($header[4]) !== 0) return false;
                $headerType = ord($header[5]);
                if ($pages === 1 && ($headerType & 0x02) !== 0x02) return false;
                $segments = ord($header[26]);
                $table = $segments > 0 ? fread($handle, $segments) : '';
                if (!is_string($table) || strlen($table) !== $segments) return false;
                $payload = 0;
                for ($index = 0; $index < $segments; $index++) $payload += ord($table[$index]);
                $payloadOffset = $offset + 27 + $segments;
                $next = $payloadOffset + $payload;
                if ($next > $size || $next <= $offset) return false;
                if ($pages === 1 && $requireOpus) {
                    if (fseek($handle, $payloadOffset) !== 0 || fread($handle, 8) !== 'OpusHead') return false;
                }
                $lastHeaderType = $headerType;
                $offset = $next;
            }
            return $pages > 0 && $offset === $size && ($lastHeaderType & 0x04) === 0x04;
        } finally {
            fclose($handle);
        }
    }

    private static function validIsoMedia(string $path, int $size): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $offset = 0;
        $boxes = 0;
        $hasFtyp = false;
        $hasMoov = false;
        $hasMedia = false;
        try {
            while ($offset < $size && ++$boxes <= 100000) {
                if ($offset + 8 > $size || fseek($handle, $offset) !== 0) return false;
                $header = fread($handle, 8);
                if (!is_string($header) || strlen($header) !== 8) return false;
                $unpacked = unpack('Nsize', substr($header, 0, 4));
                if (!is_array($unpacked)) return false;
                $boxSize = (int) $unpacked['size'];
                $headerSize = 8;
                if ($boxSize === 1) {
                    $large = fread($handle, 8);
                    if (!is_string($large) || strlen($large) !== 8) return false;
                    $parts = unpack('Nhigh/Nlow', $large);
                    if (!is_array($parts) || (int) $parts['high'] !== 0) return false;
                    $boxSize = (int) $parts['low'];
                    $headerSize = 16;
                } elseif ($boxSize === 0) {
                    $boxSize = $size - $offset;
                }
                if ($boxSize < $headerSize || $offset + $boxSize > $size) return false;
                $type = substr($header, 4, 4);
                if ($boxes === 1 && $type !== 'ftyp') return false;
                if ($type === 'ftyp') $hasFtyp = true;
                if ($type === 'moov') $hasMoov = true;
                if ($type === 'mdat') $hasMedia = true;
                $offset += $boxSize;
            }
            return $offset === $size && $hasFtyp && $hasMoov && $hasMedia;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{valid:bool,animated:bool} */
    private static function pngStructure(string $path, int $size): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return ['valid' => false, 'animated' => false];
        $offset = 8;
        $chunks = 0;
        $hasHeader = false;
        $hasData = false;
        $animated = false;
        try {
            while ($offset < $size && ++$chunks <= 100000) {
                if ($offset + 12 > $size || fseek($handle, $offset) !== 0) return ['valid' => false, 'animated' => false];
                $header = fread($handle, 8);
                if (!is_string($header) || strlen($header) !== 8) return ['valid' => false, 'animated' => false];
                $length = unpack('Nsize', substr($header, 0, 4));
                if (!is_array($length)) return ['valid' => false, 'animated' => false];
                $chunkSize = (int) $length['size'];
                $type = substr($header, 4, 4);
                $next = $offset + 12 + $chunkSize;
                if ($next > $size || $next <= $offset) return ['valid' => false, 'animated' => false];
                if ($chunks === 1 && ($type !== 'IHDR' || $chunkSize !== 13)) return ['valid' => false, 'animated' => false];
                if ($type === 'IHDR') $hasHeader = true;
                if ($type === 'IDAT') $hasData = true;
                if ($type === 'acTL') $animated = true;
                if ($type === 'IEND') {
                    return ['valid' => $chunkSize === 0 && $next === $size && $hasHeader && $hasData, 'animated' => $animated];
                }
                $offset = $next;
            }
            return ['valid' => false, 'animated' => false];
        } finally {
            fclose($handle);
        }
    }

    /** @return array{valid:bool,animated:bool} */
    private static function gifStructure(string $path, int $size): array
    {
        $header = self::readAt($path, 0, 13);
        if (!is_string($header) || strlen($header) !== 13) return ['valid' => false, 'animated' => false];
        $packed = ord($header[10]);
        $offset = 13 + (($packed & 0x80) !== 0 ? 3 * (1 << (($packed & 0x07) + 1)) : 0);
        $handle = @fopen($path, 'rb');
        if ($handle === false) return ['valid' => false, 'animated' => false];
        $frames = 0;
        $blocks = 0;
        try {
            while ($offset < $size && ++$blocks <= 100000) {
                if (fseek($handle, $offset) !== 0) return ['valid' => false, 'animated' => false];
                $marker = fread($handle, 1);
                if (!is_string($marker) || $marker === '') return ['valid' => false, 'animated' => false];
                $offset++;
                $code = ord($marker);
                if ($code === 0x3B) return ['valid' => $offset === $size && $frames > 0, 'animated' => $frames > 1];
                if ($code === 0x21) {
                    if ($offset >= $size) return ['valid' => false, 'animated' => false];
                    $offset++;
                    if (!self::skipGifSubBlocks($handle, $offset, $size)) return ['valid' => false, 'animated' => false];
                    continue;
                }
                if ($code !== 0x2C || $offset + 9 > $size || fseek($handle, $offset) !== 0) {
                    return ['valid' => false, 'animated' => false];
                }
                $descriptor = fread($handle, 9);
                if (!is_string($descriptor) || strlen($descriptor) !== 9) return ['valid' => false, 'animated' => false];
                $offset += 9;
                $localPacked = ord($descriptor[8]);
                if (($localPacked & 0x80) !== 0) $offset += 3 * (1 << (($localPacked & 0x07) + 1));
                if ($offset >= $size) return ['valid' => false, 'animated' => false];
                $offset++;
                if (!self::skipGifSubBlocks($handle, $offset, $size)) return ['valid' => false, 'animated' => false];
                $frames++;
            }
            return ['valid' => false, 'animated' => false];
        } finally {
            fclose($handle);
        }
    }

    private static function skipGifSubBlocks($handle, int &$offset, int $size): bool
    {
        while ($offset < $size) {
            if (fseek($handle, $offset) !== 0) return false;
            $lengthByte = fread($handle, 1);
            if (!is_string($lengthByte) || $lengthByte === '') return false;
            $length = ord($lengthByte);
            $offset++;
            if ($length === 0) return true;
            if ($offset + $length > $size) return false;
            $offset += $length;
        }
        return false;
    }

    /** @return array{valid:bool,animated:bool} */
    private static function webpStructure(string $path, int $size): array
    {
        $header = self::readAt($path, 0, 12);
        if (!is_string($header) || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF'
            || substr($header, 8, 4) !== 'WEBP') return ['valid' => false, 'animated' => false];
        $declared = unpack('Vsize', substr($header, 4, 4));
        if (!is_array($declared) || (int) $declared['size'] + 8 !== $size) {
            return ['valid' => false, 'animated' => false];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) return ['valid' => false, 'animated' => false];
        $offset = 12;
        $chunks = 0;
        $hasImagePayload = false;
        $hasExtendedHeader = false;
        $animationFlag = false;
        $hasAnimationHeader = false;
        $hasAnimationFrame = false;
        try {
            while ($offset < $size && ++$chunks <= 100000) {
                if ($offset + 8 > $size || fseek($handle, $offset) !== 0) return ['valid' => false, 'animated' => false];
                $chunk = fread($handle, 8);
                if (!is_string($chunk) || strlen($chunk) !== 8) return ['valid' => false, 'animated' => false];
                $length = unpack('Vsize', substr($chunk, 4, 4));
                if (!is_array($length)) return ['valid' => false, 'animated' => false];
                $chunkSize = (int) $length['size'];
                $payload = $offset + 8;
                $next = $payload + $chunkSize + ($chunkSize % 2);
                if ($next > $size || $next <= $offset) return ['valid' => false, 'animated' => false];
                $type = substr($chunk, 0, 4);
                if (in_array($type, ['VP8 ', 'VP8L'], true) && $chunkSize > 0) $hasImagePayload = true;
                if ($type === 'VP8X') {
                    if ($chunkSize !== 10 || $hasExtendedHeader) return ['valid' => false, 'animated' => false];
                    $hasExtendedHeader = true;
                    if (fseek($handle, $payload) !== 0) return ['valid' => false, 'animated' => false];
                    $flag = fread($handle, 1);
                    if (!is_string($flag) || $flag === '') return ['valid' => false, 'animated' => false];
                    $animationFlag = (ord($flag) & 0x02) === 0x02;
                }
                if ($type === 'ANIM') {
                    if ($chunkSize !== 6) return ['valid' => false, 'animated' => false];
                    $hasAnimationHeader = true;
                }
                if ($type === 'ANMF') {
                    if (!self::validWebpFramePayload($handle, $payload, $chunkSize)) {
                        return ['valid' => false, 'animated' => false];
                    }
                    $hasAnimationFrame = true;
                }
                $offset = $next;
            }
            $animated = $animationFlag && $hasExtendedHeader && $hasAnimationHeader && $hasAnimationFrame;
            $validPixels = $animated || $hasImagePayload;
            $consistentAnimation = $animationFlag === ($hasAnimationHeader || $hasAnimationFrame);
            return ['valid' => $offset === $size && $validPixels && $consistentAnimation, 'animated' => $animated];
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function validWebpFramePayload($handle, int $payloadOffset, int $chunkSize): bool
    {
        if ($chunkSize < 24) return false;
        $offset = $payloadOffset + 16;
        $end = $payloadOffset + $chunkSize;
        $hasPixels = false;
        while ($offset < $end) {
            if ($offset + 8 > $end || fseek($handle, $offset) !== 0) return false;
            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) !== 8) return false;
            $length = unpack('Vsize', substr($header, 4, 4));
            if (!is_array($length)) return false;
            $nestedSize = (int) $length['size'];
            $next = $offset + 8 + $nestedSize + ($nestedSize % 2);
            if ($nestedSize <= 0 || $next > $end || $next <= $offset) return false;
            if (in_array(substr($header, 0, 4), ['VP8 ', 'VP8L'], true)) $hasPixels = true;
            $offset = $next;
        }
        return $offset === $end && $hasPixels;
    }

    /** @return array{accepted:bool,reason:string,width:int,height:int,duration_ms:int,packet_count:int} */
    private static function probeMediaFile(string $path, string $mediaType, string $extension): array
    {
        if (!in_array($mediaType, ['audio', 'video'], true)) {
            return ['accepted' => false, 'reason' => 'media_probe_type_invalid', 'width' => 0,
                'height' => 0, 'duration_ms' => 0, 'packet_count' => 0];
        }
        $selector = $mediaType === 'video' ? 'v:0' : 'a:0';
        $result = self::runProcess([
            self::FFPROBE_BINARY, '-v', 'error', '-count_packets', '-select_streams', $selector,
            '-show_entries', 'stream=codec_type,codec_name,width,height,duration,nb_read_packets:format=duration,format_name',
            '-of', 'json', $path,
        ], self::PROBE_TIMEOUT_SECONDS, self::PROCESS_OUTPUT_BYTES);
        if (($result['started'] ?? false) !== true) {
            return ['accepted' => false, 'reason' => $mediaType . '_probe_unavailable', 'width' => 0,
                'height' => 0, 'duration_ms' => 0, 'packet_count' => 0];
        }
        if (($result['exit_code'] ?? -1) !== 0 || ($result['timed_out'] ?? true) === true
            || ($result['output_limited'] ?? true) === true || trim((string) ($result['stderr'] ?? '')) !== '') {
            return ['accepted' => false, 'reason' => $mediaType . '_probe_failed', 'width' => 0,
                'height' => 0, 'duration_ms' => 0, 'packet_count' => 0];
        }
        try {
            $payload = json_decode((string) ($result['stdout'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $payload = null;
        }
        $stream = is_array($payload) && is_array($payload['streams'] ?? null) ? ($payload['streams'][0] ?? null) : null;
        $format = is_array($payload) && is_array($payload['format'] ?? null) ? $payload['format'] : null;
        $width = is_array($stream) ? (int) ($stream['width'] ?? 0) : 0;
        $height = is_array($stream) ? (int) ($stream['height'] ?? 0) : 0;
        $codecName = is_array($stream) ? strtolower(trim((string) ($stream['codec_name'] ?? ''))) : '';
        $formatName = is_array($format) ? strtolower(trim((string) ($format['format_name'] ?? ''))) : '';
        $packetCount = is_array($stream) && is_numeric($stream['nb_read_packets'] ?? null)
            ? (int) $stream['nb_read_packets'] : 0;
        $duration = is_array($format) && is_numeric($format['duration'] ?? null)
            ? (float) $format['duration']
            : (is_array($stream) && is_numeric($stream['duration'] ?? null) ? (float) $stream['duration'] : 0.0);
        if (!is_array($stream) || ($stream['codec_type'] ?? '') !== $mediaType || $packetCount <= 0
            || ($mediaType === 'video' && !self::safeDimensions($width, $height))
            || !is_finite($duration) || $duration <= 0.0
            || !self::probeSemanticsMatch($extension, $mediaType, $formatName, $codecName)) {
            return ['accepted' => false, 'reason' => $mediaType . '_probe_failed', 'width' => 0,
                'height' => 0, 'duration_ms' => 0, 'packet_count' => 0];
        }
        return [
            'accepted' => true, 'reason' => $mediaType . '_probe_verified',
            'width' => $mediaType === 'video' ? $width : 0,
            'height' => $mediaType === 'video' ? $height : 0,
            'duration_ms' => max(1, (int) round($duration * 1000)),
            'packet_count' => $packetCount,
        ];
    }

    private static function probeSemanticsMatch(
        string $extension,
        string $mediaType,
        string $formatName,
        string $codecName
    ): bool {
        $extension = strtolower(trim($extension));
        $formats = array_values(array_filter(array_map('trim', explode(',', strtolower($formatName)))));
        if ($codecName === '' || $formats === []) return false;
        $hasFormat = static fn(array $allowed): bool => array_intersect($formats, $allowed) !== [];
        $videoCodecs = ['h264', 'hevc', 'av1', 'vp8', 'vp9', 'mpeg4', 'mpeg2video', 'h263', 'theora'];
        return match ($extension) {
            'mp3' => $mediaType === 'audio' && $hasFormat(['mp3']) && $codecName === 'mp3',
            'aac' => $mediaType === 'audio' && $hasFormat(['aac']) && $codecName === 'aac',
            'flac' => $mediaType === 'audio' && $hasFormat(['flac']) && $codecName === 'flac',
            'wav' => $mediaType === 'audio' && $hasFormat(['wav'])
                && (str_starts_with($codecName, 'pcm_') || in_array($codecName, ['adpcm_ima_wav', 'mp3'], true)),
            'ogg' => $mediaType === 'audio' && $hasFormat(['ogg'])
                && in_array($codecName, ['vorbis', 'opus', 'flac', 'speex'], true),
            'opus' => $mediaType === 'audio' && $hasFormat(['ogg']) && $codecName === 'opus',
            'm4a' => $mediaType === 'audio' && $hasFormat(['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'])
                && in_array($codecName, ['aac', 'alac'], true),
            'webm' => $mediaType === 'video' && $hasFormat(['webm', 'matroska'])
                && in_array($codecName, ['vp8', 'vp9', 'av1'], true),
            'mkv' => $mediaType === 'video' && $hasFormat(['matroska', 'webm'])
                && in_array($codecName, $videoCodecs, true),
            'avi' => $mediaType === 'video' && $hasFormat(['avi'])
                && in_array($codecName, $videoCodecs, true),
            'mp4', 'mov', 'm4v' => $mediaType === 'video'
                && $hasFormat(['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'])
                && in_array($codecName, $videoCodecs, true),
            '3gp' => $mediaType === 'video' && $hasFormat(['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'])
                && in_array($codecName, ['h263', 'h264', 'hevc', 'mpeg4'], true),
            default => false,
        };
    }

    /** @return array{started:bool,exit_code:int,timed_out:bool,output_limited:bool,stdout:string,stderr:string} */
    private static function runProcess(array $command, float $timeoutSeconds, int $maxOutputBytes): array
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!self::processFunctionAvailable($function)) {
                return ['started' => false, 'exit_code' => -1, 'timed_out' => false,
                    'output_limited' => false, 'stdout' => '', 'stderr' => ''];
            }
        }
        $stdoutPath = @tempnam(sys_get_temp_dir(), 'yiyunying-process-out-');
        $stderrPath = @tempnam(sys_get_temp_dir(), 'yiyunying-process-err-');
        if (!is_string($stdoutPath) || !is_string($stderrPath)) {
            if (is_string($stdoutPath)) @unlink($stdoutPath);
            if (is_string($stderrPath)) @unlink($stderrPath);
            return ['started' => false, 'exit_code' => -1, 'timed_out' => false,
                'output_limited' => false, 'stdout' => '', 'stderr' => ''];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            // Temporary files avoid Windows pipe reads that may block despite
            // stream_set_blocking(false). Output is polled and returned capped.
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];
        $pipes = [];
        $options = ['bypass_shell' => true, 'suppress_errors' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        try {
            $process = @proc_open($command, $descriptors, $pipes, null, null, $options);
        } catch (\Throwable) {
            $process = false;
        }
        if (!is_resource($process)) {
            @unlink($stdoutPath);
            @unlink($stderrPath);
            return ['started' => false, 'exit_code' => -1, 'timed_out' => false,
                'output_limited' => false, 'stdout' => '', 'stderr' => ''];
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        $timedOut = false;
        $outputLimited = false;
        $lastStatus = null;
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $maxOutputBytes = max(0, $maxOutputBytes);
        try {
            while (true) {
                clearstatcache(true, $stdoutPath);
                clearstatcache(true, $stderrPath);
                $stdoutSize = @filesize($stdoutPath);
                $stderrSize = @filesize($stderrPath);
                $written = max(0, is_int($stdoutSize) ? $stdoutSize : 0)
                    + max(0, is_int($stderrSize) ? $stderrSize : 0);
                if ($written > $maxOutputBytes) $outputLimited = true;
                $lastStatus = proc_get_status($process);
                if (!is_array($lastStatus) || ($lastStatus['running'] ?? false) !== true) break;
                if ($outputLimited || microtime(true) >= $deadline) {
                    $timedOut = !$outputLimited;
                    $lastStatus = self::terminateProcess($process);
                    break;
                }
                usleep(20000);
            }
            if (is_array($lastStatus) && ($lastStatus['running'] ?? false) === true) {
                $lastStatus = self::terminateProcess($process);
            }
            $closedExit = proc_close($process);
            clearstatcache(true, $stdoutPath);
            clearstatcache(true, $stderrPath);
            $stdoutSize = max(0, (int) (@filesize($stdoutPath) ?: 0));
            $stderrSize = max(0, (int) (@filesize($stderrPath) ?: 0));
            if ($stdoutSize + $stderrSize > $maxOutputBytes) $outputLimited = true;
            $stdout = self::readAt($stdoutPath, 0, min($stdoutSize, $maxOutputBytes)) ?? '';
            $remaining = max(0, $maxOutputBytes - strlen($stdout));
            $stderr = self::readAt($stderrPath, 0, min($stderrSize, $remaining)) ?? '';
            $statusExit = is_array($lastStatus) ? (int) ($lastStatus['exitcode'] ?? -1) : -1;
            $exitCode = $statusExit >= 0 ? $statusExit : (int) $closedExit;
            return [
                'started' => true, 'exit_code' => $exitCode, 'timed_out' => $timedOut,
                'output_limited' => $outputLimited, 'stdout' => $stdout, 'stderr' => $stderr,
            ];
        } finally {
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }

    /** @param resource $process @return array<string,mixed>|null */
    private static function terminateProcess($process): ?array
    {
        @proc_terminate($process);
        foreach ([500000, 1000000] as $graceMicroseconds) {
            $deadline = microtime(true) + ($graceMicroseconds / 1000000);
            do {
                $status = proc_get_status($process);
                if (!is_array($status) || ($status['running'] ?? false) !== true) return $status ?: null;
                usleep(20000);
            } while (microtime(true) < $deadline);
            @proc_terminate($process, 9);
        }
        $status = proc_get_status($process);
        return is_array($status) ? $status : null;
    }

    private static function processFunctionAvailable(string $function): bool
    {
        if (!function_exists($function)) return false;
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        return !in_array($function, $disabled, true);
    }

    private static function readPrefix(string $path): ?string
    {
        return self::readAt($path, 0, self::MAX_PREFIX_BYTES);
    }

    private static function readAt(string $path, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length < 0) return null;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try {
            if (fseek($handle, $offset) !== 0) return null;
            $bytes = $length === 0 ? '' : fread($handle, $length);
            return is_string($bytes) ? $bytes : null;
        } finally {
            fclose($handle);
        }
    }

    private static function signatureKind(string $prefix): string
    {
        $length = strlen($prefix);
        $riffType = $length >= 12 && substr($prefix, 0, 4) === 'RIFF' ? substr($prefix, 8, 4) : '';
        if ($length >= 3 && substr($prefix, 0, 3) === "\xFF\xD8\xFF") return 'jpeg';
        if ($length >= 24 && substr($prefix, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A"
            && substr($prefix, 12, 4) === 'IHDR') return 'png';
        if ($length >= 10 && in_array(substr($prefix, 0, 6), ['GIF87a', 'GIF89a'], true)) return 'gif';
        if ($length >= 16 && $riffType === 'WEBP'
            && in_array(substr($prefix, 12, 4), ['VP8 ', 'VP8L', 'VP8X'], true)) return 'webp';
        if ($length >= 14 && substr($prefix, 0, 2) === 'BM') return 'bmp';
        if (preg_match('/<svg(?:\s|>)/i', substr($prefix, 0, self::MAX_PREFIX_BYTES)) === 1) return 'svg';
        if (str_starts_with($prefix, '%PDF-')) return 'pdf';
        if (str_starts_with(ltrim($prefix), '{\\rtf')) return 'rtf';
        if ($length >= 4 && in_array(substr($prefix, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) return 'zip';
        if (str_starts_with($prefix, "7z\xBC\xAF\x27\x1C")) return '7z';
        if (str_starts_with($prefix, "Rar!\x1A\x07\x00") || str_starts_with($prefix, "Rar!\x1A\x07\x01\x00")) return 'rar';
        if ($length >= 263 && substr($prefix, 257, 5) === 'ustar') return 'tar';
        if (str_starts_with($prefix, "\x1F\x8B")) return 'gz';
        if (str_starts_with($prefix, 'BZh')) return 'bz2';
        if (str_starts_with($prefix, "\xFD7zXZ\x00")) return 'xz';
        if ($length >= 8 && substr($prefix, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return 'ole';
        if ($riffType === 'WAVE') return 'wav';
        if ($riffType === 'AVI ') return 'avi';
        if (str_starts_with($prefix, 'OggS')) return 'ogg';
        if (str_starts_with($prefix, 'fLaC')) return 'flac';
        if (str_starts_with($prefix, "\x1A\x45\xDF\xA3")) return 'ebml';
        if ($length >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xF6) === 0xF0) return 'aac';
        if (str_starts_with($prefix, 'ID3')
            || ($length >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xE0) === 0xE0)) return 'mp3';
        if ($length >= 12 && substr($prefix, 4, 4) === 'ftyp') return self::isoMediaKind($prefix);
        return self::looksLikeText($prefix) ? 'text' : 'unknown';
    }

    private static function isoMediaKind(string $prefix): string
    {
        $brands = [strtolower(substr($prefix, 8, 4))];
        $box = unpack('Nsize', substr($prefix, 0, 4));
        $limit = min(strlen($prefix), max(16, (int) ($box['size'] ?? 16)));
        for ($offset = 16; $offset + 4 <= $limit; $offset += 4) $brands[] = strtolower(substr($prefix, $offset, 4));
        $heifBrands = ['heic', 'heix', 'hevc', 'hevx', 'heif', 'mif1', 'msf1', 'miaf', 'avif', 'avis'];
        return array_intersect($brands, $heifBrands) !== [] ? 'heic' : 'iso_media';
    }

    private static function expectedKind(string $extension): ?string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp', 'bmp' => 'bmp',
            'pdf' => 'pdf', 'txt', 'md', 'json', 'csv' => 'text', 'rtf' => 'rtf',
            'odt', 'ods', 'odp', 'zip', 'docx', 'xlsx', 'pptx', 'apk' => 'zip',
            'tar' => 'tar', 'gz' => 'gz', 'mp3' => 'mp3', 'aac' => 'aac', 'wav' => 'wav',
            'ogg', 'opus' => 'ogg', 'flac' => 'flac', 'webm', 'mkv' => 'ebml', 'avi' => 'avi',
            'mp4', 'm4a', 'mov', '3gp', 'm4v' => 'iso_media',
            default => null,
        };
    }

    private static function mimeForExtension(string $extension): string
    {
        return [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp', 'pdf' => 'application/pdf',
            'txt' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json',
            'csv' => 'text/csv', 'rtf' => 'application/rtf',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'zip' => 'application/zip', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'opus' => 'audio/opus', 'flac' => 'audio/flac',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', '3gp' => 'video/3gpp',
            'm4v' => 'video/x-m4v', 'apk' => 'application/vnd.android.package-archive',
        ][$extension] ?? '';
    }

    private static function categoryForKind(string $kind): string
    {
        if (in_array($kind, ['jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'svg'], true)) return 'image';
        if (in_array($kind, ['iso_media', 'ebml', 'avi'], true)) return 'video';
        if (in_array($kind, ['mp3', 'aac', 'wav', 'ogg', 'flac'], true)) return 'audio';
        return 'file';
    }

    private static function safeDimensions(int $width, int $height): bool
    {
        return $width > 0 && $height > 0 && $width <= self::MAX_IMAGE_SIDE
            && $height <= self::MAX_IMAGE_SIDE && $width * $height <= self::MAX_IMAGE_PIXELS;
    }

    private static function decoderMemoryAvailable(int $inputBytes, int $width, int $height): bool
    {
        if ($inputBytes <= 0 || !self::safeDimensions($width, $height)) return false;
        $configuredLimit = self::iniBytes((string) ini_get('memory_limit'));
        // Unlimited PHP still uses a conservative operational ceiling so a bad
        // image cannot force the worker to discover its real host limit by OOM.
        $effectiveLimit = $configuredLimit > 0 ? $configuredLimit : 536870912;
        $usage = max(memory_get_usage(false), memory_get_usage(true));
        $remaining = max(0, $effectiveLimit - $usage);
        $pixels = $width * $height;
        $estimatedPeak = 16777216 + ($inputBytes * 2) + ($pixels * 12);
        return $estimatedPeak > 0 && $estimatedPeak <= (int) floor($remaining * 0.65);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return -1;
        if (preg_match('/^(\d+)([KMG]?)$/i', $value, $match) !== 1) return 0;
        $bytes = (int) $match[1];
        $multiplier = match (strtoupper($match[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
        };
        if ($bytes > intdiv(PHP_INT_MAX, $multiplier)) return PHP_INT_MAX;
        return $bytes * $multiplier;
    }

    private static function looksLikeText(string $prefix): bool
    {
        return $prefix !== '' && !str_contains($prefix, "\x00")
            && preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $prefix) !== 1;
    }

    /** @return array{accepted:true,reason:string,kind:string,mime_type:string,width:int,height:int,duration_ms:int,is_animated:bool} */
    private static function acceptedInspection(
        string $kind,
        string $mime,
        int $width,
        int $height,
        int $durationMs,
        bool $animated
    ): array {
        return [
            'accepted' => true, 'reason' => 'trusted_content', 'kind' => $kind, 'mime_type' => $mime,
            'width' => $width, 'height' => $height, 'duration_ms' => $durationMs, 'is_animated' => $animated,
        ];
    }

    /** @return array{accepted:false,reason:string,kind:string,mime_type:string,width:int,height:int,duration_ms:int,is_animated:bool} */
    private static function rejectedInspection(string $reason, string $kind): array
    {
        return [
            'accepted' => false, 'reason' => $reason, 'kind' => $kind, 'mime_type' => '',
            'width' => 0, 'height' => 0, 'duration_ms' => 0, 'is_animated' => false,
        ];
    }
}
