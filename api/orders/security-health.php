<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$root=dirname(__DIR__,2);
$checks=[
 'request_context'=>is_file($root.'/includes/order-request-context.php'),
 'authoritative_items'=>is_file($root.'/includes/order-authoritative.php'),
 'idempotency'=>is_file($root.'/includes/order-idempotency.php'),
 'rate_limit'=>is_file($root.'/includes/order-rate-limit.php'),
 'validated_endpoint'=>is_file($root.'/api/orders/create-validated.php'),
];
$validator=(string)@file_get_contents($root.'/api/orders/create-validated.php');
$checks['duplicate_block']=str_contains($validator,'duplicate_order_request');
$checks['rate_limit_block']=str_contains($validator,'rate_limit_exceeded');
$checks['single_request_context']=str_contains($validator,'svorc_set');
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'endpoint'=>'orders-security','checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);