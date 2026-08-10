<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$storageDir = $root . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
$lockFile = fopen($storageDir . '/cache-sync.lock', 'c+');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'cache_sync_locked']);
    exit;
}

/**
 * Resolve a URL de imagem mais confiavel para a release atual.
 *
 * O pipeline historico gravava site_url em /uploads/olist, inclusive em
 * caminhos de deploy antigos. Em releases imutaveis esses arquivos podem nao
 * existir mais. Por isso fontes remotas originais do ERP/Tiny tem prioridade.
 * URLs locais da propria ShopVivaliz so sao aceitas quando o arquivo existe
 * fisicamente na release atual.
 */
function svsce_resolve_image_url(array $row, string $root): string
{
    $remoteCandidates = [
        $row['original_url_olist'] ?? '',
        $row['image_url'] ?? '',
        $row['original_url'] ?? '',
    ];

    foreach ($remoteCandidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate)) {
            return $candidate;
        }
    }

    foreach (['site_url', 'local_url'] as $field) {
        $candidate = trim((string)($row[$field] ?? ''));
        if ($candidate === '') continue;

        if (str_starts_with($candidate, '/')) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && is_file($root . $path)) {
                return 'https://shopvivaliz.com.br' . $path;
            }
            continue;
        }

        if (!preg_match('~^https?://~i', $candidate)) continue;

        $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?? ''));
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
        if (in_array($host, ['shopvivaliz.com.br', 'www.shopvivaliz.com.br'], true)) {
            if ($path !== '' && is_file($root . $path)) {
                return 'https://shopvivaliz.com.br' . $path;
            }
            continue;
        }

        return $candidate;
    }

    return '';
}

try {
    $token = getenv('TINY_ACCESS_TOKEN') ?: getenv('OLIST_ACCESS_TOKEN') ?: '';
    if ($token === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'missing_token']);
        exit;
    }

    $items = [];
    $offset = 0;
    do {
        $url = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset={$offset}";
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n", 'timeout' => 30]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) break;
        $data = json_decode($response, true);
        $pageItems = $data['itens'] ?? [];
        if (!is_array($pageItems) || $pageItems === []) break;
        foreach ($pageItems as $item) {
            if (($item['situacao'] ?? '') === 'A') {
                $item['estoque_disponivel'] = (int)($item['estoque']['quantidade'] ?? 0);
                $items[] = $item;
            }
        }
        $offset += 100;
    } while (count($pageItems) === 100);

    // A listagem V3 do Tiny nao traz necessariamente todos os anexos do
    // cadastro. As imagens completas ja reconciliadas do ERP ficam em
    // olist_product_images. Mesclamos por SKU somente durante a geracao do
    // cache, evitando consultas ao banco em cada visita da loja.
    $imagesBySku = [];
    $imagesEnriched = 0;
    try {
        require_once $root . '/includes/pdo-database.php';
        $pdo = sv_pdo();
        if ($pdo instanceof PDO) {
            $sql = "SELECT sku,
                           site_url,
                           local_url,
                           image_url,
                           original_url_olist,
                           original_url,
                           position,
                           is_primary,
                           id
                      FROM olist_product_images
                     WHERE sku IS NOT NULL
                       AND TRIM(sku) <> ''
                       AND (status IS NULL OR status = '' OR status NOT IN ('error', 'deleted', 'inactive'))
                  ORDER BY sku ASC, is_primary DESC, position ASC, id ASC";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sku = trim((string)($row['sku'] ?? ''));
                $imageUrl = svsce_resolve_image_url($row, $root);
                if ($sku === '' || $imageUrl === '') continue;

                if (!isset($imagesBySku[$sku])) {
                    $imagesBySku[$sku] = [];
                }
                if (!in_array($imageUrl, $imagesBySku[$sku], true) && count($imagesBySku[$sku]) < 12) {
                    $imagesBySku[$sku][] = $imageUrl;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('cache image gallery enrichment failed: ' . $e->getMessage());
    }

    if ($imagesBySku !== []) {
        foreach ($items as &$item) {
            $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
            if ($sku === '' || empty($imagesBySku[$sku])) continue;

            $gallery = $imagesBySku[$sku];
            // O runtime do catalogo reconhece "imagens" e usa a primeira URL
            // como capa quando necessario. A ordem vem de is_primary/position.
            $item['imagens'] = $gallery;
            $item['images'] = $gallery;
            $item['imagem_principal_url'] = $gallery[0];
            $item['primary_image_url'] = $gallery[0];
            $item['images_count'] = count($gallery);
            $imagesEnriched++;
        }
        unset($item);
    }

    $payload = [
        'success' => true,
        'total' => count($items),
        'updated_at' => gmdate(DATE_ATOM),
        'images_enriched' => $imagesEnriched,
        'itens' => $items,
        'items' => $items,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'json_encode_failed']);
        exit;
    }

    $cacheFiles = [
        $root . '/storage/products-cache-ativos.json',
        $root . '/storage/cache/products-cache-ativos.json',
    ];
    $written = [];
    foreach ($cacheFiles as $cacheFile) {
        @mkdir(dirname($cacheFile), 0775, true);
        if (file_put_contents($cacheFile, $encoded, LOCK_EX) !== false) {
            $written[] = $cacheFile;
        }
    }

    foreach (glob($root . '/storage/cache/catalog-api/*.json') ?: [] as $file) {
        @unlink($file);
    }

    echo json_encode([
        'success' => true,
        'total' => count($items),
        'images_enriched' => $imagesEnriched,
        'files' => $written,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    flock($lockFile, LOCK_UN);
    if (is_resource($lockFile)) {
        fclose($lockFile);
    }
}
