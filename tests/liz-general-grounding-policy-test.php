<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/liz-general-policy.php';

$cases = [
    ['me passe uma receita curta de bolo', false],
    ['explique o que e fotossintese', false],
    ['qual a capital da franca', false],
    ['qual a cotacao do dolar agora', true],
    ['pesquise as noticias de hoje sobre tecnologia', true],
    ['qual a previsao do tempo hoje em divinopolis', true],
    ['quem e o presidente atual do brasil', true],
];

$failures = 0;
foreach ($cases as [$message, $expected]) {
    $actual = lizg_needs_web_grounding($message);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected=" . ($expected ? 'true' : 'false') . " actual=" . ($actual ? 'true' : 'false') . "\n");
        $failures++;
    }
}

if ($failures > 0) exit(1);
echo "PASS: stable questions skip web grounding; current/research questions request it.\n";
