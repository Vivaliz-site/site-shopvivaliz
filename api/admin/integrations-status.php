<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/integration-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$refreshRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals('1', (string)($_POST['refresh'] ?? ''));

$state = svih_read_state();
$checkedAt = strtotime((string)($state['checked_at'] ?? '')) ?: 0;
if ($state === [] || $checkedAt < time() - 65 * 60 || $refreshRequested) {
    // O clique em "Atualizar agora" executa de fato as rotinas seguras de
    // renovacao. A leitura automatica apenas valida o estado atual.
    $state = svih_check_all($refreshRequested);
}

http_response_code(($state['ok'] ?? false) ? 200 : 207);
echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
