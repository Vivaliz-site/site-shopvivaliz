<?php
declare(strict_types=1);

function sv_log_path(string $channel = 'app'): string
{
    $base = dirname(__DIR__, 2) . '/logs';
    $date = gmdate('Y-m-d');
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base . '/' . $channel . '-' . $date . '.log';
}

function sv_log(string $message, string $channel = 'app', array $context = []): void
{
    $line = [
        'ts' => gmdate(DATE_ATOM),
        'channel' => $channel,
        'message' => $message,
        'context' => $context,
    ];
    @file_put_contents(sv_log_path($channel), json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
