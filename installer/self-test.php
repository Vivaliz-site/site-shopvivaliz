<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svself_root(): string
{
    return dirname(__DIR__);
}

function svself_version(): array
{
    $file = svself_root() . '/config/shopvivaliz-version.php';
    if (!is_file($file)) {
        return ['version' => '0.0.0', 'codename' => 'unknown', 'channel' => 'unknown'];
    }

    $payload = require $file;
    return is_array($payload) ? $payload : ['version' => '0.0.0', 'codename' => 'unknown', 'channel' => 'unknown'];
}

function svself_file(string $relativePath): string
{
    $path = svself_root() . '/' . ltrim($relativePath, '/');
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    return (string)file_get_contents($path);
}

function svself_has(string $haystack, string $needle): bool
{
    return $haystack !== '' && str_contains($haystack, $needle);
}

function svself_json(string $relativePath): array
{
    $raw = svself_file($relativePath);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$version = svself_version();
$catalogApi = svself_file('api/catalog/products.php');
$imageRecovery = svself_file('api/catalog/image-recovery.php');
$productPage = svself_file('produto.php');
$runApi = svself_file('api/autodev/run.php');
$statusApi = svself_file('api/autodev/status.php');
$metricsEngine = svself_file('autodev/core/metrics_engine.php');
$cartScript = svself_file('js/cart.js');
$rankingPath = svself_root() . '/autodev/data/product_ranking.json';
$ranking = svself_json('autodev/data/product_ranking.json');
$demandPath = svself_root() . '/autodev/data/demand_forecast.json';
$demand = svself_json('autodev/data/demand_forecast.json');
$catalog = svself_json('api/catalog/fallback-products.json');
$noImageCount = 0;
$erroCount = 0;
foreach ($catalog as $row) {
    if (!is_array($row)) {
        continue;
    }
    $imageUrl = trim((string)($row['image_url'] ?? ''));
    $status = (string)($row['status'] ?? '');
    if ($imageUrl === '' || $imageUrl === '/favicon.ico') {
        $noImageCount++;
    }
    if ($status === 'erro') {
        $erroCount++;
    }
}

$checks = [
    'Commerce Brain ranker presente' => is_file(svself_root() . '/autodev/evolution/product_ranker.php'),
    'Demand predictor presente' => is_file(svself_root() . '/autodev/evolution/demand_predictor.php'),
    'Commerce director presente' => is_file(svself_root() . '/autodev/directors/commerce_director.php'),
    'Image recovery helper presente' => is_file(svself_root() . '/api/catalog/image-recovery.php'),
    'Helper de ranking do catalogo presente' => is_file(svself_root() . '/api/catalog/ranking.php'),
    'API de catalogo recupera imagens mapeadas' => svself_has($catalogApi, 'svimg_recover_product'),
    'API de catalogo aplica ranking' => svself_has($catalogApi, 'svrank_sort_products'),
    'Produto rastreia visualizacao' => svself_has($productPage, "AutoDev.track('product_view'"),
    'Produto rastreia add_to_cart' => svself_has($productPage, "AutoDev.track('add_to_cart'"),
    'AutoDev run gera ranking' => svself_has($runApi, 'autodev_commerce_director'),
    'AutoDev run gera Commerce Brain' => svself_has($runApi, "'commerce' =>"),
    'AutoDev status expoe ranking' => svself_has($statusApi, "'ranking' => ["),
    'AutoDev status expoe demanda' => svself_has($statusApi, "'demand' => ["),
    'Metricas usam receita real' => svself_has($metricsEngine, "'revenue_total'"),
    'Image recovery usa mapeamento local' => svself_has($imageRecovery, 'svimg_report_index'),
    'Endpoint residente autonomous-report presente' => is_file(svself_root() . '/api/agent/autonomous-report.php'),
    'Endpoint residente autonomous-watchdog presente' => is_file(svself_root() . '/api/agent/autonomous-watchdog.php'),
    'Endpoint residente media-quality presente' => is_file(svself_root() . '/api/agent/media-quality.php'),
    'Endpoint residente external-trigger presente' => is_file(svself_root() . '/api/agent/external-trigger.php'),
    'API de pedidos create presente' => is_file(svself_root() . '/api/orders/create.php'),
    'Alias pedido-criar presente' => is_file(svself_root() . '/pedido-criar.php'),
    'Checkout usa API de pedidos' => svself_has($cartScript, '/api/orders/create.php'),
    'Script Shopee Media Space repair presente' => is_file(svself_root() . '/scripts/shopee-media-space-repair.py'),
    'Workflow Shopee Media Space repair presente' => is_file(svself_root() . '/.github/workflows/shopee-media-space-repair.yml'),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'version' => (string)($version['version'] ?? '0.0.0'),
    'codename' => (string)($version['codename'] ?? ''),
    'channel' => (string)($version['channel'] ?? ''),
    'generated_at' => date('c'),
    'checks' => $checks,
    'ranking' => [
        'file_available' => is_file($rankingPath) && is_readable($rankingPath),
        'generated_at' => $ranking['generated_at'] ?? null,
        'ranked_count' => count(is_array($ranking['order'] ?? null) ? $ranking['order'] : []),
    ],
    'demand' => [
        'file_available' => is_file($demandPath) && is_readable($demandPath),
        'generated_at' => $demand['generated_at'] ?? null,
        'products_count' => count(is_array($demand['products'] ?? null) ? $demand['products'] : []),
    ],
    'catalog_audit' => [
        'file_available' => is_file(svself_root() . '/api/catalog/fallback-products.json'),
        'total' => count($catalog),
        'no_image' => $noImageCount,
        'erro' => $erroCount,
    ],
    'runtime_endpoints' => [
        'autonomous_report' => is_file(svself_root() . '/api/agent/autonomous-report.php'),
        'autonomous_watchdog' => is_file(svself_root() . '/api/agent/autonomous-watchdog.php'),
        'external_trigger' => is_file(svself_root() . '/api/agent/external-trigger.php'),
        'media_quality' => is_file(svself_root() . '/api/agent/media-quality.php'),
        'media_mismatch' => is_file(svself_root() . '/api/agent/media-mismatch.php'),
        'orders_create' => is_file(svself_root() . '/api/orders/create.php'),
        'pedido_criar' => is_file(svself_root() . '/pedido-criar.php'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
