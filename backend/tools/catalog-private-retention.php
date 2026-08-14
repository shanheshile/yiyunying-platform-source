<?php
declare(strict_types=1);

/**
 * Decide whether a catalog row is an inert tombstone/quarantine rather than a
 * downloadable entitlement.  A live or purchased row always requires a
 * verified private upload.  Non-deleted rows are exempt only when a private
 * quarantine record proves that their legacy URL was intentionally removed.
 *
 * @param array<string,mixed> $row
 */
function catalogPrivateRowIsInert(array $row, bool $hasPurchase, bool $hasQuarantineEvidence): bool
{
    if ($hasPurchase) return false;
    if (($row['deleted_at'] ?? null) !== null) return true;
    if (!$hasQuarantineEvidence) return false;
    return ($row['source_upload_id'] ?? null) === null
        && trim((string) ($row['legacy_url'] ?? '')) === ''
        && (int) ($row['status'] ?? 1) === 0
        && in_array(strtolower(trim((string) ($row['audit_status'] ?? ''))), ['on_hold', 'rejected'], true);
}
