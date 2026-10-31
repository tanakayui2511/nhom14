<?php

declare(strict_types=1);

function loadDatabaseConfig(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $configured = getenv('RT_DB_CONFIG');
    $configPath = is_string($configured) && $configured !== ''
        ? $configured
        : __DIR__ . '/../.local/db.json';
    if (!is_file($configPath)) {
        return;
    }
    $config = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($config)) {
        throw new RuntimeException('DATABASE_CONFIG_INVALID');
    }
    foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
        putenv($key . '=' . $config[$key]);
    }
    // Optional TLS settings are passed to libpq through the process
    // environment. Only documented values are accepted; an empty value is
    // treated as "not configured" so the ambient environment still applies.
    $sslMode = $config['PGSSLMODE'] ?? null;
    if ($sslMode !== null && $sslMode !== '') {
        $allowed = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!is_string($sslMode) || !in_array($sslMode, $allowed, true)) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
        putenv('PGSSLMODE=' . $sslMode);
    }
    $sslRootCert = $config['PGSSLROOTCERT'] ?? null;
    if ($sslRootCert !== null && $sslRootCert !== '') {
        if (!is_string($sslRootCert)) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
        putenv('PGSSLROOTCERT=' . $sslRootCert);
    }
}

function db(): PgSql\Connection
{
    if (!extension_loaded('pgsql')) {
        throw new RuntimeException('PGSQL_EXTENSION_MISSING');
    }
    loadDatabaseConfig();
    foreach (['PGHOST', 'PGDATABASE', 'PGUSER'] as $key) {
        if (!getenv($key)) {
            throw new RuntimeException('DATABASE_CONFIG_MISSING');
        }
    }
    $connection = @pg_connect('connect_timeout=3');
    if (!$connection) {
        throw new RuntimeException('DATABASE_UNAVAILABLE');
    }
    $schema = getenv('RT_DB_SCHEMA');
    if (is_string($schema) && $schema !== '') {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            throw new RuntimeException('DATABASE_SCHEMA_INVALID');
        }
        $result = @pg_query_params($connection, "SELECT set_config('search_path',\$1,false)", [$schema]);
        if ($result === false) {
            throw new RuntimeException('DATABASE_SCHEMA_UNAVAILABLE');
        }
    }
    return $connection;
}

function queryParams(PgSql\Connection $connection, string $sql, array $params): PgSql\Result
{
    $result = @pg_query_params($connection, $sql, $params);
    if ($result === false) {
        error_log('DATABASE_OPERATION_FAILED: ' . pg_last_error($connection));
        throw new RuntimeException('DATABASE_OPERATION_FAILED');
    }
    return $result;
}

function queryAll(PgSql\Connection $connection, string $sql, array $params = []): array
{
    return pg_fetch_all(queryParams($connection, $sql, $params)) ?: [];
}

function queryOne(PgSql\Connection $connection, string $sql, array $params = []): ?array
{
    $row = pg_fetch_assoc(queryParams($connection, $sql, $params));
    return $row === false ? null : $row;
}

function execSql(PgSql\Connection $connection, string $sql): void
{
    $result = @pg_query($connection, $sql);
    if ($result === false) {
        error_log('DATABASE_OPERATION_FAILED: ' . pg_last_error($connection));
        throw new RuntimeException('DATABASE_OPERATION_FAILED');
    }
}

function transaction(PgSql\Connection $connection, callable $callback): mixed
{
    $status = pg_transaction_status($connection);
    if ($status === PGSQL_TRANSACTION_IDLE) {
        execSql($connection, 'BEGIN');
        try {
            $result = $callback();
            execSql($connection, 'COMMIT');
            return $result;
        } catch (Throwable $exception) {
            @pg_query($connection, 'ROLLBACK');
            throw $exception;
        }
    }
    if ($status === PGSQL_TRANSACTION_INTRANS) {
        $savepoint = 'rt_sp_' . bin2hex(random_bytes(5));
        execSql($connection, 'SAVEPOINT ' . $savepoint);
        try {
            $result = $callback();
            execSql($connection, 'RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $exception) {
            @pg_query($connection, 'ROLLBACK TO SAVEPOINT ' . $savepoint);
            @pg_query($connection, 'RELEASE SAVEPOINT ' . $savepoint);
            throw $exception;
        }
    }
    throw new RuntimeException('DATABASE_TRANSACTION_STATE_INVALID');
}

function projectLock(PgSql\Connection $connection, int $projectId): void
{
    if ($projectId <= 0) {
        throw new RuntimeException('PROJECT_INVALID');
    }
    $row = queryOne($connection, 'SELECT id FROM projects WHERE id=$1 FOR UPDATE', [(string)$projectId]);
    if ($row === null) {
        throw new RuntimeException('PROJECT_NOT_FOUND');
    }
}
