<?php
declare(strict_types=1);

/**
 * API opt-in: Liz com contexto editorial da Central de Conhecimento.
 *
 * Mantem o endpoint principal intacto e encaminha a requisicao para
 * /api/liz-intelligent.php com contexto editorial anexado de forma segura.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/liz-knowledge-context.php';
require_once __DIR__ . '/../config/internal-http-origin.php';

function lizk_json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    lizk_json_response(405, ['ok' => false, 'error' => 'Metodo nao permitido.']);
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    lizk_json_response(400, ['ok' => false, 'error' => 'JSON invalido ou ausente.']);
}

$input = json_decode($rawInput, true);
if (!is_array($input)) {
    lizk_json_response(400, ['ok' => false, 'error' => 'JSON invalido.']);
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '') {
    lizk_json_response(400, ['ok' => false, 'error' => 'Mensagem ausente.']);
}

// CUSTO/ABUSO: encaminha para a Liz, que consome cota paga de LLM. Mesmo
// limite por IP aplicado aos demais pontos de entrada publicos.
require_once dirname(__DIR__) . '/includes/rate-limiter.php';
$lizkIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::isAllowed('liz-knowledge:' . $lizkIp, 10, 60)) {
    lizk_json_response(429, ['ok' => false, 'error' => 'Muitas perguntas seguidas. Aguarde um minuto.']);
}

$matches = sv_liz_knowledge_search($message, 3);

$input['message'] = $message;

$targetUrl = shopvivaliz_internal_url('/api/liz-intelligent.php');

$payload = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($payload)) {
    lizk_json_response(500, ['ok' => false, 'error' => 'Falha ao preparar contexto da Liz.']);
}

$ch = curl_init($targetUrl);
if ($ch === false) {
    lizk_json_response(503, ['ok' => false, 'error' => 'Nao foi possivel iniciar a Liz com conhecimento.']);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Host: shopvivaliz.com.br', 'X-Liz-Internal-Route: 1'],
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 35,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($response) || trim($response) === '') {
    lizk_json_response(503, [
        'ok' => false,
        'error' => 'A Liz com conhecimento nao respondeu. Tente novamente em alguns instantes.',
        'details' => $curlError !== '' ? 'network_error' : 'empty_response',
    ]);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    lizk_json_response(502, [
        'ok' => false,
        'error' => 'Resposta invalida da Liz principal.',
        'knowledge_found' => count($matches),
    ]);
}

$data['knowledge_found'] = count($matches);
$data['knowledge_articles'] = array_map(static fn(array $article): array => [
    'title' => (string)($article['title'] ?? ''),
    'url' => '/blog/' . rawurlencode((string)($article['slug'] ?? '')),
    'category' => (string)($article['category'] ?? ''),
], $matches);
$data['knowledge_mode'] = 'opt-in';
$data['knowledge_version'] = function_exists('sv_liz_knowledge_version') ? sv_liz_knowledge_version() : '2026-08-01.1';

lizk_json_response($httpCode > 0 ? $httpCode : 200, $data);
