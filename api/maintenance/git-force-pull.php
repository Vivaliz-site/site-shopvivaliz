<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'endpoint_disabled',
    'message' => 'Remote destructive Git synchronization is disabled. Use the protected deployment pipeline.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
