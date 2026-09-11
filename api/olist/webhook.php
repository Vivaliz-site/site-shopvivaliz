<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/webhook-secret.php';
require_once $root . '/includes/olist-erp-webhook.php';

function svoei_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svoei_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || strlen($raw) > 2_000_000) {
    svoei_response($raw === '' ? 400 : 413, ['ok' => false, 'error' => $raw === '' ? 'empty_payload' : 'payload_too_large']);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) svoei_response(400, ['ok' => false, 'error' => 'invalid_json']);

$tipo = strtolower(trim((string)($payload['tipo'] ?? '')));
if (!in_array($tipo, svoei_supported_types(), true)) {
    svoei_response(422, ['ok' => false, 'error' => 'unsupported_webhook_type']);
}
if (!sv_webhook_secret_gate('olist', false)) {
    svoei_response(401, ['ok' => false, 'error' => 'unauthorized']);
}

$data = is_array($payload['dados'] ?? null) ? $payload['dados'] : [];
$expectedIntegrationId = trim(svtop_env('OLIST_ERP_ECOMMERCE_ID'));
if ($expectedIntegrationId !== '' && (string)($payload['idEcommerce'] ?? '') !== $expectedIntegrationId) {
    svoei_response(403, ['ok' => false, 'error' => 'unexpected_ecommerce_id']);
}

try {
    switch ($tipo) {
        case 'produto':
            $productId = svoei_first_text([$data['id'] ?? '']);
            $erpSku = svoei_first_text([$data['codigo'] ?? '', $data['sku'] ?? '']);
            $product = svoei_refresh_product_from_v3($root, $productId, $erpSku);
            $mappings = svoei_product_mapping_response($data, 'https://shopvivaliz.com.br');
            svoei_log($root, 'product_synced', [
                'tipo' => $tipo,
                'sku' => (string)($product['sku'] ?? $erpSku),
                'product_id' => $productId,
            ]);
            http_response_code(200);
            echo json_encode($mappings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;

        case 'precos':
            $erpSku = svoei_first_text([$data['codigo'] ?? '', $data['sku'] ?? '', $data['skuMapeamento'] ?? '']);
            $product = svoei_refresh_product_from_v3($root, '', $erpSku);
            svoei_log($root, 'price_synced', ['tipo' => $tipo, 'sku' => (string)($product['sku'] ?? $erpSku)]);
            svoei_response(200, ['ok' => true, 'tipo' => $tipo, 'sku' => (string)($product['sku'] ?? $erpSku)]);

        case 'estoque':
            $productId = svoei_first_text([$data['idProduto'] ?? '', $data['id'] ?? '']);
            $mappedSku = svoei_first_text([$data['skuMapeamento'] ?? '', $data['sku'] ?? '']);
            $erpSku = svoei_first_text([$data['sku'] ?? '', $data['codigo'] ?? '']);
            $quantity = svoei_refresh_stock_from_v3($root, $productId, $mappedSku, $erpSku);
            svoei_log($root, 'stock_synced', ['tipo' => $tipo, 'sku' => $mappedSku, 'product_id' => $productId]);
            svoei_response(200, ['ok' => true, 'tipo' => $tipo, 'sku' => $mappedSku, 'saldo' => $quantity]);

        case 'situacao_pedido':
        case 'rastreio':
        case 'nota_fiscal':
            svoei_log($root, 'order_event_received', [
                'tipo' => $tipo,
                'order_id' => svoei_first_text([$data['idPedidoEcommerce'] ?? '', $data['idVendaTiny'] ?? '']),
                'status' => svoei_first_text([$data['situacao'] ?? '', $data['descricaoSituacao'] ?? '']),
            ]);
            if (!defined('SV_OLIST_INTERNAL_AUTHENTICATED')) define('SV_OLIST_INTERNAL_AUTHENTICATED', true);
            $GLOBALS['SV_OLIST_INTERNAL_PAYLOAD'] = $payload;
            require $root . '/api/webhooks/order-status-update.php';
            exit;

        case 'cotacao':
            $quotes = svoei_quote_freight($data);
            svoei_log($root, 'freight_quoted', ['tipo' => $tipo]);
            svoei_response(200, $quotes);
    }
} catch (InvalidArgumentException $e) {
    svoei_log($root, 'payload_rejected', ['tipo' => $tipo]);
    svoei_response(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    svoei_log($root, 'integration_failed', ['tipo' => $tipo]);
    svoei_response(503, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[olist-erp-webhook] unexpected_error type=' . get_class($e));
    svoei_log($root, 'unexpected_error', ['tipo' => $tipo]);
    svoei_response(500, ['ok' => false, 'error' => 'internal_error']);
}

svoei_response(422, ['ok' => false, 'error' => 'unsupported_webhook_type']);
