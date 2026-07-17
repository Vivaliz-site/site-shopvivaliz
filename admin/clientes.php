<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

$ordersDir = dirname(__DIR__) . '/storage/orders';
$files = is_dir($ordersDir) ? glob($ordersDir . '/*.json') : [];

$clientes = [];
foreach ($files as $file) {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        continue;
    }
    $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
    $email = trim((string)($customer['email'] ?? ''));
    $key = $email !== '' ? strtolower($email) : trim((string)($customer['phone'] ?? ''));
    if ($key === '') {
        continue;
    }

    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $subtotal = array_reduce($items, fn($s, $i) => $s + (float)($i['price'] ?? 0) * (int)($i['quantity'] ?? 1), 0.0);
    $total = $subtotal + (float)($data['shipping_total'] ?? 0);
    $status = (string)($data['status'] ?? '');
    $isPaid = in_array($status, ['payment_approved', 'pagamento_aprovado', 'nota_fiscal_enviada', 'pronto_para_enviar', 'enviado', 'entregue'], true);

    if (!isset($clientes[$key])) {
        $clientes[$key] = [
            'nome' => $customer['name'] ?? '(sem nome)',
            'email' => $email,
            'telefone' => $customer['phone'] ?? '',
            'pedidos' => 0,
            'total_gasto' => 0.0,
        ];
    }
    $clientes[$key]['pedidos']++;
    if ($isPaid) {
        $clientes[$key]['total_gasto'] += $total;
    }
}

uasort($clientes, fn($a, $b) => $b['total_gasto'] <=> $a['total_gasto']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 2rem; margin-bottom: 2rem; color: #333; }
        .clients-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .clients-table th { background: #f8f9fa; padding: 1rem; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6; }
        .clients-table td { padding: 1rem; border-bottom: 1px solid #dee2e6; }
        .clients-table tr:hover { background: #f8f9fa; }
        .empty-state { text-align: center; padding: 3rem; color: #666; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>🛍️ ShopVivaliz Admin / Clientes</div>
                <a href="/admin/" style="color: white; text-decoration: none;">← Voltar</a>
            </div>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">Gestão de Clientes (<?= count($clientes) ?>)</h1>

        <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <table class="clients-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Telefone</th>
                        <th>Pedidos</th>
                        <th>Total Pago</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clientes): ?>
                        <tr><td colspan="5" class="empty-state">Nenhum cliente ainda. Clientes aparecem aqui após o primeiro pedido no checkout.</td></tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['nome']) ?></td>
                            <td><?= htmlspecialchars($c['email']) ?></td>
                            <td><?= htmlspecialchars($c['telefone']) ?></td>
                            <td><?= (int)$c['pedidos'] ?></td>
                            <td>R$ <?= number_format($c['total_gasto'], 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
