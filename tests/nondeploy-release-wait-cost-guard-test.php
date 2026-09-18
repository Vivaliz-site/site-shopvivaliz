<?php
$root = dirname(__DIR__);
$errors = [];

$await = (string)file_get_contents($root . '/.github/workflows/production-release-await.yml');
foreach (['workflow_call:', 'runs-on: ubuntu-latest', 'if [[ -z "$EXPECTED_SHA" ]]', 'production_wait_required=false'] as $fragment) {
    if (!str_contains($await, $fragment)) {
        $errors[] = "release_await_missing:$fragment";
    }
}

$cases = [
    '.github/workflows/runtime-token-security.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        '[[ "$should_deploy" == \'true\' ]] && expected_sha="$GITHUB_SHA"',
        'uses: ./.github/workflows/production-release-await.yml',
        'expected_sha: ${{ needs.preflight.outputs.expected_sha }}',
        'audit:',
        'needs: await-release',
    ],
    '.github/workflows/runtime-env-keyset-lock.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        '[[ "$should_deploy" == \'true\' ]] && expected_sha="$GITHUB_SHA"',
        'uses: ./.github/workflows/production-release-await.yml',
        'expected_sha: ${{ needs.preflight.outputs.expected_sha }}',
        'allow_descendant: true',
        'seal-and-verify:',
        'needs: await-release',
    ],
    '.github/workflows/repair-catalog-hard-quality-pending.yml' => [
        'id: production_impact',
        'bash scripts/should-deploy-production.sh',
        'should_deploy: ${{ steps.production_impact.outputs.should_deploy }}',
        'uses: ./.github/workflows/production-release-await.yml',
        'if: ${{ needs.preflight.outputs.should_deploy == \'true\' }}',
        'allow_descendant: true',
        'if: ${{ needs.preflight.outputs.should_deploy == \'true\' && needs.await-release.result == \'success\' }}',
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
$repairJob = strstr($repair, "  repair:\n") ?: '';
foreach (['Configure verified production SSH', 'Repair existing hard-failed pending drafts'] as $step) {
    if (!str_contains($repairJob, "- name: $step")) {
        $errors[] = "repair_step_missing:$step";
    }
}
if (str_contains($repairJob, 'sleep 15') || str_contains($repairJob, 'Wait for production release containing target')) {
    $errors[] = 'repair_oracle_job_must_not_wait_for_release';
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
