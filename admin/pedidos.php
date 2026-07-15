<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/html; charset=UTF-8');

$pedidos = [];

try {
    $db = Database::getInstance();
    $result = $db->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 100");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $orderItems = [];
            $itemsResult = $db->query("SELECT * FROM order_items WHERE order_id = '" . $db->escape($row['id']) . "'");
            if ($itemsResult instanceof mysqli_result) {
                while ($item = $itemsResult->fetch_assoc()) {
                    $orderItems[] = $item;
                }
            }

            $pedidos[] = [
                'id' => $row['id'],
                'cliente' => [
                    'nome' => $row['customer_name'],
                    'email' => $row['customer_email'],
                    'telefone' => $row['customer_phone'],
                    'endereco' => $row['customer_address'],
                    'cidade' => $row['customer_city'],
                    'cep' => $row['customer_zip'],
                ],
                'items' => $orderItems,
                'payment_method' => $row['payment_method'],
                'status' => $row['status'],
                'timestamp' => $row['created_at'],
                'total' => $row['total']
            ];
        }
    }
} catch (Exception $e) {
    error_log('Erro ao carregar pedidos: ' . $e->getMessage());
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
        .client-info { font-size: 14px; margin-bottom: 12px; line-height: 1.6; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .items-table th { background: #f1f5f9; padding: 8px 12px; text-align: left; }
        .items-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .total-row { font-weight: 700; color: #173B63; }
        .wpp-btn { display: inline-block; padding: 8px 16px; background: #25D366; color: white; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; margin-top: 12px; }
        .empty { text-align: center; padding: 60px 20px; color: #64748b; }
        .count-badge { background: #173B63; color: white; padding: 4px 10px; border-radius: 20px; font-size: 13px; margin-left: 8px; }
    </style>
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
            $wppItems = implode(', ', array_map(fn($i) => $i['name'] . ' x' . ($i['quantity'] ?? 1), $items));
            $wppMsg = rawurlencode("Ola {$cliente['nome']}! Seu pedido {$p['id']} foi recebido pela Vivaliz.\nItens: {$wppItems}\nTotal: R$" . number_format($total, 2, ',', '.') . "\nEntre em contato para confirmar pagamento. Obrigado!");
            $wppTel = preg_replace('/\D/', '', $cliente['telefone'] ?? '');
            if (strlen($wppTel) === 11) $wppTel = '55' . $wppTel;
        ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-id"><?= htmlspecialchars($p['id'] ?? '-') ?></div>
                    <div class="order-date"><?= htmlspecialchars($dtFmt) ?></div>
                </div>
                <span class="status"><?= htmlspecialchars(str_replace('_', ' ', $p['status'] ?? 'pendente')) ?></span>
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
            <?php if (!empty($p['tiny_order_id']) || !empty($p['tiny_push'])): ?>
                <p style="margin-top:8px;font-size:13px;color:#475569;"><strong>ERP:</strong> <?= htmlspecialchars((string)($p['tiny_order_id'] ?: $p['tiny_push'])) ?></p>
            <?php endif; ?>
            <?php if ($wppTel): ?>
            <a class="wpp-btn" href="https://wa.me/<?= htmlspecialchars($wppTel) ?>?text=<?= $wppMsg ?>" target="_blank" rel="noreferrer">📱 Contatar pelo WhatsApp</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
