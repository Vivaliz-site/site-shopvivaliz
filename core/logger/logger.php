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

function sv_log_redact(mixed $value): mixed
{
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string)$key);
            if (preg_match('/(token|secret|password|passwd|apikey|api_key|authorization|signature)/', $keyText) === 1) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = sv_log_redact($item);
        }
        return $redacted;
    }

    if (is_string($value)) {
        $value = preg_replace('/\bsk-[A-Za-z0-9_\-]{16,}\b/', '[redacted-openai-key]', $value) ?? $value;
        $value = preg_replace('/\bAIza[A-Za-z0-9_\-]{20,}\b/', '[redacted-google-key]', $value) ?? $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._\-]{20,}/i', 'Bearer [redacted]', $value) ?? $value;
    }

    return $value;
}

function sv_log_rotate_if_needed(string $path, int $maxBytes = 5242880): void
{
    if (!is_file($path) || filesize($path) < $maxBytes) {
        return;
    }

    $rotated = $path . '.' . gmdate('His') . '.old';
    @rename($path, $rotated);
}

function sv_log(string $message, string $channel = 'app', array $context = []): void
{
    $path = sv_log_path($channel);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    sv_log_rotate_if_needed($path);
    $line = [
        'ts' => gmdate(DATE_ATOM),
        'channel' => $channel,
        'message' => $message,
        'context' => sv_log_redact($context),
    ];
    @file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
