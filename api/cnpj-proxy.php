<?php
declare(strict_types=1);

// Rodada 10 (2026-08-19) - R10-7: sem SSRF (o CNPJ e' validado como 14 digitos antes
// de compor a URL), mas com Access-Control-Allow-Origin:* + sem rate limit, qualquer
// site de terceiros podia embutir consultas que saiam do IP da ShopVivaliz contra a
// BrasilAPI -- o bloqueio por abuso recairia sobre o proprio formulario de cadastro
// PJ do site, nao sobre quem abusou. Restrito a mesma origem + rate limit (fail-closed,
// mesmo padrao de api/stock-alerts/subscribe.php).
require_once __DIR__ . '/../includes/order-rate-limit.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!svorl_allow(20, 3600, 'cnpj-proxy')) {
    http_response_code(429);
    echo json_encode(['erro' => true, 'mensagem' => 'Muitas requisições. Tente novamente em instantes.']);
    exit;
}

$cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');

if (strlen($cnpj) !== 14) {
    http_response_code(400);
    echo json_encode(['erro' => true, 'mensagem' => 'CNPJ inválido']);
    exit;
}

$url = 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj;

if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && is_string($response) && $response !== '') {
        echo $response;
        exit;
    }

    if ($httpCode === 404) {
        http_response_code(404);
        echo json_encode(['erro' => true, 'mensagem' => 'CNPJ não encontrado']);
        exit;
    }

    http_response_code(502);
    echo json_encode(['erro' => true, 'mensagem' => 'Erro ao consultar CNPJ']);
    exit;
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ],
]);

$response = @file_get_contents($url, false, $context);
$status = 0;
foreach (($http_response_header ?? []) as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
        $status = (int) $matches[1];
    }
}

if ($response !== false && $status >= 200 && $status < 300) {
    echo $response;
    exit;
}

if ($status === 404) {
    http_response_code(404);
    echo json_encode(['erro' => true, 'mensagem' => 'CNPJ não encontrado']);
    exit;
}

http_response_code(502);
echo json_encode(['erro' => true, 'mensagem' => 'Erro ao consultar CNPJ']);
