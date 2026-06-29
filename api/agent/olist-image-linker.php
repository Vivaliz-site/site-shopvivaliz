<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svil_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svil_root(): string
{
    return dirname(__DIR__, 2);
}

function svil_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function svil_auth(): void
{
    svil_env_load(svil_root() . '/.env');
    $expected = getenv('SQUAD_TOKEN') ?: '';
    if ($expected === '') {
        svil_json(503, ['ok' => false, 'error' => 'SQUAD_TOKEN not configured']);
    }
    $received = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
    if ($received === '' || !hash_equals($expected, $received)) {
        svil_json(401, ['ok' => false, 'error' => 'Unauthorized']);
    }
}

function svil_db(): mysqli
{
    svil_env_load(svil_root() . '/.env');
    $configFiles = [
        svil_root() . '/config/constants.php',
        svil_root() . '/config/database.php',
        svil_root() . '/config.php',
        svil_root() . '/includes/config.php',
    ];
    foreach ($configFiles as $file) {
        if (is_file($file)) {
            try {
                require_once $file;
            } catch (Throwable $ignored) {
            }
        }
    }

    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
        if ($db instanceof mysqli) return $db;
    }

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: ''));
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: ''));
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: ''));

    if ($name === '' || $user === '') {
        svil_json(500, ['ok' => false, 'error' => 'database_config_unavailable']);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function svil_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function svil_index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function svil_exec(mysqli $db, string $sql, array &$actions, string $label): void
{
    try {
        $db->query($sql);
        $actions[] = ['step' => $label, 'status' => 'ok'];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (preg_match('/already exists|duplicate (column|key|index)/i', $message)) {
            $actions[] = ['step' => $label, 'status' => 'already_exists'];
            return;
        }
        throw $e;
    }
}

function svil_ensure_column(mysqli $db, string $table, string $column, string $sql, array &$actions): void
{
    if (svil_column_exists($db, $table, $column)) {
        $actions[] = ['step' => 'ensure_column', 'target' => $table . '.' . $column, 'status' => 'already_exists'];
        return;
    }
    svil_exec($db, $sql, $actions, 'ensure_column ' . $table . '.' . $column);
}

function svil_ensure_index(mysqli $db, string $table, string $index, string $sql, array &$actions): void
{
    if (svil_index_exists($db, $table, $index)) {
        $actions[] = ['step' => 'ensure_index', 'target' => $index, 'status' => 'already_exists'];
        return;
    }
    svil_exec($db, $sql, $actions, 'ensure_index ' . $index);
}

function svil_schema(mysqli $db): array
{
    $actions = [];
    svil_exec($db, "CREATE TABLE IF NOT EXISTS olist_products (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, sku VARCHAR(191) NULL, olist_product_id VARCHAR(64) NULL, olist_id VARCHAR(64) NULL, idProduto VARCHAR(64) NULL, name VARCHAR(255) NULL, primary_image_url VARCHAR(1000) NULL, images_count INT NOT NULL DEFAULT 0, image_sync_status VARCHAR(40) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY idx_sku (sku), KEY idx_olist_product_id (olist_product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $actions, 'create olist_products');
    svil_exec($db, "CREATE TABLE IF NOT EXISTS olist_product_images (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED NULL, product_local_id BIGINT UNSIGNED NULL, olist_product_id VARCHAR(64) NULL, olist_id VARCHAR(64) NULL, sku VARCHAR(191) NULL, image_url VARCHAR(1000) NULL, site_url VARCHAR(1000) NULL, local_url VARCHAR(1000) NULL, original_url VARCHAR(1000) NULL, original_url_olist VARCHAR(1000) NULL, local_file VARCHAR(1000) NULL, `position` INT NOT NULL DEFAULT 0, is_primary TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(80) NOT NULL DEFAULT 'olist_api', status VARCHAR(40) NOT NULL DEFAULT 'active', url_hash CHAR(64) NULL, file_hash CHAR(64) NULL, dedupe_key VARCHAR(191) NULL, uploaded TINYINT(1) NOT NULL DEFAULT 0, linked TINYINT(1) NOT NULL DEFAULT 0, error_message TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY idx_product_status (product_local_id, status), KEY idx_sku_status (sku, status), KEY idx_dedupe_key (dedupe_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $actions, 'create olist_product_images');

    $imageColumns = [
        ['product_id', 'ALTER TABLE olist_product_images ADD COLUMN product_id BIGINT UNSIGNED NULL'],
        ['product_local_id', 'ALTER TABLE olist_product_images ADD COLUMN product_local_id BIGINT UNSIGNED NULL'],
        ['olist_product_id', 'ALTER TABLE olist_product_images ADD COLUMN olist_product_id VARCHAR(64) NULL'],
        ['olist_id', 'ALTER TABLE olist_product_images ADD COLUMN olist_id VARCHAR(64) NULL'],
        ['sku', 'ALTER TABLE olist_product_images ADD COLUMN sku VARCHAR(191) NULL'],
        ['image_url', 'ALTER TABLE olist_product_images ADD COLUMN image_url VARCHAR(1000) NULL'],
        ['site_url', 'ALTER TABLE olist_product_images ADD COLUMN site_url VARCHAR(1000) NULL'],
        ['local_url', 'ALTER TABLE olist_product_images ADD COLUMN local_url VARCHAR(1000) NULL'],
        ['original_url', 'ALTER TABLE olist_product_images ADD COLUMN original_url VARCHAR(1000) NULL'],
        ['original_url_olist', 'ALTER TABLE olist_product_images ADD COLUMN original_url_olist VARCHAR(1000) NULL'],
        ['local_file', 'ALTER TABLE olist_product_images ADD COLUMN local_file VARCHAR(1000) NULL'],
        ['position', 'ALTER TABLE olist_product_images ADD COLUMN `position` INT NOT NULL DEFAULT 0'],
        ['is_primary', 'ALTER TABLE olist_product_images ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0'],
        ['source', "ALTER TABLE olist_product_images ADD COLUMN source VARCHAR(80) NOT NULL DEFAULT 'olist_api'"],
        ['status', "ALTER TABLE olist_product_images ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'active'"],
        ['url_hash', 'ALTER TABLE olist_product_images ADD COLUMN url_hash CHAR(64) NULL'],
        ['file_hash', 'ALTER TABLE olist_product_images ADD COLUMN file_hash CHAR(64) NULL'],
        ['dedupe_key', 'ALTER TABLE olist_product_images ADD COLUMN dedupe_key VARCHAR(191) NULL'],
        ['uploaded', 'ALTER TABLE olist_product_images ADD COLUMN uploaded TINYINT(1) NOT NULL DEFAULT 0'],
        ['linked', 'ALTER TABLE olist_product_images ADD COLUMN linked TINYINT(1) NOT NULL DEFAULT 0'],
        ['error_message', 'ALTER TABLE olist_product_images ADD COLUMN error_message TEXT NULL'],
        ['updated_at', 'ALTER TABLE olist_product_images ADD COLUMN updated_at DATETIME NULL'],
    ];
    foreach ($imageColumns as [$column, $sql]) svil_ensure_column($db, 'olist_product_images', $column, $sql, $actions);

    $productColumns = [
        ['sku', 'ALTER TABLE olist_products ADD COLUMN sku VARCHAR(191) NULL'],
        ['olist_product_id', 'ALTER TABLE olist_products ADD COLUMN olist_product_id VARCHAR(64) NULL'],
        ['olist_id', 'ALTER TABLE olist_products ADD COLUMN olist_id VARCHAR(64) NULL'],
        ['idProduto', 'ALTER TABLE olist_products ADD COLUMN idProduto VARCHAR(64) NULL'],
        ['name', 'ALTER TABLE olist_products ADD COLUMN name VARCHAR(255) NULL'],
        ['primary_image_url', 'ALTER TABLE olist_products ADD COLUMN primary_image_url VARCHAR(1000) NULL'],
        ['images_count', 'ALTER TABLE olist_products ADD COLUMN images_count INT NOT NULL DEFAULT 0'],
        ['image_sync_status', "ALTER TABLE olist_products ADD COLUMN image_sync_status VARCHAR(40) NOT NULL DEFAULT 'pending'"],
        ['updated_at', 'ALTER TABLE olist_products ADD COLUMN updated_at DATETIME NULL'],
    ];
    foreach ($productColumns as [$column, $sql]) svil_ensure_column($db, 'olist_products', $column, $sql, $actions);

    svil_ensure_index($db, 'olist_product_images', 'idx_sv_olist_images_dedupe', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_dedupe (dedupe_key)', $actions);
    svil_ensure_index($db, 'olist_product_images', 'idx_sv_olist_images_product_status', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_product_status (product_local_id, status)', $actions);
    svil_ensure_index($db, 'olist_product_images', 'idx_sv_olist_images_sku_status', 'ALTER TABLE olist_product_images ADD INDEX idx_sv_olist_images_sku_status (sku, status)', $actions);
    svil_ensure_index($db, 'olist_products', 'idx_sv_olist_products_sku', 'ALTER TABLE olist_products ADD INDEX idx_sv_olist_products_sku (sku)', $actions);
    return $actions;
}

function svil_decode_csv(array $body): string
{
    if (!empty($body['csv_gzip_base64'])) {
        $bytes = base64_decode((string)$body['csv_gzip_base64'], true);
        if ($bytes === false) svil_json(400, ['ok' => false, 'error' => 'invalid_csv_gzip_base64']);
        $decoded = @gzdecode($bytes);
        if (!is_string($decoded)) svil_json(400, ['ok' => false, 'error' => 'invalid_gzip_payload']);
        return $decoded;
    }
    if (!empty($body['csv_base64'])) {
        $decoded = base64_decode((string)$body['csv_base64'], true);
        if (!is_string($decoded)) svil_json(400, ['ok' => false, 'error' => 'invalid_csv_base64']);
        return $decoded;
    }
    svil_json(400, ['ok' => false, 'error' => 'missing_csv_payload']);
}

function svil_bool($value): bool
{
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'sim', 'uploaded', 'linked'], true);
}

function svil_rows_from_csv(string $csv): array
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $headers = fgetcsv($fh);
    if (!is_array($headers)) return [];
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $rows = [];
    while (($values = fgetcsv($fh)) !== false) {
        $row = [];
        foreach ($headers as $idx => $header) {
            $row[(string)$header] = $values[$idx] ?? '';
        }
        if (trim(implode('', $row)) !== '') $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function svil_find_product(mysqli $db, string $sku, string $olistId): ?array
{
    if ($sku !== '') {
        $stmt = $db->prepare('SELECT id, sku FROM olist_products WHERE UPPER(sku) = UPPER(?) LIMIT 1');
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (is_array($row)) return $row;
    }
    if ($olistId !== '') {
        $stmt = $db->prepare('SELECT id, sku FROM olist_products WHERE olist_product_id = ? OR olist_id = ? OR idProduto = ? LIMIT 1');
        $stmt->bind_param('sss', $olistId, $olistId, $olistId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (is_array($row)) return $row;
    }
    return null;
}

function svil_bind_images(mysqli $db, array $rows, bool $dryRun): array
{
    $summary = [
        'rows_received' => count($rows),
        'rows_uploaded' => 0,
        'images_inserted' => 0,
        'images_updated' => 0,
        'images_linked' => 0,
        'products_touched' => 0,
        'products_with_image' => 0,
        'products_without_match' => 0,
    ];
    $productsTouched = [];
    $finalRows = [];

    $sql = "INSERT INTO olist_product_images (product_id, product_local_id, olist_product_id, olist_id, sku, image_url, site_url, local_url, original_url, original_url_olist, local_file, `position`, is_primary, source, status, url_hash, file_hash, dedupe_key, uploaded, linked, error_message, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'olist_api_download_upload', 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $insert = $db->prepare($sql);
    $update = $db->prepare("UPDATE olist_product_images SET product_id=?, product_local_id=?, olist_product_id=?, olist_id=?, sku=?, image_url=?, site_url=?, local_url=?, original_url=?, original_url_olist=?, local_file=?, `position`=?, is_primary=?, source='olist_api_download_upload', status='active', url_hash=?, file_hash=?, uploaded=?, linked=?, error_message=?, updated_at=NOW() WHERE dedupe_key=? OR image_url=?");

    foreach ($rows as $row) {
        if (!svil_bool($row['uploaded'] ?? '') || ($row['site_url'] ?? '') === '') continue;
        $summary['rows_uploaded']++;
        $sku = trim((string)($row['sku'] ?? ''));
        $olistId = preg_replace('/\D+/', '', (string)($row['olist_product_id'] ?? ''));
        $siteUrl = trim((string)($row['site_url'] ?? ''));
        $originalUrl = trim((string)($row['original_url_olist'] ?? ''));
        $localFile = trim((string)($row['local_file'] ?? ''));
        $position = (int)($row['image_position'] ?? 0);
        $isPrimary = svil_bool($row['is_primary'] ?? '') || $position === 1;
        $urlHash = hash('sha256', $originalUrl !== '' ? $originalUrl : $siteUrl);
        $fileHash = hash('sha256', $localFile !== '' ? $localFile : $siteUrl);
        $dedupeKey = hash('sha256', strtoupper($sku) . '|' . $olistId . '|' . $urlHash);
        $product = svil_find_product($db, $sku, $olistId);
        $productId = $product ? (int)$product['id'] : null;
        $linked = $productId !== null;
        if (!$linked) $summary['products_without_match']++;
        if ($linked) {
            $productsTouched[(string)$productId] = true;
            $summary['images_linked']++;
        }

        if (!$dryRun) {
            $exists = false;
            $check = $db->prepare('SELECT id FROM olist_product_images WHERE dedupe_key = ? OR image_url = ? LIMIT 1');
            $check->bind_param('ss', $dedupeKey, $siteUrl);
            $check->execute();
            $exists = (bool)$check->get_result()->fetch_row();
            $productIdParam = $productId;
            $uploadedInt = 1;
            $linkedInt = $linked ? 1 : 0;
            $error = (string)($row['error_message'] ?? '');
            if ($exists) {
                $update->bind_param('iisssssssssiissiisss', $productIdParam, $productIdParam, $olistId, $olistId, $sku, $siteUrl, $siteUrl, $siteUrl, $originalUrl, $originalUrl, $localFile, $position, $isPrimary, $urlHash, $fileHash, $uploadedInt, $linkedInt, $error, $dedupeKey, $siteUrl);
                $update->execute();
                $summary['images_updated']++;
            } else {
                $insert->bind_param('iisssssssssiisssiis', $productIdParam, $productIdParam, $olistId, $olistId, $sku, $siteUrl, $siteUrl, $siteUrl, $originalUrl, $originalUrl, $localFile, $position, $isPrimary, $urlHash, $fileHash, $dedupeKey, $uploadedInt, $linkedInt, $error);
                $insert->execute();
                $summary['images_inserted']++;
            }
        }

        $row['product_local_id'] = $productId ? (string)$productId : '';
        $row['linked'] = $linked ? 'true' : 'false';
        $row['status'] = $linked ? 'linked' : 'uploaded';
        $finalRows[] = $row;
    }

    if (!$dryRun && $productsTouched) {
        foreach (array_keys($productsTouched) as $productId) {
            $id = (int)$productId;
            $db->query("UPDATE olist_products p SET primary_image_url = (SELECT i.image_url FROM olist_product_images i WHERE i.product_local_id = p.id AND i.status = 'active' AND i.image_url IS NOT NULL AND i.image_url <> '' ORDER BY i.is_primary DESC, i.`position` ASC, i.id ASC LIMIT 1), images_count = (SELECT COUNT(*) FROM olist_product_images i WHERE i.product_local_id = p.id AND i.status = 'active' AND i.image_url IS NOT NULL AND i.image_url <> ''), image_sync_status = 'linked', updated_at = NOW() WHERE p.id = {$id}");
        }
        if (svil_column_exists($db, 'products', 'image_url') && svil_column_exists($db, 'products', 'sku')) {
            $db->query("UPDATE products p JOIN olist_products op ON UPPER(op.sku) = UPPER(p.sku) SET p.image_url = op.primary_image_url WHERE op.image_sync_status = 'linked' AND op.primary_image_url IS NOT NULL AND op.primary_image_url <> ''");
        }
    }

    $summary['products_touched'] = count($productsTouched);
    $result = $db->query("SELECT COUNT(*) c FROM olist_products WHERE primary_image_url IS NOT NULL AND primary_image_url <> ''");
    $summary['products_with_image'] = (int)($result->fetch_assoc()['c'] ?? 0);
    return ['summary' => $summary, 'final_rows' => $finalRows];
}

function svil_write_final_csv(array $rows): string
{
    $dir = svil_root() . '/storage/reports';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . '/olist_imagens_site_mapeamento.csv';
    $headers = ['sku','olist_product_id','product_local_id','nome_produto','image_position','original_url_olist','local_file','site_url','uploaded','linked','is_primary','status','error_message'];
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers);
    foreach ($rows as $row) {
        $out = [];
        foreach ($headers as $header) $out[] = $row[$header] ?? '';
        fputcsv($fh, $out);
    }
    fclose($fh);
    return $path;
}

if (($_GET['health'] ?? '') === '1') {
    svil_json(200, ['ok' => true, 'endpoint' => 'olist-image-linker', 'token_required_for_post' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svil_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

svil_auth();

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8000000) {
    svil_json(413, ['ok' => false, 'error' => 'Payload too large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svil_json(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

try {
    $db = svil_db();
    $schemaActions = svil_schema($db);
    $csv = svil_decode_csv($body);
    $rows = svil_rows_from_csv($csv);
    $dryRun = ($body['dry_run'] ?? false) === true;
    $db->begin_transaction();
    $result = svil_bind_images($db, $rows, $dryRun);
    if ($dryRun) {
        $db->rollback();
    } else {
        $db->commit();
    }
    $finalPath = $dryRun ? '' : svil_write_final_csv($result['final_rows']);
    svil_json(200, [
        'ok' => true,
        'agent' => 'olist_image_linker',
        'dry_run' => $dryRun,
        'generated_at' => date('c'),
        'summary' => $result['summary'],
        'final_csv' => $finalPath,
        'schema_actions_count' => count($schemaActions),
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
    }
    svil_json(500, ['ok' => false, 'agent' => 'olist_image_linker', 'error' => 'link_failed', 'message' => $e->getMessage()]);
}
