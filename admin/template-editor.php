<?php
declare(strict_types=1);

// Admin guard
require_once __DIR__ . '/../includes/admin-guard.php';

// Get list of editable templates
$templates = [
    'index.php' => ['name' => 'Homepage', 'description' => 'Página inicial do site'],
    'catalogo.php' => ['name' => 'Catálogo', 'description' => 'Página de listagem de produtos'],
    'sobre.php' => ['name' => 'Sobre', 'description' => 'Página sobre a empresa'],
    'contato.php' => ['name' => 'Contato', 'description' => 'Página de contato'],
    'carrinho/index.php' => ['name' => 'Carrinho', 'description' => 'Página do carrinho'],
];

$currentTemplate = $_GET['template'] ?? 'index.php';
$templatePath = __DIR__ . '/../' . $currentTemplate;

// Security check
if (!isset($templates[$currentTemplate]) || !file_exists($templatePath)) {
    $currentTemplate = 'index.php';
    $templatePath = __DIR__ . '/../index.php';
}

$content = file_get_contents($templatePath);

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $newContent = $_POST['content'] ?? '';

    if (file_put_contents($templatePath, $newContent)) {
        $message = '✓ Template atualizado com sucesso!';
        $messageType = 'success';
        $content = $newContent;
    } else {
        $message = '✗ Erro ao salvar template';
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
            <!-- Sidebar com lista de templates -->
            <div class="sidebar">
                <div class="sidebar-header">Templates</div>
                <ul class="template-list">
                    <?php foreach ($templates as $path => $meta): ?>
                        <li class="template-item">
                            <a href="?template=<?php echo urlencode($path); ?>"
                               class="<?php echo $currentTemplate === $path ? 'active' : ''; ?>">
                                <span class="template-name"><?php echo htmlspecialchars($meta['name']); ?></span>
                                <span class="template-desc"><?php echo htmlspecialchars($meta['description']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Editor -->
            <div class="editor-wrapper">
                <div class="editor-toolbar">
                    <h2><?php echo htmlspecialchars($templates[$currentTemplate]['name'] ?? 'Template'); ?></h2>
                    <button type="button" class="btn btn-secondary" onclick="togglePreview()">👁️ Preview</button>
                    <button type="button" class="btn btn-primary" onclick="saveTemplate()">💾 Salvar</button>
                </div>

                <div class="editor-content">
                    <div class="editor-split">
                        <!-- Editor de código -->
                        <div class="editor-pane">
                            <div class="pane-header">📄 Código HTML/PHP</div>
                            <form method="POST" id="editor-form">
                                <input type="hidden" name="action" value="save">
                                <textarea name="content" id="code-editor" placeholder="Edite o template aqui..."><?php echo htmlspecialchars($content); ?></textarea>
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
                                    O preview será atualizado após salvar
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const codeEditor = document.getElementById('code-editor');
        const charCountEl = document.getElementById('char-count');
        const editorForm = document.getElementById('editor-form');

        // Atualizar contagem de caracteres
        codeEditor.addEventListener('input', function() {
            charCountEl.textContent = this.value.length.toLocaleString('pt-BR');
        });

        // Atualizar preview em tempo real
        codeEditor.addEventListener('input', function() {
            updatePreview();
        });

        function updatePreview() {
            const preview = document.getElementById('preview-pane');
            // Nota: Preview completo requer renderização real no servidor
            const lines = codeEditor.value.split('\n').length;
            preview.innerHTML = `<p style="padding: 20px; color: #666; font-size: 13px;">
                <strong>${lines}</strong> linhas editadas<br>
                <em>Clique em "Salvar" para ver o preview real</em>
            </p>`;
        }

        function togglePreview() {
            // Toggle entre preview e editor
            const split = document.querySelector('.editor-split');
            if (split.style.gridTemplateColumns === '1fr') {
                split.style.gridTemplateColumns = '1fr 1fr';
            } else {
                split.style.gridTemplateColumns = '1fr';
            }
        }

        function saveTemplate() {
            if (confirm('Deseja salvar as alterações? Esta ação não pode ser desfeita.')) {
                editorForm.submit();
            }
        }

        // Inicializar contagem
        charCountEl.textContent = codeEditor.value.length.toLocaleString('pt-BR');

        // Auto-save a cada 2 minutos (opcional)
        // setInterval(() => {
        //     console.log('Auto-save: não implementado');
        // }, 120000);
    </script>
</body>
</html>
