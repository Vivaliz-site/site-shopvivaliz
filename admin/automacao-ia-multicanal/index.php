<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin-guard.php';
/**
 * Painel de Administração - Automação IA Multi-Canal
 * Sistema para gerenciar cadastro de produtos em múltiplos canais
 * com textos e imagens otimizados por IA
 */

session_start();

// Verificar autenticação
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /auth/login.php');
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automação IA Multi-Canal - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/admin-automation.css">
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <h2>Automação IA</h2>
                <p>Multi-Canal</p>
            </div>

            <nav class="sidebar-menu">
                <a href="?page=dashboard" class="menu-item <?php echo $page === 'dashboard' ? 'active' : ''; ?>">
                    📊 Dashboard
                </a>
                <a href="?page=automacoes" class="menu-item <?php echo $page === 'automacoes' ? 'active' : ''; ?>">
                    ⚙️ Automações
                </a>
                <a href="?page=produtos" class="menu-item <?php echo $page === 'produtos' ? 'active' : ''; ?>">
                    📦 Produtos
                </a>
                <a href="?page=canais" class="menu-item <?php echo $page === 'canais' ? 'active' : ''; ?>">
                    🎯 Canais
                </a>
                <a href="?page=historico" class="menu-item <?php echo $page === 'historico' ? 'active' : ''; ?>">
                    📋 Histórico
                </a>
                <a href="?page=configuracoes" class="menu-item <?php echo $page === 'configuracoes' ? 'active' : ''; ?>">
                    ⚡ Configurações
                </a>
                <a href="?page=manual" class="menu-item <?php echo $page === 'manual' ? 'active' : ''; ?>">
                    📖 Manual
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Top Bar -->
            <div class="admin-topbar">
                <h1>Automação IA para Cadastro Multi-Canal</h1>
                <div class="topbar-actions">
                    <button class="btn btn-primary" onclick="openModal('newAutomacao')">
                        ➕ Nova Automação
                    </button>
                    <button class="btn btn-secondary" onclick="testConnection()">
                        🧪 Testar Conexão
                    </button>
                </div>
            </div>

            <!-- Content Area -->
            <div class="admin-content">
                <?php
                $pages = [
                    'dashboard' => 'pages/dashboard.php',
                    'automacoes' => 'pages/automacoes.php',
                    'produtos' => 'pages/produtos.php',
                    'canais' => 'pages/canais.php',
                    'historico' => 'pages/historico.php',
                    'configuracoes' => 'pages/configuracoes.php',
                    'manual' => 'pages/manual.php',
                ];

                $pageFile = $pages[$page] ?? $pages['dashboard'];

                if (file_exists(__DIR__ . '/' . $pageFile)) {
                    include $pageFile;
                } else {
                    echo '<p>Página não encontrada</p>';
                }
                ?>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="newAutomacao" class="modal">
        <div class="modal-content">
            <h2>Nova Automação</h2>
            <form id="formNovaAutomacao">
                <div class="form-group">
                    <label>Nome da Automação</label>
                    <input type="text" name="nome" required>
                </div>
                <div class="form-group">
                    <label>ERP Conectado</label>
                    <select name="erp" required>
                        <option value="">Selecione...</option>
                        <option value="tiny">Tiny ERP</option>
                        <option value="bling">Bling ERP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Canais de Destino</label>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="canais" value="tiktok"> TikTok Shop</label>
                        <label><input type="checkbox" name="canais" value="amazon"> Amazon</label>
                        <label><input type="checkbox" name="canais" value="mercadolivre"> Mercado Livre</label>
                        <label><input type="checkbox" name="canais" value="shopify"> Shopify</label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeModal('newAutomacao')" class="btn btn-secondary">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Criar Automação
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="/js/admin-automation.js"></script>
</body>
</html>
