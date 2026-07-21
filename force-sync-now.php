<?php
/**
 * Force Sync Now - Força sincronização Git imediata
 * Acesso: https://shopvivaliz.com.br/force-sync-now.php
 */

header('Content-Type: application/json; charset=utf-8');

$output = [];
$return_code = 0;

// Executar git reset --hard origin/main
$cmd = 'cd /home/ubuntu/site-shopvivaliz 2>/dev/null && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1';
@exec($cmd, $output, $return_code);

http_response_code($return_code === 0 ? 200 : 500);
echo json_encode([
    'ok' => $return_code === 0,
    'code' => $return_code,
    'output' => $output,
    'timestamp' => date('c'),
    'message' => $return_code === 0 ? 'Sync successful' : 'Sync failed'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
