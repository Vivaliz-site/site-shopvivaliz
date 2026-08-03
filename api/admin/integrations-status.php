<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/integration-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$state = svih_read_state();
$checkedAt = strtotime((string)($state['checked_at'] ?? '')) ?: 0;
if ($state === [] || $checkedAt < time() - 65 * 60 || (string)($_GET['refresh'] ?? '') === '1') $state = svih_check_all(false);

http_response_code(($state['ok'] ?? false) ? 200 : 207);
echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
