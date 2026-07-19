<?php
/**
 * Webhook Receiver - Olist envia notificações de mudanças
 * POST https://dev.shopvivaliz.com.br/olist/webhook-receiver.php
 *
 * Gatilhos:
 * - Produto criado
 * - Produto atualizado
 * - Preço alterado
 * - Estoque alterado
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$webhook_log = dirname(__DIR__) . '/logs/webhook.log';
@mkdir(dirname($webhook_log), 0755, true);

// Registrar requisição
$request_body = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');
$log_entry = "[$timestamp] " . $_SERVER['REQUEST_METHOD'] . " " . json_encode(json_decode($request_body, true)) . "\n";
@file_put_contents($webhook_log, $log_entry, FILE_APPEND);

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Apenas POST permitido']);
    exit;
}

// ============================================================
// HMAC-SHA256 SIGNATURE VERIFICATION
// ============================================================
function verify_webhook_signature(string $request_body, string $signature): bool
{
    // Get webhook secret from environment or config
    $root = dirname(__DIR__);
    $secret = '';

    // Try to get from environment
    if (function_exists('getenv')) {
        $secret = getenv('OLIST_WEBHOOK_SECRET') ?: '';
    }

    // Try to get from .env file
    if (!$secret) {
        $env_file = $root . '/.env';
        if (is_file($env_file)) {
            foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, 'OLIST_WEBHOOK_SECRET=')) {
                    $parts = explode('=', $line, 2);
                    $secret = trim(trim($parts[1] ?? ''), "\"'");
                    break;
                }
            }
        }
    }

    // If no secret configured, log warning but continue (graceful degradation)
    if (!$secret) {
        error_log("[WEBHOOK] WARNING: OLIST_WEBHOOK_SECRET not configured. Signature verification skipped.");
        return true; // Allow unsigned webhooks until secret is configured
    }

    // Compute expected signature: HMAC-SHA256(body, secret)
    $expected_signature = hash_hmac('sha256', $request_body, $secret, false);

    // Compare signatures (constant-time comparison to prevent timing attacks)
    $result = hash_equals($expected_signature, $signature);

    if (!$result) {
        error_log("[WEBHOOK] FAILED: Signature mismatch. Expected: " . substr($expected_signature, 0, 16) . "... Got: " . substr($signature, 0, 16) . "...");
    }

    return $result;
}

// Verify HMAC signature if header present
$signature = $_SERVER['HTTP_X_OLIST_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if ($signature) {
    if (!verify_webhook_signature($request_body, $signature)) {
        error_log("[WEBHOOK] REJECTED: Invalid signature from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(403);
        echo json_encode(['erro' => 'Assinatura inválida']);
        exit;
    }
    error_log("[WEBHOOK] VERIFIED: Signature valid");
} else {
    error_log("[WEBHOOK] WARNING: No signature header found. Accepting unsigned webhook.");
}

// Parse JSON
$data = json_decode($request_body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON inválido']);
    exit;
}

// ============================================================
// Processar eventos
// ============================================================

$event_type = $data['event'] ?? $data['tipo'] ?? $data['type'] ?? null;
$product_id = $data['produto_id'] ?? $data['product_id'] ?? $data['id'] ?? null;
if (!$product_id && isset($data['dados']['idProduto'])) {
    $product_id = $data['dados']['idProduto'];
}

// Eventos que interessam (Olist envia "tipo" em vez de "event")
$sync_events = ['produto.criado', 'produto.atualizado', 'produto.alterado', 'preco.alterado', 'estoque.alterado', 'estoque', 'preco', 'produto'];

if (in_array($event_type, $sync_events) || strpos($event_type, 'produto') !== false || strpos($event_type, 'estoque') !== false || strpos($event_type, 'preco') !== false) {
    // Disparar sincronização
    exec('php ' . dirname(__DIR__) . '/olist/sync-on-webhook.php > /dev/null 2>&1 &');

    // Enriquecer com estoque via API v3 OAuth (calculo correto, inclusive kits)
    exec('php ' . dirname(__DIR__) . '/olist/fetch-estoque-v3.php > /dev/null 2>&1 &');

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Webhook recebido e sincronização iniciada',
        'event' => $event_type,
        'product_id' => $product_id,
        'steps' => ['sync-v3', 'enrich-v3']
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Webhook ignorado (evento não monitorado)',
        'event' => $event_type,
    ]);
}
?>
