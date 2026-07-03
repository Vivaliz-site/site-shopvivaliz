<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Recebe notificação do Mercado Livre
$payload = file_get_contents('php://input');
$data    = json_decode($payload ?: '', true) ?? [];

// Log seguro (sem tokens)
$logDir  = dirname(__DIR__, 2) . '/storage/logs';
@mkdir($logDir, 0755, true);
$logFile = $logDir . '/ml-webhook.log';
$entry   = date('c') . ' | topic=' . ($data['topic'] ?? '?')
         . ' resource=' . ($data['resource'] ?? '?')
         . ' user_id=' . ($data['user_id'] ?? '?') . PHP_EOL;
@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

http_response_code(200);
echo 'OK';
