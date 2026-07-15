<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function sp_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

sp_env_load(__DIR__ . '/../.env');
sp_env_load(__DIR__ . '/../.env.local');

$reportPath = getenv('REPORT_LOG_PATH') ?: __DIR__ . '/../logs/email-activity-report.txt';
$emailTo = getenv('EMAIL_TO') ?: 'fredmourao@gmail.com,atendimento@shopvivaliz.com.br';
$hours = getenv('REPORT_WINDOW_HOURS') ?: '4';

if (!is_file($reportPath)) {
    fwrite(STDERR, "Relatorio nao encontrado em: {$reportPath}\n");
    exit(1);
}

$bodyText = trim((string)file_get_contents($reportPath));
if ($bodyText === '') {
    fwrite(STDERR, "Relatorio vazio em: {$reportPath}\n");
    exit(1);
}

$subject = '[ShopVivaliz] Relatorio autonomo ' . $hours . 'h - ' . date('d/m/Y H:i');
$html = '<pre style="font-family:Consolas,Monaco,monospace;white-space:pre-wrap;line-height:1.45">'
    . htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '</pre>';

$ok = send_email($emailTo, $subject, $html, $bodyText);
if (!$ok) {
    fwrite(STDERR, "Falha ao enviar relatorio pelo mailer.php\n");
    exit(1);
}

echo "Relatorio enviado com sucesso via mailer.php\n";
