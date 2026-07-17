<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Authentication and payload validation must not depend on database startup.
// This guarantees deterministic 4xx responses even during a DB outage.
require_once __DIR__ . '/../../config/bootstrap-env.php';
require_once __DIR__ . '/../../api/emails/send-order-notification.php';

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

$olist_order_id = $data['order_id'] ?? $data['olist_id'] ?? $data['id'] ?? '';
$status = $data['status'] ?? $data['order_status'] ?? '';
if (empty($olist_order_id) || empty($status)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../scripts/mailer.php';

try {
    $db = Database::getInstance()->getConnection();

    // Mapear IDs: olist_order_id -> pedido_id
    $tracking = $data['tracking_number'] ?? $data['tracking'] ?? '';
    $estimated_delivery = $data['estimated_delivery_date'] ?? '';

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
        'SELECT p.id, p.user_id, p.email, p.order_status, u.email as user_email, u.name
         FROM orders p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.olist_order_id = ? LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->error);
    }

    $stmt->bind_param('s', $olist_order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    // Atualizar status se for diferente
    if ($order['order_status'] !== $normalized_status) {
        $update = $db->prepare(
            'UPDATE orders SET order_status = ?, tracking_number = ?,
             estimated_delivery = ?, updated_at = NOW()
             WHERE id = ?'
        );

        if ($update) {
            $update->bind_param('sssi', $normalized_status, $tracking, $estimated_delivery, $order['id']);
            $update->execute();

            // Enviar email para cliente
            $customer_email = $order['user_email'] ?? $order['email'];
            if ($customer_email) {
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
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'order_id' => $order['id'],
        'status' => $normalized_status,
        'tracking' => $tracking,
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

    $html .= "<p><a href='https://dev.shopvivaliz.com.br/meus-pedidos' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Ver Detalhes do Pedido</a></p>";
    $html .= "<p>Obrigado por sua compra!</p>";

    // Usar função de envio de email
    send_email(
        to: $email,
        subject: $subject,
        html: $html
    );
}
