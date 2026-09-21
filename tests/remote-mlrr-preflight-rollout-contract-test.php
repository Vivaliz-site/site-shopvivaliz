<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/shopvivaliz-remote-access.yml';
$scriptPath = $root . '/scripts/remote-mlrr-preflight-rollout.sh';
$docsPath = $root . '/docs/REMOTE-ACCESS-GITHUB.md';
$errors = [];

foreach ([$workflowPath, $scriptPath, $docsPath] as $path) {
    if (!is_file($path)) {
        $errors[] = "missing file: {$path}";
    }
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

$workflow = (string)file_get_contents($workflowPath);
$script = (string)file_get_contents($scriptPath);
$docs = (string)file_get_contents($docsPath);

foreach ([
    '- mlrr_preflight_rollout',
    '"mlrr_preflight_rollout"',
    'action == "mlrr_preflight_rollout" and target != "shopvivaliz-free-a1"',
    'mlrr_preflight_rollout)',
    'bash scripts/remote-mlrr-preflight-rollout.sh',
] as $needle) {
    if (!str_contains($workflow, $needle)) {
        $errors[] = "remote workflow missing MLRR contract: {$needle}";
    }
}

$sequence = [
    'scripts/mlrr-production-ops.sh prepare',
    'scripts/mlrr-production-ops.sh shadow',
    'scripts/mlrr-production-ops.sh validate',
    'scripts/mlrr-production-ops.sh preflight',
];
$last = -1;
foreach ($sequence as $needle) {
    $pos = strpos($script, $needle);
    if ($pos === false) {
        $errors[] = "MLRR rollout script missing: {$needle}";
        continue;
    }
    if ($pos <= $last) {
        $errors[] = 'MLRR rollout operation order is invalid';
    }
    $last = $pos;
}

foreach ([
    'emit-execution-provenance.py',
    'EXECUTION_PROVENANCE_HMAC_KEY',
    'git merge --ff-only origin/main',
] as $needle) {
    if (!str_contains($script, $needle)) {
        $errors[] = "MLRR rollout provenance contract missing: {$needle}";
    }
}

foreach (['/return-review', 'curl -X POST', 'CURLOPT_POST'] as $needle) {
    if (str_contains($script, $needle)) {
        $errors[] = "MLRR remote rollout must stay write-free: {$needle}";
    }
}

if (!str_contains($docs, 'action=mlrr_preflight_rollout')) {
    $errors[] = 'remote access docs missing MLRR preflight action';
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "remote-mlrr-preflight-rollout-contract: ok\n";
