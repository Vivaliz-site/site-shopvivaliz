<?php
/**
 * Proxy seguro para exportar catálogo via Tiny API v2.
 * Chamado internamente pelo pipeline de imagens IA.
 *
 * POST /shop-catalog-export.php
 *   Body: pagina=1&limite=50
 *   Header: X-Squad: <SQUAD_TOKEN>
 */

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

// Token Olist embutido no deploy
$olist_token = '__OLIST_TOKEN__';
if (!$olist_token) {
    http_response_code(400);
    echo json_encode(['erro' => 'token nao configurado']);
    exit;
}

// Params
$pagina  = intval($_POST['pagina'] ?? 1);
$limite  = intval($_POST['limite'] ?? 50);
$params  = http_build_query(['token' => $olist_token, 'formato' => 'json', 'pagina' => $pagina, 'limite' => $limite]);

$ch = curl_init("https://api.tiny.com.br/api/v2/produtos.json?$params");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($http_code);
echo $response;
