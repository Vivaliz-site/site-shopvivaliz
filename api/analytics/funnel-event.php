<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap-env.php';
require_once __DIR__ . '/../../includes/pdo-database.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function svfe_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    svfe_reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
    svfe_reply(403, ['ok' => false, 'error' => 'cross_site_blocked']);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
    svfe_reply(400, ['ok' => false, 'error' => 'invalid_payload']);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    svfe_reply(400, ['ok' => false, 'error' => 'invalid_json']);
}

$allowedEvents = [
    'view_item',
    'view_item_list',
    'add_to_cart',
    'remove_from_cart',
    'view_cart',
    'begin_checkout',
    'add_shipping_info',
    'add_payment_info',
    'generate_lead',
    'search',
];
$eventName = strtolower(trim((string)($input['event_name'] ?? '')));
if (!in_array($eventName, $allowedEvents, true)) {
    svfe_reply(422, ['ok' => false, 'error' => 'event_not_allowed']);
}

$eventId = strtolower(trim((string)($input['event_id'] ?? '')));
if (!preg_match('/^[a-f0-9-]{16,64}$/', $eventId)) {
    svfe_reply(422, ['ok' => false, 'error' => 'invalid_event_id']);
}

$pagePath = trim((string)($input['page_path'] ?? '/'));
$pagePath = (string)(parse_url($pagePath, PHP_URL_PATH) ?: '/');
$pagePath = '/' . ltrim(substr($pagePath, 0, 240), '/');
$itemCount = max(0, min(100, (int)($input['item_count'] ?? 0)));
$clientId = trim((string)($input['client_id'] ?? ''));
// Accepts the randomId() UUID/hex fallback used without consent, and the
// real GA4 client_id format ("digits.digits", optionally "|session_id")
// that funnelClientId() in js/shopvivaliz-google-events.js sends whenever
// analytics consent was granted and a _ga cookie already exists.
if (!preg_match('/^(?:[a-f0-9-]{16,64}|\d+\.\d+(?:\|[A-Za-z0-9._$-]{1,96})?)$/', $clientId)) {
    svfe_reply(422, ['ok' => false, 'error' => 'invalid_client_id']);
}

$secret = (string)(getenv('APP_KEY') ?: getenv('SHOPVIVALIZ_AGENT_KEY') ?: 'shopvivaliz-funnel-v1');
$sessionHash = hash_hmac('sha256', $clientId, $secret);

$pdo = null;
foreach (['sv_pdo', 'sv_db', 'db', 'get_pdo'] as $function) {
    if (function_exists($function)) {
        $candidate = $function();
        if ($candidate instanceof PDO) {
            $pdo = $candidate;
            break;
        }
    }
}
if (!$pdo instanceof PDO) {
    svfe_reply(503, ['ok' => false, 'error' => 'database_unavailable']);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sv_conversion_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id VARCHAR(64) NOT NULL,
        event_name VARCHAR(64) NOT NULL,
        page_path VARCHAR(255) NOT NULL,
        item_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        session_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sv_conversion_event_id (event_id),
        KEY idx_sv_conversion_event_created (event_name, created_at),
        KEY idx_sv_conversion_session_created (session_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $rate = $pdo->prepare('SELECT COUNT(*) FROM sv_conversion_events WHERE session_hash = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
    $rate->execute([$sessionHash]);
    if ((int)$rate->fetchColumn() >= 30) {
        svfe_reply(429, ['ok' => false, 'error' => 'rate_limited']);
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO sv_conversion_events (event_id, event_name, page_path, item_count, session_hash, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    $stmt->execute([$eventId, $eventName, $pagePath, $itemCount, $sessionHash]);
    svfe_reply(201, ['ok' => true, 'stored' => $stmt->rowCount() === 1]);
} catch (Throwable $e) {
    error_log('funnel-event failure: ' . $e->getMessage());
    svfe_reply(500, ['ok' => false, 'error' => 'storage_failed']);
}
