<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-legacy-upload-binding.php';

$hash = str_repeat('a', 64);
$catalog = [
    'admin_id' => 7, 'app_id' => 3, 'user_id' => 11, 'scene' => 'resource_source',
    'legacy_url' => 'https://app.example.test/uploads/3/2026/08/a.zip?download=1',
    'size_bytes' => 10, 'file_sha256' => $hash, 'deleted_at' => null,
];
$upload = [
    'id' => 91, 'admin_id' => 7, 'app_id' => 3, 'user_id' => 11, 'scene' => 'resource_source',
    'file_path' => 'uploads/3/2026/08/a.zip', 'size_bytes' => 10, 'sha256' => $hash, 'status' => 1,
];
$file = static fn (string $path): array => [
    'status' => 'file', 'size_bytes' => 10, 'sha256' => $hash, 'nlink' => 1,
];
$checks = [];
$checks['one exact match resolves'] = (catalogLegacyResolveBinding($catalog, [$upload], 'https://app.example.test', $file)['upload_id'] ?? 0) === 91;
$checks['same exact upload may back multiple catalog rows'] =
    (catalogLegacyResolveBinding(array_replace($catalog, ['title' => 'second']), [$upload], 'https://app.example.test', $file)['upload_id'] ?? 0) === 91;
$checks['zero match blocks'] = (catalogLegacyResolveBinding($catalog, [], 'https://app.example.test', $file)['reason'] ?? '') === 'no_match';
$checks['multiple match blocks'] = (catalogLegacyResolveBinding($catalog, [$upload, array_replace($upload, ['id' => 92])], 'https://app.example.test', $file)['reason'] ?? '') === 'multiple_matches';
$checks['tenant mismatch blocks'] = (catalogLegacyResolveBinding($catalog, [array_replace($upload, ['admin_id' => 8])], 'https://app.example.test', $file)['reason'] ?? '') === 'tenant_mismatch';
$checks['scene mismatch blocks'] = (catalogLegacyResolveBinding($catalog, [array_replace($upload, ['scene' => 'store_app_package'])], 'https://app.example.test', $file)['reason'] ?? '') === 'scene_mismatch';
$checks['path escape blocks'] = (catalogLegacyResolveBinding(array_replace($catalog, ['legacy_url' => '/uploads/3/2026/08/../a.zip']), [], 'https://app.example.test', $file)['reason'] ?? '') === 'path_escape';
$hardLink = static fn (string $path): array => ['status' => 'file', 'size_bytes' => 10, 'sha256' => $hash, 'nlink' => 2];
$checks['hard link blocks'] = (catalogLegacyResolveBinding($catalog, [$upload], 'https://app.example.test', $hardLink)['reason'] ?? '') === 'hard_link';
$wrongHash = static fn (string $path): array => ['status' => 'file', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'nlink' => 1];
$checks['hash mismatch blocks'] = (catalogLegacyResolveBinding($catalog, [$upload], 'https://app.example.test', $wrongHash)['reason'] ?? '') === 'physical_hash_mismatch';
$checks['foreign origin blocks'] = (catalogLegacyResolveBinding(array_replace($catalog, ['legacy_url' => 'https://evil.example/uploads/3/2026/08/a.zip']), [], 'https://app.example.test', $file)['reason'] ?? '') === 'foreign_origin';
$checks['explicit historical origin resolves'] =
    (catalogLegacyResolveBinding(array_replace($catalog, ['legacy_url' => 'https://old.example/uploads/3/2026/08/a.zip']), [$upload], ['https://app.example.test', 'https://old.example'], $file)['upload_id'] ?? 0) === 91;
$checks['basename-only alias blocks'] = (catalogLegacyResolveBinding(array_replace($catalog, ['legacy_url' => '/a.zip']), [$upload], 'https://app.example.test', $file)['reason'] ?? '') === 'non_canonical_upload_path';
$quarantineBase = array_replace($catalog, [
    'source_upload_id' => null, 'status' => 0, 'audit_status' => 'on_hold', 'has_purchase' => false,
]);
$checks['inactive unpurchased on-hold row may quarantine'] = catalogLegacyQuarantineEligibility($quarantineBase)['ok'] ?? false;
$checks['purchased row cannot quarantine'] = (catalogLegacyQuarantineEligibility(array_replace($quarantineBase, ['has_purchase' => true]))['reason'] ?? '') === 'purchase_requires_private_file';
$reservedPurchased = array_replace($quarantineBase, [
    'legacy_url' => 'https://example.com/retired-demo-resource.zip',
    'has_purchase' => true,
    'status' => 1,
    'audit_status' => 'approved',
]);
$reservedEligibility = catalogLegacyQuarantineEligibility($reservedPurchased, 'foreign_origin');
$checks['purchased reserved-domain fixture is disabled with explicit evidence'] =
    ($reservedEligibility['ok'] ?? false)
    && ($reservedEligibility['purchased_unavailable'] ?? false)
    && ($reservedEligibility['reason_code'] ?? '') === 'reserved_example_origin_purchase_unavailable';
$checks['migration-63 on-hold state remains eligible for reserved fixture quarantine'] =
    (catalogLegacyQuarantineEligibility(
        array_replace($reservedPurchased, ['status' => 0, 'audit_status' => 'on_hold']),
        'foreign_origin'
    )['reason_code'] ?? '') === 'reserved_example_origin_purchase_unavailable';
$checks['purchased non-reserved foreign origin still blocks'] =
    (catalogLegacyQuarantineEligibility(
        array_replace($reservedPurchased, ['legacy_url' => 'https://vendor.example.net/asset.zip']),
        'foreign_origin'
    )['reason'] ?? '') === 'purchase_requires_private_file';
$checks['purchased reserved-domain fixture state drift blocks'] =
    (catalogLegacyQuarantineEligibility(
        array_replace($reservedPurchased, ['audit_status' => 'pending']),
        'foreign_origin'
    )['reason'] ?? '') === 'purchased_demo_state_drift';
$checks['active row cannot quarantine'] = (catalogLegacyQuarantineEligibility(array_replace($quarantineBase, ['status' => 1]))['reason'] ?? '') === 'catalog_row_still_active';
$checks['pending row cannot quarantine'] = (catalogLegacyQuarantineEligibility(array_replace($quarantineBase, ['audit_status' => 'pending']))['reason'] ?? '') === 'catalog_row_not_quarantined';

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Catalog legacy upload binding contract passed: ' . count($checks) . PHP_EOL);
