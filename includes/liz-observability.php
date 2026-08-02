<?php
declare(strict_types=1);

function sv_liz_record_metric(array $event): void
{
    $directory = dirname(__DIR__) . '/storage/liz-metrics';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        error_log('[Liz] metrics directory unavailable');
        return;
    }
    $safe = [
        'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DateTime::ATOM),
        'request_id' => bin2hex(random_bytes(8)),
        'intent' => (string)($event['intent'] ?? 'unknown'),
        'provider' => $event['provider'] ?? null,
        'latency_ms' => max(0, (int)($event['latency_ms'] ?? 0)),
        'grounding_status' => (string)($event['grounding_status'] ?? 'unknown'),
        'grounding_sources' => array_values(array_map('strval', (array)($event['grounding_sources'] ?? []))),
        'handoff' => !empty($event['handoff']),
        'outcome' => (string)($event['outcome'] ?? 'unknown'),
        'http_status' => (int)($event['http_status'] ?? 0),
    ];
    $line = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($line)) {
        @file_put_contents($directory . '/events.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
