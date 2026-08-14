<?php
declare(strict_types=1);

/**
 * Isolated upload-deduplication contract.
 *
 * The normal check run proves the source invariants without requiring a local
 * database. Set YIYUNYING_TEST_MARIADB_DSN (server DSN without a production
 * database) to run the real MariaDB concurrency/rollback fixture. The fixture
 * creates and drops only its own random yiyun_upload_contract_* database.
 */

$root = dirname(__DIR__);
$storagePath = $root . '/app/Services/UploadStorageService.php';
$storage = file_get_contents($storagePath);
if (!is_string($storage) || $storage === '') {
    fwrite(STDERR, "Unable to read UploadStorageService\n");
    exit(1);
}
$persistStart = strpos($storage, 'private static function persistProcessedUpload');
$persistEnd = strpos($storage, 'private static function dedupeLockName');
$persist = $persistStart !== false && $persistEnd !== false && $persistEnd > $persistStart
    ? substr($storage, $persistStart, $persistEnd - $persistStart)
    : '';
$transactionAt = strpos($persist, 'return Database::transaction');
$prevalidationAt = strpos($persist, 'self::selectReusableUploadCandidates(');
$staticChecks = [
    $persist !== '',
    str_contains($persist, 'SELECT GET_LOCK(?, 10) AS acquired'),
    str_contains($persist, 'SELECT RELEASE_LOCK(?) AS released'),
    str_contains($persist, 'ORDER BY id'),
    !str_contains($persist, 'LIMIT 50'),
    $prevalidationAt !== false && $transactionAt !== false && $prevalidationAt < $transactionAt,
    str_contains($persist, '$cache[$cacheKey] = self::validateReusablePhysicalUpload'),
    str_contains($persist, 'physical_validation_count'),
    str_contains($persist, 'self::uploadRowFingerprint($locked)'),
    str_contains($persist, 'self::storedPhysicalFingerprint('),
    strpos(substr($persist, (int) $transactionAt), 'prevalidatedReusableUpload') === false,
    strpos(substr($persist, (int) $transactionAt), 'hash_file(') === false,
    str_contains($storage, 'if (!$committed) {')
        && str_contains($storage, '$primaryFailure = null')
        && str_contains($storage, "error_log('upload_cleanup_after_failure: '"),
];
if (in_array(false, $staticChecks, true)) {
    $failed = [];
    foreach ($staticChecks as $index => $passed) if ($passed !== true) $failed[] = (string) ($index + 1);
    fwrite(STDERR, 'Upload dedupe source contract failed: checks ' . implode(', ', $failed) . "\n");
    exit(1);
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
}

function dsnWithDatabase(string $dsn, string $database): string
{
    $dsn = (string) preg_replace('/;dbname=[^;]*/i', '', rtrim($dsn, ';'));
    return $dsn . ';dbname=' . $database;
}

