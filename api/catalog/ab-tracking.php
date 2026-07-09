<?php
/**
 * API Endpoint: Rastreamento A/B (impressões e conversões)
 *
 * POST /api/catalog/ab-tracking.php
 * Body: {action: 'impression'|'conversion', page_id, variant_id, value?}
 * Response: {ok: bool}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/LayoutManager.php';

use Core\Database;
use Core\LayoutManager;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $action = $input['action'] ?? null;
    $variantId = (int)($input['variant_id'] ?? 0);
    $value = (float)($input['value'] ?? 0);

    if (!$action || !in_array($action, ['impression', 'conversion'])) {
        throw new Exception('Invalid action');
    }

    if ($variantId <= 0) {
        throw new Exception('Invalid variant_id');
    }

    $db = Database::connect();
    $layoutManager = new LayoutManager($db, 0);

    if ($action === 'impression') {
        $success = $layoutManager->recordImpression($variantId);
    } else {
        $success = $layoutManager->recordConversion($variantId, $value);
    }

    echo json_encode([
        'ok' => $success,
        'action' => $action,
        'variant_id' => $variantId
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
