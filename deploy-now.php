<?php
/**
 * Deploy Imediato - Força sincronização via git
 * URL: https://shopvivaliz.com.br/deploy-now.php
 */

header('Content-Type: application/json; charset=utf-8');

$output = [];
$return_code = 0;

// Executar git reset --hard origin/main
$cmd = 'cd /home/ubuntu/site-shopvivaliz 2>/dev/null && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1';
exec($cmd, $output, $return_code);

// Executar apenas se estamos no servidor (não local)
if (php_uname('a') === getenv('HOSTNAME') || gethostname() !== 'DESKTOP-') {
    // Log
    file_put_contents('/tmp/deploy-now.log',
        date('Y-m-d H:i:s') . ' - Deploy executado: ' . implode("\n", $output) . "\n",
        FILE_APPEND
    );
}

http_response_code($return_code === 0 ? 200 : 500);
echo json_encode([
    'ok' => $return_code === 0,
    'code' => $return_code,
    'output' => $output,
    'timestamp' => date('c'),
    'host' => gethostname(),
]);
?>
