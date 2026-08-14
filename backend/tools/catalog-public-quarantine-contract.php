<?php
declare(strict_types=1);

/**
 * Pure decision and filesystem primitives for the maintenance-only public
 * upload quarantine. Database discovery remains in the CLI entry point so
 * these invariants can be regression-tested without production credentials.
 */

/** @return array{action:string,reason:string} */
function catalogPublicQuarantineDecision(
    string $typeAssessment,
    bool $trustedManagedAvatar,
    int $registeredUploads,
    int $pathReferences,
    int $uploadIdReferences
): array {
    if ($pathReferences < 0 || $uploadIdReferences < 0 || $registeredUploads < 0) {
        throw new InvalidArgumentException('Reference counts cannot be negative');
    }
    if ($trustedManagedAvatar && $typeAssessment === 'safe') {
        return ['action' => 'retain', 'reason' => 'trusted_managed_avatar'];
    }
    if ($registeredUploads > 0) {
        if ($typeAssessment === 'safe') {
            return ['action' => 'retain', 'reason' => 'registered_safe_upload'];
        }
        if ($pathReferences > 0) {
            return ['action' => 'conflict', 'reason' => 'registered_unsafe_path_reference'];
        }
        return [
            'action' => 'disable_and_quarantine',
            'reason' => $uploadIdReferences > 0
                ? 'registered_unsafe_id_reference_preserved'
                : 'registered_unsafe_unreferenced',
        ];
    }
    if ($pathReferences > 0 || $uploadIdReferences > 0) {
        return ['action' => 'conflict', 'reason' => 'unregistered_database_reference'];
    }
    return [
        'action' => 'quarantine',
        'reason' => $typeAssessment === 'safe' ? 'safe_orphan' : 'unsafe_unregistered',
    ];
}

function catalogPublicQuarantineCanonicalRelative(string $relative): ?string
{
    if (str_contains($relative, "\0")) return null;
    $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
    if (!str_starts_with($relative, 'uploads/') || $relative === 'uploads/.gitkeep') return null;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return null;
    }
    return $relative;
}

/** @return array{size:int,sha256:string,nlink:int} */
function catalogPublicQuarantineFingerprint(string $path, string $publicUploadsRoot): array
{
    $root = realpath($publicUploadsRoot);
    $resolved = realpath($path);
    if ($root === false || $resolved === false || is_link($path) || !is_file($path)) {
        throw new RuntimeException('Upload path is missing, linked or not a regular file');
    }
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $resolvedNormalized = str_replace('\\', '/', $resolved);
    if (!str_starts_with($resolvedNormalized, $root)) {
        throw new RuntimeException('Upload path escaped the public uploads root');
    }
    $stat = @lstat($path);
    $size = @filesize($path);
    $sha256 = @hash_file('sha256', $path);
    if (!is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1 || $size === false
        || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', strtolower($sha256)) !== 1) {
        throw new RuntimeException('Upload fingerprint or hard-link safety check failed');
    }
    return ['size' => (int) $size, 'sha256' => strtolower($sha256), 'nlink' => 1];
}

/** @param array{size:int,sha256:string} $expected */
function catalogPublicQuarantineAssertFingerprint(
    string $path,
    string $publicUploadsRoot,
    array $expected
): void {
    $actual = catalogPublicQuarantineFingerprint($path, $publicUploadsRoot);
    if ($actual['size'] !== (int) $expected['size']
        || !hash_equals(strtolower((string) $expected['sha256']), $actual['sha256'])) {
        throw new RuntimeException('Upload changed after quarantine planning');
    }
}
