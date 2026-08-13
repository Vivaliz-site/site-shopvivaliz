<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function sv_audit_read(string $path): string
{
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Nao foi possivel ler: ' . $path);
    }
    return $content;
}

function sv_audit_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$adminIndex = sv_audit_read($root . '/admin/automacao-ia-multicanal/index.php');
$adminDashboard = sv_audit_read($root . '/admin/automacao-ia-multicanal/pages/dashboard.php');
$adminAutomations = sv_audit_read($root . '/admin/automacao-ia-multicanal/pages/automacoes.php');
$adminJs = sv_audit_read($root . '/js/admin-automation.js');
$checkoutCss = sv_audit_read($root . '/css/checkout.css');
$layoutCss = sv_audit_read($root . '/css/layout-polish-v1.css');
$publicJs = sv_audit_read($root . '/js/public-experience-v1.js');
$orderEntry = sv_audit_read($root . '/api/orders/create.php');
$orderValidated = sv_audit_read($root . '/api/orders/create-validated.php');

// Admin IA: nenhuma rota inexistente ou painel demonstrativo pode parecer real.
sv_audit_expect(str_contains($adminIndex, "'dashboard' => 'pages/dashboard.php'"), 'Dashboard canonico ausente no modulo IA.');
sv_audit_expect(str_contains($adminIndex, "'automacoes' => 'pages/automacoes.php'"), 'Pagina de rotinas canonicas ausente no modulo IA.');
foreach (['pages/produtos.php', 'pages/canais.php', 'pages/historico.php', 'pages/configuracoes.php', 'pages/manual.php'] as $deadRoute) {
    sv_audit_expect(!str_contains($adminIndex, $deadRoute), 'Rota administrativa inexistente voltou: ' . $deadRoute);
}
foreach (['94.3%', '3 rodando agora', 'TikTok - Descrições com Emojis', '96.5%', 'new Chart('] as $fakeState) {
    sv_audit_expect(!str_contains($adminDashboard . $adminAutomations, $fakeState), 'Estado demonstrativo voltou ao admin: ' . $fakeState);
}
sv_audit_expect(str_contains($adminJs, "fetch('/api/health.php'"), 'Health check do admin nao consulta o endpoint real.');

// Checkout: a reserva de estoque acontece no backend apos o envio do pedido.
// Logo, o timer pre-submit nao pode ser apresentado como reserva ja existente.
sv_audit_expect(
    preg_match('/\.checkout-timer-banner\s*\{[^}]*display\s*:\s*none\s*!important/s', $checkoutCss) === 1,
    'Timer de falsa reserva voltou a ficar visivel antes da criacao do pedido.'
);
sv_audit_expect(
    preg_match('/\.payment-opt\s+input\[type=radio\][^{]*\{[^}]*display\s*:\s*none/s', $checkoutCss) !== 1,
    'Radios de pagamento nao podem usar display:none; isso remove foco por teclado.'
);
sv_audit_expect(str_contains($checkoutCss, 'input[type=radio]:focus-visible + .payment-opt-box'), 'Foco visivel dos meios de pagamento ausente.');

// Carrinho: antes da hidratacao nao deve haver CTA efetivamente clicavel nem promessa de frete.
sv_audit_expect(str_contains($layoutCss, 'body:has(#cart-items-list:empty) #btn-checkout'), 'Guarda pre-hidratacao do checkout do carrinho ausente.');
sv_audit_expect(str_contains($layoutCss, 'body:has(#cart-items-list:empty) .free-shipping-container'), 'Guarda pre-hidratacao de frete gratis ausente.');

// Widgets globais: nao cobrir checkout/admin e manter estados que o CSS espera.
sv_audit_expect(str_contains($publicJs, 'if (!isPublicPath(window.location.pathname)) return;'), 'Dock publico pode voltar a ser injetado em rotas sensiveis.');
foreach (['sv-page-catalog', 'sv-catalog-top', 'sv-page-home', 'sv-home-top'] as $stateClass) {
    sv_audit_expect(str_contains($publicJs, $stateClass), 'Estado visual global ausente: ' . $stateClass);
}

// Pedidos: preservar a cadeia autoritativa que recalcula itens e assina frete.
sv_audit_expect(str_contains($orderEntry, "require __DIR__ . '/create-validated.php';"), 'Entrada de pedidos deixou de usar o fluxo validado.');
foreach (['svoa_resolve_items', 'item_price_mismatch', 'shipping_quote_invalid', 'svir_reserve'] as $guard) {
    sv_audit_expect(str_contains($orderValidated, $guard), 'Guarda autoritativa de pedido ausente: ' . $guard);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "OK: integridade operacional e visual protegida.\n";
