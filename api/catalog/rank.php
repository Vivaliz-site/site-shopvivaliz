<?php
declare(strict_types=1);
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * V16 Commerce Brain — ranking engine
 * Calcula commerce_score por produto e retorna lista ranqueada.
 * GET /api/catalog/rank.php?limit=20&category=X&signal=views|cart|orders
 */

function svrank_root(): string { return dirname(__DIR__, 2); }

function svrank_signals(): array
{
    $path = svrank_root() . '/storage/commerce_signals.json';
    if (is_file($path) && (time() - filemtime($path)) < 3600) {
        $d = json_decode((string)file_get_contents($path), true);
        if (is_array($d)) return $d;
    }
    return [];
}

function svrank_orders(): array
{
    $path = svrank_root() . '/logs/pedidos.jsonl';
    if (!is_file($path)) return [];
    $counts = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $p = json_decode($line, true);
        foreach ((array)($p['items'] ?? []) as $item) {
            $sku = (string)($item['sku'] ?? '');
            if ($sku !== '') $counts[$sku] = ($counts[$sku] ?? 0) + (int)($item['quantity'] ?? 1);
        }
    }
    return $counts;
}

function svrank_commerce_score(array $p, array $signals, array $orderCounts): float
{
    $score = 0.0;

    // Qualidade do produto (V14)
    $score += (float)($p['quality_score'] ?? 0) * 0.3;

    // Tem imagem + múltiplas imagens
    $imgCount = (int)($p['images_count'] ?? 0);
    if ($imgCount >= 1) $score += 10;
    if ($imgCount >= 3) $score += 10;
    if ($imgCount >= 5) $score += 5;

    // Tem preço real
    $price = (float)($p['price'] ?? 0);
    if ($price > 0) {
        $score += 20;
        // Faixa de preço ótima (R$20–R$300 converte melhor)
        if ($price >= 20 && $price <= 300) $score += 10;
    }

    // Tem descrição
    if (!empty(trim((string)($p['description'] ?? '')))) $score += 10;

    // Tem slug SEO
    if (!empty(trim((string)($p['slug'] ?? '')))) $score += 5;

    // Sinais de comportamento (views, cart_adds)
    $sku = (string)($p['sku'] ?? '');
    $pid = (string)($p['olist_product_id'] ?? '');
    $views    = (int)($signals['views'][$sku]      ?? $signals['views'][$pid]      ?? 0);
    $cartAdds = (int)($signals['cart'][$sku]        ?? $signals['cart'][$pid]       ?? 0);
    $score += min($views * 0.5, 15.0);
    $score += min($cartAdds * 2.0, 20.0);

    // Pedidos confirmados — sinal mais forte
    $ordered = (int)($orderCounts[$sku] ?? 0);
    $score += min($ordered * 5.0, 25.0);

    // Tags premium (aumenta percepção de valor)
    $tags = is_array($p['tags'] ?? null) ? $p['tags'] : [];
    if (in_array('profissional', $tags)) $score += 5;
    if (in_array('kit', $tags))         $score += 3;

    return round($score, 2);
}

$limit    = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$category = trim((string)($_GET['category'] ?? $_GET['categoria'] ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));

$jsonPath = svrank_root() . '/api/catalog/fallback-products.json';
$products = is_file($jsonPath)
    ? (json_decode((string)file_get_contents($jsonPath), true) ?: [])
    : [];

$signals     = svrank_signals();
$orderCounts = svrank_orders();

$ranked = [];
foreach ($products as $row) {
    if (!is_array($row)) continue;
    if ($category !== '' && strcasecmp((string)($row['category'] ?? ''), $category) !== 0) continue;
    if ($q !== '' && stripos(($row['sku'] ?? '') . ' ' . ($row['name'] ?? ''), $q) === false) continue;

    $row['commerce_score'] = svrank_commerce_score($row, $signals, $orderCounts);
    $ranked[] = $row;
}

usort($ranked, fn($a, $b) => $b['commerce_score'] <=> $a['commerce_score']);
$ranked = array_slice($ranked, 0, $limit);

// Demand prediction: produtos com cart_adds sem pedido = alta intenção
$highIntent = [];
foreach ($ranked as $r) {
    $sku  = (string)($r['sku'] ?? '');
    $cart = (int)($signals['cart'][$sku] ?? 0);
    $ord  = (int)($orderCounts[$sku] ?? 0);
    if ($cart > 0 && $ord === 0) $highIntent[] = $sku;
}

http_response_code(200);
echo json_encode([
    'ok'            => true,
    'count'         => count($ranked),
    'products'      => $ranked,
    'high_intent'   => $highIntent,
    'signals_active'=> !empty($signals),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
