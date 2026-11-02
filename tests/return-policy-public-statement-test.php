<?php
declare(strict_types=1);

$policy = (string)file_get_contents(dirname(__DIR__) . '/politica-devolucoes.php');
$errors = [];

if (!str_contains($policy, '7 dias corridos')) {
    $errors[] = 'return_window_not_declared';
}
if (!str_contains($policy, 'o consumidor não paga o frete de devolução')) {
    $errors[] = 'free_withdrawal_return_shipping_not_declared';
}
if (!str_contains($policy, 'autorização de postagem, etiqueta ou procedimento de coleta sem custo')) {
    $errors[] = 'merchant_return_method_not_explained';
}
if (!str_contains($policy, 'legislação determine tratamento específico')) {
    $errors[] = 'legal_exception_not_preserved';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "return policy public statement: ok\n";
