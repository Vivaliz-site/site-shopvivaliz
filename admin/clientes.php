<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/database.php';

$clientes = [];

// Fonte principal: contas realmente cadastradas no site.
try {
    $db = Database::getInstance()->getConnection();
    $result = $db->query('SELECT id, name, email, phone, created_at FROM users ORDER BY created_at DESC LIMIT 2000');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $phone = trim((string)($row['phone'] ?? ''));
            $key = $email !== '' ? $email : ('user:' . (string)($row['id'] ?? $phone));
            $clientes[$key] = [
                'nome' => trim((string)($row['name'] ?? '')) ?: '(sem nome)',
                'email' => trim((string)($row['email'] ?? '')),
                'telefone' => $phone,
                'pedidos' => 0,
                'total_gasto' => 0.0,
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('[admin/clientes] users lookup failed: ' . $e->getMessage());
}

// Complementa as contas com dados dos pedidos e inclui compradores sem conta.
$ordersDir = dirname(__DIR__) . '/storage/orders';
$files = is_dir($ordersDir) ? (glob($ordersDir . '/*.json') ?: []) : [];
foreach ($files as $file) {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) continue;

    $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
    $email = trim((string)($customer['email'] ?? ''));
    $phone = trim((string)($customer['phone'] ?? ''));
    $key = $email !== '' ? strtolower($email) : ($phone !== '' ? 'phone:' . preg_replace('/\D/', '', $phone) : '');
    if ($key === '') continue;

    if (!isset($clientes[$key])) {
        $clientes[$key] = [
            'nome' => trim((string)($customer['name'] ?? '')) ?: '(sem nome)',
            'email' => $email,
            'telefone' => $phone,
            'pedidos' => 0,
            'total_gasto' => 0.0,
            'created_at' => '',
        ];
    } else {
        if ($clientes[$key]['telefone'] === '' && $phone !== '') $clientes[$key]['telefone'] = $phone;
        if ($clientes[$key]['nome'] === '(sem nome)' && !empty($customer['name'])) $clientes[$key]['nome'] = (string)$customer['name'];
    }

    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $subtotal = array_reduce($items, static fn(float $sum, array $item): float => $sum + (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1), 0.0);
    $total = $subtotal + (float)($data['shipping_total'] ?? 0);
    $status = strtolower((string)($data['status'] ?? ''));
    $isPaid = in_array($status, ['payment_approved','pagamento_aprovado','nota_fiscal_enviada','pronto_para_enviar','enviado','entregue'], true);
    $clientes[$key]['pedidos']++;
    if ($isPaid) $clientes[$key]['total_gasto'] += $total;
}

uasort($clientes, static function (array $a, array $b): int {
    $spent = $b['total_gasto'] <=> $a['total_gasto'];
    if ($spent !== 0) return $spent;
    return strcmp((string)$b['created_at'], (string)$a['created_at']);
});
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Admin</title>
    <link rel="icon" type="image/png" href="/images/logo-vivaliz-square-v2.png">
    <link rel="apple-touch-icon" href="/images/logo-vivaliz-square-v2.png">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body{background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto}.navbar{background:#1a1a2e;padding:1rem;color:#fff}.container{max-width:1200px;margin:0 auto;padding:2rem}.page-title{font-size:2rem;margin-bottom:2rem;color:#333}.clients-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}.clients-table th{background:#f8f9fa;padding:1rem;text-align:left;font-weight:600;border-bottom:2px solid #dee2e6}.clients-table td{padding:1rem;border-bottom:1px solid #dee2e6}.clients-table tr:hover{background:#f8f9fa}.empty-state{text-align:center;padding:3rem;color:#666}.admin-searchbar{display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap}.admin-searchbar input{flex:1 1 320px;padding:.85rem 1rem;border:1px solid #d1d5db;border-radius:8px;font-size:1rem;background:#fff}.admin-searchbar input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.12)}.admin-search-meta{color:#6b7280;font-size:.95rem;white-space:nowrap}.client-origin{font-size:.75rem;color:#6b7280;margin-top:.2rem}
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
<div class="navbar"><div class="container"><div style="display:flex;justify-content:space-between;align-items:center"><div>🛍️ ShopVivaliz Admin / Clientes</div><a href="/admin/" style="color:#fff;text-decoration:none">← Voltar</a></div></div></div>
<div class="container">
    <h1 class="page-title">Gestão de Clientes (<?= count($clientes) ?>)</h1>
    <div class="admin-searchbar"><input type="search" id="client-search" placeholder="Buscar por nome, e-mail ou telefone" autocomplete="off" aria-label="Buscar cliente no admin"><div class="admin-search-meta" id="client-search-meta"><?= count($clientes) ?> clientes</div></div>
    <div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
        <table class="clients-table"><thead><tr><th>Nome</th><th>Email</th><th>Telefone</th><th>Pedidos</th><th>Total Pago</th></tr></thead><tbody>
        <?php if (!$clientes): ?>
            <tr id="clients-empty-row"><td colspan="5" class="empty-state">Nenhuma conta cadastrada ou pedido encontrado.</td></tr>
        <?php else: foreach ($clientes as $c): ?>
            <tr data-search="<?= htmlspecialchars(strtolower(trim($c['nome'].' '.$c['email'].' '.$c['telefone'])), ENT_QUOTES, 'UTF-8') ?>">
                <td><?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?><?php if ($c['pedidos'] === 0): ?><div class="client-origin">Conta cadastrada</div><?php endif; ?></td>
                <td><?= htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($c['telefone'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$c['pedidos'] ?></td>
                <td>R$ <?= number_format((float)$c['total_gasto'], 2, ',', '.') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>
</div>
<script>
(()=>{const input=document.getElementById('client-search'),meta=document.getElementById('client-search-meta'),rows=[...document.querySelectorAll('tbody tr[data-search]')];function render(){const q=(input?.value||'').trim().toLowerCase();let visible=0;rows.forEach(row=>{const show=!q||(row.dataset.search||'').includes(q);row.style.display=show?'':'none';if(show)visible++});if(meta)meta.textContent=q?`${visible} resultado(s) para "${input.value}"`:`${visible} cliente(s)`}input?.addEventListener('input',render);render()})();
</script>
</body>
</html>
