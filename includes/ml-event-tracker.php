<?php
declare(strict_types=1);

function svml_track_event(string $eventType, string $productId, array $metadata = []): bool
{
    $allowed = ['page_view', 'click', 'add_to_cart', 'purchase'];
    $eventType = trim($eventType);
    $productId = substr(trim($productId), 0, 191);
    if (!in_array($eventType, $allowed, true) || $productId === '') {
        return false;
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $sessionId = session_id();
    $visitorSeed = $sessionId . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $payload = json_encode([
        'event_type' => $eventType,
        'product_id' => $productId,
        'visitor_id' => hash('sha256', $visitorSeed),
        'session_id' => $sessionId !== '' ? hash('sha256', $sessionId) : null,
        'metadata' => array_merge([
            'path' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
            'source' => 'storefront_php',
        ], $metadata),
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($payload) || $payload === '') {
        return false;
    }

    $ch = curl_init('http://127.0.0.1:8091/event');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $body !== false && $status === 202;
}
