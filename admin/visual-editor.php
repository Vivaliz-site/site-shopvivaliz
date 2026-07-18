<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

require_once __DIR__ . '/../config/bootstrap-env.php';

// Configuração de layout salva em JSON
$layoutFile = __DIR__ . '/../config/layout-config.json';

$layoutConfig = [
    'banners' => [
        [
            'id' => 'banner-1',
            'title' => 'Banner 1',
            'image' => '/public/assets/home-banners/banner-primeira-compra.jpg',
            'link' => '#',
            'active' => true,
        ],
        [
            'id' => 'banner-2',
            'title' => 'Banner 2',
            'image' => '/images/placeholder-banner-2.jpg',
            'link' => '#',
            'active' => true,
        ],
    ],
    'categories' => [
        'order' => ['utilidades', 'ferramentas', 'jardim', 'banheiro', 'pet', 'cozinha'],
        'visible' => ['utilidades', 'ferramentas', 'jardim', 'banheiro', 'pet', 'cozinha'],
    ],
    'products' => [
        'itemsPerPage' => 8,
        'autoPlay' => true,
        'autoPlayInterval' => 5000,
    ],
];

// Carregar configuração existente
if (is_file($layoutFile)) {
    $saved = json_decode(file_get_contents($layoutFile), true);
    if (is_array($saved)) {
        $layoutConfig = array_merge($layoutConfig, $saved);
    }
}

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode((string)file_get_contents('php://input'), true);

    if (is_array($data)) {
        $layoutConfig = array_merge($layoutConfig, $data);
        file_put_contents($layoutFile, json_encode($layoutConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Configuração salva com sucesso']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Visual - ShopVivaliz</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        .editor-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            height: 100vh;
            gap: 0;
        }

        .preview-area {
            background: white;
            overflow-y: auto;
            padding: 20px;
        }

        .control-panel {
            background: #2c3e50;
            color: white;
            overflow-y: auto;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .control-panel h3 {
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
            color: #ecf0f1;
            border-bottom: 2px solid #3498db;
            padding-bottom: 8px;
        }

        .control-panel h3:first-child {
            margin-top: 0;
        }

        .section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
        }

        .section-title {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            color: #ecf0f1;
        }

        .sortable-list {
            list-style: none;
        }

        .sortable-item {
            background: #34495e;
            padding: 10px;
            margin-bottom: 6px;
            border-radius: 4px;
            cursor: move;
            border-left: 3px solid #3498db;
            user-select: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            font-size: 13px;
        }

        .sortable-item:hover {
            background: #455a64;
            transform: translateX(4px);
        }

        .sortable-item.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .sortable-item input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }

        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
            width: 100%;
            margin-top: 8px;
        }

        .btn:hover {
            background: #2980b9;
        }

        .btn-danger {
            background: #e74c3c;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-success {
            background: #27ae60;
        }

        .btn-success:hover {
            background: #229954;
        }

        .input-group {
            margin-bottom: 12px;
        }

        .input-group label {
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
            color: #bdc3c7;
        }

        .input-group input,
        .input-group select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #34495e;
            border-radius: 4px;
            background: #2c3e50;
            color: white;
            font-size: 12px;
        }

        .input-group input:focus,
        .input-group select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        /* Preview Styles */
        .preview-banners {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .banners-carousel {
            position: relative;
            width: 100%;
            height: 250px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }

        .banner-item {
            display: none;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
        }

        .banner-item.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .banner-nav {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 10;
        }

        .banner-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: all 0.3s;
        }

        .banner-dot.active {
            background: white;
            transform: scale(1.3);
        }

        .categories-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .categories-section h2 {
            margin-bottom: 15px;
            font-size: 18px;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        .category-card {
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: move;
            transition: all 0.2s;
            user-select: none;
        }

        .category-card:hover {
            border-color: #3498db;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.15);
            transform: translateY(-2px);
        }

        .category-card.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .category-icon {
            font-size: 36px;
            margin-bottom: 8px;
        }

        .category-name {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }

        .status-message {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 1000;
        }

        .status-message.show {
            display: block;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .header {
            background: white;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 20px;
            color: #2c3e50;
        }

        .btn-save {
            background: #27ae60;
            padding: 10px 20px;
        }

        .btn-save:hover {
            background: #229954;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📐 Editor Visual - ShopVivaliz</h1>
        <button class="btn btn-save" onclick="salvarConfiguracao()">💾 Salvar Alterações</button>
    </div>

    <div class="editor-container">
        <div class="preview-area">
            <!-- Banners Deslizantes -->
            <div class="preview-banners">
                <h2>Banners Promocionais</h2>
                <div class="banners-carousel" id="bannersCarousel">
                    <?php foreach ($layoutConfig['banners'] as $i => $banner): ?>
                        <div class="banner-item <?= $i === 0 ? 'active' : '' ?>"
                             style="background-image: url('<?= htmlspecialchars($banner['image']) ?>')">
                            <?= htmlspecialchars($banner['title']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="banner-nav">
                    <?php foreach ($layoutConfig['banners'] as $i => $banner): ?>
                        <div class="banner-dot <?= $i === 0 ? 'active' : '' ?>"
                             onclick="mostrarBanner(<?= $i ?>)"></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Categorias -->
            <div class="categories-section">
                <h2>Categorias de Produtos</h2>
                <p style="color: #666; margin-bottom: 15px; font-size: 14px;">
                    💡 Dica: Arraste as categorias para reposicionar. O editor à direita controla a ordem e visibilidade.
                </p>
                <div class="categories-grid" id="categoriesGrid">
                    <?php
                    $categoryEmojis = [
                        'utilidades' => '🏠',
                        'ferramentas' => '🛠️',
                        'caixas' => '📦',
                        'rodízios' => '⚙️',
                        'banheiro' => '🚿',
                        'pet' => '🐾',
                        'jardim' => '🌿',
                        'flores' => '🪴',
                    ];

                    foreach ($layoutConfig['categories']['order'] as $category):
                        $emoji = $categoryEmojis[$category] ?? '📦';
                    ?>
                        <div class="category-card" draggable="true" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="category-icon"><?= $emoji ?></div>
                            <div class="category-name"><?= ucfirst(htmlspecialchars($category)) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Painel de Controle -->
        <div class="control-panel">
            <h2 style="font-size: 16px; margin-bottom: 20px;">⚙️ Configurações</h2>

            <!-- Banners -->
            <h3>Banners</h3>
            <div class="section">
                <div class="section-title">Gerenciar Banners</div>
                <ul class="sortable-list" id="bannersList">
                    <?php foreach ($layoutConfig['banners'] as $banner): ?>
                        <li class="sortable-item" data-id="<?= htmlspecialchars($banner['id']) ?>">
                            <input type="checkbox" class="banner-toggle"
                                   <?= $banner['active'] ? 'checked' : '' ?>
                                   onchange="atualizarBanner(this)">
                            <span><?= htmlspecialchars($banner['title']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Categorias -->
            <h3>Categorias</h3>
            <div class="section">
                <div class="section-title">Ordem e Visibilidade</div>
                <ul class="sortable-list" id="categoriesList">
                    <?php foreach ($layoutConfig['categories']['order'] as $category):
                        $isVisible = in_array($category, $layoutConfig['categories']['visible']);
                    ?>
                        <li class="sortable-item" data-category="<?= htmlspecialchars($category) ?>" draggable="true">
                            <input type="checkbox" class="category-toggle"
                                   <?= $isVisible ? 'checked' : '' ?>
                                   onchange="atualizarCategoria(this)">
                            <span><?= ucfirst(htmlspecialchars($category)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Produtos -->
            <h3>Produtos</h3>
            <div class="section">
                <div class="input-group">
                    <label>Itens por Página</label>
                    <input type="number" id="itemsPerPage"
                           value="<?= $layoutConfig['products']['itemsPerPage'] ?>"
                           min="4" max="20">
                </div>
                <div class="input-group">
                    <label>
                        <input type="checkbox" id="autoPlay"
                               <?= $layoutConfig['products']['autoPlay'] ? 'checked' : '' ?>>
                        Auto-play Carrossel
                    </label>
                </div>
                <div class="input-group">
                    <label>Intervalo Auto-play (ms)</label>
                    <input type="number" id="autoPlayInterval"
                           value="<?= $layoutConfig['products']['autoPlayInterval'] ?>"
                           min="1000" step="1000">
                </div>
            </div>

            <button class="btn btn-success" onclick="salvarConfiguracao()">💾 Salvar Tudo</button>
            <button class="btn btn-danger" onclick="resetarConfiguracao()">🔄 Restaurar Padrão</button>
        </div>
    </div>

    <div class="status-message" id="statusMessage"></div>

    <script>
        let currentBannerIndex = 0;
        const banners = document.querySelectorAll('.banner-item');
        const bannerDots = document.querySelectorAll('.banner-dot');

        function mostrarBanner(index) {
            banners.forEach((b, i) => {
                b.classList.toggle('active', i === index);
            });
            bannerDots.forEach((d, i) => {
                d.classList.toggle('active', i === index);
            });
            currentBannerIndex = index;
        }

        // Configurar drag and drop para categorias
        const categoriesGrid = document.getElementById('categoriesGrid');
        const categoriesList = document.getElementById('categoriesList');

        let draggedItem = null;

        categoriesGrid.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('category-card')) {
                draggedItem = e.target;
                e.target.classList.add('dragging');
            }
        });

        categoriesGrid.addEventListener('dragend', () => {
            if (draggedItem) {
                draggedItem.classList.remove('dragging');
            }
        });

        categoriesGrid.addEventListener('dragover', (e) => {
            e.preventDefault();
            const items = [...categoriesGrid.children];
            const afterElement = [...categoriesGrid.children].find(child => {
                const rect = child.getBoundingClientRect();
                return e.clientY < rect.top + rect.height / 2;
            });

            if (afterElement == null) {
                categoriesGrid.appendChild(draggedItem);
            } else {
                categoriesGrid.insertBefore(draggedItem, afterElement);
            }
        });

        // Sincronizar ordem com painel de controle
        categoriesGrid.addEventListener('drop', () => {
            atualizarOrdemCategorias();
        });

        function atualizarOrdemCategorias() {
            const novaOrdem = [...categoriesGrid.children].map(card =>
                card.getAttribute('data-category')
            );

            // Atualizar lista de controle
            categoriesList.innerHTML = '';
            novaOrdem.forEach(category => {
                const item = document.createElement('li');
                item.className = 'sortable-item';
                item.setAttribute('data-category', category);
                item.draggable = true;
                item.innerHTML = `
                    <input type="checkbox" class="category-toggle" checked
                           onchange="atualizarCategoria(this)">
                    <span>${category.charAt(0).toUpperCase() + category.slice(1)}</span>
                `;
                categoriesList.appendChild(item);
            });
        }

        function atualizarCategoria(checkbox) {
            const item = checkbox.closest('.sortable-item');
            const category = item.getAttribute('data-category');

            if (checkbox.checked) {
                item.style.opacity = '1';
            } else {
                item.style.opacity = '0.5';
            }
        }

        function atualizarBanner(checkbox) {
            const item = checkbox.closest('.sortable-item');
            item.style.opacity = checkbox.checked ? '1' : '0.5';
        }

        async function salvarConfiguracao() {
            const config = {
                banners: document.querySelectorAll('.sortable-item[data-id]').length > 0 ?
                    Array.from(document.querySelectorAll('.sortable-item[data-id]')).map(item => ({
                        id: item.getAttribute('data-id'),
                        active: item.querySelector('input[type="checkbox"]').checked,
                    })) : undefined,
                categories: {
                    order: [...categoriesGrid.children].map(card => card.getAttribute('data-category')),
                    visible: Array.from(document.querySelectorAll('.category-toggle:checked')).map(cb =>
                        cb.closest('.sortable-item').getAttribute('data-category')
                    ),
                },
                products: {
                    itemsPerPage: parseInt(document.getElementById('itemsPerPage').value),
                    autoPlay: document.getElementById('autoPlay').checked,
                    autoPlayInterval: parseInt(document.getElementById('autoPlayInterval').value),
                }
            };

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(config)
                });

                const result = await response.json();

                if (result.success) {
                    mostrarMensagem('✅ Configuração salva com sucesso!');

                    // Reload página após 1 segundo
                    setTimeout(() => window.location.reload(), 1000);
                }
            } catch (err) {
                mostrarMensagem('❌ Erro ao salvar: ' + err.message, 'error');
            }
        }

        function resetarConfiguracao() {
            if (confirm('Restaurar configuração padrão? Isso não pode ser desfeito.')) {
                window.location.href = '?reset=1';
            }
        }

        function mostrarMensagem(msg, tipo = 'success') {
            const el = document.getElementById('statusMessage');
            el.textContent = msg;
            el.className = 'status-message show';
            el.style.background = tipo === 'error' ? '#e74c3c' : '#27ae60';
            setTimeout(() => el.classList.remove('show'), 3000);
        }

        // Auto-play dos banners
        setInterval(() => {
            if (banners.length > 0) {
                currentBannerIndex = (currentBannerIndex + 1) % banners.length;
                mostrarBanner(currentBannerIndex);
            }
        }, 5000);
    </script>
</body>
</html>
