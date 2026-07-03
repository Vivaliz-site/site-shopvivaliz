<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svar_root(): string { return dirname(__DIR__, 2); }

function svar_json(string $rel): array {
    $path = svar_root() . '/' . ltrim($rel, '/');
    if (!is_file($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function svar_file_age(string $rel): ?int {
    $path = svar_root() . '/' . ltrim($rel, '/');
    return is_file($path) ? (int)(time() - filemtime($path)) : null;
}

$catalog    = svar_json('api/catalog/fallback-products.json');
$ranking    = svar_json('autodev/data/product_ranking.json');
$demand     = svar_json('autodev/data/demand_forecast.json');

$totalProducts = count($catalog);
$noImage = 0;
$zeroPrice = 0;
foreach ($catalog as $p) {
    if (!is_array($p)) continue;
    $img = trim((string)($p['image_url'] ?? ''));
    if ($img === '' || $img === '/favicon.ico') $noImage++;
    if ((float)($p['price'] ?? 0) <= 0) $zeroPrice++;
}

$mlTokenPath = svar_root() . '/storage/private/ml-tokens.json';
$mlTokenAge  = is_file($mlTokenPath) ? (int)(time() - filemtime($mlTokenPath)) : null;
$mlTokenData = is_file($mlTokenPath) ? json_decode((string)file_get_contents($mlTokenPath), true) : null;

echo json_encode([
    'agent'        => 'autonomous-report',
    'generated_at' => date('c'),
    'catalog' => [
        'total'       => $totalProducts,
        'no_image'    => $noImage,
        'zero_price'  => $zeroPrice,
        'file_age_s'  => svar_file_age('api/catalog/fallback-products.json'),
    ],
    'ranking' => [
        'available'   => !empty($ranking),
        'generated_at'=> $ranking['generated_at'] ?? null,
        'count'       => count($ranking['order'] ?? []),
        'file_age_s'  => svar_file_age('autodev/data/product_ranking.json'),
    ],
    'demand' => [
        'available'   => !empty($demand),
        'generated_at'=> $demand['generated_at'] ?? null,
        'count'       => count($demand['products'] ?? []),
        'file_age_s'  => svar_file_age('autodev/data/demand_forecast.json'),
    ],
    'integrations' => [
        'ml_oauth_connected' => is_array($mlTokenData) && !empty($mlTokenData['access_token']),
        'ml_token_age_s'     => $mlTokenAge,
        'ml_user_id'         => is_array($mlTokenData) ? ($mlTokenData['user_id'] ?? null) : null,
        'shopee_repair_done' => is_file(svar_root() . '/utils/shopee_client.py'),
        'git_guardian_active'=> is_file(svar_root() . '/scripts/git_autonomous_agent.py'),
    ],
    'endpoints' => [
        'autonomous_watchdog' => is_file(svar_root() . '/api/agent/autonomous-watchdog.php'),
        'external_trigger'    => is_file(svar_root() . '/api/agent/external-trigger.php'),
        'squad_chat'          => is_file(svar_root() . '/api/agent/squad-chat.php'),
        'ml_login'            => is_file(svar_root() . '/api/ml/login.php'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
