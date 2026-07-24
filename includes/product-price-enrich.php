<?php
declare(strict_types=1);

/**
 * Enriquecimento de preco/estoque via banco de dados, mesmo padrao usado
 * em produto.php: sobrescreve o catalogo estatico (fallback-products.json)
 * com dados reais da tabela `products` quando o banco esta configurado.
 */

function svp_env_load(): void
{
    // config/constants.php carrega config/runtime-secrets.php, gerado pelo
    // deploy a partir dos GitHub Secrets (o servidor nao recebe .env via FTP).
    $constants = dirname(__DIR__) . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }
    $path = dirname(__DIR__) . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function svp_db(): ?mysqli
{
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) {
        return null;
    }

    svp_env_load();
    $constants = dirname(__DIR__) . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }

$host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
$port = (int)(getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3306));
$name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : '');
$user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : '');
$pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
    if ($name === '' || $user === '') {
        return null;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name, $port);
    if ($db->connect_errno) {
        return null;
    }

    $db->set_charset('utf8mb4');
    return $db;
}

/**
 * @return array<string, array{price: float, stock: int}> indexado por sku
 */
function svp_bulk_price_stock(?mysqli $db, array $skus): array
{
    $skus = array_values(array_unique(array_filter(array_map(
        static fn($s) => trim((string)$s),
        $skus
    ), static fn($s) => $s !== '')));

    if (!$db instanceof mysqli || $skus === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $sql = "SELECT sku, COALESCE(price, 0) AS price, COALESCE(stock, 0) AS stock
            FROM products
            WHERE sku IN ($placeholders)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $types = str_repeat('s', count($skus));
    $stmt->bind_param($types, ...$skus);
    $stmt->execute();
    $result = $stmt->get_result();

    $out = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $out[$sku] = [
            'price' => (float)($row['price'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
        ];
    }
    $stmt->close();

    return $out;
}

/**
 * The detailed Olist cache is the canonical price and stock source. The local
 * products table is intentionally not layered over it: the table may lag the
 * ERP and historically contained multiplied prices. Keep this compatibility
 * function as a no-op for older callers.
 */
function svp_enrich_products(array $products): array
{
    static $fallbackMap = null;
    if ($fallbackMap === null) {
        $path = dirname(__DIR__) . '/api/catalog/fallback-products.json';
        $fallbackMap = [];
        if (is_file($path)) {
            $json = json_decode((string)file_get_contents($path), true);
            if (is_array($json)) {
                foreach ($json as $item) {
                    if (!is_array($item)) continue;
                    $s = strtoupper(trim((string)($item['sku'] ?? '')));
                    if ($s !== '') {
                        $fallbackMap[$s] = $item;
                    }
                }
            }
        }
    }

    foreach ($products as &$p) {
        if (!is_array($p)) continue;
        $sku = strtoupper(trim((string)($p['sku'] ?? '')));
        $fb = $fallbackMap[$sku] ?? null;
        if ($fb) {
            if (((float)($p['price'] ?? 0)) <= 0 && ((float)($fb['price'] ?? 0)) > 0) {
                $p['price'] = (float)$fb['price'];
            }
            if (((int)($p['stock'] ?? 0)) <= 0 && ((int)($fb['stock'] ?? 0)) > 0) {
                $p['stock'] = (int)$fb['stock'];
            }
            if (trim((string)($p['image_url'] ?? '')) === '' && !empty($fb['image_url'])) {
                $p['image_url'] = trim((string)$fb['image_url']);
            }
            if (empty($p['images']) && !empty($fb['images'])) {
                $p['images'] = $fb['images'];
            }
        }
    }
    unset($p);

    return $products;
}

function svp_lookup_product(?mysqli $db, string $sku = '', string $productId = ''): array
{
    $sku = trim($sku);
    $productId = trim($productId);
    if (!$db instanceof mysqli || ($sku === '' && $productId === '')) {
        return [];
    }

    $sql = "SELECT
                p.id,
                p.sku,
                COALESCE(op.olist_product_id, '') AS olist_product_id,
                COALESCE(op.olist_id, '') AS olist_id,
                COALESCE(NULLIF(p.name, ''), NULLIF(op.name, ''), '') AS name,
                COALESCE(NULLIF(p.description, ''), '') AS description,
                COALESCE(p.price, 0) AS price,
                COALESCE(p.stock, 0) AS stock,
                COALESCE(NULLIF(op.primary_image_url, ''), NULLIF(p.image_url, ''), '') AS image_url
            FROM products p
            LEFT JOIN olist_products op ON op.sku = p.sku
            WHERE (? <> '' AND p.sku = ?)
               OR (? <> '' AND (CAST(p.id AS CHAR) = ? OR op.olist_product_id = ? OR op.olist_id = ?))
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ssssss', $sku, $sku, $productId, $productId, $productId, $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? ($result->fetch_assoc() ?: []) : [];
    $stmt->close();

    return is_array($row) ? $row : [];
}
