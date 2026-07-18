<?php

declare(strict_types=1);

/**
 * Recebe o webhook "Notas fiscais autorizadas" da Tiny ERP e, quando a NF do
 * pedido e emitida, dispara a compra/geracao da etiqueta Melhor Envio -- em
 * vez de gerar a etiqueta na aprovacao do pagamento (como api/webhook-mercadopago.php
 * fazia ate agora). Ver docs/TINY-ERP-API-V3.md secao "Webhooks" pra config
 * manual necessaria no painel da Tiny (o app "Webhooks" so pode ser
 * configurado pela UI, nao ha endpoint de API pra isso).
 *
 * Configuracao no painel Tiny: Menu -> Configuracoes -> Aba Geral ->
 * Outras configuracoes -> Webhooks -> "Notificacoes de notas fiscais
 * autorizadas" -> URL: https://dev.shopvivaliz.com.br/api/webhooks/tiny-nota-fiscal.php?token=<TINY_WEBHOOK_SECRET>
 */

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';

function svtnf_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svtnf_response(405, 'method_not_allowed');
}

$expectedToken = svtop_env('TINY_WEBHOOK_SECRET');
if ($expectedToken === '') {
    error_log('[TinyWebhook] webhook unavailable: TINY_WEBHOOK_SECRET nao configurado');
    svtnf_response(503, 'webhook_unconfigured');
}
$receivedToken = trim((string)($_GET['token'] ?? ''));
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    error_log('[TinyWebhook] webhook rejeitado: token invalido ou ausente');
    svtnf_response(401, 'invalid_token');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 50000) {
    svtnf_response(413, 'payload_too_large');
}
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

// Formato exato do payload da Tiny pra este evento ainda nao foi observado
// ao vivo (o app "Webhooks" precisa estar configurado na conta pra isso
// acontecer) -- tenta os campos mais prováveis com base no padrao dos demais
// eventos documentados pela Tiny (dados.id / dados.idPedido / id / idPedido).
// Loga o payload cru sempre que o pedido nao for localizado, pra ajustar o
// parsing com um exemplo real assim que o primeiro webhook chegar.
$dados = is_array($body['dados'] ?? null) ? $body['dados'] : [];
$tinyOrderId = trim((string)(
    $dados['idPedido']
    ?? $dados['pedido']['id']
    ?? $dados['id']
    ?? $body['idPedido']
    ?? $body['id']
    ?? ''
));

if ($tinyOrderId === '') {
    error_log('[TinyWebhook] payload sem id de pedido reconhecivel: ' . substr($raw, 0, 2000));
    svtnf_response(200, 'ignored_no_order_id');
}

$orderPath = svtnf_find_order_by_tiny_id($tinyOrderId);
if ($orderPath === '') {
    error_log('[TinyWebhook] pedido Tiny id=' . $tinyOrderId . ' nao corresponde a nenhum pedido local (nao gerenciado pelo site ou etiqueta ja gerada)');
    svtnf_response(200, 'order_not_found_locally');
}

$order = json_decode((string)file_get_contents($orderPath), true);
if (!is_array($order)) {
    svtnf_response(200, 'order_file_invalid');
}

// A idempotencia real (nao repetir a compra) e garantida dentro de
// svml_purchase_and_generate_label() via a tabela orders (melhorenvio_shipment_id
// + label_url) -- ver includes/melhorenvio-label.php. Disparar de novo aqui
// e seguro, so reexecuta o processo em background e ele mesmo detecta que
// ja tem etiqueta e retorna cedo.
error_log('[TinyWebhook] NF autorizada para pedido Tiny id=' . $tinyOrderId . ', disparando geracao de etiqueta: ' . $orderPath);

$labelCmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/melhorenvio/generate-label-background.php') . ' ' .
            escapeshellarg($orderPath);
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    pclose(popen('start /B ' . $labelCmd, 'r'));
} else {
    exec($labelCmd . ' > /dev/null 2>&1 &');
}

svtnf_response(200, 'label_queued');

/**
 * Varre storage/orders (e o diretorio temporario de fallback) procurando o
 * pedido local cujo tiny_order_id bate com o id recebido no webhook.
 */
function svtnf_find_order_by_tiny_id(string $tinyOrderId): string
{
    foreach (svmp_order_directories() as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'SV*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            if ((string)($data['tiny_order_id'] ?? '') === $tinyOrderId) {
                return $file;
            }
        }
    }
    return '';
}
