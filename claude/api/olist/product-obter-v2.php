<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svpo_root(): string { return dirname(__DIR__, 2); }

function svpo_env_load(string $path): void
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

function svpo_json(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

svpo_env_load(svpo_root() . '/.env');

$expected = getenv('SQUAD_TOKEN') ?: '';
$received = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? $_GET['squad_token'] ?? '';
if ($expected !== '' && !hash_equals($expected, (string)$received)) {
    svpo_json(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$token = getenv('TOKEN_API_OLIST') ?: getenv('TINY_API_TOKEN') ?: getenv('OLIST_API_TOKEN') ?: '';
if ($token === '') {
    svpo_json(503, ['ok' => false, 'error' => 'TOKEN_API_OLIST not configured']);
}

$id = preg_replace('/\D+/', '', (string)($_GET['id'] ?? ''));
if ($id === '') {
    svpo_json(400, ['ok' => false, 'error' => 'missing_id']);
}

$params = http_build_query([
    'token' => $token,
    'id' => $id,
    'formato' => 'json',
]);
$url = 'https://api.tiny.com.br/api2/produto.obter.php?' . $params;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: ShopVivaliz-TinyV2/1.0',
    ],
]);
$body = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $err !== '') {
    svpo_json(502, ['ok' => false, 'error' => 'tiny_v2_request_failed']);
}

http_response_code($http ?: 200);
echo (string)$body;

