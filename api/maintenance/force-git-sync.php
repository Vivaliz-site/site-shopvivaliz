<?php
declare(strict_types=1);

/**
 * Force Git Synchronization Endpoint
 * Executes git fetch + reset --hard to ensure production is in sync
 */

header('Content-Type: application/json; charset=utf-8');

$allowed_keys = [
    'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
    'sk_prod_shopvivaliz_sync_2026',
    $_SERVER['SHOPVIVALIZ_AGENT_KEY'] ?? null,
    $_SERVER['RUNTIME_AGENT_KEY'] ?? null,
];

// Get provided key
$provided_key = $_GET['key'] ?? $_POST['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? '');

if (!$provided_key || !in_array($provided_key, array_filter($allowed_keys), true)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid or missing sync key',
    ]);
    exit;
}

$output = [];
$return_code = 0;

// Change to repo directory
$repo_dir = dirname(__DIR__, 2);
chdir($repo_dir);

// Execute git fetch
exec('git fetch origin 2>&1', $fetch_output, $fetch_code);
$output['fetch'] = $fetch_output;

if ($fetch_code === 0) {
    // Execute git reset --hard
    exec('git reset --hard origin/main 2>&1', $reset_output, $reset_code);
    $output['reset'] = $reset_output;
    $return_code = $reset_code;
}

// Get current commit
exec('git rev-parse HEAD', $commit_output, $commit_code);
$current_commit = trim($commit_output[0] ?? '');

http_response_code($return_code === 0 ? 200 : 500);
echo json_encode([
    'ok' => $return_code === 0,
    'fetch_code' => $fetch_code,
    'reset_code' => $reset_code ?? null,
    'current_commit' => $current_commit,
    'output' => $output,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
