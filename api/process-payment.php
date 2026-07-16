<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'legacy_endpoint_retired',
    'message' => 'Use the order-bound Mercado Pago boleto or Checkout Pro endpoints.',
]);