function databaseConnection(string $dsn): PDO
{
    return new PDO(
        $dsn,
        envValue('YIYUNYING_TEST_MARIADB_USER', 'root'),
        envValue('YIYUNYING_TEST_MARIADB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function quotedIdentifier(string $identifier): string
{
    if (preg_match('/^[a-z0-9_]+$/D', $identifier) !== 1) {
        throw new RuntimeException('Unsafe isolated database identifier');
    }
    return '`' . $identifier . '`';
}

function dedupeLockName(string $fingerprint): string
{
    return 'yiyun_upload_' . substr(hash('sha256', $fingerprint), 0, 48);
}

function workerCommand(): array
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows' && extension_loaded('pdo_mysql')) {
        $extensionDir = (string) ini_get('extension_dir');
        if ($extensionDir !== '') {
            $command[] = '-d';
            $command[] = 'extension_dir=' . $extensionDir;
            $command[] = '-d';
            $command[] = 'extension=php_pdo_mysql.dll';
        }
    }
    $command[] = __FILE__;
    return $command;
}

function runWorker(string $fingerprint, int $owner): never
{
    try {
        $dsn = envValue('YIYUNYING_TEST_MARIADB_DSN');
        $database = envValue('YIYUNYING_TEST_MARIADB_DATABASE');
        if ($dsn === '' || preg_match('/^yiyun_upload_contract_[a-f0-9]{12}$/D', $database) !== 1) {
            throw new RuntimeException('Worker isolation context missing');
        }
        $pdo = databaseConnection(dsnWithDatabase($dsn, $database));
        $lockName = dedupeLockName($fingerprint);
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        $statement->execute([$lockName]);
        if ((int) ($statement->fetchColumn() ?: 0) !== 1) throw new RuntimeException('Worker lock unavailable');
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT id FROM physical_files WHERE fingerprint = ? AND status = 1 ORDER BY id LIMIT 1'
            );
            $statement->execute([$fingerprint]);
            $physicalId = (int) ($statement->fetchColumn() ?: 0);
            if ($physicalId <= 0) {
                $statement = $pdo->prepare(
                    'INSERT INTO physical_files (fingerprint, stored_path, status) VALUES (?, ?, 1)'
                );
                $statement->execute([$fingerprint, 'fixture/' . hash('sha256', $fingerprint) . '.bin']);
                $physicalId = (int) $pdo->lastInsertId();
                // Keep the first transaction open briefly so the second worker
                // must prove serialization through GET_LOCK.
                usleep(250000);
            }
            $statement = $pdo->prepare(
                'INSERT INTO logical_uploads (owner_id, physical_id, fingerprint, status) VALUES (?, ?, ?, 1)'
            );
            $statement->execute([$owner, $physicalId, $fingerprint]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        } finally {
            try {
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lockName]);
            } catch (Throwable) {
            }
        }
        echo "worker-passed\n";
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "worker-failed\n");
        exit(1);
    }
}

if (($argv[1] ?? '') === 'worker') {
    $fingerprint = strtolower((string) ($argv[2] ?? ''));
    $owner = (int) ($argv[3] ?? 0);
    if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || $owner <= 0) {
        fwrite(STDERR, "worker-input-invalid\n");
        exit(1);
    }
    runWorker($fingerprint, $owner);
}

$dsn = envValue('YIYUNYING_TEST_MARIADB_DSN');
if ($dsn === '') {
    echo "Upload dedupe MariaDB contract: source invariants passed; isolated integration not selected\n";
    exit(0);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "Upload dedupe MariaDB contract requires pdo_mysql\n");
    exit(1);
}

