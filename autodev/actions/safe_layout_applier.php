<?php
declare(strict_types=1);

/**
 * Executa rollback seguro: registra a solicitação sem operações destrutivas.
 * O rollback real exige intervenção humana via workflow autenticado.
 */
function rollback(string $reason = 'high_risk_detected'): array
{
    $ts = date('c');
    $log_dir = dirname(__DIR__, 2) . '/automation/eha/reports';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $entry = "[$ts] ROLLBACK_REQUESTED reason=$reason\n";
    file_put_contents($log_dir . '/eha.log', $entry, FILE_APPEND | LOCK_EX);

    return [
        'status'    => 'rollback_requested',
        'reason'    => $reason,
        'timestamp' => $ts,
        'note'      => 'Rollback automático requer aprovação humana. Evento registrado.',
    ];
}
