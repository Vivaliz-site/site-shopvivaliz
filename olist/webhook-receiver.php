<?php
/**
 * Webhook Receiver - Olist envia notificacoes de mudancas
 * POST https://shopvivaliz.com.br/olist/webhook-receiver.php
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
require_once $root . '/includes/runtime-env-reader.php';

$webhookLog = $root . '/logs/webhook.log';
@mkdir(dirname($webhookLog), 0755, true);

$requestBody = (string)file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');
$decodedForLog = json_decode($requestBody, true);
$logEntry = "[$timestamp] " . ($_SERVER['REQUEST_METHOD'] ?? '') . ' '
    . json_encode($decodedForLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . PHP_EOL;
@file_put_contents($webhookLog, $logEntry, FILE_APPEND | LOCK_EX);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Apenas POST permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function verify_webhook_signature(string $requestBody, string $signature, string $root): bool
{
    $secret = svre_value('OLIST_WEBHOOK_SECRET', $root);
    if ($secret === '') {
        error_log('[WEBHOOK] WARNING: OLIST_WEBHOOK_SECRET not configured. Signature verification skipped.');
        return true;
    }

    $expectedSignature = hash_hmac('sha256', $requestBody, $secret, false);
    $valid = hash_equals($expectedSignature, $signature);
    if (!$valid) {
        error_log('[WEBHOOK] FAILED: Signature mismatch.');
    }
    return $valid;
}

$signature = (string)($_SERVER['HTTP_X_OLIST_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '');
if ($signature !== '') {
    if (!verify_webhook_signature($requestBody, $signature, $root)) {
        error_log('[WEBHOOK] REJECTED: Invalid signature.');
        http_response_code(403);
        echo json_encode(['erro' => 'Assinatura invalida'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    error_log('[WEBHOOK] VERIFIED: Signature valid');
} else {
    error_log('[WEBHOOK] WARNING: No signature header found. Accepting unsigned webhook.');
}

$data = json_decode($requestBody, true);
if (!is_array($data) || $data === []) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON invalido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$eventType = trim((string)($data['event'] ?? $data['tipo'] ?? $data['type'] ?? ''));
$productId = $data['produto_id'] ?? $data['product_id'] ?? $data['id'] ?? null;
if (!$productId && isset($data['dados']['idProduto'])) {
    $productId = $data['dados']['idProduto'];
}

$syncEvents = [
    'produto.criado',
    'produto.atualizado',
    'produto.alterado',
    'preco.alterado',
    'estoque.alterado',
    'estoque',
    'preco',
    'produto',
];
$shouldSync = in_array($eventType, $syncEvents, true)
    || str_contains($eventType, 'produto')
    || str_contains($eventType, 'estoque')
    || str_contains($eventType, 'preco');

if ($shouldSync) {
    // O refresh precisa ser sequencial: primeiro recria a lista canonica de
    // ativos, depois consulta o estoque autoritativo e so entao espelha no
    // banco local. Rodar sync e estoque em paralelo cria corrida de cache.
    $pipeline = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/olist/sync-on-webhook.php')
        . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/olist/fetch-estoque-v3.php')
        . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/sync-daemon-to-db.php');
    exec('nohup sh -c ' . escapeshellarg($pipeline) . ' > /dev/null 2>&1 &');

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Webhook recebido e sincronizacao sequencial iniciada',
        'event' => $eventType,
        'product_id' => $productId,
        'steps' => ['active-sync-v3', 'authoritative-stock-v3', 'local-db-sync'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Webhook ignorado (evento nao monitorado)',
    'event' => $eventType,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
