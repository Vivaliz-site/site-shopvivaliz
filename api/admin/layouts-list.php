<?php
/**
 * API Endpoint: Listar layouts disponíveis
 * Fonte primária: MySQL (via LayoutManager)
 * Fallback: Arquivos JSON se BD indisponível
 *
 * GET /api/admin/layouts-list.php
 * Response: {ok: bool, count: int, layouts: [{page_id, name, type, viewport, sections, updated, source}]}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/LayoutManager.php';

use Core\Database;
use Core\LayoutManager;

header('Content-Type: application/json; charset=utf-8');

try {
    $layouts = [];
    $source = 'database';

    // Tentar carregar do banco primeiro
    try {
        $db = Database::connect();
        $layoutManager = new LayoutManager($db, $_SESSION['user_id'] ?? 0);
        $allLayouts = $layoutManager->getAll(500, 0);

        foreach ($allLayouts as $layout) {
            $config = json_decode($layout['config'] ?? '{}', true);
            $layouts[] = [
                'page_id' => $layout['page_id'] ?? '',
                'name' => $layout['page_id'] ?? 'Unknown',
                'type' => $layout['page_type'] ?? 'homepage',
                'viewport' => $layout['viewport'] ?? 'both',
                'sections' => count($config['sections'] ?? []),
                'updated' => date('Y-m-d H:i', strtotime($layout['updated_at'] ?? 'now')),
                'source' => 'database',
                'published' => (bool)($layout['published'] ?? false)
            ];
        }
    } catch (\Throwable $e) {
        // BD falhou, fazer fallback para arquivos
        $source = 'files';
        $layoutsDir = __DIR__ . '/../../layouts';

        if (is_dir($layoutsDir)) {
            $files = glob($layoutsDir . '/*-config.json');

            foreach ($files as $file) {
                $pageId = str_replace(['-config.json', $layoutsDir . '/'], '', $file);
                $content = file_get_contents($file);
                $config = json_decode($content, true);

                if ($config) {
                    $layouts[] = [
                        'page_id' => $pageId,
                        'name' => $config['page_id'] ?? $pageId,
                        'type' => $config['type'] ?? 'unknown',
                        'viewport' => $config['viewport'] ?? 'both',
                        'sections' => count($config['sections'] ?? []),
                        'updated' => date('Y-m-d H:i', filemtime($file)),
                        'source' => 'files',
                        'published' => false
                    ];
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'count' => count($layouts),
        'layouts' => $layouts,
        'source' => $source
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
