<?php
declare(strict_types=1);

/**
 * Git Force Pull - Force synchronize production code
 * This endpoint pulls the latest code from GitHub origin/main
 */

header('Content-Type: application/json; charset=utf-8');

// Validate API key
$allowed_keys = [
    'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
    $_SERVER['SHOPVIVALIZ_AGENT_KEY'] ?? null,
];

$provided_key = $_GET['key'] ?? $_POST['key'] ?? ($_SERVER['HTTP_X_FORCE_PULL_KEY'] ?? '');

if (!$provided_key || !in_array($provided_key, array_filter($allowed_keys), true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing key']);
    exit;
}

// Execute git pull
$repo_dir = dirname(__DIR__, 2);
chdir($repo_dir);

$output = [];

// git fetch
exec('git fetch origin 2>&1', $fetch_out);
$output['fetch'] = $fetch_out;

// git reset --hard
exec('git reset --hard origin/main 2>&1', $reset_out);
$output['reset'] = $reset_out;

// Get commit
exec('git rev-parse HEAD', $commit_out);
$commit = trim($commit_out[0] ?? '');

// Verify key is present
$watchdog_file = $repo_dir . '/api/agent/autonomous-watchdog.php';
$has_key = is_file($watchdog_file) &&
           str_contains(file_get_contents($watchdog_file), 'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k');

echo json_encode([
    'ok' => true,
    'commit' => $commit,
    'has_agent_key' => $has_key,
    'fetch' => count($fetch_out) > 0 ? 'OK' : 'No changes',
    'reset' => count($reset_out) > 0 ? 'OK' : 'No changes',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
