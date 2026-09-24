<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$doc = (string)file_get_contents($root . '/docs/knowledge/gepeto.md');
$schema = (string)file_get_contents($root . '/docs/actions/gepeto-ai-squad.openapi.yaml');
$buscadorDoc = (string)file_get_contents($root . '/docs/knowledge/buscador.md');

$checks = [
    'doc canonical health operation' => str_contains($doc, '`getBuscadorHealth`'),
    'doc no legacy health operation' => !str_contains($doc, '`getAiSquadHealth`'),
    'doc canonical endpoint' => str_contains($doc, '`endpoint=buscador`'),
    'schema canonical endpoint' => str_contains($schema, '/api/agent/buscador.php:'),
    'schema canonical health operation' => str_contains($schema, 'operationId: getBuscadorHealth'),
    'schema canonical run operation' => str_contains($schema, 'operationId: runBuscador'),
    'Gepeto docs identify Plugin migration' => str_contains($doc, 'Plugin'),
    'Gepeto docs make Buscador MCP canonical' => str_contains($doc, 'Buscador MCP'),
    'Gepeto docs name dedicated MCP key' => str_contains($doc, 'BUSCADOR_MCP_KEY'),
    'legacy Action is labeled legacy' => str_contains($doc, 'Action') && str_contains(mb_strtolower($doc, 'UTF-8'), 'legado'),
    'legacy key is not current exclusive auth' => !str_contains($doc, 'usando exclusivamente a credencial de runtime `GEPETO_ACTION_KEY`'),
    'Buscador docs list the real core test' => str_contains($buscadorDoc, 'php tests/ai-squad-core-test.php'),
    'Buscador docs list the real reliability contract' => str_contains($buscadorDoc, 'php tests/buscador-reliability-contract-test.php'),
    'Gepeto docs require streaming MCP upstream' => str_contains($doc, 'streaming') && !str_contains($doc, 'stream=false'),
    'Buscador docs require streaming MCP upstream' => str_contains($buscadorDoc, 'streaming') && !str_contains($buscadorDoc, 'stream=false'),
    'Buscador docs do not list removed core test' => !str_contains($buscadorDoc, 'buscador-core-test.php'),
    'Buscador docs do not list removed bridge test prefix' => !str_contains($buscadorDoc, 'buscador-claude-bridge-test.mjs'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "GEPETO_BUSCADOR_DOC_CONTRACT_TEST=PASS\n";
