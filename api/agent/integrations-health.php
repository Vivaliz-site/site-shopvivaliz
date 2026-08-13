<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/require-agent-key.php';
sv_require_agent_key();
require_once __DIR__ . '/../../includes/integration-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// O monitor nunca rotaciona OAuth. O daemon e o unico escritor do token store.
$result = svih_check_all(false);
http_response_code(($result['ok'] ?? false) ? 200 : 207);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
