<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Authentication and payload validation must not depend on database startup.
// This guarantees deterministic 4xx responses even during a DB outage.
require_once __DIR__ . '/../../config/bootstrap-env.php';
require_once __DIR__ . '/../../api/emails/send-order-notification.php';
require_once __DIR__ . '/../../includes/tiny-order-push.php';

function svtnf_first_non_empty_string(array $values): string
{
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function svtnf_extract_payload(array $data): array
{
    return is_array($data['dados'] ?? null) ? $data['dados'] : [];
}

function svtnf_extract_order_reference(array $data): string
{
    $dados = svtnf_extract_payload($data);
    return svtnf_first_non_empty_string([
        $dados['idPedidoEcommerce'] ?? '',
        $dados['idVendaTiny'] ?? '',
        $dados['pedido']['id'] ?? '',
        $data['idPedidoEcommerce'] ?? '',
        $data['idVendaTiny'] ?? '',
        $data['idPedido'] ?? '',
        $data['order_id'] ?? '',
        $data['olist_id'] ?? '',
        $data['id'] ?? '',
    ]);
}

function svtnf_extract_status(array $data): string
{
    $dados = svtnf_extract_payload($data);
    $raw = svtnf_first_non_empty_string([
        $dados['situacao'] ?? '',
        $dados['descricaoSituacao'] ?? '',
        $data['status'] ?? '',
        $data['order_status'] ?? '',
    ]);

    if ($raw === '') {
        return '';
    }

    $map = [
        '8' => 'dados_incompletos',
        '0' => 'aguardando_pagamento',
        '3' => 'pagamento_aprovado',
        '4' => 'pronto_para_enviar',
        '1' => 'nota_fiscal_enviada',
        '7' => 'pronto_para_enviar',
        '5' => 'enviado',
        '6' => 'entregue',
        '2' => 'cancelado',
        '9' => 'nao_entregue',
        'dados incompletos' => 'dados_incompletos',
        'aberta' => 'aguardando_pagamento',
        'aprovada' => 'pagamento_aprovado',
        'preparando envio' => 'pronto_para_enviar',
        'faturada' => 'nota_fiscal_enviada',
        'pronto envio' => 'pronto_para_enviar',
        'enviada' => 'enviado',
        'entregue' => 'entregue',
        'cancelada' => 'cancelado',
        'nao entregue' => 'nao_entregue',
        'não entregue' => 'nao_entregue',
    ];

    $normalized = strtolower(trim($raw));
    return $map[$normalized] ?? $normalized;
}

function svtnf_extract_tracking(array $data): string
{
    $dados = svtnf_extract_payload($data);
    return svtnf_first_non_empty_string([
        $dados['codigoRastreio'] ?? '',
        $dados['tracking'] ?? '',
        $dados['urlRastreio'] ?? '',
        $data['tracking_number'] ?? '',
        $data['tracking'] ?? '',
    ]);
}

function svtnf_extract_estimated_delivery(array $data): string
{
    $dados = svtnf_extract_payload($data);
    return svtnf_first_non_empty_string([
        $dados['dataPrevistaEntrega'] ?? '',
        $dados['estimated_delivery'] ?? '',
        $dados['estimated_delivery_date'] ?? '',
        $data['estimated_delivery_date'] ?? '',
        $data['prazoEntrega'] ?? '',
    ]);
}

function svtnf_site_base_url(): string
{
    $official = @include dirname(__DIR__, 2) . '/config/official-site.php';
    if (is_array($official) && !empty($official['base_url'])) {
        return rtrim((string)$official['base_url'], '/');
    }

    $configured = trim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return 'https://www.shopvivaliz.com.br';
}

// Validar token do webhook
$webhook_token = getenv('OLIST_WEBHOOK_TOKEN') ?: getenv('ERP_WEBHOOK_TOKEN') ?: '';

// O servidor nao repassa Authorization para $_SERVER['HTTP_AUTHORIZATION']
// (comportamento comum de Apache/PHP-FPM sem CGIPassAuth) -- confirmado ao
// vivo que o header chega via getallheaders() mas nao via $_SERVER, o que
// fazia esse endpoint rejeitar TODA chamada real da Tiny com 401, mesmo com
// o token correto configurado.
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth_header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'Authorization') === 0) {
            $auth_header = $headerValue;
            break;
        }
    }
}

