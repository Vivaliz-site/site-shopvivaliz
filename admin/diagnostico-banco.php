<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svdb_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svdb_env(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function svdb_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['c'] ?? 0) > 0;
}

function svdb_scalar(mysqli $db, string $sql): int|string|null
{
    $result = $db->query($sql);
    if (!$result) {
        return null;
    }
    $row = $result->fetch_row();
    return $row[0] ?? null;
}

function svdb_table_summary(mysqli $db, string $table): array
{
    if (!svdb_table_exists($db, $table)) {
        return ['exists' => false];
    }

    $count = svdb_scalar($db, 'SELECT COUNT(*) FROM `' . $db->real_escape_string($table) . '`');
    $distinctSku = null;
    $columns = [];
    $colResult = $db->query('SHOW COLUMNS FROM `' . $db->real_escape_string($table) . '`');
    if ($colResult) {
        while ($row = $colResult->fetch_assoc()) {
            $columns[] = (string)($row['Field'] ?? '');
        }
    }
    if (in_array('sku', $columns, true)) {
        $distinctSku = svdb_scalar($db, 'SELECT COUNT(DISTINCT sku) FROM `' . $db->real_escape_string($table) . '` WHERE sku IS NOT NULL AND sku <> ""');
    }

    $indexes = [];
    $idxResult = $db->query('SHOW INDEX FROM `' . $db->real_escape_string($table) . '`');
    if ($idxResult) {
        while ($row = $idxResult->fetch_assoc()) {
            $indexes[] = [
                'name' => (string)($row['Key_name'] ?? ''),
                'column' => (string)($row['Column_name'] ?? ''),
                'unique' => ((int)($row['Non_unique'] ?? 1)) === 0,
            ];
        }
    }

    return [
        'exists' => true,
        'rows' => is_numeric($count) ? (int)$count : $count,
        'distinct_skus' => is_numeric($distinctSku) ? (int)$distinctSku : $distinctSku,
        'columns' => array_values(array_filter($columns)),
        'indexes' => $indexes,
    ];
}

function svdb_recommended_indexes(mysqli $db): array
{
    $recommendations = [
        'products' => [
            'idx_products_sku' => 'ALTER TABLE products ADD INDEX idx_products_sku (sku)',
            'idx_products_status' => 'ALTER TABLE products ADD INDEX idx_products_status (status)',
        ],
        'olist_products' => [
            'idx_olist_products_sku' => 'ALTER TABLE olist_products ADD INDEX idx_olist_products_sku (sku)',
            'idx_olist_products_id' => 'ALTER TABLE olist_products ADD INDEX idx_olist_products_id (id)',
        ],
        'olist_product_images' => [
            'idx_olist_product_images_sku' => 'ALTER TABLE olist_product_images ADD INDEX idx_olist_product_images_sku (sku)',
        ],
        'catalog_optimizations_staging' => [
            'idx_catalog_staging_status_channel' => 'ALTER TABLE catalog_optimizations_staging ADD INDEX idx_catalog_staging_status_channel (status, channel)',
            'idx_catalog_staging_product_channel' => 'ALTER TABLE catalog_optimizations_staging ADD INDEX idx_catalog_staging_product_channel (product_id, channel)',
        ],
    ];

    $missing = [];
    foreach ($recommendations as $table => $indexes) {
        if (!svdb_table_exists($db, $table)) {
            continue;
        }
        $existing = [];
        $idxResult = $db->query('SHOW INDEX FROM `' . $db->real_escape_string($table) . '`');
        if ($idxResult) {
            while ($row = $idxResult->fetch_assoc()) {
                $existing[(string)($row['Key_name'] ?? '')] = true;
            }
        }
        foreach ($indexes as $name => $sql) {
            if (!isset($existing[$name])) {
                $missing[] = ['table' => $table, 'index' => $name, 'sql' => $sql];
            }
        }
    }

    return $missing;
}

$host = svdb_env('DB_HOST', 'localhost');
$user = svdb_env('DB_USER', 'root');
$pass = svdb_env('DB_PASS', '');
$dbName = svdb_env('DB_NAME', 'shopvivaliz');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = @new mysqli($host, $user, $pass, $dbName, 3306);
} catch (Throwable $e) {
    svdb_json(503, ['ok' => false, 'error' => 'database_unavailable']);
}

if ($db->connect_errno) {
    svdb_json(503, ['ok' => false, 'error' => 'database_unavailable']);
}

$db->set_charset('utf8mb4');

$tables = [];
$tableResult = $db->query('SHOW TABLES');
if ($tableResult) {
    while ($row = $tableResult->fetch_row()) {
        $tables[] = (string)$row[0];
    }
}

$targetTables = ['products', 'olist_products', 'olist_product_images', 'catalog_optimizations_staging', 'product_channel_content', 'orders'];
$summary = [];
foreach ($targetTables as $table) {
    $summary[$table] = svdb_table_summary($db, $table);
}

$payload = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'database' => [
        'host' => $host,
        'name' => $dbName,
        'server_info' => $db->server_info,
        'tables_total' => count($tables),
    ],
    'tables' => $summary,
    'recommended_indexes' => svdb_recommended_indexes($db),
    'notes' => [
        'recommended_indexes_are_dry_run' => true,
        'no_schema_changes_were_applied' => true,
    ],
];

$db->close();
svdb_json(200, $payload);