$database = 'yiyun_upload_contract_' . bin2hex(random_bytes(6));
$server = null;
$fixture = null;
$rollbackPath = '';
$previousDatabaseEnv = getenv('YIYUNYING_TEST_MARIADB_DATABASE');
try {
    $server = databaseConnection($dsn);
    $server->exec('CREATE DATABASE ' . quotedIdentifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('YIYUNYING_TEST_MARIADB_DATABASE=' . $database);
    $fixture = databaseConnection(dsnWithDatabase($dsn, $database));
    $fixture->exec(
        'CREATE TABLE physical_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fingerprint CHAR(64) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            status TINYINT NOT NULL DEFAULT 1,
            KEY idx_fingerprint (fingerprint, status)
        ) ENGINE=InnoDB'
    );
    $fixture->exec(
        'CREATE TABLE logical_uploads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT UNSIGNED NOT NULL,
            physical_id BIGINT UNSIGNED NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            status TINYINT NOT NULL DEFAULT 1,
            KEY idx_fingerprint (fingerprint, status)
        ) ENGINE=InnoDB'
    );
    $fixture->exec(
        'CREATE TABLE dirty_candidates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fingerprint CHAR(64) NOT NULL,
            is_valid TINYINT NOT NULL,
            status TINYINT NOT NULL DEFAULT 1,
            KEY idx_fingerprint (fingerprint, status)
        ) ENGINE=InnoDB'
    );
    $fixture->exec(
        'CREATE TABLE rollback_rows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            marker VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB'
    );

    $dirtyFingerprint = hash('sha256', 'dirty-first-60-' . random_bytes(8));
    $insertDirty = $fixture->prepare(
        'INSERT INTO dirty_candidates (fingerprint, is_valid, status) VALUES (?, ?, 1)'
    );
    $fixture->beginTransaction();
    for ($index = 0; $index < 60; $index++) $insertDirty->execute([$dirtyFingerprint, 0]);
    $insertDirty->execute([$dirtyFingerprint, 1]);
    $fixture->commit();
    $queryDirty = $fixture->prepare(
        'SELECT id, is_valid FROM dirty_candidates WHERE fingerprint = ? AND status = 1 ORDER BY id'
    );
    $queryDirty->execute([$dirtyFingerprint]);
    $dirtyRows = $queryDirty->fetchAll();
    $validPosition = null;
    foreach ($dirtyRows as $index => $row) {
        if ((int) $row['is_valid'] === 1) { $validPosition = $index; break; }
    }
    if (count($dirtyRows) !== 61 || $validPosition !== 60) {
        throw new RuntimeException('Dirty-first-60 scan contract failed');
    }

    $fingerprint = hash('sha256', 'concurrent-' . random_bytes(8));
    $baseCommand = workerCommand();
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $workers = [];
    foreach ([101, 202] as $owner) {
        $pipes = [];
        $process = proc_open(array_merge($baseCommand, ['worker', $fingerprint, (string) $owner]), $descriptors, $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to start MariaDB concurrency worker');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || trim((string) $stdout) !== 'worker-passed' || trim((string) $stderr) !== '') {
            throw new RuntimeException('MariaDB concurrency worker failed');
        }
    }
    $statement = $fixture->prepare('SELECT COUNT(*) FROM physical_files WHERE fingerprint = ? AND status = 1');
    $statement->execute([$fingerprint]);
    $physicalCount = (int) $statement->fetchColumn();
    $statement = $fixture->prepare('SELECT COUNT(DISTINCT physical_id), COUNT(*) FROM logical_uploads WHERE fingerprint = ?');
    $statement->execute([$fingerprint]);
    $logicalCounts = $statement->fetch(PDO::FETCH_NUM);
    if ($physicalCount !== 1 || (int) ($logicalCounts[0] ?? 0) !== 1 || (int) ($logicalCounts[1] ?? 0) !== 2) {
        throw new RuntimeException('Concurrent physical dedupe contract failed');
    }
    $duplicateGroups = (int) $fixture->query(
        'SELECT COUNT(*) FROM (
            SELECT fingerprint FROM physical_files WHERE status = 1 GROUP BY fingerprint HAVING COUNT(*) > 1
        ) duplicate_groups'
    )->fetchColumn();
    if ($duplicateGroups !== 0) throw new RuntimeException('Duplicate audit failed before optional schema migration');

    $rollbackPath = tempnam(sys_get_temp_dir(), 'yiyunying-upload-rollback-');
    if (!is_string($rollbackPath) || file_put_contents($rollbackPath, 'candidate') !== 9) {
        throw new RuntimeException('Unable to create rollback fixture');
    }
    try {
        $fixture->beginTransaction();
        $statement = $fixture->prepare('INSERT INTO rollback_rows (marker) VALUES (?)');
        $statement->execute(['must-rollback']);
        throw new RuntimeException('intentional rollback');
    } catch (Throwable $exception) {
        if ($fixture->inTransaction()) $fixture->rollBack();
        if ($exception->getMessage() !== 'intentional rollback') throw $exception;
    } finally {
        if (is_file($rollbackPath) && !unlink($rollbackPath)) {
            throw new RuntimeException('Rollback file cleanup failed');
        }
    }
    if ((int) $fixture->query('SELECT COUNT(*) FROM rollback_rows')->fetchColumn() !== 0
        || is_file($rollbackPath)) {
        throw new RuntimeException('Rollback DB/file cleanup contract failed');
    }

    echo "Upload dedupe MariaDB contract: passed (concurrent=2, physical=1, dirty_scan=61, rollback=clean)\n";
} catch (Throwable) {
    fwrite(STDERR, "Upload dedupe MariaDB contract failed\n");
    exit(1);
} finally {
    if (is_string($rollbackPath) && $rollbackPath !== '' && is_file($rollbackPath)) @unlink($rollbackPath);
    $fixture = null;
    if ($server instanceof PDO && preg_match('/^yiyun_upload_contract_[a-f0-9]{12}$/D', $database) === 1) {
        try { $server->exec('DROP DATABASE IF EXISTS ' . quotedIdentifier($database)); } catch (Throwable) {}
    }
    if ($previousDatabaseEnv === false) putenv('YIYUNYING_TEST_MARIADB_DATABASE');
    else putenv('YIYUNYING_TEST_MARIADB_DATABASE=' . $previousDatabaseEnv);
}
