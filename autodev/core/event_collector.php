<?php
/**
 * AutoDev Event Collector
 * Tracks and persists user interaction events with file-locking for race-condition safety.
 */

declare(strict_types=1);

define('EVENT_LOG_PATH', __DIR__ . '/../../data/events.log');

// Valid event types
const EVENT_TYPES = [
    'page_view',
    'product_view',
    'add_to_cart',
    'checkout_start',
    'order_complete',
    'bounce',
];

/**
 * Ensure the data directory exists.
 */
function _ensure_data_dir(): void
{
    $dir = dirname(EVENT_LOG_PATH);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create data directory: $dir");
        }
    }
}

/**
 * Append a single event to the event log.
 *
 * @param string $event  One of the EVENT_TYPES constants.
 * @param array  $data   Arbitrary context (product_id, session_id, ip, user_agent, …).
 * @return bool          True on success, false on failure.
 */
function track(string $event, array $data = []): bool
{
    if (!in_array($event, EVENT_TYPES, true)) {
        error_log("[AutoDev] Unknown event type ignored: $event");
        return false;
    }

    _ensure_data_dir();

    $record = [
        'event'      => $event,
        'timestamp'  => time(),
        'datetime'   => date('Y-m-d H:i:s'),
        'ip'         => _get_client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'cli',
        'data'       => $data,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $fh = fopen(EVENT_LOG_PATH, 'a');
    if ($fh === false) {
        error_log('[AutoDev] Cannot open events.log for writing.');
        return false;
    }

    $locked = flock($fh, LOCK_EX);
    if (!$locked) {
        fclose($fh);
        error_log('[AutoDev] Could not acquire file lock on events.log.');
        return false;
    }

    $written = fwrite($fh, $line);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $written !== false;
}

/**
 * Read and optionally filter events from the log.
 *
 * @param int|null    $since_timestamp  Unix timestamp — only events at or after this time.
 * @param string|null $event_type       Filter to a specific event type.
 * @return array                        Array of event records (associative arrays).
 */
function get_events(?int $since_timestamp = null, ?string $event_type = null): array
{
    _ensure_data_dir();

    if (!file_exists(EVENT_LOG_PATH)) {
        return [];
    }

    $fh = fopen(EVENT_LOG_PATH, 'r');
    if ($fh === false) {
        error_log('[AutoDev] Cannot open events.log for reading.');
        return [];
    }

    flock($fh, LOCK_SH);

    $events = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }

        // Filter by timestamp
        if ($since_timestamp !== null && ($record['timestamp'] ?? 0) < $since_timestamp) {
            continue;
        }

        // Filter by event type
        if ($event_type !== null && ($record['event'] ?? '') !== $event_type) {
            continue;
        }

        $events[] = $record;
    }

    flock($fh, LOCK_UN);
    fclose($fh);

    return $events;
}

/**
 * Remove events older than $days days from the log.
 * Rewrites the file atomically to avoid partial reads.
 *
 * @param int $days  Events older than this many days are discarded.
 * @return int       Number of events removed.
 */
function flush_old_events(int $days = 30): int
{
    _ensure_data_dir();

    if (!file_exists(EVENT_LOG_PATH)) {
        return 0;
    }

    $cutoff = time() - ($days * 86400);

    // Read all events under a shared lock
    $fh = fopen(EVENT_LOG_PATH, 'r');
    if ($fh === false) {
        error_log('[AutoDev] Cannot open events.log for flush read.');
        return 0;
    }

    flock($fh, LOCK_SH);
    $all_lines = [];
    while (($line = fgets($fh)) !== false) {
        $all_lines[] = $line;
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    $kept    = [];
    $removed = 0;

    foreach ($all_lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $record = json_decode($trimmed, true);
        if (!is_array($record) || ($record['timestamp'] ?? 0) < $cutoff) {
            $removed++;
            continue;
        }
        $kept[] = rtrim($line) . "\n";
    }

    // Rewrite file atomically using a temp file
    $tmp = EVENT_LOG_PATH . '.tmp';
    $wh  = fopen($tmp, 'w');
    if ($wh === false) {
        error_log('[AutoDev] Cannot open temp file for flush write.');
        return 0;
    }

    flock($wh, LOCK_EX);
    foreach ($kept as $line) {
        fwrite($wh, $line);
    }
    flock($wh, LOCK_UN);
    fclose($wh);

    rename($tmp, EVENT_LOG_PATH);

    return $removed;
}

/**
 * Detect the real client IP, handling common proxy headers.
 */
function _get_client_ip(): string
{
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            // X-Forwarded-For can be a comma-separated list
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}
