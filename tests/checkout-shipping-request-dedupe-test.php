<?php
declare(strict_types=1);

$checkout = file_get_contents(dirname(__DIR__) . '/checkout.php');
if ($checkout === false) {
    fwrite(STDERR, "FAIL: checkout.php nao pode ser lido\n");
    exit(1);
}

$checks = [
    "var shippingPendingCep = '';" => 'estado de cotacao em andamento ausente',
    'if (shippingPendingCep === cep) return;' => 'cotacao duplicada para o mesmo CEP nao e bloqueada',
    'shippingPendingCep = cep;' => 'CEP nao e marcado antes da requisicao',
    "if (shippingPendingCep === cep) shippingPendingCep = '';" => 'estado de cotacao nao e liberado apos conclusao',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($checkout, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "OK: checkout deduplica cotacao em andamento para o mesmo CEP\n";
