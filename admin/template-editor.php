<?php
declare(strict_types=1);

// Admin guard
require_once __DIR__ . '/../includes/admin-guard.php';

// Inicializar sistema de editor visual
require_once __DIR__ . '/../core/init-editor.php';

use Core\BlockRegistry;
use Core\DynamicRenderer;

// Layouts disponíveis
$layouts = [
    'homepage' => ['name' => 'Homepage', 'description' => 'Página inicial', 'icon' => '🏠'],
    'catalogo' => ['name' => 'Catálogo', 'description' => 'Listagem de produtos', 'icon' => '📦'],
    'sobre' => ['name' => 'Sobre', 'description' => 'Página sobre a empresa', 'icon' => '👥'],
    'contato' => ['name' => 'Contato', 'description' => 'Página de contato', 'icon' => '📞'],
];

$currentLayout = $_GET['layout'] ?? 'homepage';
$layoutPath = __DIR__ . '/../layouts/' . $currentLayout . '-config.json';

// Validar layout
if (!isset($layouts[$currentLayout])) {
    $currentLayout = 'homepage';
    $layoutPath = __DIR__ . '/../layouts/homepage-config.json';
}

// Carregar layout
$layoutContent = [];
$message = null;
$messageType = 'info';

if (file_exists($layoutPath)) {
    $content = file_get_contents($layoutPath);
    $layoutContent = json_decode($content, true);
} else {
    // Usar exemplo como padrão
    if (file_exists(__DIR__ . '/../layouts/homepage-example.json')) {
        $layoutContent = json_decode(file_get_contents(__DIR__ . '/../layouts/homepage-example.json'), true);
    }
}

