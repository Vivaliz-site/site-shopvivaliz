<?php
declare(strict_types=1);

$status = [
    'apcu_enabled' => function_exists('apcu_fetch'),
    'apc_enabled_ini' => ini_get('apc.enabled'),
    'apc_enable_cli_ini' => ini_get('apc.enable_cli'),
    'apcu_cache_info' => function_exists('apcu_cache_info') ? @apcu_cache_info(true) : null,
];

header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);
