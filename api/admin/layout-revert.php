<?php
/**
 * API Endpoint: Reverter layout a uma versão anterior
 * POST /api/admin/layout-revert.php
 * Body: {page_id, commit_hash}
 * Response: {ok: bool, config: {...}}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/GitVersioning.php';

use Core\GitVersioning;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $pageId = $input['page_id'] ?? null;
    $hash = $input['commit_hash'] ?? null;

    if (!$pageId || !$hash) {
        throw new Exception('Missing required fields: page_id, commit_hash');
    }

    // Validar formato
    if (!preg_match('/^[a-z0-9-]+$/', $pageId) || !preg_match('/^[a-f0-9]+$/', $hash)) {
        throw new Exception('Invalid page_id or commit_hash format');
    }

    $git = new GitVersioning();

    if (!$git->isEnabled()) {
        throw new Exception('Git não está habilitado');
    }

    $config = $git->revertToCommit($hash, $pageId);

    if (!$config) {
        throw new Exception("Não foi possível carregar a versão do commit {$hash}");
    }

    echo json_encode([
        'ok' => true,
        'page_id' => $pageId,
        'commit_hash' => $hash,
        'config' => $config,
        'message' => 'Versão carregada. Revise e salve para confirmar o revert.'
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
