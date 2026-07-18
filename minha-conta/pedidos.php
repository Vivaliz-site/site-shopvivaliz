<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/account-chrome.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../includes/csrf.php';

$svAccountUser = sv_account_require_login();
sv_account_ensure_schema();

$svAccountPageTitle = 'Meus Pedidos';
$svAccountActive = 'pedidos';

$orders = [];
try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, order_number, olist_order_id, order_total, order_status, payment_method,
                tracking_number, estimated_delivery, nf_id, nf_numero, nf_serie, nf_chave_acesso, nf_data_emissao,
                items_json, nf_pdf_url, nf_xml_url, label_url, created_at
         FROM orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50'
    );
    $stmt->execute([':uid' => $svAccountUser['id']]);
    $orders = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[MinhaConta] pedidos query failed: ' . $e->getMessage());
}

$statusLabels = [
    'aguardando_pagamento' => 'Aguardando Pagamento',
    'pagamento_aprovado' => 'Pagamento Aprovado',
    'nota_fiscal_enviada' => 'Nota Fiscal Enviada',
    'pronto_para_enviar' => 'Pronto para Enviar',
    'enviado' => 'Enviado',
    'entregue' => 'Entregue',
    'cancelamento_solicitado' => 'Cancelamento Solicitado',
    'cancelado' => 'Cancelado',
    'devolvido' => 'Devolvido',
];
$statusColors = [
    'aguardando_pagamento' => '#ff9800',
    'pagamento_aprovado' => '#2196f3',
    'nota_fiscal_enviada' => '#2196f3',
    'pronto_para_enviar' => '#ff9800',
    'enviado' => '#2196f3',
    'entregue' => '#4caf50',
    'cancelamento_solicitado' => '#ff9800',
    'cancelado' => '#f44336',
    'devolvido' => '#f44336',
];
// Cancelamento so permitido antes de faturar/separar o pedido.
$cancellableStatuses = ['aguardando_pagamento', 'pagamento_aprovado'];

require __DIR__ . '/../includes/account-chrome-top.php';
?>
<h1>Meus Pedidos</h1>
<p class="sv-subtitle">Acompanhe o status, rastreie a entrega e baixe a nota fiscal dos seus pedidos.</p>

<?php if (empty($orders)): ?>
    <div style="text-align:center; padding:60px 20px; color:#999;">
        <p style="margin-bottom:16px;">Você ainda não fez nenhum pedido.</p>
        <a href="/" class="sv-btn">Começar a comprar</a>
    </div>
