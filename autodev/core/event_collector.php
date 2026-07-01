<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

const AUTODEV_EVENT_LOG_PATH = __DIR__ . '/../data/events.log';
const AUTODEV_EVENT_TYPES = [
    'page_view',
    'product_view',
    'add_to_cart',
    'checkout_start',
    'checkout_submit',
    'order_complete',
    'search',
    'admin_view',
    'bounce',
];

function autodev_track(string $event, array $data = []): bool
{
    if (!in_array($event, AUTODEV_EVENT_TYPES, true)) {
        return false;
    }

    autodev_data_dir();
    $record = [
        'event' => $event,
        'time' => time(),
        'ts' => time(),
        'datetime' => date('c'),
        'path' => (string)($_SERVER['REQUEST_URI'] ?? ($data['path'] ?? 'cli')),
        'ip' => autodev_client_ip(),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'),
        'session_id' => autodev_session_id(),
        'data' => $data,
    ];

    return file_put_contents(
        AUTODEV_EVENT_LOG_PATH,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    ) !== false;
}

function autodev_get_events(?int $since = null, ?string $eventType = null): array
{
    if (!is_file(AUTODEV_EVENT_LOG_PATH)) {
        return [];
    }

    $events = [];
    $fh = fopen(AUTODEV_EVENT_LOG_PATH, 'r');
    if ($fh === false) {
        return [];
    }

    flock($fh, LOCK_SH);
    while (($line = fgets($fh)) !== false) {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            continue;
        }
        if ($since !== null && (int)($decoded['time'] ?? 0) < $since) {
            continue;
        }
        if ($eventType !== null && (string)($decoded['event'] ?? '') !== $eventType) {
            continue;
        }
        $events[] = $decoded;
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    return $events;
}

function autodev_flush_old_events(int $days = 30): int
{
    if (!is_file(AUTODEV_EVENT_LOG_PATH)) {
        return 0;
    }

    $cutoff = time() - ($days * 86400);
    $events = autodev_get_events();
    $kept = [];
    $removed = 0;
    foreach ($events as $event) {
        if ((int)($event['time'] ?? 0) < $cutoff) {
            $removed++;
            continue;
        }
        $kept[] = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    file_put_contents(AUTODEV_EVENT_LOG_PATH, implode(PHP_EOL, $kept) . ($kept ? PHP_EOL : ''), LOCK_EX);
    return $removed;
}

function autodev_session_id(): string
{
    $incoming = (string)($_SERVER['HTTP_X_AUTODEV_SESSION'] ?? '');
    if ($incoming !== '') {
        return substr(preg_replace('/[^a-zA-Z0-9_-]+/', '', $incoming), 0, 80);
    }
    return substr(hash('sha256', autodev_client_ip() . '|' . ((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'))), 0, 32);
}

function autodev_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}
