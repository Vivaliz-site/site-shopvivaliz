<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lzr_reply(int $status, string $body): never
{
    http_response_code($status);
    echo $body;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['health'] ?? '') === '1') {
    lzr_reply(200, json_encode(['ok' => true, 'endpoint' => 'liz-intelligent', 'router' => true], JSON_UNESCAPED_UNICODE));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lzr_reply(405, json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE));
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
$message = mb_strtolower(trim((string)($input['message'] ?? '')), 'UTF-8');
if ($message === '') {
    lzr_reply(400, json_encode(['ok' => false, 'error' => 'Mensagem ausente.'], JSON_UNESCAPED_UNICODE));
}

$commercePattern = '/\b(produto|produtos|pre[cç]o|estoque|comprar|compra|pedido|entrega|frete|cupom|desconto|troca|devolu[cç][aã]o|garantia|pagamento|pix|cart[aã]o|boleto|carrinho|rod[ií]zio|puxador|dobradi[cç]a|corredi[cç]a|fechadura|ferramenta|shopvivaliz|vivaliz|atendente|whatsapp)\b/u';
$isCommerce = preg_match($commercePattern, $message) === 1;
$target = $isCommerce ? '/api/liz-intelligent.php' : '/api/liz-general.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br'));
$url = $scheme . '://' . $host . $target;

$ch = curl_init($url);
if ($ch === false) {
    lzr_reply(503, json_encode(['ok' => false, 'error' => 'A Liz está temporariamente indisponível.'], JSON_UNESCAPED_UNICODE));
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 32,
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($body) || $body === '') {
    lzr_reply(503, json_encode(['ok' => false, 'error' => 'Não foi possível concluir sua solicitação agora.'], JSON_UNESCAPED_UNICODE));
}

lzr_reply($status > 0 ? $status : 503, $body);
