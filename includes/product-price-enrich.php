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
        $currentValue = getenv($key);
        if ($key !== '' && ($currentValue === false || trim((string)$currentValue) === '')) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
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
    // ERP-only cadastro rule (2026-08-21): do not enrich product registration
    // fields from fallback-products.json, products table, snapshots or local
    // files. All public product data must come from the active ERP cache
    // generated from Tiny/Olist. Kept as compatibility no-op for older callers.
    return $products;
}

function svp_lookup_product(?mysqli $db, string $sku = '', string $productId = ''): array
{
    // ERP/Tiny API v3 cache is the only product registration source. Do not
    // read local products/olist_products tables as fallback for public product
    // data. Kept as compatibility no-op for older callers.
    return [];
}
