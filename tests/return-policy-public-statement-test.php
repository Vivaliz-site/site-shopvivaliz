<?php
declare(strict_types=1);

$policy = (string)file_get_contents(dirname(__DIR__) . '/politica-devolucoes.php');
$errors = [];

if (!str_contains($policy, '7 dias corridos')) {
    $errors[] = 'return_window_not_declared';
}
if (!str_contains($policy, 'custo da postagem de devolução é de responsabilidade do consumidor')) {
    $errors[] = 'customer_return_cost_not_declared';
}
if (!str_contains($policy, 'legislação determine tratamento diferente')) {
    $errors[] = 'legal_exception_not_preserved';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "return policy public statement: ok\n";
