<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/init-editor.php';
require_once __DIR__ . '/../../core/GitVersioning.php';

use Core\LayoutManager;
use Core\Database;
use Core\GitVersioning;

header('Content-Type: application/json; charset=utf-8');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $pageId = $input['page_id'] ?? null;
    $config = $input['config'] ?? null;
    $pageType = $input['page_type'] ?? 'homepage';
    $viewport = $input['viewport'] ?? 'both';
    $publish = (bool)($input['publish'] ?? false);

    if (!$pageId || !$config) {
        throw new Exception('Missing required fields: page_id, config');
    }

    // Validar JSON da config
    if (is_string($config)) {
        $config = json_decode($config, true);
        if (!$config) {
            throw new Exception('Config must be valid JSON');
        }
    }

    // Tentar salvar no banco de dados
    $dbSaved = false;
    try {
        $db = Database::connect();
        $userId = $_SESSION['user_id'] ?? 0;
        $layoutManager = new LayoutManager($db, $userId);
        $dbSaved = $layoutManager->save($pageId, $config, $pageType, $viewport, $publish);
    } catch (\Throwable $dbError) {
        error_log("Database save failed: " . $dbError->getMessage());
        // Fallback para arquivo JSON se BD falhar
        $dbSaved = false;
    }

    // Fallback: salvar também em arquivo JSON (backup)
    $layoutPath = __DIR__ . '/../../layouts/' . $pageId . '-config.json';
    $fileSaved = file_put_contents(
        $layoutPath,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    if (!$dbSaved && !$fileSaved) {
        throw new Exception('Failed to save layout (both database and file failed)');
    }

    // Auto-commit via git se disponível
    $gitCommit = null;
    try {
        $git = new GitVersioning();
        if ($git->isEnabled()) {
            $summary = $_POST['git_summary'] ?? $input['git_summary'] ?? 'Atualização via editor';
            $gitCommit = $git->commitLayout($pageId, $summary);
        }
    } catch (\Throwable $gitError) {
        error_log("Git commit failed (non-fatal): " . $gitError->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Layout salvo com sucesso',
        'page_id' => $pageId,
        'saved_to' => $dbSaved ? 'database' : 'file',
        'published' => $publish,
        'git_commit' => $gitCommit
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
