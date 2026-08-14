<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-public-quarantine-contract.php';

$checks = [
    [catalogPublicQuarantineDecision('safe', true, 0, 0, 0), 'retain', 'trusted_managed_avatar'],
    [catalogPublicQuarantineDecision('safe', false, 1, 0, 0), 'retain', 'registered_safe_upload'],
    [catalogPublicQuarantineDecision('svg', false, 1, 2, 0), 'conflict', 'registered_unsafe_path_reference'],
    [catalogPublicQuarantineDecision('unknown', false, 1, 0, 1), 'disable_and_quarantine', 'registered_unsafe_id_reference_preserved'],
    [catalogPublicQuarantineDecision('unknown', false, 1, 0, 0), 'disable_and_quarantine', 'registered_unsafe_unreferenced'],
    [catalogPublicQuarantineDecision('safe', false, 0, 0, 0), 'quarantine', 'safe_orphan'],
    [catalogPublicQuarantineDecision('svg', false, 0, 0, 0), 'quarantine', 'unsafe_unregistered'],
    [catalogPublicQuarantineDecision('safe', false, 0, 1, 0), 'conflict', 'unregistered_database_reference'],
];
foreach ($checks as [$actual, $action, $reason]) {
    if ($actual['action'] !== $action || $actual['reason'] !== $reason) {
        fwrite(STDERR, "Quarantine decision contract failed\n");
        exit(1);
    }
}

if (catalogPublicQuarantineCanonicalRelative('uploads/2/2026/08/a.png') !== 'uploads/2/2026/08/a.png'
    || catalogPublicQuarantineCanonicalRelative('../uploads/a.png') !== null
    || catalogPublicQuarantineCanonicalRelative('uploads/a/../b.png') !== null
    || catalogPublicQuarantineCanonicalRelative('downloads/a.png') !== null) {
    fwrite(STDERR, "Quarantine path contract failed\n");
    exit(1);
}

$base = sys_get_temp_dir() . '/yiyunying-quarantine-contract-' . bin2hex(random_bytes(6));
$uploads = $base . '/public/uploads';
if (!mkdir($uploads, 0700, true)) {
    fwrite(STDERR, "Unable to create quarantine fixture\n");
    exit(1);
}
$file = $uploads . '/fixture.bin';
file_put_contents($file, 'before');
$fingerprint = catalogPublicQuarantineFingerprint($file, $uploads);
catalogPublicQuarantineAssertFingerprint($file, $uploads, $fingerprint);
file_put_contents($file, 'after');
$changedRejected = false;
try {
    catalogPublicQuarantineAssertFingerprint($file, $uploads, $fingerprint);
} catch (RuntimeException) {
    $changedRejected = true;
}
@unlink($file);
@rmdir($uploads);
@rmdir(dirname($uploads));
@rmdir($base);
if (!$changedRejected) {
    fwrite(STDERR, "Quarantine CAS contract failed\n");
    exit(1);
}

echo "Catalog public quarantine contract: passed\n";
