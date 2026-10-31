<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/Database.php';

try {
    $connection = db();
    $sql = file_get_contents(__DIR__ . '/../database/seed.sql');
    if ($sql === false) {
        throw new RuntimeException('SEED_FILE_MISSING');
    }
    execSql($connection, $sql);
    echo "SEED_OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'SEED_FAILED: ' . $exception->getMessage() . "\n");
    exit(2);
}
