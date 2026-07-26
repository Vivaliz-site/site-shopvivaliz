<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/includes/ml-event-tracker.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$allowed = ['page_view', 'click', 'add_to_cart', 'purchase'];
$eventType = is_array($input) ? trim((string)($input['event_type'] ?? '')) : '';
$productId = is_array($input) ? substr(trim((string)($input['product_id'] ?? '')), 0, 191) : '';
if (!in_array($eventType, $allowed, true) || $productId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_event']);
    exit;
}

if (!svml_track_event($eventType, $productId, ['path' => substr((string)($input['path'] ?? ''), 0, 500)])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'collector_unavailable']);
    exit;
}
http_response_code(202);
echo json_encode(['ok' => true]);
