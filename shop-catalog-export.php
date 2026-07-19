<?php
/**
 * Proxy seguro para exportar catálogo via Tiny API v3 (OAuth).
 * Chamado internamente pelo pipeline de imagens IA.
 *
 * Migrado da API v2 legada em 2026-07-18 -- ver docs/MEMORIA-AGENTES.md.
 * O token estático embutido no deploy (__OLIST_TOKEN__) foi substituído pelo
 * access_token OAuth persistido em storage/private/tokens.json, o mesmo usado
 * por olist/sync-products.php.
 *
 * POST /shop-catalog-export.php
 *   Body: pagina=1&limite=50
 *   Header: X-Squad: <SQUAD_TOKEN>
 */

declare(strict_types=1);

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Autenticação
$expected    = '__SQUAD_TOKEN__';
$squad_token = $_SERVER['HTTP_X_SQUAD'] ?? $_POST['_sq'] ?? '';
if ($expected && $squad_token !== $expected) {
    http_response_code(401);
    echo json_encode(['erro' => 'Unauthorized']);
    exit;
}

// Access token OAuth v3 persistido pelo sync real
$tokensFile  = __DIR__ . '/storage/private/tokens.json';
$olist_token = '';
if (is_file($tokensFile)) {
    $tokens = json_decode((string)file_get_contents($tokensFile), true);
    $olist_token = (string)($tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? '');
}
if ($olist_token === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'access_token OAuth nao configurado']);
    exit;
}

// Params (API v3 usa limit/offset, nao pagina/limite)
$pagina  = max(1, intval($_POST['pagina'] ?? 1));
$limite  = intval($_POST['limite'] ?? 50);
$params  = http_build_query([
    'situacao' => 'A',
    'limit'    => $limite,
    'offset'   => ($pagina - 1) * $limite,
]);

$ch = curl_init("https://api.tiny.com.br/public-api/v3/produtos?$params");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        "Authorization: Bearer $olist_token",
    ],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($http_code);
echo $response;
