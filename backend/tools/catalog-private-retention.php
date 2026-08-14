<?php
declare(strict_types=1);

/**
 * Decide whether a catalog row is an inert tombstone/quarantine rather than a
 * downloadable entitlement. Purchased rows normally require verified private
 * bytes. The sole exception is an explicitly recorded reserved example.com
 * fixture: its purchase history is preserved, while the non-operational public
 * URL is disabled until an administrator supplies a real private upload.
 *
 * @param array<string,mixed> $row
 */
function catalogPrivateRowIsInert(array $row, bool $hasPurchase, ?array $quarantineEvidence): bool
{
    if (($row['deleted_at'] ?? null) !== null) return true;
    if ($quarantineEvidence === null) return false;
    $evidenceUrl = trim((string) ($quarantineEvidence['legacy_url'] ?? ''));
    $evidenceHash = strtolower((string) ($quarantineEvidence['legacy_url_sha256'] ?? ''));
    if ($evidenceUrl === ''
        || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
        || !hash_equals($evidenceHash, hash('sha256', $evidenceUrl))) {
        return false;
    }
    if ($hasPurchase) {
        $parts = parse_url($evidenceUrl);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower((string) ($parts['host'] ?? '')), ['example.com', 'www.example.com'], true)
            || (string) ($quarantineEvidence['reason_code'] ?? '') !== 'reserved_example_origin_purchase_unavailable') {
            return false;
        }
    }
    return ($row['source_upload_id'] ?? null) === null
        && trim((string) ($row['legacy_url'] ?? '')) === ''
        && (int) ($row['status'] ?? 1) === 0
        && in_array(strtolower(trim((string) ($row['audit_status'] ?? ''))), ['on_hold', 'rejected'], true);
}
