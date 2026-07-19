<?php
declare(strict_types=1);
/**
 * ADMIN ENDPOINT - Force git pull on server
 * Access: https://shopvivaliz.com.br/admin/force-git-pull.php
 *
 * This endpoint forces a git pull from GitHub to update checkout to latest main branch
 * Use when automatic cron sync fails or is delayed
 */

require_once __DIR__ . '/../includes/admin-guard.php';

$remote_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Log file
$log_file = __DIR__ . '/../logs/admin-force-pull.log';
@mkdir(dirname($log_file), 0755, true);

$timestamp = date('Y-m-d H:i:s');

// Log the request
file_put_contents($log_file, "[$timestamp] Access from: $remote_ip\n", FILE_APPEND);

// 1. Check if root directory is accessible
$repo_root = realpath(__DIR__ . '/..');
if (!$repo_root || !is_dir("$repo_root/.git")) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Git repository not found'], JSON_PRETTY_PRINT);
    file_put_contents($log_file, "[$timestamp] ERROR: Git repo not found at $repo_root\n", FILE_APPEND);
    exit;
}

// 2. Fix git permissions (dubious ownership error) - try shell_exec for shell context
$shell_wrapper = "bash -c 'git config --global --add safe.directory \"$repo_root\" 2>&1 || true'";
@shell_exec($shell_wrapper);
file_put_contents($log_file, "[$timestamp] Git config attempted via shell_exec\n", FILE_APPEND);

// 2b. Also try local git config
$local_fix = "cd '$repo_root' && git config --local user.email 'ci@shopvivaliz.com.br' 2>&1";
@exec($local_fix, $local_output);

// 3. Execute git pull
$cmd = "bash -c 'cd \"$repo_root\" && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1'";
@exec($cmd, $output, $return_code);

// 3. Check if checkout file was updated
$checkout_file = "$repo_root/checkout/index.php";
$has_pagarme = false;
$has_mercado = false;

if (file_exists($checkout_file)) {
    $content = file_get_contents($checkout_file);
    $has_pagarme = strpos($content, 'pagarme') !== false;
    $has_mercado = strpos($content, 'mercado_pago') !== false;
}

// 4. Clear OPcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
    file_put_contents($log_file, "[$timestamp] OPcache cleared\n", FILE_APPEND);
}

// 5. Prepare response
$success = ($return_code === 0 && $has_pagarme && $has_mercado);

$response = [
    'status' => $success ? 'success' : 'partial',
    'timestamp' => $timestamp,
    'git_return_code' => $return_code,
    'git_output' => implode("\n", array_slice($output, -10)), // Last 10 lines
    'checkout_file_exists' => file_exists($checkout_file),
    'pagarme_found' => $has_pagarme,
    'mercado_pago_found' => $has_mercado,
    'message' => $success
        ? 'Git pull successful and both payment gateways are present'
        : 'Git pull completed but verification issues detected'
];

// Log result
file_put_contents($log_file, "[$timestamp] Result: " . json_encode($response) . "\n", FILE_APPEND);

// Return response
http_response_code($success ? 200 : 206);
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
