<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/**
 * Painel de Administração - Automação IA Multi-Canal
 * Sistema para gerenciar cadastro de produtos em múltiplos canais
 * com textos e imagens otimizados por IA.
 */
$page = strtolower(trim((string)($_GET['page'] ?? 'dashboard')));
$pages = [
    'dashboard' => 'pages/dashboard.php',
    'automacoes' => 'pages/automacoes.php',
    'produtos' => 'pages/produtos.php',
    'canais' => 'pages/canais.php',
    'historico' => 'pages/historico.php',
    'configuracoes' => 'pages/configuracoes.php',
    'manual' => 'pages/manual.php',
];
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Automação IA Multi-Canal — ShopVivaliz Admin</title>
    <link rel="stylesheet" href="/css/admin-automation.css">
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <style>
        .sv-admin-return{display:inline-flex;align-items:center;gap:6px;margin:12px 12px 4px;padding:9px 12px;border-radius:8px;text-decoration:none;font-weight:800;color:#173b63;background:#eef2f7}
        .admin-topbar{gap:12px;flex-wrap:wrap}.topbar-actions{display:flex;gap:8px;flex-wrap:wrap}
        @media(max-width:760px){.admin-container{min-width:0}.admin-sidebar{width:100%;max-width:none;position:relative}.admin-main{min-width:0}.admin-topbar,.admin-content{padding-left:14px!important;padding-right:14px!important}.topbar-actions .btn{flex:1 1 150px}}
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <a class="sv-admin-return" href="/admin/">← Central Admin</a>
            <div class="sidebar-header">
                <h2>Automação IA</h2>
                <p>Multi-Canal</p>
            </div>

            <nav class="sidebar-menu" aria-label="Automação IA multicanal">
                <a href="?page=dashboard" class="menu-item <?= $page === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
                <a href="?page=automacoes" class="menu-item <?= $page === 'automacoes' ? 'active' : '' ?>">⚙️ Automações</a>
                <a href="?page=produtos" class="menu-item <?= $page === 'produtos' ? 'active' : '' ?>">📦 Produtos</a>
                <a href="?page=canais" class="menu-item <?= $page === 'canais' ? 'active' : '' ?>">🎯 Canais</a>
                <a href="?page=historico" class="menu-item <?= $page === 'historico' ? 'active' : '' ?>">📋 Histórico</a>
                <a href="?page=configuracoes" class="menu-item <?= $page === 'configuracoes' ? 'active' : '' ?>">⚡ Configurações</a>
                <a href="?page=manual" class="menu-item <?= $page === 'manual' ? 'active' : '' ?>">📖 Manual</a>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="admin-topbar">
                <h1>Automação IA para Cadastro Multi-Canal</h1>
                <div class="topbar-actions">
                    <button class="btn btn-primary" type="button" onclick="openModal('newAutomacao')">➕ Nova Automação</button>
                    <button class="btn btn-secondary" type="button" onclick="testConnection()">🧪 Testar Conexão</button>
                </div>
            </div>

            <div class="admin-content">
                <?php
                $pageFile = __DIR__ . '/' . $pages[$page];
                if (is_file($pageFile)) {
                    include $pageFile;
                } else {
                    echo '<section class="card"><h2>Página indisponível</h2><p>O módulo solicitado não foi encontrado.</p></section>';
                }
                ?>
            </div>
        </main>
    </div>

    <div id="newAutomacao" class="modal" aria-hidden="true">
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
                    <button type="button" onclick="closeModal('newAutomacao')" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Automação</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/js/admin-automation.js"></script>
</body>
</html>
