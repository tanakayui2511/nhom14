<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/Database.php';

$options = array_slice($argv, 1);
$statusOnly = in_array('--status', $options, true);
$dryRun = in_array('--dry-run', $options, true);
$reapply = in_array('--reapply', $options, true);

try {
    $connection = db();
    // Status and dry-run must not write, not even the bookkeeping table.
    $existing = queryOne($connection, "SELECT to_regclass('schema_migrations') AS name");
    $hasTable = is_array($existing) && ($existing['name'] ?? null) !== null;
    $readOnlyMode = $statusOnly || $dryRun;
    if (!$hasTable && !$readOnlyMode) {
        execSql(
            $connection,
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                checksum TEXT NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )'
        );
        $hasTable = true;
    }
    $appliedRows = $hasTable
        ? queryAll($connection, 'SELECT version,checksum,applied_at FROM schema_migrations ORDER BY version')
        : [];
    $applied = [];
    foreach ($appliedRows as $row) {
        $applied[(string)$row['version']] = $row;
    }
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        throw new RuntimeException('Không tìm thấy migration.');
    }
    $pending = 0;
    foreach ($files as $file) {
        $version = basename($file);
        $checksum = hash_file('sha256', $file);
        $isApplied = isset($applied[$version]);
        if ($isApplied && !$reapply) {
            if ($statusOnly) {
                echo 'APPLIED ' . $version . ' ' . $applied[$version]['applied_at'] . "\n";
            }
            if ((string)$applied[$version]['checksum'] !== $checksum) {
                fwrite(STDERR, 'WARN checksum changed after apply: ' . $version . "\n");
            }
            continue;
        }
        $pending++;
        if ($statusOnly || $dryRun) {
            echo ($isApplied ? 'REAPPLY ' : 'PENDING ') . $version . "\n";
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Không đọc được migration: ' . $version);
        }
        transaction($connection, function () use ($connection, $version, $checksum, $sql, $isApplied): void {
            execSql($connection, $sql);
            if ($isApplied) {
                queryParams(
                    $connection,
                    'UPDATE schema_migrations SET checksum=$2, applied_at=now() WHERE version=$1',
                    [$version, $checksum]
                );
            } else {
                queryParams(
                    $connection,
                    'INSERT INTO schema_migrations(version,checksum) VALUES($1,$2)',
                    [$version, $checksum]
                );
            }
        });
        echo 'OK ' . $version . "\n";
    }
    if ($statusOnly) {
        exit(0);
    }
    if ($dryRun) {
        echo 'DRY_RUN pending=' . $pending . "\n";
        exit(0);
    }
    echo 'MIGRATIONS_OK applied=' . count($applied) . ' executed=' . $pending . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'MIGRATION_FAILED: ' . $exception->getMessage() . "\n");
    exit(2);
}