// Handle save via API (não mais escrita direta em arquivo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $layoutJson = $_POST['layout_json'] ?? '{}';
    $decoded = json_decode($layoutJson, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        // Chamar API para salvar (com fallback a arquivo se BD indisponível)
        $payload = json_encode([
            'page_id' => $currentLayout,
            'config' => $decoded,
            'page_type' => $_POST['page_type'] ?? 'homepage',
            'viewport' => $_POST['viewport'] ?? 'both',
            'publish' => (bool)($_POST['publish'] ?? false)
        ]);

        $ch = curl_init(__DIR__ . '/../api/admin/layouts-save.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Cookie: ' . session_name() . '=' . session_id()
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && ($result['ok'] ?? false)) {
            $message = '✓ Layout salvo com sucesso! (Fonte: ' . ($result['saved_to'] ?? 'unknown') . ')';
            $messageType = 'success';
            $layoutContent = $decoded;
        } else {
            $message = '✗ Erro ao salvar: ' . ($result['error'] ?? 'Erro desconhecido');
            $messageType = 'error';
        }
    } else {
        $message = '✗ JSON inválido: ' . json_last_error_msg();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Templates - ShopVivaliz</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #173B63 0%, #0f2847 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .main-content {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
        }

        .sidebar {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .sidebar-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e5e9f0;
            font-weight: 600;
            font-size: 14px;
        }

        .template-list {
            list-style: none;
        }

        .template-item {
            padding: 0;
            border-bottom: 1px solid #e5e9f0;
        }

        .template-item a {
            display: block;
            padding: 12px 15px;
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
            cursor: pointer;
        }

        .template-item a:hover {
            background: #f0f2f5;
        }

        .template-item a.active {
            background: #173B63;
            color: white;
            font-weight: 600;
        }

        .template-name {
            display: block;
            font-size: 14px;
            font-weight: 500;
        }

        .template-desc {
            display: block;
            font-size: 12px;
            opacity: 0.7;
            margin-top: 2px;
        }

        .editor-wrapper {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .editor-toolbar {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e5e9f0;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .editor-toolbar h2 {
            font-size: 16px;
            flex: 1;
            margin: 0;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #059669;
            color: white;
        }

        .btn-primary:hover {
            background: #047857;
        }

        .btn-secondary {
            background: #e5e9f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d5dce5;
        }

        .message {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message.error {
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #fecaca;
        }

        .editor-content {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        .editor-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .editor-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            height: 100%;
            gap: 10px;
            padding: 10px;
        }

        .editor-pane {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid #e5e9f0;
            border-radius: 6px;
        }

        .pane-header {
            background: #f8f9fa;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 600;
            border-bottom: 1px solid #e5e9f0;
        }

        textarea {
            flex: 1;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            border: none;
            resize: none;
            overflow-y: auto;
        }

        .preview {
            padding: 12px;
            overflow-y: auto;
            background: white;
        }

        .preview iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .char-count {
            font-size: 12px;
            color: #999;
            padding: 8px 12px;
            border-top: 1px solid #e5e9f0;
        }

        @media (max-width: 1024px) {
            .editor-split {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Editor de Templates</h1>
            <p>Edite as páginas do site de forma segura e rápida</p>
        </div>

        <?php if (isset($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="main-content">
            <!-- Sidebar com lista de layouts -->
            <div class="sidebar">
                <div class="sidebar-header">🎨 Layouts</div>
                <ul class="template-list">
                    <?php foreach ($layouts as $key => $meta): ?>
                        <li class="template-item">
                            <a href="?layout=<?php echo urlencode($key); ?>"
                               class="<?php echo $currentLayout === $key ? 'active' : ''; ?>">
                                <span class="template-name"><?php echo htmlspecialchars($meta['icon'] . ' ' . $meta['name']); ?></span>
                                <span class="template-desc"><?php echo htmlspecialchars($meta['description']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div style="border-top: 1px solid #e5e9f0; padding: 15px; margin-top: 15px;">
                    <div class="sidebar-header">📦 Blocos Disponíveis</div>
                    <div style="font-size: 12px; line-height: 1.6;">
                        <?php
                        $categories = BlockRegistry::getCategories();
                        foreach ($categories as $category):
                            $blocks = BlockRegistry::getByCategory($category);
                        ?>
                            <div style="margin-top: 10px;">
                                <strong style="color: #173B63; display: block; margin-bottom: 5px;"><?php echo htmlspecialchars($category); ?></strong>
                                <?php foreach ($blocks as $name => $config): ?>
                                    <div style="padding: 4px 8px; background: #f8f9fa; border-radius: 4px; margin-bottom: 4px; cursor: help;" title="<?php echo htmlspecialchars($config['description']); ?>">
                                        <?php echo htmlspecialchars($config['icon'] . ' ' . $name); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Editor -->
            <div class="editor-wrapper">
                <div class="editor-toolbar">
                    <h2><?php echo htmlspecialchars($layouts[$currentLayout]['name'] ?? 'Layout'); ?></h2>
                    <button type="button" class="btn btn-secondary" onclick="validateLayout()">✓ Validar</button>
                    <button type="button" class="btn btn-secondary" onclick="togglePreview()">👁️ Preview</button>
                    <button type="button" class="btn btn-primary" onclick="saveLayout()">💾 Salvar</button>
                </div>

                <div class="editor-content">
                    <div class="editor-split">
                        <!-- Editor JSON -->
                        <div class="editor-pane">
                            <div class="pane-header">📋 JSON Layout</div>
                            <form method="POST" id="editor-form">
                                <input type="hidden" name="action" value="save">
                                <textarea name="layout_json" id="json-editor" placeholder="Edite o layout em JSON..."><?php echo htmlspecialchars(json_encode($layoutContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                            </form>
                            <div class="char-count">
                                <span id="char-count">0</span> caracteres
                            </div>
                        </div>

                        <!-- Preview -->
                        <div class="editor-pane">
                            <div class="pane-header">👁️ Preview</div>
                            <div class="preview" id="preview-pane">
                                <p style="text-align: center; color: #999; padding: 20px;">
                                    Clique em "Preview" para gerar a visualização
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const jsonEditor = document.getElementById('json-editor');
        const charCountEl = document.getElementById('char-count');
        const editorForm = document.getElementById('editor-form');
        const previewPane = document.getElementById('preview-pane');

        // Atualizar contagem de caracteres
        jsonEditor.addEventListener('input', function() {
            charCountEl.textContent = this.value.length.toLocaleString('pt-BR');
        });

        function validateLayout() {
            try {
                const json = JSON.parse(jsonEditor.value);
                alert('✓ JSON válido! ' + (json.sections ? json.sections.length : 0) + ' seção(ões).');
            } catch (e) {
                alert('✗ Erro de JSON:\n' + e.message);
            }
        }

        function togglePreview() {
            const split = document.querySelector('.editor-split');
            if (split.style.gridTemplateColumns === '1fr') {
                split.style.gridTemplateColumns = '1fr 1fr';
                generatePreview();
            } else {
                split.style.gridTemplateColumns = '1fr';
            }
        }

        function generatePreview() {
            try {
                const json = JSON.parse(jsonEditor.value);
                if (!json.sections || json.sections.length === 0) {
                    previewPane.innerHTML = '<p style="padding: 20px; color: #999;">Nenhuma seção definida</p>';
                    return;
                }

                let html = '<div style="padding: 20px; font-size: 12px; line-height: 1.6;">';
                html += '<strong style="color: #173B63; display: block; margin-bottom: 10px;">Estrutura do Layout:</strong>';

                json.sections.forEach((section, idx) => {
                    const blockType = section.type || 'Unknown';
                    const blockId = section.id || 'unnamed';
                    html += `<div style="padding: 8px; background: #f8f9fa; margin-bottom: 8px; border-left: 3px solid #173B63;">
                        ${idx + 1}. <strong>${blockType}</strong><br>
                        <code style="font-size: 11px; color: #666;">#${blockId}</code>
                    </div>`;
                });

                html += '</div>';
                previewPane.innerHTML = html;
            } catch (e) {
                previewPane.innerHTML = '<p style="padding: 20px; color: #d97706;"><strong>Erro:</strong> ' + e.message + '</p>';
            }
        }

        function saveLayout() {
            try {
                JSON.parse(jsonEditor.value);
                if (confirm('Deseja salvar o layout? Esta ação não pode ser desfeita.')) {
                    editorForm.submit();
                }
            } catch (e) {
                alert('✗ JSON inválido, não pode salvar:\n' + e.message);
            }
        }

        // Inicializar contagem
        charCountEl.textContent = jsonEditor.value.length.toLocaleString('pt-BR');
    </script>
</body>
</html>
