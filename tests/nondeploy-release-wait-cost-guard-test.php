<?php
$root = dirname(__DIR__);
$errors = [];

$cases = [
    '.github/workflows/runtime-token-security.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        "github.event_name == 'push' && steps.production_impact.outputs.should_deploy == 'true'",
    ],
    '.github/workflows/runtime-env-keyset-lock.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        "github.event_name == 'push' && steps.production_impact.outputs.should_deploy == 'true'",
    ],
    '.github/workflows/repair-catalog-hard-quality-pending.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        "steps.production_impact.outputs.should_deploy == 'true'",
        'No production repair required',
    ],
];

foreach ($cases as $relative => $required) {
    $path = $root . '/' . $relative;
    $text = is_file($path) ? str_replace("\r\n", "\n", (string)file_get_contents($path)) : '';
    if ($text === '') {
        $errors[] = "missing_workflow:$relative";
        continue;
    }
    foreach ($required as $fragment) {
        if (!str_contains($text, $fragment)) {
            $errors[] = "missing_guard:$relative:$fragment";
        }
    }
}

$repair = (string)file_get_contents($root . '/.github/workflows/repair-catalog-hard-quality-pending.yml');
foreach (['Configure verified production SSH', 'Wait for production release containing target', 'Repair existing hard-failed pending drafts'] as $step) {
    $pos = strpos($repair, "- name: $step");
    if ($pos === false) {
        $errors[] = "repair_step_missing:$step";
        continue;
    }
    $slice = substr($repair, $pos, 350);
    if (!str_contains($slice, 'if: ${{ steps.production_impact.outputs.should_deploy == \'true\' }}')) {
        $errors[] = "repair_step_not_guarded:$step";
    }
}

$quality = (string)file_get_contents($root . '/.github/workflows/quality-gate.yml');
if (!str_contains($quality, 'php tests/nondeploy-release-wait-cost-guard-test.php')) {
    $errors[] = 'quality_gate_missing_nondeploy_wait_contract';
}

if ($errors !== []) {
    fwrite(STDERR, json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo "nondeploy-release-wait-cost-guard: ok\n";