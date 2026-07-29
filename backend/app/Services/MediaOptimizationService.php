<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class MediaOptimizationService
{
    public static function optimize(string $absolutePath, string $mime, string $scene, int $targetBytes): array
    {
        $mime = strtolower(trim($mime));
        $animated = $mime === 'image/gif' || strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'gif';
        if ($animated) {
            return self::unchanged($absolutePath, 'animated_preserved', true);
        }
        if (str_starts_with($mime, 'image/')) {
            return self::image($absolutePath, $mime, $scene, $targetBytes);
        }
        if (str_starts_with($mime, 'video/')) {
            return self::video($absolutePath);
        }
        return self::unchanged($absolutePath, 'not_required', false);
    }

    private static function image(string $path, string $mime, string $scene, int $targetBytes): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return self::unchanged($path, 'client_optimized', false);
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') return self::unchanged($path, 'read_failed', false);
        $source = @imagecreatefromstring($bytes);
        if ($source === false) return self::unchanged($path, 'decode_failed', false);
        $width = imagesx($source);
        $height = imagesy($source);
        $maxSide = str_contains(mb_strtolower($scene), '表情') || str_contains(strtolower($scene), 'sticker') ? 512 : 2560;
        $scale = min(1.0, $maxSide / max(1, $width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

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
            return self::unchanged($path, 'already_efficient', false);
        }
        return [
            'path' => $output,
            'mime_type' => 'image/webp',
            'size_bytes' => (int) filesize($output),
            'status' => 'optimized',
            'is_animated' => false,
            'thumbnail_path' => $output,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    private static function video(string $path): array
    {
        if (!self::commandAvailable('ffmpeg')) return self::unchanged($path, 'client_optimized', false);
        $output = preg_replace('/\.[^.]+$/', '', $path) . '.optimized.mp4';
        $command = 'ffmpeg -y -i ' . escapeshellarg($path)
            . ' -vf ' . escapeshellarg("scale='min(1920,iw)':-2")
            . ' -c:v libx264 -preset veryfast -crf 28 -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg($output) . ' 2>&1';
        $lines = [];
        $exit = 1;
        @exec($command, $lines, $exit);
        if ($exit !== 0 || !is_file($output) || filesize($output) <= 0 || filesize($output) >= filesize($path)) {
            @unlink($output);
            return self::unchanged($path, $exit === 0 ? 'already_efficient' : 'client_optimized', false);
        }
        return [
            'path' => $output,
            'mime_type' => 'video/mp4',
            'size_bytes' => (int) filesize($output),
            'status' => 'optimized',
            'is_animated' => false,
            'thumbnail_path' => '',
            'width' => 0,
            'height' => 0,
        ];
    }

    private static function unchanged(string $path, string $status, bool $animated): array
    {
        return [
            'path' => $path,
            'mime_type' => function_exists('mime_content_type') ? (string) @mime_content_type($path) : '',
            'size_bytes' => is_file($path) ? (int) filesize($path) : 0,
            'status' => $status,
            'is_animated' => $animated,
            'thumbnail_path' => '',
            'width' => 0,
            'height' => 0,
        ];
    }

    private static function commandAvailable(string $command): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) return false;
        $probe = DIRECTORY_SEPARATOR === '\\' ? 'where ' . $command : 'command -v ' . $command;
        $output = [];
        $exit = 1;
        @exec($probe . ' 2>&1', $output, $exit);
        return $exit === 0;
    }
}