// O painel de Webhooks da Tiny (Configuracoes > Webhooks) so aceita uma URL
// por evento, sem campo de header customizado -- entao nao ha como a Tiny
// mandar "Authorization: Bearer ...". Aceita o token tambem via query string
// (?token=...) pra poder embutir na propria URL cadastrada no painel.
$provided_token = '';
if ($auth_header !== '' && str_starts_with($auth_header, 'Bearer ')) {
    $provided_token = substr($auth_header, 7);
} elseif (isset($_GET['token'])) {
    $provided_token = (string)$_GET['token'];
}

if (empty($webhook_token) || $provided_token === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!hash_equals($webhook_token, $provided_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Obter payload
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$tipo = strtolower(trim((string)($data['tipo'] ?? $data['event'] ?? $data['type'] ?? '')));
$olist_order_id = svtnf_extract_order_reference($data);
$status = svtnf_extract_status($data);
$tracking = svtnf_extract_tracking($data);
$estimated_delivery = svtnf_extract_estimated_delivery($data);
$invoiceId = svtnf_extract_invoice_id($data);
$invoiceDetails = [];
$invoiceXml = [];

if ($tipo === 'nota_fiscal' && $status === '') {
    $status = 'nota_fiscal_enviada';
}
if ($tipo === 'rastreio' && $status === '') {
    $status = 'enviado';
}

if (empty($olist_order_id) || empty($status)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../scripts/mailer.php';
require_once __DIR__ . '/../../includes/mercadopago-gateway.php';
require_once __DIR__ . '/../../includes/account-schema.php';

try {
    $db = Database::getInstance()->getConnection();
    sv_account_ensure_schema();

    // Mapear status do Olist para nosso sistema
    $status_map = [
        'waiting_payment' => 'aguardando_pagamento',
        'payment_approved' => 'pagamento_aprovado',
        'invoice_sent' => 'nota_fiscal_enviada',
        'invoiced' => 'nota_fiscal_enviada',
        'ready_to_ship' => 'pronto_para_enviar',
        'shipped' => 'enviado',
        'delivered' => 'entregue',
        'cancellation_requested' => 'cancelamento_solicitado',
        'cancelled' => 'cancelado',
        'returned' => 'devolvido',
    ];

    $normalized_status = $status_map[$status] ?? $status;

    // Buscar pedido
    $stmt = $db->prepare(
        'SELECT p.id, p.user_id, p.email, p.order_status, p.order_number, u.email as user_email, u.name
         FROM orders p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.olist_order_id = ? OR p.order_number = ? LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->error);
    }

    $stmt->bind_param('ss', $olist_order_id, $olist_order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    $orderPath = $orderNumber !== '' ? svmp_find_order_path($orderNumber) : '';
    $orderId = (int)$order['id'];
    $statusChanged = $order['order_status'] !== $normalized_status;

    $shouldPersist = $order['order_status'] !== $normalized_status
        || $tracking !== ''
        || $estimated_delivery !== ''
        || $invoiceId !== '';

    // Atualizar status/dados se houve mudanca ou se o webhook trouxe NF/rastreio novos.
    if ($shouldPersist) {
        $update = $db->prepare(
            'UPDATE orders SET order_status = ?,
             tracking_number = COALESCE(NULLIF(?, ""), tracking_number),
             estimated_delivery = COALESCE(NULLIF(?, ""), estimated_delivery),
             nf_id = COALESCE(NULLIF(?, ""), nf_id),
             nf_numero = COALESCE(NULLIF(?, ""), nf_numero),
             nf_serie = COALESCE(NULLIF(?, ""), nf_serie),
             nf_chave_acesso = COALESCE(NULLIF(?, ""), nf_chave_acesso),
             nf_data_emissao = COALESCE(NULLIF(?, ""), nf_data_emissao),
             updated_at = NOW()
             WHERE id = ?'
        );

        if ($update) {
            $nfDataEmissao = '';
            if ($invoiceId !== '' && svtop_tiny_credentials_configured()) {
                try {
                    $token = svtop_tiny_get_token();
                    if ($token !== '') {
                        $invoiceResp = svtop_tiny_get_invoice($invoiceId, $token);
                        if (($invoiceResp['status'] ?? 0) === 200 && is_array($invoiceResp['json'] ?? null)) {
                            $invoiceDetails = $invoiceResp['json'];
                            $invoiceXmlResp = svtop_tiny_get_invoice_xml($invoiceId, $token);
                            if (($invoiceXmlResp['status'] ?? 0) === 200 && is_array($invoiceXmlResp['json'] ?? null)) {
                                $invoiceXml = $invoiceXmlResp['json'];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[TinyWebhook] invoice fetch failed: ' . $e->getMessage());
                }
            }

            $nfDataEmissao = (string)($invoiceDetails['dataEmissao'] ?? '');
            $nfId = (string)($invoiceDetails['id'] ?? $invoiceId);
            $nfNumero = (string)($invoiceDetails['numero'] ?? '');
            $nfSerie = (string)($invoiceDetails['serie'] ?? '');
            $nfChave = (string)($invoiceDetails['chaveAcesso'] ?? '');

            $update->bind_param(
                'ssssssssi',
                $normalized_status,
                $tracking,
                $estimated_delivery,
                $nfId,
                $nfNumero,
                $nfSerie,
                $nfChave,
                $nfDataEmissao,
                $orderId
            );
            $update->execute();

            if ($orderPath !== '') {
                svtnf_update_local_order_file($orderPath, $normalized_status, $tracking, $estimated_delivery, $invoiceDetails, $invoiceXml);
            }

            // Enviar email para cliente
            $customer_email = $order['user_email'] ?? $order['email'];
            if ($statusChanged && $customer_email) {
                send_order_status_email(
                    email: $customer_email,
                    name: $order['name'] ?? 'Cliente',
                    order_id: $order['id'],
                    olist_id: $olist_order_id,
                    status: $normalized_status,
                    tracking: $tracking,
                    estimated_delivery: $estimated_delivery
                );
            }

            // Gatilho real da etiqueta Melhor Envio: NAO mais na aprovacao do
            // pagamento (api/webhook-mercadopago.php nao dispara mais isso),
            // e sim aqui, quando a Tiny confirma que a NF do pedido foi de
            // fato emitida -- essa URL (?type=invoice) ja esta cadastrada no
            // painel Tiny (Configuracoes > API do ERP > Notificacoes > URL
            // para envio da nota fiscal), so faltava agir sobre o evento.
            if ($normalized_status === 'nota_fiscal_enviada') {
                if ($orderPath !== '') {
                    $labelCmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/melhorenvio/generate-label-background.php') . ' ' .
                                escapeshellarg($orderPath);
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        pclose(popen('start /B ' . $labelCmd, 'r'));
                    } else {
                        exec($labelCmd . ' > /dev/null 2>&1 &');
                    }
                    error_log('[TinyWebhook] NF emitida, etiqueta disparada: order=' . $orderNumber . ' olist_order_id=' . $olist_order_id);
                } else {
                    error_log('[TinyWebhook] NF emitida mas pedido local nao encontrado: olist_order_id=' . $olist_order_id . ' order_number=' . $orderNumber);
                }
            }
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'status' => $normalized_status,
        'tracking' => $tracking,
        'invoice_id' => $invoiceId,
    ]);

} catch (Exception $e) {
    error_log('Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function send_order_status_email(
    string $email,
    string $name,
    int $order_id,
    string $olist_id,
    string $status,
    string $tracking,
    string $estimated_delivery
): void {
    $siteBaseUrl = svtnf_site_base_url();
    $status_labels = [
        'aguardando_pagamento' => 'Aguardando Pagamento',
        'pagamento_aprovado' => 'Pagamento Aprovado',
        'nota_fiscal_enviada' => 'Nota Fiscal Enviada',
        'pronto_para_enviar' => 'Pronto para Enviar',
        'enviado' => 'Enviado',
        'entregue' => 'Entregue',
        'cancelado' => 'Cancelado',
    ];

    $status_label = $status_labels[$status] ?? ucfirst(str_replace('_', ' ', $status));

    $subject = "Atualização do seu Pedido #$order_id - $status_label";

    $html = "<h2>Oi $name,</h2>";
    $html .= "<p>Seu pedido <strong>#$order_id</strong> foi atualizado!</p>";
    $html .= "<p><strong>Status:</strong> $status_label</p>";

    if ($tracking) {
        $html .= "<p><strong>Código de Rastreamento:</strong> <code>$tracking</code></p>";
    }

    if ($estimated_delivery) {
        $html .= "<p><strong>Entrega Estimada:</strong> $estimated_delivery</p>";
    }

    $html .= "<p><a href='{$siteBaseUrl}/meus-pedidos' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Ver Detalhes do Pedido</a></p>";
    $html .= "<p>Obrigado por sua compra!</p>";

    // Usar função de envio de email
    send_email(
        to: $email,
        subject: $subject,
        html: $html
    );
}

function svtnf_extract_invoice_id(array $data): string
{
    $candidates = [
        $data['idNota'] ?? '',
        $data['idNotaFiscalTiny'] ?? '',
        $data['notaFiscal']['id'] ?? '',
        $data['nota_fiscal']['id'] ?? '',
        $data['invoice']['id'] ?? '',
        $data['nf']['id'] ?? '',
        $data['dados']['idNota'] ?? '',
        $data['dados']['idNotaFiscalTiny'] ?? '',
        $data['dados']['notaFiscal']['id'] ?? '',
        $data['dados']['nota_fiscal']['id'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function svtnf_update_local_order_file(
    string $path,
    string $status,
    string $tracking,
    string $estimatedDelivery,
    array $invoiceDetails,
    array $invoiceXml
): void {
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return;
    }

    try {
        rewind($handle);
        $order = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($order)) {
            $order = [];
        }

        $order['status'] = $status;
        $order['order_status'] = $status;
        if ($tracking !== '') {
            $order['tracking_number'] = $tracking;
            $order['tracking'] = $tracking;
        }
        if ($estimatedDelivery !== '') {
            $order['estimated_delivery'] = $estimatedDelivery;
        }
        if ($invoiceDetails !== []) {
            $order['tiny_invoice'] = [
                'id' => (string)($invoiceDetails['id'] ?? ''),
                'numero' => (string)($invoiceDetails['numero'] ?? ''),
                'serie' => (string)($invoiceDetails['serie'] ?? ''),
                'chaveAcesso' => (string)($invoiceDetails['chaveAcesso'] ?? ''),
                'dataEmissao' => (string)($invoiceDetails['dataEmissao'] ?? ''),
            ];
        }
        if ($invoiceXml !== []) {
            $order['tiny_invoice_xml'] = [
                'hasXmlNfe' => array_key_exists('xmlNfe', $invoiceXml),
                'hasXmlCancelamento' => array_key_exists('xmlCancelamento', $invoiceXml),
            ];
        }

        $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
    } catch (Throwable $e) {
        error_log('[TinyWebhook] local order update failed: ' . $e->getMessage());
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
