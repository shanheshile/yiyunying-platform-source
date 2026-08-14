<?php
declare(strict_types=1);

/**
 * Convert a historical public URL into the exact relative path stored by the
 * upload service.  This intentionally rejects aliases and path cleanup: a
 * value must already be canonical before it may identify an upload row.
 *
 * @return array{ok: bool, path?: string, reason?: string}
 */
function catalogLegacyCanonicalPath(string $legacyUrl, int $appId, string|array $applicationUrl): array
{
    $value = trim($legacyUrl);
    if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || str_contains($value, '\\')) {
        return ['ok' => false, 'reason' => 'invalid_url'];
    }
    if (preg_match('/%(?:2f|5c|00)/i', $value) === 1 || preg_match('/%(?![0-9a-f]{2})/i', $value) === 1) {
        return ['ok' => false, 'reason' => 'encoded_path_separator'];
    }
    $parts = parse_url($value);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'reason' => 'invalid_url'];
    }
    if (isset($parts['host'])) {
        $applicationUrls = is_array($applicationUrl) ? $applicationUrl : [$applicationUrl];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $originAllowed = false;
        foreach ($applicationUrls as $allowedUrl) {
            $base = parse_url((string) $allowedUrl);
            if (is_array($base)
                && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($base['host'] ?? ''))
                && (!isset($parts['port']) || !isset($base['port']) || (int) $parts['port'] === (int) $base['port'])) {
                $originAllowed = true;
                break;
            }
        }
        if (!in_array($scheme, ['http', 'https'], true) || !$originAllowed) {
            return ['ok' => false, 'reason' => 'foreign_origin'];
        }
    } elseif (isset($parts['scheme'])) {
        return ['ok' => false, 'reason' => 'invalid_scheme'];
    }
    $rawPath = (string) ($parts['path'] ?? '');
    if ($rawPath === '' || str_contains($rawPath, '//')) {
        return ['ok' => false, 'reason' => 'non_canonical_path'];
    }
    $segments = explode('/', ltrim($rawPath, '/'));
    $decoded = [];
    foreach ($segments as $segment) {
        $part = rawurldecode($segment);
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '/') || str_contains($part, '\\')) {
            return ['ok' => false, 'reason' => 'path_escape'];
        }
        $decoded[] = $part;
    }
    $relative = implode('/', $decoded);
    if (preg_match('#^uploads/' . preg_quote((string) $appId, '#') . '/[0-9]{4}/(?:0[1-9]|1[0-2])/[A-Za-z0-9][A-Za-z0-9._-]{0,254}$#D', $relative) !== 1) {
        return ['ok' => false, 'reason' => 'non_canonical_upload_path'];
    }
    return ['ok' => true, 'path' => $relative];
}

/**
 * Resolve a catalog row only when one exact upload and one trustworthy file
 * agree on tenant, owner, scene, canonical path, size and SHA-256.
 *
 * @param array<string,mixed> $catalog
 * @param array<int,array<string,mixed>> $uploads rows sharing the exact path, across every tenant
 * @param callable(string):array{status:string,size_bytes?:int,sha256?:string,nlink?:int} $inspect
 * @return array<string,mixed>
 */
