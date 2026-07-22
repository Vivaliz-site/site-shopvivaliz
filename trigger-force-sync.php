<?php
/**
 * Trigger Force Sync - Força sincronização imediata do Git na VM
 * Execução: curl https://shopvivaliz.com.br/trigger-force-sync.php
 *
 * Cria um arquivo trigger que o daemon de sincronização detecta
 */

header('Content-Type: application/json; charset=utf-8');

$trigger_file = '/tmp/shopvivaliz-force-sync-' . time();
$output = [];
$return_code = 0;

// Tentar criar arquivo trigger (se em produção)
if (file_exists('/home/ubuntu/site-shopvivaliz')) {
    // Estamos na VM - executar sync direto
    $cmd = 'cd /home/ubuntu/site-shopvivaliz && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1';
    @exec($cmd, $output, $return_code);

    $message = $return_code === 0 ? 'Sync executed successfully' : 'Sync failed';
    $action = 'direct_exec';
} else {
    // Estamos em dev - criar arquivo trigger
    @touch($trigger_file);
    $output[] = 'Trigger file created: ' . $trigger_file;
    $message = 'Force sync triggered (will execute on next cron)';
    $action = 'trigger_file';
    $return_code = 0;
}

// Log
$log_dir = __DIR__ . '/logs';
@mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/force-sync-' . date('Y-m-d') . '.log';
$log_line = date('Y-m-d H:i:s') . ' | Action: ' . $action . ' | Result: ' . implode(' | ', $output) . "\n";
@file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);

http_response_code($return_code === 0 ? 200 : 500);
echo json_encode([
    'ok' => $return_code === 0,
    'action' => $action,
    'message' => $message,
    'output' => $output,
    'timestamp' => date('c'),
    'code' => $return_code,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
?>