<?php else: ?>
    <div style="display:flex; flex-direction:column; gap:16px;">
        <?php foreach ($orders as $order): ?>
            <?php
            $status = $order['order_status'];
            $label = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
            $color = $statusColors[$status] ?? '#999';
            $canCancel = in_array($status, $cancellableStatuses, true);
            $hasItems = !empty($order['items_json']);
            ?>
            <div class="sv-order-card" style="border:1px solid #eee; border-radius:8px; padding:18px;" data-order-id="<?php echo (int)$order['id']; ?>">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                    <div>
                        <div style="font-weight:600; color:#173b63;">Pedido <?php echo htmlspecialchars($order['order_number'] ?: ('#' . $order['id'])); ?></div>
                        <div style="font-size:13px; color:#666;"><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:600;">R$ <?php echo number_format((float)$order['order_total'], 2, ',', '.'); ?></div>
                        <span style="display:inline-block; margin-top:4px; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; color:white; background:<?php echo htmlspecialchars($color); ?>;">
                            <?php echo htmlspecialchars($label); ?>
                        </span>
                    </div>
                </div>

                <?php if ($order['tracking_number']): ?>
                    <div style="font-size:13px; margin-bottom:8px;">
                        📦 Rastreio: <code><?php echo htmlspecialchars($order['tracking_number']); ?></code>
                        <a href="https://rastreamento.correios.com.br/app/index.php?codigo=<?php echo urlencode($order['tracking_number']); ?>" target="_blank" rel="noopener" style="margin-left:8px; color:#173b63;">Rastrear entrega →</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['nf_numero']) || !empty($order['nf_chave_acesso'])): ?>
                    <div style="font-size:13px; margin-bottom:8px; color:#444;">
                        🧾 NF
                        <?php if (!empty($order['nf_numero'])): ?>
                            <span>#<?php echo htmlspecialchars($order['nf_numero']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($order['nf_serie'])): ?>
                            <span>série <?php echo htmlspecialchars($order['nf_serie']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($order['nf_data_emissao'])): ?>
                            <span>emitida em <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($order['nf_data_emissao']))); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($order['nf_chave_acesso'])): ?>
                        <div style="font-size:12px; margin-bottom:8px;">
                            <code><?php echo htmlspecialchars($order['nf_chave_acesso']); ?></code>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                    <?php if ($order['nf_pdf_url']): ?>
                        <a class="sv-btn secondary" href="<?php echo htmlspecialchars($order['nf_pdf_url']); ?>" target="_blank" rel="noopener">NF-e (PDF)</a>
                    <?php else: ?>
                        <button class="sv-btn secondary disabled" disabled title="Nota fiscal ainda não disponível para este pedido">NF-e (PDF)</button>
                    <?php endif; ?>

                    <?php if ($order['nf_xml_url']): ?>
                        <a class="sv-btn secondary" href="<?php echo htmlspecialchars($order['nf_xml_url']); ?>" target="_blank" rel="noopener">NF-e (XML)</a>
                    <?php else: ?>
                        <button class="sv-btn secondary disabled" disabled title="Nota fiscal ainda não disponível para este pedido">NF-e (XML)</button>
                    <?php endif; ?>

                    <?php if (!empty($order['label_url'])): ?>
                        <a class="sv-btn secondary" href="<?php echo htmlspecialchars($order['label_url']); ?>" target="_blank" rel="noopener">Etiqueta de transporte</a>
                    <?php else: ?>
                        <button class="sv-btn secondary disabled" disabled title="Etiqueta ainda não gerada para este pedido">Etiqueta de transporte</button>
                    <?php endif; ?>

                    <?php if ($hasItems): ?>
                        <button class="sv-btn sv-recompra-btn" data-order-id="<?php echo (int)$order['id']; ?>">🔁 Comprar novamente</button>
                    <?php else: ?>
                        <button class="sv-btn disabled" disabled title="Itens deste pedido não estão disponíveis para recompra automática">🔁 Comprar novamente</button>
                    <?php endif; ?>

                    <?php if ($canCancel): ?>
                        <button class="sv-btn danger sv-cancel-btn" data-order-id="<?php echo (int)$order['id']; ?>">Cancelar pedido</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    var csrfToken = <?php echo json_encode(sv_csrf_token('account-actions')); ?>;

    function setLoading(btn, loading) {
        btn.disabled = loading;
        if (loading) {
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = '<span class="sv-spinner"></span> Processando...';
        } else if (btn.dataset.originalText) {
            btn.innerHTML = btn.dataset.originalText;
        }
    }

    document.querySelectorAll('.sv-cancel-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Tem certeza que deseja cancelar este pedido?')) return;
            setLoading(btn, true);
            fetch('/api/account/cancel-order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: btn.dataset.orderId, csrf_token: csrfToken })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setLoading(btn, false);
                if (data.ok) {
                    window.svToast('Pedido cancelado com sucesso.');
                    setTimeout(function () { window.location.reload(); }, 900);
                } else {
                    window.svToast(data.error || 'Não foi possível cancelar o pedido.', true);
                }
            })
            .catch(function () {
                setLoading(btn, false);
                window.svToast('Erro de conexão. Tente novamente.', true);
            });
        });
    });

    document.querySelectorAll('.sv-recompra-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setLoading(btn, true);
            fetch('/api/account/recompra.php?order_id=' + encodeURIComponent(btn.dataset.orderId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setLoading(btn, false);
                if (data.ok && data.items && data.items.length) {
                    var current = (window.ShopVivalizCart && window.ShopVivalizCart.get()) || [];
                    data.items.forEach(function (item) {
                        var existing = current.find(function (i) { return i.sku === item.sku; });
                        if (existing) existing.quantity = (Number(existing.quantity) || 1) + (Number(item.quantity) || 1);
                        else current.push(item);
                    });
                    if (window.ShopVivalizCart) window.ShopVivalizCart.set(current);
                    window.svToast('Itens adicionados ao carrinho!');
                    setTimeout(function () { window.location.href = '/carrinho'; }, 700);
                } else {
                    window.svToast(data.error || 'Não foi possível repetir este pedido.', true);
                }
            })
            .catch(function () {
                setLoading(btn, false);
                window.svToast('Erro de conexão. Tente novamente.', true);
            });
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/account-chrome-bottom.php'; ?>
