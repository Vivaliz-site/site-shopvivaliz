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
$gateway = strtolower(trim((string)($_GET['gateway'] ?? 'mercado_pago')));
$orderNumber = trim((string)($_GET['order_nsu'] ?? $_GET['external_reference'] ?? ''));
$orderNumber = preg_match('/^SV\d{17}$/', $orderNumber) === 1 ? $orderNumber : '';
$reportedApproved = in_array($result, ['success', 'approved'], true);
$failed = in_array($result, ['failure', 'rejected'], true);
$gatewayLabel = $gateway === 'infinitepay' ? 'InfinitePay' : 'Mercado Pago';

$orderData = [];
$orderTotal = 0.0;
$orderItems = [];
$serverApproved = false;
$serverAnalyticsSent = false;
if ($orderNumber !== '') {
    $orderFile = __DIR__ . '/storage/orders/' . substr($orderNumber, 2, 8) . '/' . $orderNumber . '.json';
    if (is_file($orderFile)) {
        $decodedOrder = json_decode((string)file_get_contents($orderFile), true);
        if (is_array($decodedOrder) && (string)($decodedOrder['order_number'] ?? '') === $orderNumber) {
            $orderData = $decodedOrder;
            $orderTotal = (float)($orderData['total'] ?? 0.0);
            $serverApproved = (string)($orderData['status'] ?? '') === 'payment_approved';
            $serverAnalyticsSent = ($orderData['analytics_purchase_sent'] ?? false) === true;
            $rawItems = is_array($orderData['items'] ?? null) ? $orderData['items'] : [];
            foreach ($rawItems as $item) {
                if (!is_array($item)) continue;
                $orderItems[] = [
                    'sku' => (string)($item['sku'] ?? $item['item_id'] ?? ''),
                    'name' => (string)($item['name'] ?? $item['item_name'] ?? 'Produto Vivaliz'),
                    'price' => (float)($item['price'] ?? 0),
                    'quantity' => max(1, (int)($item['quantity'] ?? 1)),
                ];
            }
        }
    }
}

if ($serverApproved) {
    $title = 'Pagamento confirmado';
    $message = 'Seu pagamento foi confirmado com segurança. O pedido seguirá para processamento.';
} elseif ($reportedApproved) {
    $title = 'Pagamento recebido';
    $message = 'O ' . $gatewayLabel . ' recebeu o pagamento. Estamos aguardando a confirmação segura do webhook antes de marcar o pedido como pago.';
} elseif ($failed) {
    $title = 'Pagamento não concluído';
    $message = 'O pagamento não foi concluído. Você pode voltar ao checkout e tentar novamente.';
} else {
    $title = 'Pagamento pendente';
    $message = 'O meio de pagamento foi gerado e aguarda conclusão ou compensação.';
}

