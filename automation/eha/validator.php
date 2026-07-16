<?php
/**
 * EHA Validator — valida se o release está pronto para produção
 * Aceita $metrics pré-coletados para evitar double health-check.
 */

function validate_final_release(array $metrics = []): array {
    if (empty($metrics)) {
        require_once __DIR__ . '/health_check.php';
        $metrics = collect_metrics();
    }

    $checkout_ok = (bool)($metrics['checkout_ok'] ?? false);
    $api_ok      = (bool)($metrics['api_ok']      ?? false);
    $db_ok       = (bool)($metrics['db_ok']       ?? false);
    $pages_ok    = (bool)($metrics['pages_ok']    ?? false);

    $all_ok = $checkout_ok && $api_ok && $db_ok && $pages_ok;

    $result = [
        'status'      => $all_ok ? 'READY_FOR_PRODUCTION' : 'BLOCKED',
        'checkout_ok' => $checkout_ok,
        'api_ok'      => $api_ok,
        'db_ok'       => $db_ok,
        'pages_ok'    => $pages_ok,
        'timestamp'   => date('c'),
    ];

    $dir = __DIR__ . '/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/last_status.txt', $result['status']);
    file_put_contents($dir . '/last_validation.json', json_encode($result, JSON_PRETTY_PRINT));

    return $result;
}
