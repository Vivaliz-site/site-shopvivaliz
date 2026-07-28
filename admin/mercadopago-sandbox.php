<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/mercadopago-sandbox.php';
header('Content-Type: text/html; charset=UTF-8');

$resultFlag = trim((string)($_GET['result'] ?? ''));
$sandboxConfigured = svmp_sb_access_token() !== '' && svmp_sb_public_key() !== '';
$ordersDir = dirname(__DIR__) . '/storage/orders-sandbox';
$files = is_dir($ordersDir) ? glob($ordersDir . '/*.json') : [];
rsort($files);
$recentOrders = [];
foreach (array_slice($files, 0, 10) as $file) {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        continue;
    }
    $recentOrders[] = [
        'order_number' => (string)($data['order_number'] ?? basename($file, '.json')),
        'status' => (string)($data['status'] ?? ''),
        'total' => (float)($data['total'] ?? 0),
        'email' => (string)($data['customer']['email'] ?? ''),
        'checkout_url' => (string)($data['mercadopago']['preference']['checkout_url'] ?? ''),
        'payment_id' => (string)($data['mercadopago']['payment_id'] ?? ''),
        'created_at' => (string)($data['created_at'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercado Pago Sandbox - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f8fafc; color:#1e293b; margin:0; padding:24px; }
        .card { background:#fff; border:1px solid #dbe5ef; border-radius:12px; padding:20px; margin-bottom:18px; }
        .badge { display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:700; background:#e0f2fe; color:#075985; }
        .warn { background:#fff7ed; color:#9a3412; }
        label { display:block; margin-bottom:12px; font-weight:600; }
        input { width:100%; box-sizing:border-box; padding:10px; margin-top:6px; border:1px solid #cbd5e1; border-radius:8px; }
        button { background:#173B63; color:#fff; border:0; border-radius:8px; padding:12px 16px; cursor:pointer; font-weight:700; }
        table { width:100%; border-collapse:collapse; }
        th, td { border-bottom:1px solid #e2e8f0; padding:10px; text-align:left; font-size:14px; }
        .grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; }
        .small { color:#64748b; font-size:13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Mercado Pago Sandbox</h1>
        <p class="small">Fluxo isolado de teste. Não usa endpoints produtivos do checkout do site.</p>
        <p><a href="/admin/">← voltar ao admin</a></p>
        <?php if ($resultFlag !== ''): ?>
            <p class="badge"><?= htmlspecialchars(strtoupper($resultFlag)) ?></p>
        <?php endif; ?>
        <p class="badge<?= $sandboxConfigured ? '' : ' warn' ?>">
            <?= $sandboxConfigured ? 'Credenciais sandbox detectadas' : 'Faltam credenciais sandbox no ambiente' ?>
        </p>
    </div>

    <div class="card">
        <h2>Criar checkout de teste</h2>
        <form id="sandbox-form">
            <div class="grid">
                <label>Nome
                    <input name="customer_name" value="Comprador Sandbox ShopVivaliz" required>
                </label>
                <label>E-mail
                    <input name="customer_email" type="email" value="shopvivaliz@gmail.com" required>
                </label>
                <label>Telefone
                    <input name="customer_phone" value="37999374112">
                </label>
                <label>CPF
                    <input name="cpf" value="01366995619">
                </label>
                <label>CEP
                    <input name="cep" value="35501236">
                </label>
                <label>Endereço
                    <input name="address" value="Rua Campina Verde">
                </label>
                <label>Número
                    <input name="street_number" value="841">
                </label>
                <label>Bairro
                    <input name="neighborhood" value="Sao Jose">
                </label>
                <label>Cidade
                    <input name="city" value="Divinopolis">
                </label>
                <label>UF
                    <input name="state" value="MG">
                </label>
                <label>Título do item
                    <input name="title" value="Pedido de teste Mercado Pago Sandbox">
                </label>
                <label>SKU
                    <input name="sku" value="sandbox-item">
                </label>
                <label>Quantidade
                    <input name="quantity" type="number" min="1" value="1">
                </label>
                <label>Preço unitário
                    <input name="unit_price" type="number" min="0.01" step="0.01" value="21.36">
                </label>
                <label>Frete
                    <input name="shipping_total" type="number" min="0" step="0.01" value="0.00">
                </label>
            </div>
            <button type="submit">Gerar checkout sandbox</button>
            <p id="sandbox-status" class="small"></p>
        </form>
    </div>

    <div class="card">
        <h2>Últimos pedidos sandbox</h2>
        <table>
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>E-mail</th>
                    <th>Payment ID</th>
                    <th>Checkout</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentOrders === []): ?>
                    <tr><td colspan="6">Nenhum pedido sandbox ainda.</td></tr>
                <?php else: foreach ($recentOrders as $order): ?>
                    <tr>
                        <td><?= htmlspecialchars($order['order_number']) ?></td>
                        <td><?= htmlspecialchars($order['status']) ?></td>
                        <td>R$ <?= number_format($order['total'], 2, ',', '.') ?></td>
                        <td><?= htmlspecialchars($order['email']) ?></td>
                        <td><?= htmlspecialchars($order['payment_id']) ?></td>
                        <td>
                            <?php if ($order['checkout_url'] !== ''): ?>
                                <a href="<?= htmlspecialchars($order['checkout_url']) ?>" target="_blank" rel="noopener">abrir</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    document.getElementById('sandbox-form').addEventListener('submit', async function (event) {
        event.preventDefault();
        const status = document.getElementById('sandbox-status');
        status.textContent = 'Gerando checkout sandbox...';
        const payload = Object.fromEntries(new FormData(event.target).entries());
        try {
            const response = await fetch('/api/sandbox/create-preference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                status.textContent = 'Falha: ' + (data.error || 'erro desconhecido');
                return;
            }
            status.innerHTML = 'Pedido ' + data.order_number + ' criado. <a href=\"' + data.checkout_url + '\" target=\"_blank\" rel=\"noopener\">Abrir checkout sandbox</a>';
            window.setTimeout(() => window.location.reload(), 1500);
        } catch (error) {
            status.textContent = 'Falha de conexão ao gerar checkout sandbox.';
        }
    });
    </script>
</body>
</html>
