<?php
/**
 * ALTERADO 2026-07-13: Busca DIRETO do ERP OLIST (Tiny)
 * FONTE DE VERDADE: ERP OLIST apenas
 * E-commerce local desativado
 */

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function get_erp_token(): ?string
{
    $root = dirname(__DIR__, 2);

    // Token file
    $token_file = $root . '/storage/private/tokens.json';
    if (is_file($token_file) && is_readable($token_file)) {
        $tokens = json_decode(file_get_contents($token_file), true);
        if (is_array($tokens)) {
            return $tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? null;
        }
    }

    // .env
    $env_file = $root . '/.env';
    if (is_file($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=') || str_starts_with($line, 'TINY_ACCESS_TOKEN=')) {
                $parts = explode('=', $line, 2);
                return trim(trim($parts[1] ?? ''), "\"'");
            }
        }
    }

    return null;
}

function fetch_erp_products(int $page = 1, int $limit = 100): array
{
    $token = get_erp_token();
    if (!$token) {
        return [];
    }

    // API V3 (Tiny Public API)
    $offset = ($page - 1) * $limit;
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit={$limit}&offset={$offset}";

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 15,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if (!$response) {
        return [];
    }

    $data = json_decode($response, true);

    // V3 retorna em 'itens' dentro de resposta estruturada
    if (isset($data['itens']) && is_array($data['itens'])) {
        return $data['itens'];
    }

    return is_array($data) ? $data : [];
}

function svcat_search_normalize(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return $transliterated !== false ? $transliterated : $value;
}

function normalize_product(array $item): array
{
    // V3 API retorna em 'itens' com estrutura diferente
    $preco_obj = $item['precos'] ?? [];
    $preco = (float)($preco_obj['preco'] ?? $preco_obj['preco_venda'] ?? $item['preco'] ?? 0);

    // Estoque: lê do cache (estoque_disponivel) ou da API (estoque.quantidade)
    $stock = (int)($item['estoque_disponivel'] ?? ($item['estoque']['quantidade'] ?? 0));
    $attachments = is_array($item['anexos'] ?? null) ? $item['anexos'] : [];
    $imageUrl = trim((string)($item['imagem_principal_url'] ?? ''));
    if ($imageUrl === '') {
        foreach ($attachments as $attachment) {
            $candidate = is_array($attachment) ? trim((string)($attachment['url'] ?? '')) : '';
            if (preg_match('~^https://~i', $candidate)) { $imageUrl = $candidate; break; }
        }
    }

    return [
        'id' => (string)($item['id'] ?? ''),
        'sku' => trim((string)($item['sku'] ?? $item['codigo'] ?? '')),
        'olist_product_id' => (string)($item['id'] ?? ''),
        'name' => trim((string)($item['descricao'] ?? $item['nome'] ?? 'Produto')),
        'description' => trim((string)($item['descricaoComplementar'] ?? $item['descricao_complementar'] ?? $item['descricao'] ?? '')),
        'price' => $preco,
        'stock' => $stock,
        'image_url' => $imageUrl,
        'images_count' => count($attachments) ?: (int)($item['imagens_count'] ?? 0),
        'category' => trim((string)($item['categoria']['nome'] ?? $item['categoria']['caminhoCompleto'] ?? '')),
        'status' => 'active',
    ];
}

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

