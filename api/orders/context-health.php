<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$root=dirname(__DIR__,2);
$create=(string)@file_get_contents($root.'/api/orders/create.php');
$validated=(string)@file_get_contents($root.'/api/orders/create-validated.php');
$processor=(string)@file_get_contents($root.'/api/orders/process-validated.php');
$checks=[
 'single_entrypoint'=>str_contains($create,"create-validated.php"),
 'single_input_read'=>substr_count($validated,"php://input")===1,
 'validated_processor'=>str_contains($validated,"process-validated.php"),
 'context_required'=>str_contains($processor,'svorc_body')&&str_contains($processor,'svorc_items'),
 'legacy_processor_bypassed'=>!str_contains($validated,"require __DIR__ . '/create.php'"),
];
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'endpoint'=>'orders-context','checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);