function catalogLegacyResolveBinding(
    array $catalog,
    array $uploads,
    string|array $applicationUrl,
    callable $inspect
): array {
    $canonical = catalogLegacyCanonicalPath(
        (string) ($catalog['legacy_url'] ?? ''),
        (int) ($catalog['app_id'] ?? 0),
        $applicationUrl
    );
    if (!($canonical['ok'] ?? false)) return $canonical;
    $path = (string) $canonical['path'];
    $expectedScene = strtolower(trim((string) ($catalog['scene'] ?? '')));
    $tenantRows = array_values(array_filter($uploads, static function (array $upload) use ($catalog): bool {
        return (int) ($upload['admin_id'] ?? 0) === (int) ($catalog['admin_id'] ?? 0)
            && (int) ($upload['app_id'] ?? 0) === (int) ($catalog['app_id'] ?? 0);
    }));
    if ($tenantRows === [] && $uploads !== []) return ['ok' => false, 'reason' => 'tenant_mismatch'];
    $sceneRows = array_values(array_filter($tenantRows, static function (array $upload) use ($expectedScene): bool {
        return strtolower(trim((string) ($upload['scene'] ?? ''))) === $expectedScene;
    }));
    if ($sceneRows === [] && $tenantRows !== []) return ['ok' => false, 'reason' => 'scene_mismatch'];
    $ownerRows = array_values(array_filter($sceneRows, static function (array $upload) use ($catalog): bool {
        $catalogOwner = $catalog['user_id'] ?? null;
        $uploadOwner = $upload['user_id'] ?? null;
        return $catalogOwner === null
            ? $uploadOwner === null
            : $uploadOwner !== null && (int) $uploadOwner === (int) $catalogOwner;
    }));
    if ($ownerRows === [] && $sceneRows !== []) return ['ok' => false, 'reason' => 'owner_mismatch'];
    if (count($ownerRows) === 0) return ['ok' => false, 'reason' => 'no_match'];
    if (count($ownerRows) !== 1) return ['ok' => false, 'reason' => 'multiple_matches'];
    $upload = $ownerRows[0];
    if ((string) ($upload['file_path'] ?? '') !== $path) return ['ok' => false, 'reason' => 'path_mismatch'];
    if (($catalog['deleted_at'] ?? null) === null && (int) ($upload['status'] ?? 0) !== 1) {
        return ['ok' => false, 'reason' => 'inactive_upload'];
    }
    $expectedHash = strtolower(trim((string) ($upload['sha256'] ?? '')));
    $expectedSize = (int) ($upload['size_bytes'] ?? 0);
    if (preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1 || $expectedSize <= 0) {
        return ['ok' => false, 'reason' => 'invalid_upload_metadata'];
    }
    $catalogHash = strtolower(trim((string) ($catalog['file_sha256'] ?? '')));
    if ($catalogHash !== '' && (!preg_match('/^[a-f0-9]{64}$/D', $catalogHash) || !hash_equals($expectedHash, $catalogHash))) {
        return ['ok' => false, 'reason' => 'catalog_hash_mismatch'];
    }
    $catalogSize = (int) ($catalog['size_bytes'] ?? 0);
    if ($catalogSize > 0 && $catalogSize !== $expectedSize) return ['ok' => false, 'reason' => 'catalog_size_mismatch'];
    $physical = $inspect($path);
    if (($physical['status'] ?? '') !== 'file') return ['ok' => false, 'reason' => 'unsafe_or_missing_file'];
    if ((int) ($physical['nlink'] ?? 0) !== 1) return ['ok' => false, 'reason' => 'hard_link'];
    $actualHash = strtolower(trim((string) ($physical['sha256'] ?? '')));
    if ((int) ($physical['size_bytes'] ?? -1) !== $expectedSize
        || preg_match('/^[a-f0-9]{64}$/D', $actualHash) !== 1
        || !hash_equals($expectedHash, $actualHash)) {
        return ['ok' => false, 'reason' => 'physical_hash_mismatch'];
    }
    return [
        'ok' => true,
        'path' => $path,
        'path_sha256' => hash('sha256', $path),
        'upload_id' => (int) ($upload['id'] ?? 0),
        'file_sha256' => $expectedHash,
        'size_bytes' => $expectedSize,
    ];
}

/**
 * A legacy catalog URL may be quarantined only after migration 63 has already
 * made the row non-public and when no buyer has acquired continuing access.
 * The original URL is preserved in a private audit record before it is cleared.
 *
 * @param array<string,mixed> $catalog
 * @return array{ok:bool,reason?:string}
 */
function catalogLegacyQuarantineEligibility(array $catalog, string $resolutionReason = ''): array
{
    if (($catalog['source_upload_id'] ?? null) !== null) {
        return ['ok' => false, 'reason' => 'source_upload_present'];
    }
    $hasPurchase = (bool) ($catalog['has_purchase'] ?? false);
    if ($hasPurchase) {
        $legacyUrl = trim((string) ($catalog['legacy_url'] ?? ''));
        $host = strtolower((string) (parse_url($legacyUrl, PHP_URL_HOST) ?? ''));
        $reservedDemoOrigin = $resolutionReason === 'foreign_origin'
            && in_array($host, ['example.com', 'www.example.com'], true);
        if (!$reservedDemoOrigin) {
            return ['ok' => false, 'reason' => 'purchase_requires_private_file'];
        }
        $status = (int) ($catalog['status'] ?? -1);
        $auditStatus = strtolower(trim((string) ($catalog['audit_status'] ?? '')));
        $beforeMigration = $status === 1 && $auditStatus === 'approved';
        $afterMigration = $status === 0 && $auditStatus === 'on_hold';
        if ((!$beforeMigration && !$afterMigration) || $legacyUrl === '') {
            return ['ok' => false, 'reason' => 'purchased_demo_state_drift'];
        }
        return [
            'ok' => true,
            'reason_code' => 'reserved_example_origin_purchase_unavailable',
            'purchased_unavailable' => true,
        ];
    }
    if ((int) ($catalog['status'] ?? 1) !== 0) {
        return ['ok' => false, 'reason' => 'catalog_row_still_active'];
    }
    $auditStatus = strtolower(trim((string) ($catalog['audit_status'] ?? '')));
    if (!in_array($auditStatus, ['on_hold', 'rejected'], true)) {
        return ['ok' => false, 'reason' => 'catalog_row_not_quarantined'];
    }
    if (trim((string) ($catalog['legacy_url'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'legacy_url_missing'];
    }
    return ['ok' => true, 'reason_code' => $resolutionReason !== '' ? $resolutionReason : 'unresolved'];
}
