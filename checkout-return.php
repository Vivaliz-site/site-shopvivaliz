<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$result = strtolower(trim((string)($_GET['result'] ?? $_GET['status'] ?? 'pending')));
$result = in_array($result, ['success', 'approved', 'pending', 'failure', 'rejected'], true) ? $result : 'pending';
$orderNumber = trim((string)($_GET['external_reference'] ?? ''));
$orderNumber = preg_match('/^SV\d{17}$/', $orderNumber) === 1 ? $orderNumber : '';
$approved = in_array($result, ['success', 'approved'], true);
$failed = in_array($result, ['failure', 'rejected'], true);
$title = $approved ? 'Pagamento recebido' : ($failed ? 'Pagamento não concluído' : 'Pagamento pendente');
$message = $approved
    ? 'O Mercado Pago recebeu o pagamento. A confirmação final do pedido será atualizada pelo webhook seguro.'
    : ($failed
        ? 'O pagamento não foi concluído. Você pode voltar ao checkout e tentar novamente.'
        : 'O meio de pagamento foi gerado e aguarda conclusão ou compensação.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/checkout.css">
</head>
<body>
<?php $svNavCurrent = 'checkout'; include __DIR__ . '/includes/navbar.php'; ?>
<main class="container" style="max-width:760px;padding:64px 20px">
    <section class="checkout-card" style="text-align:center">
        <div style="font-size:52px" aria-hidden="true"><?= $approved ? '✅' : ($failed ? '⚠️' : '🕒') ?></div>
        <h1 class="checkout-title"><?= htmlspecialchars($title) ?></h1>
        <?php if ($orderNumber !== ''): ?>
            <p style="font-weight:700;color:#0f8f62">Pedido <?= htmlspecialchars($orderNumber) ?></p>
        <?php endif; ?>
        <p><?= htmlspecialchars($message) ?></p>
        <div class="modal-actions" style="justify-content:center;margin-top:24px">
            <?php if ($failed): ?><a class="btn btn-primary" href="/checkout">Voltar ao checkout</a><?php endif; ?>
            <a class="btn btn-secondary" href="/catalogo">Ir ao catálogo</a>
        </div>
    </section>
</main>
</body>
</html>