function svcat_env_value(string $key): string
{
    svcat_env_load();

    $runtimeSecrets = svcat_root() . '/config/runtime-secrets.php';
    if (is_file($runtimeSecrets) && is_readable($runtimeSecrets)) {
        $secrets = require $runtimeSecrets;
        if (is_array($secrets) && isset($secrets[$key]) && is_scalar($secrets[$key])) {
            $value = trim((string)$secrets[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    $envFile = svcat_root() . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === $key) return trim(trim($v), "\"'");
        }
    }

    $tokensFile = svcat_root() . '/storage/private/tokens.json';
    if (is_file($tokensFile) && is_readable($tokensFile)) {
        $tokens = json_decode((string)file_get_contents($tokensFile), true);
        if (is_array($tokens) && isset($tokens[$key]) && is_scalar($tokens[$key])) {
            $value = trim((string)$tokens[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return (string)getenv($key);
}

function svcat_tiny_access_token(): string
{
    $clientId     = svcat_env_value('OLIST_CLIENT_ID')     ?: svcat_env_value('TINY_CLIENT_ID');
    $clientSecret = svcat_env_value('OLIST_CLIENT_SECRET') ?: svcat_env_value('TINY_CLIENT_SECRET');
    $refreshToken = svcat_env_value('OLIST_REFRESH_TOKEN') ?: svcat_env_value('TINY_REFRESH_TOKEN');
    if ($clientId && $clientSecret && $refreshToken) {
        $payload = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded
User-Agent: ShopVivaliz/1.0
",
            'content' => $payload,
            'timeout' => 15,
        ]]);
        $raw = @file_get_contents(
            'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token',
            false, $ctx
        );
        if ($raw) {
            $data = json_decode($raw, true);
            $at = (string)($data['access_token'] ?? '');
            if ($at !== '') return $at;
        }
    }
    return svcat_env_value('OLIST_ACCESS_TOKEN') ?: svcat_env_value('TINY_ACCESS_TOKEN');
}

function svcat_tiny_price_map(): array
{
    $cache = svcat_root() . '/storage/tiny_prices_cache.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 21600) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data)) return $data;
    }
    $token = svcat_tiny_access_token();
    if ($token === '') return [];

    $map = [];
    $page = 1;
    $maxPages = 10;
    while ($page <= $maxPages) {
        $url = "https://api.tiny.com.br/public-api/v3/produtos?pagina={$page}&limite=100";
        $ctx = stream_context_create(['http' => [
            'timeout' => 20,
            'header'  => "Authorization: Bearer {$token}
User-Agent: ShopVivaliz/1.0
Accept: application/json
",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) break;
        $data = json_decode($raw, true);
        $items = $data['data']['itens'] ?? [];
        if (empty($items)) break;
        foreach ($items as $item) {
            $pid = (string)($item['id'] ?? '');
            $sku = (string)($item['codigo'] ?? '');
            $price = (float)str_replace(',', '.', (string)($item['preco'] ?? '0'));
            if ($pid !== '') $map[$pid] = $price;
            if ($sku !== '') $map['sku:' . $sku] = $price;
        }
        if (count($items) < 100) break;
        $page++;
        usleep(300000);
    }
    if ($map) {
        @mkdir(svcat_root() . '/storage', 0755, true);
        @file_put_contents($cache, json_encode($map, JSON_UNESCAPED_UNICODE));
    }
    return $map;
}

function svcat_db_products(mysqli $db, int $limit, string $q): array
{
    $products = [];
    if (svcat_table_exists($db, 'products')) {
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = 'WHERE p.active = 1 AND (UPPER(COALESCE(p.sku, "")) LIKE UPPER(?) OR UPPER(COALESCE(p.name, "")) LIKE UPPER(?))';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        } else {
            $where = 'WHERE p.active = 1';
        }
        $sql = "SELECT
                    p.id,
                    p.sku,
                    op.olist_product_id,
                    op.olist_id,
                    p.name,
                    p.description,
                    p.price,
                    p.stock,
                    COALESCE(NULLIF(op.primary_image_url, ''), p.image_url) AS image_url,
                    COALESCE(op.images_count, 0) AS images_count,
                    COALESCE(op.image_sync_status, 'active') AS image_sync_status
                FROM products p
                LEFT JOIN olist_products op ON op.sku = p.sku
                {$where}
                ORDER BY (COALESCE(op.primary_image_url, p.image_url) IS NULL OR COALESCE(op.primary_image_url, p.image_url) = '') ASC, p.updated_at DESC, p.id DESC
                LIMIT ?";
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

    if (!$products && svcat_table_exists($db, 'olist_products')) {
        $where = 'WHERE active = 1';
        $params = [];
        if ($q !== '') {
            $where = 'WHERE (UPPER(COALESCE(op.sku, "")) LIKE UPPER(?) OR UPPER(COALESCE(op.name, "")) LIKE UPPER(?))';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        } else {
            $where = '';
        }
        $sql = "SELECT
                    op.id,
                    op.sku,
                    op.olist_product_id,
                    op.olist_id,
                    op.name,
                    COALESCE(p.description, '') AS description,
                    COALESCE(p.price, 0) AS price,
                    COALESCE(p.stock, 0) AS stock,
                    COALESCE(NULLIF(op.primary_image_url, ''), p.image_url) AS image_url,
                    op.images_count,
                    op.image_sync_status
                FROM olist_products op
                LEFT JOIN products p ON p.sku = op.sku
                {$where}
                ORDER BY (op.primary_image_url IS NULL OR op.primary_image_url = '') ASC, op.updated_at DESC, op.id DESC
                LIMIT ?";
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

function svcat_fallback_products(int $limit, string $q, string $category = ''): array
{
    $jsonPath = svcat_root() . '/api/catalog/fallback-products.json';
    if (is_file($jsonPath) && is_readable($jsonPath)) {
        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) continue;
                $sku = trim((string)($row['sku'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));
                if ($q !== '' && stripos($sku . ' ' . $name, $q) === false) continue;
                if ($category !== '' && strcasecmp((string)($row['category'] ?? ''), $category) !== 0) continue;
                $p = svcat_product($row);
                // Passa campos V14
                $p['category']      = (string)($row['category'] ?? '');
                $p['slug']          = (string)($row['slug'] ?? '');
                $p['quality_score'] = (int)($row['quality_score'] ?? 0);
                $p['tags']          = is_array($row['tags'] ?? null) ? $row['tags'] : [];
                $items[] = $p;
                if (count($items) >= $limit) break;
            }
            if ($items) return $items;
        }
    }

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
    return array_map(static function (array $product): array {
        unset($product['_primary_seen']);
        return $product;
    }, array_values($bySku));
}

// ALTERADO 2026-07-13: Busca DIRETO do ERP, nao mais ecommerce Olist
// Ecommerce desativado - usar apenas Tiny/ERP

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));

$products = [];
$all_erp = [];

// Tentar ler do cache JSON primeiro (APENAS ATIVOS - status A)
$cache_file = svcat_root() . '/storage/products-cache-ativos.json';
$cache_exists = is_file($cache_file);
$cache_fresh = $cache_exists; // Use cache if exists, avoiding 24h expiration lockouts
$cache_used = false;

if ($cache_exists && $cache_fresh) {
    $cache_content = @file_get_contents($cache_file);
    if ($cache_content) {
        $cache_data = json_decode($cache_content, true);
        if (isset($cache_data['itens']) && is_array($cache_data['itens'])) {
            foreach ($cache_data['itens'] as $item) {
                // FILTER: Only include active products (situacao === 'A')
                if (isset($item['situacao']) && $item['situacao'] === 'A') {
                    $all_erp[] = normalize_product($item);
                }
            }
            $cache_used = true;
        }
    }
}

if (!$cache_used) {
    // Fallback: buscar da API e filtrar APENAS ATIVOS (situacao === 'A')
    $page = 1;
    $max_pages = 50;
    while ($page <= $max_pages) {
        $items = fetch_erp_products($page, 100);
        if (!is_array($items) || empty($items)) {
            break;
        }
        foreach ($items as $item) {
            // FILTRO CRÍTICO: apenas produtos status A (ativos)
            if (isset($item['situacao']) && $item['situacao'] === 'A') {
                $all_erp[] = normalize_product($item);
            }
        }
        if (count($items) < 100) {
            break;
        }
        $page++;
        usleep(500000);
    }
}

if ($q !== '') {
    $qNormalized = svcat_search_normalize($q);
    $all_erp = array_filter($all_erp, function($p) use ($qNormalized) {
        $searchText = svcat_search_normalize($p['sku'] . ' ' . $p['name']);
        return strpos($searchText, $qNormalized) !== false;
    });
}

$products = array_slice(array_values($all_erp), 0, $limit);

// Categorias do fallback.json (apenas leitura)
$categories = [];
$jsonPath = svcat_root() . '/api/catalog/fallback-products.json';
if (is_file($jsonPath)) {
    $all = json_decode((string)file_get_contents($jsonPath), true) ?: [];
    $catCount = [];
    foreach ($all as $row) {
        $cat = (string)($row['category'] ?? '');
        if ($cat !== '') $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
    }
    arsort($catCount);
    $categories = $catCount;
}

if (empty($categories)) {
    $catCount = [];
    foreach ($all_erp as $row) {
        $cat = (string)($row['category'] ?? '');
        if ($cat !== '') $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
    }
    arsort($catCount);
    $categories = $catCount;
}

svcat_json(200, [
    'ok'         => true,
    'source'     => 'erp_olist',
    'count'         => count($products),
    'products'   => $products,
    'categories' => $categories,
]);

