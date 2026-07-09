<?php
/**
 * API Endpoint: Lista de blocos disponíveis com metadata
 * Usado pelo editor visual drag-and-drop para montar a paleta
 *
 * GET /api/admin/blocks-list.php
 * Response: {ok: bool, blocks: [{name, icon, category, description, metadata}]}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/init-editor.php';

use Core\BlockRegistry;

header('Content-Type: application/json; charset=utf-8');

try {
    $blocks = BlockRegistry::getAll();

    $formatted = array_map(function ($block) {
        return [
            'name' => $block['name'] ?? '',
            'icon' => $block['icon'] ?? '📦',
            'category' => $block['category'] ?? 'other',
            'description' => $block['description'] ?? '',
            'metadata' => $block['metadata'] ?? []
        ];
    }, $blocks);

    echo json_encode([
        'ok' => true,
        'blocks' => $formatted,
        'count' => count($formatted)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
