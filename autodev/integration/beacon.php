<?php
/**
 * AutoDev Bounce Beacon
 *
 * Receives POST JSON from the tracker.php JS snippet when a bounce is detected.
 * Called via navigator.sendBeacon — must respond quickly with 204.
 */

declare(strict_types=1);

// No output allowed — sendBeacon ignores the body anyway.
header('Content-Type: text/plain', true, 204);

try {
    require_once __DIR__ . '/../core/event_collector.php';

    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['event'] ?? '') !== 'bounce') {
        exit;
    }

    track_event('bounce', [
        'session_id'  => substr((string)($data['session_id'] ?? ''), 0, 64),
        'uri'         => substr((string)($data['uri'] ?? ''), 0, 512),
        'elapsed_ms'  => (int)($data['elapsed_ms'] ?? 0),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);
} catch (\Throwable $e) {
    // Silent — never expose errors to the client
}

exit;
