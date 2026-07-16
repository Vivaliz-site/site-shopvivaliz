<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svcat_root(): string
{
    return dirname(__DIR__, 2);
}

function svcat_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svcat_env_load(): void
{
    $path = svcat_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function svcat_db(): ?mysqli
{
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) return null;
    svcat_env_load();
    $constants = svcat_root() . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = (int)(defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306));
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
    if ($name === '' || $user === '') return null;

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    if ($db->connect_errno) return null;
    $db->set_charset('utf8mb4');
    return $db;
}

function svcat_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function svcat_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (str_starts_with($url, '//')) return 'https:' . $url;
    if (str_starts_with($url, '/')) return $url;
    return $url;
}

function svcat_product(array $row): array
{
    $sku = trim((string)($row['sku'] ?? ''));
    $name = trim((string)($row['name'] ?? $row['nome_produto'] ?? $row['description'] ?? 'Produto ShopVivaliz'));
    $price = (float)($row['price'] ?? 0);
    return [
        'id' => (string)($row['id'] ?? $row['olist_product_id'] ?? $sku),
        'sku' => $sku,
        'olist_product_id' => (string)($row['olist_product_id'] ?? $row['olist_id'] ?? ''),
        'name' => $name !== '' ? $name : ($sku !== '' ? $sku : 'Produto ShopVivaliz'),
        'description' => trim((string)($row['description'] ?? '')),
        'price' => $price,
        'stock' => (int)($row['stock'] ?? 0),
        'image_url' => svcat_normalize_url((string)($row['image_url'] ?? $row['primary_image_url'] ?? $row['site_url'] ?? '')),
        'images_count' => (int)($row['images_count'] ?? 0),
        'status' => (string)($row['status'] ?? $row['image_sync_status'] ?? 'active'),
    ];
}

function svcat_get(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return $default;
}

function svcat_db_products(mysqli $db, int $limit, string $q): array
{
    $products = [];
    if (svcat_table_exists($db, 'olist_products')) {
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = 'WHERE UPPER(COALESCE(sku, "")) LIKE UPPER(?) OR UPPER(COALESCE(name, "")) LIKE UPPER(?)';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }
        $sql = "SELECT id, sku, olist_product_id, olist_id, name, primary_image_url AS image_url, images_count, image_sync_status FROM olist_products {$where} ORDER BY (primary_image_url IS NULL OR primary_image_url = '') ASC, updated_at DESC, id DESC LIMIT ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($params) {
                $stmt->bind_param('ssi', $params[0], $params[1], $limit);
            } else {
                $stmt->bind_param('i', $limit);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $products[] = svcat_product($row);
        }
    }

    if (!$products && svcat_table_exists($db, 'products')) {
        $where = 'WHERE active = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (UPPER(COALESCE(sku, "")) LIKE UPPER(?) OR UPPER(COALESCE(name, "")) LIKE UPPER(?))';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }
        $sql = "SELECT id, sku, name, description, price, stock, image_url FROM products {$where} ORDER BY updated_at DESC, id DESC LIMIT ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($params) $stmt->bind_param('ssi', $params[0], $params[1], $limit);
            else $stmt->bind_param('i', $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $products[] = svcat_product($row);
        }
    }
    return $products;
}

function svcat_fallback_products(int $limit, string $q): array
{
    $paths = [
        svcat_root() . '/uploads/olist_imagens_site_mapeamento.csv',
        svcat_root() . '/storage/reports/olist_imagens_site_mapeamento.csv',
    ];
    $bySku = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $fh = fopen($path, 'r');
        if (!$fh) continue;
        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            continue;
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        while (($values = fgetcsv($fh)) !== false) {
            $row = [];
            foreach ($headers as $idx => $header) $row[(string)$header] = $values[$idx] ?? '';
            $sku = svcat_get($row, ['sku', 'SKU', 'Código (SKU)', 'Codigo (SKU)']);
            $name = svcat_get($row, ['nome_produto', 'name', 'produto', 'Descrição', 'Descricao'], $sku);
            $image = svcat_get($row, ['site_url', 'image_url', 'primary_image_url', 'api_urls', 'planilha_urls', 'original_url_olist']);
            if (str_contains($image, ' | ')) $image = explode(' | ', $image)[0];
            if ($sku === '' || $image === '') continue;
            if ($q !== '' && stripos($sku . ' ' . $name, $q) === false) continue;
            $key = strtoupper($sku);
            $isPrimary = strtolower(svcat_get($row, ['is_primary'], '')) === 'true' || svcat_get($row, ['image_position']) === '1';
            if (!isset($bySku[$key])) {
                $bySku[$key] = svcat_product([
                    'sku' => $sku,
                    'name' => $name,
                    'olist_product_id' => $row['olist_product_id'] ?? $row['ID'] ?? '',
                    'image_url' => $image,
                    'images_count' => 1,
                    'status' => 'fallback_report',
                ]);
                $bySku[$key]['_primary_seen'] = $isPrimary;
            } else {
                $bySku[$key]['images_count']++;
                if ($isPrimary && empty($bySku[$key]['_primary_seen'])) {
                    $bySku[$key]['image_url'] = svcat_normalize_url($image);
                    $bySku[$key]['_primary_seen'] = true;
                }
            }
            if (count($bySku) >= $limit) break 2;
        }
        fclose($fh);
    }

    if (!$bySku) {
        $phpProductsFile = svcat_root() . '/olist/produtos-olist-array.php';
        if (is_file($phpProductsFile) && is_readable($phpProductsFile)) {
            include $phpProductsFile;
            if (!empty($GLOBALS['produtos_olist'])) {
                foreach ($GLOBALS['produtos_olist'] as $p) {
                    $sku = $p['id'] ?? '';
                    $name = $p['nome'] ?? '';
                    $image = $p['url_imagem'] ?? '';
                    if ($sku === '' || $image === '') continue;
                    if ($q !== '' && stripos($sku . ' ' . $name, $q) === false) continue;
                    $key = strtoupper($sku);
                    $bySku[$key] = svcat_product([
                        'sku' => $sku,
                        'name' => $name,
                        'olist_product_id' => $sku,
                        'image_url' => $image,
                        'images_count' => 1,
                        'price' => (float)($p['preco'] ?? 0),
                        'status' => 'fallback_php',
                    ]);
                    if (count($bySku) >= $limit) break;
                }
            }
        }
    }

    return array_map(static function (array $product): array {
        unset($product['_primary_seen']);
        return $product;
    }, array_values($bySku));
}

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));
$source = 'fallback_report';
$products = [];
try {
    $db = svcat_db();
    if ($db instanceof mysqli) {
        $products = svcat_db_products($db, $limit, $q);
        if ($products) {
            $source = 'database';
        }
    }
} catch (Throwable $e) {
    $products = [];
    $source = 'fallback_report';
}
if (!$products) {
    $products = svcat_fallback_products($limit, $q);
    $source = 'fallback_report';
}

svcat_json(200, [
    'ok' => true,
    'source' => $products && $source === 'database' ? 'database' : 'fallback_report',
    'count' => count($products),
    'products' => $products,
]);
