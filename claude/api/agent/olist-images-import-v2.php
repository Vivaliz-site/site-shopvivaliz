<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function sviv2_root(): string { return dirname(__DIR__, 2); }

function sviv2_json(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sviv2_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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

function sviv2_auth(): void
{
    sviv2_env_load(sviv2_root() . '/.env');
    $expected = getenv('SQUAD_TOKEN') ?: '';
    if ($expected === '') sviv2_json(503, ['ok' => false, 'error' => 'SQUAD_TOKEN not configured']);
    $received = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
    if ($received === '' || !hash_equals($expected, $received)) {
        sviv2_json(401, ['ok' => false, 'error' => 'Unauthorized']);
    }
}

function sviv2_db(): mysqli
{
    sviv2_env_load(sviv2_root() . '/.env');
    foreach ([sviv2_root() . '/config/constants.php', sviv2_root() . '/config/database.php'] as $file) {
        if (is_file($file)) require_once $file;
    }
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
        if ($db instanceof mysqli) return $db;
    }
    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
    if ($name === '' || $user === '') sviv2_json(500, ['ok' => false, 'error' => 'database_config_unavailable']);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function sviv2_exec(mysqli $db, string $sql): void
{
    try { $db->query($sql); }
    catch (Throwable $e) {
        if (!preg_match('/already exists|duplicate (column|key|index)/i', $e->getMessage())) throw $e;
    }
}

function sviv2_schema(mysqli $db): void
{
    sviv2_exec($db, "CREATE TABLE IF NOT EXISTS olist_products (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, sku VARCHAR(191) NULL, olist_product_id VARCHAR(64) NULL, olist_id VARCHAR(64) NULL, idProduto VARCHAR(64) NULL, name VARCHAR(255) NULL, primary_image_url VARCHAR(1000) NULL, images_count INT NOT NULL DEFAULT 0, image_sync_status VARCHAR(40) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY idx_sku (sku), KEY idx_olist_product_id (olist_product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    sviv2_exec($db, "CREATE TABLE IF NOT EXISTS olist_product_images (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED NULL, product_local_id BIGINT UNSIGNED NULL, olist_product_id VARCHAR(64) NULL, olist_id VARCHAR(64) NULL, sku VARCHAR(191) NULL, image_url VARCHAR(1000) NULL, site_url VARCHAR(1000) NULL, local_url VARCHAR(1000) NULL, original_url VARCHAR(1000) NULL, original_url_olist VARCHAR(1000) NULL, local_file VARCHAR(1000) NULL, `position` INT NOT NULL DEFAULT 0, is_primary TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(80) NOT NULL DEFAULT 'olist_api_v2', status VARCHAR(40) NOT NULL DEFAULT 'active', url_hash CHAR(64) NULL, file_hash CHAR(64) NULL, dedupe_key VARCHAR(191) NULL, uploaded TINYINT(1) NOT NULL DEFAULT 0, linked TINYINT(1) NOT NULL DEFAULT 0, error_message TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY idx_product_status (product_local_id, status), KEY idx_sku_status (sku, status), KEY idx_dedupe_key (dedupe_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sviv2_csv_payload(array $body): string
{
    if (!empty($_FILES['sheet']['tmp_name'])) return (string)file_get_contents($_FILES['sheet']['tmp_name']);
    if (!empty($body['csv_base64'])) {
        $decoded = base64_decode((string)$body['csv_base64'], true);
        if (is_string($decoded)) return $decoded;
    }
    sviv2_json(400, ['ok' => false, 'error' => 'missing_csv_payload']);
}

function sviv2_rows(string $csv): array
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
        foreach ($headers as $idx => $header) $row[(string)$header] = $values[$idx] ?? '';
        if (trim(implode('', $row)) !== '') $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function sviv2_pick(array $row, array $names): string
{
    $norm = [];
    foreach ($row as $k => $v) $norm[mb_strtolower(trim((string)$k), 'UTF-8')] = trim((string)$v);
    foreach ($names as $name) {
        $key = mb_strtolower($name, 'UTF-8');
        if (!empty($norm[$key])) return $norm[$key];
    }
    return '';
}

function sviv2_token(): string
{
    sviv2_env_load(sviv2_root() . '/.env');
    $token = getenv('TOKEN_API_OLIST') ?: getenv('TINY_API_TOKEN') ?: getenv('OLIST_API_TOKEN') ?: '';
    if ($token === '') sviv2_json(503, ['ok' => false, 'error' => 'TOKEN_API_OLIST not configured']);
    return $token;
}

function sviv2_tiny_product(string $token, string $id): array
{
    $url = 'https://api.tiny.com.br/api2/produto.obter.php?' . http_build_query(['token' => $token, 'id' => $id, 'formato' => 'json']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ShopVivaliz-TinyV2Importer/1.0']]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') throw new RuntimeException('tiny_v2_request_failed');
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('tiny_v2_invalid_json');
    $ret = $data['retorno'] ?? [];
    if (($ret['status'] ?? '') === 'Erro' || ($ret['status'] ?? '') === 'ERRO') {
        throw new RuntimeException(json_encode($ret['erros'] ?? $ret['erro'] ?? $ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return is_array($ret['produto'] ?? null) ? $ret['produto'] : [];
}

function sviv2_list($value): array
{
    if (!$value) return [];
    return is_array($value) && array_is_list($value) ? $value : [$value];
}

function sviv2_url_from_item($item): string
{
    if (is_string($item)) return trim($item);
    if (!is_array($item)) return '';
    if (count($item) === 1) {
        $first = reset($item);
        if (is_array($first)) $item = $first;
    }
    foreach (['url', 'link', 'anexo', 'imagem', 'src'] as $k) {
        if (!empty($item[$k])) return trim((string)$item[$k]);
    }
    return '';
}

function sviv2_product_id(mysqli $db, string $sku, string $olistId, string $name): int
{
    if ($sku !== '') {
        $stmt = $db->prepare('SELECT id FROM olist_products WHERE UPPER(sku) = UPPER(?) LIMIT 1');
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return (int)$row['id'];
    }
    $stmt = $db->prepare("INSERT INTO olist_products (sku, olist_product_id, olist_id, idProduto, name, image_sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
    $stmt->bind_param('sssss', $sku, $olistId, $olistId, $olistId, $name);
    $stmt->execute();
    return (int)$db->insert_id;
}

function sviv2_upsert_image(mysqli $db, int $productId, string $sku, string $olistId, string $url, int $position): string
{
    $urlHash = hash('sha256', $url);
    $dedupe = hash('sha256', mb_strtoupper($sku, 'UTF-8') . '|' . $olistId . '|' . $urlHash);
    $isPrimary = $position === 1 ? 1 : 0;
    $linked = $productId > 0 ? 1 : 0;
    $source = 'olist_api_v2';
    $status = 'active';
    $check = $db->prepare('SELECT id FROM olist_product_images WHERE dedupe_key = ? OR image_url = ? LIMIT 1');
    $check->bind_param('ss', $dedupe, $url);
    $check->execute();
    $exists = (bool)$check->get_result()->fetch_row();
    if ($exists) {
        $stmt = $db->prepare("UPDATE olist_product_images SET product_id=?, product_local_id=?, olist_product_id=?, olist_id=?, sku=?, image_url=?, site_url=?, local_url=?, original_url=?, original_url_olist=?, `position`=?, is_primary=?, source=?, status=?, url_hash=?, file_hash='', uploaded=1, linked=?, error_message='', updated_at=NOW() WHERE dedupe_key=? OR image_url=?");
        $stmt->bind_param('iissssssssiisssiss', $productId, $productId, $olistId, $olistId, $sku, $url, $url, $url, $url, $url, $position, $isPrimary, $source, $status, $urlHash, $linked, $dedupe, $url);
        $stmt->execute();
        return 'updated';
    }
    $stmt = $db->prepare("INSERT INTO olist_product_images (product_id, product_local_id, olist_product_id, olist_id, sku, image_url, site_url, local_url, original_url, original_url_olist, local_file, `position`, is_primary, source, status, url_hash, file_hash, dedupe_key, uploaded, linked, error_message, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, '', ?, 1, ?, '', NOW(), NOW())");
    $stmt->bind_param('iissssssssiissssi', $productId, $productId, $olistId, $olistId, $sku, $url, $url, $url, $url, $url, $position, $isPrimary, $source, $status, $urlHash, $dedupe, $linked);
    $stmt->execute();
    return 'inserted';
}

if (($_GET['health'] ?? '') === '1') {
    sviv2_json(200, ['ok' => true, 'endpoint' => 'olist-images-import-v2']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sviv2_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

sviv2_auth();
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];
$dryRun = ($body['dry_run'] ?? false) === true || ($_POST['dry_run'] ?? '') === '1';
$limit = max(0, (int)($body['limit'] ?? $_POST['limit'] ?? 0));
$sleep = max(0.0, (float)($body['sleep'] ?? $_POST['sleep'] ?? 0.5));

try {
    $csv = sviv2_csv_payload($body);
    $rows = sviv2_rows($csv);
    if ($limit > 0) $rows = array_slice($rows, 0, $limit);
    $db = sviv2_db();
    sviv2_schema($db);
    $token = sviv2_token();
    $summary = ['products' => count($rows), 'with_images' => 0, 'images_inserted' => 0, 'images_updated' => 0, 'errors' => 0, 'dry_run' => $dryRun];
    $errors = [];
    if (!$dryRun) $db->begin_transaction();
    foreach ($rows as $idx => $row) {
        $id = preg_replace('/\D+/', '', sviv2_pick($row, ['ID', 'id']));
        $sku = sviv2_pick($row, ['Código (SKU)', 'Codigo (SKU)', 'SKU', 'sku']);
        $name = sviv2_pick($row, ['Descrição', 'Descricao', 'nome']);
        if ($id === '') continue;
        try {
            $product = sviv2_tiny_product($token, $id);
            $urls = [];
            foreach (['anexos', 'imagens_externas'] as $group) {
                foreach (sviv2_list($product[$group] ?? []) as $item) {
                    $url = sviv2_url_from_item($item);
                    if ($url !== '' && !in_array($url, $urls, true)) $urls[] = $url;
                }
            }
            if ($urls) $summary['with_images']++;
            if (!$dryRun) {
                $productId = sviv2_product_id($db, $sku, $id, $name);
                foreach ($urls as $pos => $url) {
                    $action = sviv2_upsert_image($db, $productId, $sku, $id, $url, $pos + 1);
                    if ($action === 'inserted') $summary['images_inserted']++;
                    else $summary['images_updated']++;
                }
                $db->query("UPDATE olist_products p SET primary_image_url = (SELECT i.image_url FROM olist_product_images i WHERE i.product_local_id = p.id AND i.status = 'active' ORDER BY i.is_primary DESC, i.`position` ASC, i.id ASC LIMIT 1), images_count = (SELECT COUNT(*) FROM olist_product_images i WHERE i.product_local_id = p.id AND i.status = 'active'), image_sync_status = 'linked', updated_at = NOW() WHERE p.id = " . (int)$productId);
                if ($sku !== '') {
                    $stmt = $db->prepare("UPDATE products SET image_url = (SELECT primary_image_url FROM olist_products WHERE UPPER(sku) = UPPER(?) LIMIT 1) WHERE UPPER(sku) = UPPER(?)");
                    $stmt->bind_param('ss', $sku, $sku);
                    $stmt->execute();
                }
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            if (count($errors) < 20) $errors[] = ['row' => $idx + 2, 'id' => $id, 'sku' => $sku, 'error' => $e->getMessage()];
            if (preg_match('/API Bloqueada|Excedido o número de acessos/i', $e->getMessage())) break;
        }
        if ($sleep > 0) usleep((int)($sleep * 1000000));
    }
    if (!$dryRun) $db->commit();
    sviv2_json(200, ['ok' => true, 'agent' => 'olist_images_import_v2', 'summary' => $summary, 'errors_sample' => $errors]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
    }
    sviv2_json(500, ['ok' => false, 'agent' => 'olist_images_import_v2', 'error' => 'import_failed', 'message' => $e->getMessage()]);
}

