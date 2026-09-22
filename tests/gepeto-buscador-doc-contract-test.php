<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$doc = (string)file_get_contents($root . '/docs/knowledge/gepeto.md');
$schema = (string)file_get_contents($root . '/docs/actions/gepeto-ai-squad.openapi.yaml');

$checks = [
    'doc canonical health operation' => str_contains($doc, '`getBuscadorHealth`'),
    'doc no legacy health operation' => !str_contains($doc, '`getAiSquadHealth`'),
    'doc canonical endpoint' => str_contains($doc, '`endpoint=buscador`'),
    'schema canonical endpoint' => str_contains($schema, '/api/agent/buscador.php:'),
    'schema canonical health operation' => str_contains($schema, 'operationId: getBuscadorHealth'),
    'schema canonical run operation' => str_contains($schema, 'operationId: runBuscador'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "GEPETO_BUSCADOR_DOC_CONTRACT_TEST=PASS\n";
