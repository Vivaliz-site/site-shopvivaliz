<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
header('Content-Type: text/html; charset=UTF-8');

// Fonte primaria: arquivos storage/orders/*.json, criados por api/orders/create-v2.php
// e atualizados por api/webhook-mercadopago.php. A tabela MySQL `orders` (schema
// legado divergente, ver includes/account-schema.php) e usada apenas para
// complementar o status mais recente quando disponivel.
$statusFromDb = [];
try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    $stmt = $pdo->query('SELECT order_number, order_status, olist_order_id FROM orders WHERE order_number IS NOT NULL');
    foreach ($stmt->fetchAll() as $row) {
        $statusFromDb[$row['order_number']] = $row;
    }
} catch (Throwable $e) {
    error_log('[admin/pedidos] Falha ao ler status do MySQL: ' . $e->getMessage());
}

$statusLabels = [
    'pending_confirmation' => 'Aguardando Confirmação',
    'payment_pending' => 'Aguardando Pagamento',
    'payment_approved' => 'Pagamento Aprovado',
    'aguardando_pagamento' => 'Aguardando Pagamento',
    'pagamento_aprovado' => 'Pagamento Aprovado',
    'nota_fiscal_enviada' => 'Nota Fiscal Enviada',
    'pronto_para_enviar' => 'Pronto para Enviar',
    'enviado' => 'Enviado',
    'entregue' => 'Entregue',
    'cancelado' => 'Cancelado',
    'devolvido' => 'Devolvido',
];

$pedidos = [];
$ordersDir = dirname(__DIR__) . '/storage/orders';
$files = is_dir($ordersDir) ? glob($ordersDir . '/*.json') : [];
rsort($files);

