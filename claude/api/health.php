<?php
header('Content-Type: application/json; charset=utf-8');
$response = [
    'ok' => true,
    'status' => 'READY_FOR_PRODUCTION',
    'timestamp' => date('c'),
    'version' => '2.0',
    'logo' => '/assets/images/logo/vivaliz-logo.svg',
];
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>