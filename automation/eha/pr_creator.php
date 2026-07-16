<?php
/**
 * EHA PR Creator — cria PR automático com escapeshellarg para segurança
 */

require_once dirname(__DIR__) . '/create_auto_pr.php';
require_once dirname(__DIR__, 2) . '/autodev/actions/safe_layout_applier.php';

function eha_create_auto_pr(string $msg): array
{
    $proposal = create_auto_pr($msg);
    _eha_log('PR_CREATOR proposal=' . json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $proposal;
}

function eha_rollback(string $reason = 'high_risk_detected'): array
{
    $result = rollback($reason);
    _eha_log('ROLLBACK result=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $result;
}

function _eha_log(string $msg): void {
    $dir  = __DIR__ . '/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/eha_events.txt';
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // janela deslizante: mantém últimas 500 linhas
    $lines = @file($file) ?: [];
    if (count($lines) > 500) {
        file_put_contents($file, implode('', array_slice($lines, -500)), LOCK_EX);
    }
}