// Gate browser conversion tracking on the server-side order state. The gateway
// return query string alone is not authoritative. When the provider redirects
// slightly before its webhook worker finishes, retry the same safe read a few
// times so an approved purchase is much less likely to be lost from analytics.
$confirmCheck = min(10, max(0, (int)($_GET['confirm_check'] ?? 0)));
$shouldRetryConfirmation = $reportedApproved && !$serverApproved && $orderNumber !== '' && $confirmCheck < 10;
$retryUrl = '';
if ($shouldRetryConfirmation) {
    $retryUrl = '/checkout-return.php?' . http_build_query([
        'gateway' => $gateway,
        'result' => 'approved',
        'order_nsu' => $orderNumber,
        'confirm_check' => $confirmCheck + 1,
    ]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/checkout.css">
    <script src="/js/ml-events.js?v=1" defer></script>
    <?php if ($serverApproved && $orderNumber !== ''): ?>
    <script>
      window.ShopVivalizPurchaseContext = <?= json_encode([
          'transaction_id' => $orderNumber,
          'value' => $orderTotal,
          'items' => $orderItems,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php endif; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>

    <?php if ($serverApproved && !$serverAnalyticsSent && $orderNumber !== '' && $orderItems !== []): ?>
    <!-- GA4 browser fallback: used only when the canonical approved server event was not sent. -->
    <script>
    window.addEventListener('load', function () {
      if (typeof gtag !== 'function') return;
      var transactionId = <?= json_encode($orderNumber) ?>;
      var dedupeKey = 'sv_ga4_purchase_' + transactionId;
      try {
        if (localStorage.getItem(dedupeKey) === 'sent') return;
      } catch (e) {}

      gtag('event', 'purchase', {
        transaction_id: transactionId,
        value: <?= json_encode(round($orderTotal, 2)) ?>,
        currency: 'BRL',
        items: <?= json_encode(array_map(static fn(array $item): array => [
            'item_id' => $item['sku'],
            'item_name' => $item['name'],
            'price' => round((float)$item['price'], 2),
            'quantity' => (int)$item['quantity'],
        ], $orderItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
      });

      try { localStorage.setItem(dedupeKey, 'sent'); } catch (e) {}
    });
    </script>
    <?php endif; ?>

    <?php
    $googleAdsId = trim((string)(getenv('GOOGLE_ADS_ID') ?: getenv('GOOGLE_ADS_CONVERSION_ID') ?: ''));
    $googleAdsLabel = trim((string)(getenv('GOOGLE_ADS_CONVERSION_LABEL') ?: ''));
    if ($serverApproved && $orderNumber !== '' && $googleAdsId !== '' && $googleAdsLabel !== ''):
        $customer = is_array($orderData['customer'] ?? null) ? $orderData['customer'] : [];
        $email = strtolower(trim((string)($customer['email'] ?? '')));
        $phoneDigits = preg_replace('/\D+/', '', (string)($customer['phone'] ?? '')) ?? '';
        if ($phoneDigits !== '' && !str_starts_with($phoneDigits, '55')) $phoneDigits = '55' . $phoneDigits;
        $name = trim((string)($customer['name'] ?? ''));
        $parts = explode(' ', $name, 2);
        $firstName = strtolower(trim($parts[0] ?? ''));
        $lastName = strtolower(trim($parts[1] ?? ''));
        $cep = preg_replace('/\D+/', '', (string)($customer['cep'] ?? '')) ?? '';
    ?>
    <!-- Google Ads Enhanced Conversions: server-approved orders only. -->
    <script>
      window.addEventListener('load', function() {
        if (typeof gtag !== 'function') return;
        var adsDedupeKey = 'sv_ads_conversion_' + <?= json_encode($orderNumber) ?>;
        try {
          if (localStorage.getItem(adsDedupeKey) === 'sent') return;
        } catch (e) {}

        gtag('set', 'user_data', {
          'email': '<?= $email !== '' ? hash('sha256', $email) : '' ?>',
          'phone_number': '<?= $phoneDigits !== '' ? hash('sha256', '+' . $phoneDigits) : '' ?>',
          'address': {
            'first_name': '<?= $firstName !== '' ? hash('sha256', $firstName) : '' ?>',
            'last_name': '<?= $lastName !== '' ? hash('sha256', $lastName) : '' ?>',
            'postal_code': '<?= $cep !== '' ? hash('sha256', $cep) : '' ?>',
            'country': '<?= hash('sha256', 'br') ?>'
          }
        });
        gtag('event', 'conversion', {
          'send_to': '<?= htmlspecialchars($googleAdsId, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($googleAdsLabel, ENT_QUOTES, 'UTF-8') ?>',
          'value': <?= json_encode(round($orderTotal, 2)) ?>,
          'currency': 'BRL',
          'transaction_id': <?= json_encode($orderNumber) ?>
        });
        try { localStorage.setItem(adsDedupeKey, 'sent'); } catch (e) {}
      });
    </script>
    <?php endif; ?>

    <?php if ($shouldRetryConfirmation): ?>
    <script>
      window.setTimeout(function () {
        window.location.replace(<?= json_encode($retryUrl) ?>);
      }, 3000);
    </script>
    <?php endif; ?>
</head>
<body>
<?php $svNavCurrent = 'checkout'; include __DIR__ . '/includes/navbar.php'; ?>
<main class="container" style="max-width:760px;padding:64px 20px">
    <section class="checkout-card" style="text-align:center">
        <div style="font-size:52px" aria-hidden="true"><?= $serverApproved ? '✅' : ($failed ? '⚠️' : '🕒') ?></div>
        <h1 class="checkout-title"><?= htmlspecialchars($title) ?></h1>
        <?php if ($orderNumber !== ''): ?>
            <p style="font-weight:700;color:#0f8f62">Pedido <?= htmlspecialchars($orderNumber) ?></p>
        <?php endif; ?>
        <p><?= htmlspecialchars($message) ?></p>
        <?php if ($shouldRetryConfirmation): ?>
            <p style="font-size:14px;color:#64748b">Confirmando o pedido automaticamente…</p>
        <?php endif; ?>
        <div class="modal-actions" style="justify-content:center;margin-top:24px">
            <?php if ($failed): ?><a class="btn btn-primary" href="/checkout">Voltar ao checkout</a><?php endif; ?>
            <a class="btn btn-secondary" href="/catalogo">Ir ao catálogo</a>
        </div>
    </section>
</main>
<?php if ($serverApproved && $orderItems !== []): ?>
<script>
window.addEventListener('load', () => {
  for (const item of <?= json_encode($orderItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) {
    window.dispatchEvent(new CustomEvent('shopvivaliz:purchase', {detail: {product_id: item.sku}}));
  }
});
</script>
<?php endif; ?>
</body>
</html>
