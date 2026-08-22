<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/account-chrome.php';
require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../includes/account-schema.php';
require_once __DIR__ . '/../../includes/tiny-order-push.php';

$user = sv_account_require_login();
sv_account_ensure_schema();

$orderId = (int)($_GET['order_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
if ($orderId <= 0 || !in_array($format, ['json', 'xml'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_request']);
    exit;
}

$pdo = sv_pdo();
$stmt = $pdo->prepare('SELECT id, user_id, order_number, olist_order_id, email, nf_id, nf_numero, nf_serie, nf_chave_acesso, nf_data_emissao FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute([':id' => $orderId, ':uid' => (int)$user['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($order)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'order_not_found']);
    exit;
}

$invoiceId = trim((string)($order['nf_id'] ?? ''));
if ($invoiceId === '') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invoice_not_available']);
    exit;
}

$token = svtop_tiny_get_token();
if ($token === '') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'erp_token_unavailable']);
    exit;
}

$detailsResp = svtop_tiny_get_invoice($invoiceId, $token);
if (($detailsResp['status'] ?? 0) !== 200 || !is_array($detailsResp['json'] ?? null)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'erp_invoice_fetch_failed', 'status' => (int)($detailsResp['status'] ?? 0)]);
    exit;
}
$details = $detailsResp['json'];
$xml = '';
$xmlResp = svtop_tiny_get_invoice_xml($invoiceId, $token);
if (($xmlResp['status'] ?? 0) === 200 && is_array($xmlResp['json'] ?? null)) {
    $xml = (string)($xmlResp['json']['xmlNfe'] ?? '');
}

$nfNumero = (string)($details['numero'] ?? $order['nf_numero'] ?? '');
$nfSerie = (string)($details['serie'] ?? $order['nf_serie'] ?? '');
$nfChave = (string)($details['chaveAcesso'] ?? $order['nf_chave_acesso'] ?? '');
$nfEmissao = (string)($details['dataEmissao'] ?? $order['nf_data_emissao'] ?? '');
$publicXmlUrl = '/api/account/invoice.php?order_id=' . $orderId . '&format=xml';
$update = $pdo->prepare('UPDATE orders SET nf_numero = COALESCE(NULLIF(:numero, ""), nf_numero), nf_serie = COALESCE(NULLIF(:serie, ""), nf_serie), nf_chave_acesso = COALESCE(NULLIF(:chave, ""), nf_chave_acesso), nf_data_emissao = COALESCE(NULLIF(:emissao, ""), nf_data_emissao), nf_xml_url = :xml_url, updated_at = NOW() WHERE id = :id AND user_id = :uid');
$update->execute([
    ':numero' => $nfNumero,
    ':serie' => $nfSerie,
    ':chave' => $nfChave,
    ':emissao' => $nfEmissao,
    ':xml_url' => $xml !== '' ? $publicXmlUrl : null,
    ':id' => $orderId,
    ':uid' => (int)$user['id'],
]);

if ($format === 'xml') {
    if ($xml === '') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'invoice_xml_not_available']);
        exit;
    }
    $filename = 'nfe-' . preg_replace('/[^0-9A-Za-z_-]+/', '-', (string)($order['order_number'] ?: $invoiceId)) . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $xml;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'invoice' => [
        'id' => $invoiceId,
        'numero' => $nfNumero,
        'serie' => $nfSerie,
        'chave_acesso' => $nfChave,
        'data_emissao' => $nfEmissao,
        'xml_available' => $xml !== '',
        'xml_url' => $xml !== '' ? $publicXmlUrl : null,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
