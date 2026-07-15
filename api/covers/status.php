<?php
/**
 * api/covers/status.php — Status de geração de capas
 *
 * GET /api/covers/status.php
 * GET /api/covers/status.php?sku=SOME-SKU
 * GET /api/covers/status.php?format=csv
 *
 * Retorna JSON com contagem de capas geradas vs total de produtos no catálogo.
 */

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

function covers_root(): string
{
    return dirname(__DIR__, 2);
}

function covers_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ── Caminhos ─────────────────────────────────────────────────────────────────

$catalogFile = covers_root() . '/api/catalog/fallback-products.json';
$coversDir   = covers_root() . '/storage/covers';

// ── Carrega catálogo ──────────────────────────────────────────────────────────

if (!is_file($catalogFile)) {
    covers_json(500, ['error' => 'Catálogo não encontrado', 'path' => $catalogFile]);
}

$products = json_decode(file_get_contents($catalogFile), true);
if (!is_array($products)) {
    covers_json(500, ['error' => 'JSON inválido no catálogo']);
}

// ── Filtro por SKU opcional ───────────────────────────────────────────────────

$filterSku = $_GET['sku'] ?? null;
if ($filterSku !== null) {
    $products = array_values(array_filter($products, fn($p) => ($p['sku'] ?? '') === $filterSku));
}

// ── Conta capas existentes ────────────────────────────────────────────────────

$total        = count($products);
$withImage    = 0;
$tiktokDone   = 0;
$mlDone       = 0;
$bothDone     = 0;
$details      = [];

foreach ($products as $product) {
    $hasImage = !empty($product['image_url']) || !empty($product['images'][0]);
    if ($hasImage) {
        $withImage++;
    }

    $rawSku  = $product['sku'] ?? $product['id'] ?? '';
    $sku     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $rawSku);
    $skuDir  = $coversDir . '/' . $sku;

    $tiktokExists = is_file($skuDir . '/tiktok.jpg');
    $mlExists     = is_file($skuDir . '/ml.jpg');

    if ($tiktokExists) $tiktokDone++;
    if ($mlExists)     $mlDone++;
    if ($tiktokExists && $mlExists) $bothDone++;

    if ($filterSku !== null || isset($_GET['detail'])) {
        $details[] = [
            'sku'          => $rawSku,
            'name'         => $product['name'] ?? '',
            'has_image'    => $hasImage,
            'tiktok_ready' => $tiktokExists,
            'ml_ready'     => $mlExists,
            'tiktok_url'   => $tiktokExists ? "/storage/covers/$sku/tiktok.jpg" : null,
            'ml_url'       => $mlExists     ? "/storage/covers/$sku/ml.jpg"     : null,
            'tiktok_size'  => $tiktokExists ? filesize($skuDir . '/tiktok.jpg') : null,
            'ml_size'      => $mlExists     ? filesize($skuDir . '/ml.jpg')     : null,
        ];
    }
}

$pctTiktok = $withImage > 0 ? round($tiktokDone / $withImage * 100, 1) : 0;
$pctMl     = $withImage > 0 ? round($mlDone / $withImage * 100, 1) : 0;
$pctBoth   = $withImage > 0 ? round($bothDone / $withImage * 100, 1) : 0;

// ── Resposta ──────────────────────────────────────────────────────────────────

$response = [
    'generated_at'        => date('c'),
    'catalog_total'       => $total,
    'with_source_image'   => $withImage,
    'covers' => [
        'tiktok' => [
            'done'    => $tiktokDone,
            'pending' => $withImage - $tiktokDone,
            'pct'     => $pctTiktok,
        ],
        'ml' => [
            'done'    => $mlDone,
            'pending' => $withImage - $mlDone,
            'pct'     => $pctMl,
        ],
        'both_complete' => [
            'done'    => $bothDone,
            'pending' => $withImage - $bothDone,
            'pct'     => $pctBoth,
        ],
    ],
    'storage_path' => '/storage/covers/',
    'generate_command' => 'php scripts/generate-covers.php',
];

if (!empty($details)) {
    $response['products'] = $details;
}

covers_json(200, $response);
