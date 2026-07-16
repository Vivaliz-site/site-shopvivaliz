<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/account-chrome.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';

$svAccountUser = sv_account_require_login();
sv_account_ensure_schema();

$svAccountPageTitle = 'Painel';
$svAccountActive = 'dashboard';

$lastOrder = null;
$orderCount = 0;
try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM orders WHERE user_id = :uid');
    $stmt->execute([':uid' => $svAccountUser['id']]);
    $orderCount = (int)($stmt->fetch()['total'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT id, order_number, order_total, order_status, tracking_number, created_at
         FROM orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $svAccountUser['id']]);
    $lastOrder = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    error_log('[MinhaConta] dashboard query failed: ' . $e->getMessage());
}

$statusLabels = [
    'aguardando_pagamento' => 'Aguardando Pagamento',
    'pagamento_aprovado' => 'Pagamento Aprovado',
    'nota_fiscal_enviada' => 'Nota Fiscal Enviada',
    'pronto_para_enviar' => 'Pronto para Enviar',
    'enviado' => 'Enviado',
    'entregue' => 'Entregue',
    'cancelado' => 'Cancelado',
    'devolvido' => 'Devolvido',
];

require __DIR__ . '/../includes/account-chrome-top.php';
?>
<h1>Olá, <?php echo htmlspecialchars(explode(' ', $svAccountUser['name'] ?: 'Cliente')[0]); ?>! 👋</h1>
<p class="sv-subtitle">Bem-vindo(a) de volta à sua área de cliente.</p>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 28px;">
    <div style="background:#f7f9fc; border-radius:8px; padding:18px;">
        <div style="font-size:13px; color:#666;">Total de pedidos</div>
        <div style="font-size:28px; font-weight:700; color:#173b63;"><?php echo $orderCount; ?></div>
    </div>
</div>

<h2 style="font-size:18px; margin-bottom:12px;">Último pedido</h2>
<?php if ($lastOrder): ?>
    <div style="border:1px solid #eee; border-radius:8px; padding:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-weight:600;">Pedido <?php echo htmlspecialchars($lastOrder['order_number'] ?: ('#' . $lastOrder['id'])); ?></div>
            <div style="font-size:13px; color:#666;"><?php echo date('d/m/Y', strtotime($lastOrder['created_at'])); ?> · R$ <?php echo number_format((float)$lastOrder['order_total'], 2, ',', '.'); ?></div>
            <div style="font-size:13px; color:#173b63; margin-top:4px;"><?php echo htmlspecialchars($statusLabels[$lastOrder['order_status']] ?? $lastOrder['order_status']); ?></div>
        </div>
        <a href="/minha-conta/pedidos.php" class="sv-btn secondary">Ver todos os pedidos</a>
    </div>
<?php else: ?>
    <div style="text-align:center; padding:40px 20px; color:#999;">
        <p style="margin-bottom:16px;">Você ainda não fez nenhum pedido.</p>
        <a href="/" class="sv-btn">Começar a comprar</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/account-chrome-bottom.php'; ?>
