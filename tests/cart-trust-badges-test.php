<?php
declare(strict_types=1);

$cart = file_get_contents(dirname(__DIR__) . '/carrinho.php');
if ($cart === false) {
    fwrite(STDERR, "Nao foi possivel ler carrinho.php\n");
    exit(1);
}

$failures = [];
foreach (['cart-trust-grid', 'Compra Segura', 'Pagamento protegido', 'Envio Rápido', 'Entrega para todo o Brasil', 'Troca Facilitada', '7 dias para devolução'] as $needle) {
    if (!str_contains($cart, $needle)) {
        $failures[] = "carrinho.php deve conter: {$needle}";
    }
}
if (substr_count($cart, 'class="cart-trust-item"') !== 3) {
    $failures[] = 'carrinho.php deve renderizar exatamente 3 micro-cards de confiança';
}
foreach (['sv-trust-badge', '🔒', '🚚', '↩️'] as $needle) {
    if (str_contains($cart, $needle)) {
        $failures[] = "carrinho.php nao deve manter selo legado/emoji: {$needle}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Cart trust badges validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "cart-trust-badges: ok\n";
