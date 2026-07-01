<?php
/**
 * EHA Risk Classifier — classifica o risco de um log/métrica
 */

function classify(string|array $error_log): string {
    // aceita string (raw log) ou array de métricas
    if (is_array($error_log)) {
        $metrics = $error_log;
        if (!empty($metrics['checkout_fail']) || !empty($metrics['e2e_failed'])) return 'HIGH';
        if (!empty($metrics['error_high']))   return 'HIGH';
        if (!empty($metrics['error_medium'])) return 'MEDIUM';
        return 'LOW';
    }

    $log = strtolower((string) $error_log);

    // padrões críticos → HIGH
    $high_patterns = [
        'fatal error', 'exception', 'checkout fail', 'payment fail',
        'database error', 'connection refused', 'rollback', 'critical',
        'e2e_failed', '500 internal', 'segfault',
    ];
    foreach ($high_patterns as $p) {
        if (str_contains($log, $p)) return 'HIGH';
    }

    // padrões de atenção → MEDIUM
    $medium_patterns = [
        'warning', 'deprecated', 'missing route', 'missing image',
        'undefined', 'null', '404 not found', 'timeout',
    ];
    foreach ($medium_patterns as $p) {
        if (str_contains($log, $p)) return 'MEDIUM';
    }

    return 'LOW';
}
