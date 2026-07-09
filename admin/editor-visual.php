<?php
/**
 * Editor Visual com Drag-and-Drop
 * Interface moderna para edição de layouts
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../core/init-editor.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/LayoutManager.php';

use Core\Database;
use Core\LayoutManager;
use Core\DynamicRenderer;

$currentLayout = $_GET['layout'] ?? 'homepage';
$layoutJson = [];

// Carregar layout do BD ou arquivo
try {
    $db = Database::connect();
    $renderer = DynamicRenderer::fromDatabase($currentLayout);
} catch (\Throwable $e) {
    // Fallback para arquivo
    $layoutPath = __DIR__ . '/../layouts/' . $currentLayout . '-config.json';
    if (file_exists($layoutPath)) {
        $layoutJson = json_decode(file_get_contents($layoutPath), true);
    } else {
        $layoutPath = __DIR__ . '/../layouts/homepage-example.json';
        if (file_exists($layoutPath)) {
            $layoutJson = json_decode(file_get_contents($layoutPath), true);
        }
    }
}

if (empty($layoutJson)) {
    $layoutJson = [
        'page_id' => $currentLayout,
        'meta' => ['title' => ucfirst($currentLayout), 'description' => ''],
        'sections' => []
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Visual — ShopVivaliz</title>
    <link rel="stylesheet" href="/assets/css/editor-dragdrop.css">
</head>
<body>
    <div class="editor-toolbar">
        <h1>🎨 Editor Visual</h1>
        <button class="secondary" id="toggle-code-view">📝 Ver JSON</button>
        <button id="btn-save-final">💾 Salvar</button>
    </div>

    <div id="editor-container" class="editor-container">
        <!-- Paleta de Blocos (Esquerda) -->
        <div id="editor-palette" role="region" aria-label="Blocos disponíveis"></div>

        <!-- Canvas (Centro) -->
        <div id="editor-canvas" role="region" aria-label="Canvas de edição"></div>

        <!-- Properties Panel (Direita) -->
        <div id="editor-properties" role="region" aria-label="Propriedades do bloco">
            <div class="properties-empty">Selecione um bloco para editar</div>
        </div>
    </div>

    <!-- Code View -->
    <div id="editor-code">
        <textarea id="layout-json-code" spellcheck="false"></textarea>
    </div>

    <!-- Sortable.js CDN (leve, sem dependências) -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <!-- Editor scripts -->
    <script>
        // Passar layout inicial para o JS
        window.initialLayoutJson = <?php echo json_encode($layoutJson, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="/js/editor-dragdrop.js"></script>

    <style>
        body {
            margin: 0;
            padding: 0;
            background: white;
        }
    </style>
</body>
</html>
