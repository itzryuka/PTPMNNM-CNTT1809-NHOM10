<?php

declare(strict_types=1);
// Dùng lại biến môi trường cho lệnh psql ngoài PHP. Không nạp mã PHP cấu hình.
$configPath = __DIR__ . '/../.local/db.json';
if (is_file($configPath)) {
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
}
function db(): PgSql\Connection
{
    if (!extension_loaded('pgsql')) {
        throw new RuntimeException('PGSQL_EXTENSION_MISSING');
    }
    foreach (['PGHOST', 'PGDATABASE', 'PGUSER'] as $key) {
        if (!getenv($key)) {
            throw new RuntimeException('DATABASE_CONFIG_MISSING');
        }
    }
    $connection = @pg_connect('connect_timeout=3');
    if (!$connection) {
        throw new RuntimeException('DATABASE_UNAVAILABLE');
    }
    return $connection;
}
function queryParams(PgSql\Connection $connection, string $sql, array $params): PgSql\Result
{
    $result = @pg_query_params($connection, $sql, $params);
    if ($result === false) {
        throw new RuntimeException('DATABASE_OPERATION_FAILED');
    }
    return $result;
}
