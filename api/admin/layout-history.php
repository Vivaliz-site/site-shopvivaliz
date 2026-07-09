<?php
/**
 * API Endpoint: Histórico de Git de um layout
 * GET /api/admin/layout-history.php?page_id=homepage
 * Response: {ok: bool, history: [{hash, message, author, date}]}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/GitVersioning.php';

use Core\GitVersioning;

header('Content-Type: application/json; charset=utf-8');

try {
    $pageId = $_GET['page_id'] ?? $_POST['page_id'] ?? null;

    if (!$pageId) {
        throw new Exception('page_id parameter required');
    }

    // Validar page_id (apenas alfanumérico e hífens)
    if (!preg_match('/^[a-z0-9-]+$/', $pageId)) {
        throw new Exception('Invalid page_id format');
    }

    $git = new GitVersioning();

    if (!$git->isEnabled()) {
        echo json_encode([
            'ok' => false,
            'error' => 'Git não está habilitado neste servidor',
            'history' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $history = $git->getHistory($pageId, 50);

    echo json_encode([
        'ok' => true,
        'page_id' => $pageId,
        'history' => $history,
        'count' => count($history)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
