<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/config/agent-keys.php';
require_once dirname(__DIR__) . '/includes/secure-session.php';
require_once dirname(__DIR__) . '/includes/rate-limiter.php';
require_once dirname(__DIR__) . '/includes/internal-http-origin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lzr_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lzr_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function lzr_request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? '';
    return is_string($value) ? trim($value) : '';
}

function lzr_expected_agent_key(): string
{
    foreach (['SHOPVIVALIZ_AGENT_KEY', 'RUNTIME_AGENT_KEY', 'WATCHDOG_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function lzr_authorize_search(): void
{
    $expected = lzr_expected_agent_key();
    if ($expected === '') {
        lzr_reply(503, ['ok' => false, 'error' => 'Busca da Liz indisponivel no momento.']);
    }

    $provided = lzr_request_header('X-ShopVivaliz-Agent-Key');
    if ($provided === '') {
        $authorization = lzr_request_header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = trim($matches[1]);
        }
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        header('WWW-Authenticate: Bearer realm="ShopVivaliz Liz Search"');
        lzr_reply(401, ['ok' => false, 'error' => 'Nao autorizado.']);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Health check endpoint
if ($method === 'GET' && ($_GET['health'] ?? '') === '1') {
    lzr_reply(200, ['ok' => true, 'endpoint' => 'liz-router', 'router' => true, 'version' => '1.1.0']);
}

// Product search endpoint (GET)
if ($method === 'GET' && isset($_GET['q'])) {
    lzr_authorize_search();

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!RateLimiter::isAllowed('liz-search:' . $ip, 10, 60)) {
        lzr_reply(429, ['ok' => false, 'error' => 'Too many requests.']);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sort = trim((string)($_GET['sort'] ?? 'relevance'));

    if ($q === '') {
        lzr_reply(400, ['ok' => false, 'error' => 'Parâmetro de busca "q" é obrigatório.']);
    }

    $target = '/api/product-search.php';
    $url = shopvivaliz_internal_url($target)
        . '?q=' . urlencode($q)
        . '&limit=' . $limit
        . '&page=' . $page
        . '&sort=' . urlencode($sort);
    $ch = curl_init($url);
    if ($ch === false) {
        lzr_reply(503, ['ok' => false, 'error' => 'Serviço temporariamente indisponível.']);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Host: shopvivaliz.com.br',
        ],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        error_log('Product search failed: ' . ($curlError !== '' ? $curlError : 'empty response'));
        lzr_reply(503, ['ok' => false, 'error' => 'Não foi possível concluir sua solicitação agora.']);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        error_log('Product search returned non-JSON response with HTTP ' . $status);
        lzr_reply(502, ['ok' => false, 'error' => 'Resposta inválida da busca de produtos.']);
    }

    lzr_reply($status >= 100 ? $status : 503, $decoded);
}

// Liz chat endpoint (POST)
if ($method !== 'POST') {
    lzr_reply(405, ['ok' => false, 'error' => 'Método não permitido.']);
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    lzr_reply(400, ['ok' => false, 'error' => 'JSON inválido.']);
}

$message = lzr_lower(trim((string)($input['message'] ?? '')));
if ($message === '') {
    lzr_reply(400, ['ok' => false, 'error' => 'Mensagem ausente.']);
}

$commercePattern = '/\b(produto|produtos|pre[cç]o|estoque|comprar|compra|pedido|entrega|frete|cupom|desconto|troca|devolu[cç][aã]o|garantia|pagamento|pix|cart[aã]o|boleto|carrinho|rod[ií]zio|rod[ií]zios|puxador|puxadores|dobradi[cç]a|dobradi[cç]as|corredi[cç]a|corredi[cç]as|fechadura|fechaduras|ferramenta|ferramentas|shopvivaliz|vivaliz|atendente|whatsapp)\b/u';
$isCommerce = preg_match($commercePattern, $message) === 1;
$target = $isCommerce ? '/api/liz-intelligent.php' : '/api/liz-general.php';

// Encaminhamento somente para o próprio servidor. Não use HTTP_HOST do cliente,
// evitando que um cabeçalho Host manipulado transforme o roteador em proxy SSRF.
$url = shopvivaliz_internal_url($target);
$ch = curl_init($url);
if ($ch === false) {
    lzr_reply(503, ['ok' => false, 'error' => 'A Liz está temporariamente indisponível.']);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Host: shopvivaliz.com.br',
        'X-Liz-Internal-Route: 1',
    ],
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 35,
    CURLOPT_FOLLOWLOCATION => false,
]);

$body = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($body) || $body === '') {
    error_log('Liz router falhou ao encaminhar para ' . $target . ': ' . ($curlError !== '' ? $curlError : 'resposta vazia'));
    lzr_reply(503, ['ok' => false, 'error' => 'Não foi possível concluir sua solicitação agora.']);
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    error_log('Liz router recebeu resposta não JSON de ' . $target . ' com HTTP ' . $status);
    lzr_reply(502, ['ok' => false, 'error' => 'A Liz recebeu uma resposta inválida. Tente novamente.']);
}

lzr_reply($status >= 100 ? $status : 503, $decoded);