foreach (array_slice($files, 0, 100) as $file) {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        continue;
    }

    $orderNumber = (string)($data['order_number'] ?? basename($file, '.json'));
    $dbRow = $statusFromDb[$orderNumber] ?? null;
    $status = $dbRow['order_status'] ?? (string)($data['status'] ?? 'pending_confirmation');
    $tinyOrderId = $dbRow['olist_order_id'] ?? ($data['tiny_order_id'] ?? null);

    $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
    $pedidos[] = [
        'id' => $orderNumber,
        'cliente' => [
            'nome' => $customer['name'] ?? '',
            'email' => $customer['email'] ?? '',
            'telefone' => $customer['phone'] ?? '',
            'endereco' => $customer['address'] ?? $customer['street_name'] ?? '',
            'numero' => $customer['street_number'] ?? '',
            'complemento' => '',
            'cidade' => $customer['city'] ?? '',
            'cep' => $customer['cep'] ?? '',
        ],
        'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        'payment_method' => $data['payment_label'] ?? $data['payment_method'] ?? '',
        'status' => $status,
        'timestamp' => $data['created_at'] ?? '',
        'shipping_total' => $data['shipping_total'] ?? 0,
        'shipping_label' => $data['shipping_label'] ?? '',
        'tiny_order_id' => $tinyOrderId,
        'tiny_push' => $data['tiny_push'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Admin Vivaliz</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 20px; }
        h1 { color: #173B63; margin-bottom: 4px; }
        .subtitle { color: #64748b; margin-bottom: 24px; font-size: 14px; }
        .order-card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
        .order-id { font-weight: 700; color: #173B63; font-size: 16px; }
        .order-date { font-size: 13px; color: #64748b; }
        .status { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; background: #fef3c7; color: #92400e; }
        .status.paid { background: #dcfce7; color: #166534; }
        .client-info { font-size: 14px; margin-bottom: 12px; line-height: 1.6; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .items-table th { background: #f1f5f9; padding: 8px 12px; text-align: left; }
        .items-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .total-row { font-weight: 700; color: #173B63; }
        .wpp-btn { display: inline-block; padding: 8px 16px; background: #25D366; color: white; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; margin-top: 12px; }
        .empty { text-align: center; padding: 60px 20px; color: #64748b; }
        .count-badge { background: #173B63; color: white; padding: 4px 10px; border-radius: 20px; font-size: 13px; margin-left: 8px; }
        .erp-warn { color: #b91c1c; }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <h1>Pedidos <span class="count-badge"><?= count($pedidos) ?></span></h1>
    <p class="subtitle">Pedidos recebidos pelo checkout da Vivaliz · <a href="/admin/">← Admin</a></p>

    <?php if (!$pedidos): ?>
        <div class="empty">
            <p style="font-size:48px;">📋</p>
            <h2>Nenhum pedido ainda</h2>
            <p>Pedidos aparecerão aqui quando clientes finalizarem o checkout.</p>
        </div>
    <?php else: ?>
        <?php foreach ($pedidos as $p):
            $cliente = $p['cliente'] ?? [];
            $items   = $p['items']   ?? [];
            $subtotal = array_reduce($items, function ($s, $i) {
                return $s + (float)($i['price'] ?? 0) * (int)($i['quantity'] ?? 1);
            }, 0.0);
            $shippingTotal = (float)($p['shipping_total'] ?? 0);
            $total = $subtotal + $shippingTotal;
            $dt = $p['timestamp'] ?? '';
            try { $dtFmt = (new DateTime($dt))->format('d/m/Y H:i'); } catch (\Throwable $e) { $dtFmt = $dt; }
            $wppItems = implode(', ', array_map(fn($i) => ($i['name'] ?? '') . ' x' . ($i['quantity'] ?? 1), $items));
            $wppMsg = rawurlencode("Ola {$cliente['nome']}! Seu pedido {$p['id']} foi recebido pela Vivaliz.\nItens: {$wppItems}\nTotal: R$" . number_format($total, 2, ',', '.') . "\nEntre em contato para confirmar pagamento. Obrigado!");
            $wppTel = preg_replace('/\D/', '', $cliente['telefone'] ?? '');
            if (strlen($wppTel) === 11) $wppTel = '55' . $wppTel;
            $isPaid = in_array($p['status'], ['payment_approved', 'pagamento_aprovado', 'nota_fiscal_enviada', 'pronto_para_enviar', 'enviado', 'entregue'], true);
            $statusLabel = $statusLabels[$p['status']] ?? ucfirst(str_replace('_', ' ', $p['status'] ?? 'pendente'));
        ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-id"><?= htmlspecialchars($p['id'] ?? '-') ?></div>
                    <div class="order-date"><?= htmlspecialchars($dtFmt) ?></div>
                </div>
                <span class="status<?= $isPaid ? ' paid' : '' ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
            <div class="client-info">
                <strong><?= htmlspecialchars($cliente['nome'] ?? '') ?></strong><br>
                📧 <?= htmlspecialchars($cliente['email'] ?? '') ?> &nbsp;|&nbsp;
                📱 <?= htmlspecialchars($cliente['telefone'] ?? '') ?><br>
                📍 <?= htmlspecialchars(($cliente['endereco'] ?? '') . ', ' . ($cliente['numero'] ?? '') . ' ' . ($cliente['complemento'] ?? '') . ' — ' . ($cliente['cidade'] ?? '') . ' CEP ' . ($cliente['cep'] ?? '')) ?>
            </div>
            <table class="items-table">
                <thead><tr><th>Produto</th><th>SKU</th><th>Qtd</th><th>Preço unit.</th><th>Subtotal</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $price = (float)($item['price'] ?? 0);
                        $qty   = (int)($item['quantity'] ?? 1);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(substr($item['name'] ?? '-', 0, 50)) ?></td>
                        <td><?= htmlspecialchars($item['sku'] ?? '-') ?></td>
                        <td><?= $qty ?></td>
                        <td><?= $price > 0 ? 'R$ ' . number_format($price, 2, ',', '.') : 'sob consulta' ?></td>
                        <td><?= $price > 0 ? 'R$ ' . number_format($price * $qty, 2, ',', '.') : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4" style="text-align:right;padding-right:16px;">SUBTOTAL</td>
                        <td><?= $subtotal > 0 ? 'R$ ' . number_format($subtotal, 2, ',', '.') : 'sob consulta' ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="4" style="text-align:right;padding-right:16px;">FRETE</td>
                        <td><?= $shippingTotal > 0 ? 'R$ ' . number_format($shippingTotal, 2, ',', '.') : 'a confirmar' ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="4" style="text-align:right;padding-right:16px;">TOTAL</td>
                        <td><?= $total > 0 ? 'R$ ' . number_format($total, 2, ',', '.') : 'sob consulta' ?></td>
                    </tr>
                </tbody>
            </table>
            <?php if (!empty($p['shipping_label'])): ?>
                <p style="margin-top:12px;font-size:13px;color:#475569;"><strong>Entrega:</strong> <?= htmlspecialchars((string)$p['shipping_label']) ?></p>
            <?php endif; ?>
            <?php if (!empty($p['tiny_order_id'])): ?>
                <p style="margin-top:8px;font-size:13px;color:#475569;"><strong>ERP:</strong> Pedido Tiny #<?= htmlspecialchars((string)$p['tiny_order_id']) ?></p>
            <?php elseif ($isPaid): ?>
                <p style="margin-top:8px;font-size:13px;" class="erp-warn"><strong>⚠️ ERP:</strong> Pago mas não enviado ao Tiny (<?= htmlspecialchars((string)($p['tiny_push'] ?: 'motivo desconhecido')) ?>)</p>
            <?php endif; ?>
            <?php if ($wppTel): ?>
            <a class="wpp-btn" href="https://wa.me/<?= htmlspecialchars($wppTel) ?>?text=<?= $wppMsg ?>" target="_blank" rel="noreferrer">📱 Contatar pelo WhatsApp</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
