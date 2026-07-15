<?php
declare(strict_types=1);

/**
 * Proxy Tiny API V2.
 *
 * Auth: X-Squad-Token header, or squad_token query for old automation calls.
 * List:   GET api/olist/products-proxy.php?pagina=1&pesquisa=SKU
 * Detail: GET api/olist/products-proxy.php?id=123
 */

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svi_proxy_root(): string { return dirname(__DIR__, 2); }

function svi_proxy_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function svi_proxy_json(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

svi_proxy_env_load(svi_proxy_root() . '/.env');

$squadToken = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? $_GET['squad_token'] ?? '';
$expected = getenv('SQUAD_TOKEN') ?: '';
if ($expected !== '' && !hash_equals($expected, (string)$squadToken)) {
    svi_proxy_json(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$tinyToken = getenv('TOKEN_API_OLIST') ?: getenv('TINY_API_TOKEN') ?: getenv('OLIST_API_TOKEN') ?: '';
if ($tinyToken === '') {
    svi_proxy_json(503, ['ok' => false, 'error' => 'TOKEN_API_OLIST not configured']);
}

$params = ['token' => $tinyToken, 'formato' => 'json'];
$id = preg_replace('/\D+/', '', (string)($_GET['id'] ?? ''));
if ($id !== '') {
    $endpoint = 'produto.obter.php';
    $params['id'] = $id;
} else {
    $endpoint = 'produtos.pesquisa.php';
    foreach (['pagina', 'limite', 'situacao', 'pesquisa'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') $params[$key] = (string)$_GET[$key];
    }
}

$apiUrl = 'https://api.tiny.com.br/api2/' . $endpoint . '?' . http_build_query($params);
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: ShopVivaliz-TinyV2Proxy/1.0',
    ],
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $error !== '') {
    svi_proxy_json(502, ['ok' => false, 'error' => 'tiny_v2_request_failed']);
}

http_response_code($httpCode ?: 200);
echo (string)$response;
