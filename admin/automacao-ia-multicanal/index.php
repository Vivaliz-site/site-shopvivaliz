<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/**
 * Central administrativa da automacao IA multicanal.
 *
 * Este modulo funciona como cockpit de navegacao. Escritas e publicacoes devem
 * ocorrer somente nos modulos canonicos, que possuem seus proprios gates,
 * auditoria e validacao. Nao exibimos KPIs sinteticos nem rotas sem backend.
 */
$page = strtolower(trim((string)($_GET['page'] ?? 'dashboard')));
$pages = [
    'dashboard' => 'pages/dashboard.php',
    'automacoes' => 'pages/automacoes.php',
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
        .admin-topbar{gap:12px;flex-wrap:wrap}.topbar-actions{display:flex;gap:8px;flex-wrap:wrap}.topbar-actions a{text-decoration:none}
        .sv-admin-live-status{min-height:20px;margin:8px 32px 0;color:#475569;font-size:13px;font-weight:700}
        .sv-admin-live-status.ok{color:#067647}.sv-admin-live-status.err{color:#b42318}
        @media(max-width:760px){.admin-container{min-width:0}.admin-sidebar{width:100%;max-width:none;position:relative;height:auto;padding:14px}.admin-main{min-width:0;margin-left:0}.sidebar-header{text-align:left;margin:12px 0 16px}.sidebar-menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.menu-item{min-height:44px;display:flex;align-items:center}.admin-topbar,.admin-content{padding-left:14px!important;padding-right:14px!important}.sv-admin-live-status{margin-left:14px;margin-right:14px}.topbar-actions{width:100%}.topbar-actions .btn{flex:1 1 150px;min-height:44px}}
        @media(max-width:430px){.sidebar-menu{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <a class="sv-admin-return" href="/admin/">← Central Admin</a>
            <div class="sidebar-header">
                <h2>Automação IA</h2>
                <p>Operação multicanal auditável</p>
            </div>

            <nav class="sidebar-menu" aria-label="Automação IA multicanal">
                <a href="?page=dashboard" class="menu-item <?= $page === 'dashboard' ? 'active' : '' ?>">📊 Visão operacional</a>
                <a href="?page=automacoes" class="menu-item <?= $page === 'automacoes' ? 'active' : '' ?>">⚙️ Rotinas canônicas</a>
                <a href="/admin/produtos.php" class="menu-item">📦 Produtos</a>
                <a href="/admin/connections.php" class="menu-item">🔗 Conexões</a>
                <a href="/admin/audit-dashboard.php" class="menu-item">📋 Auditoria</a>
                <a href="/admin/settings.php" class="menu-item">🛠️ Configurações</a>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="admin-topbar">
                <h1>Automação IA para Cadastro Multi-Canal</h1>
                <div class="topbar-actions">
                    <a class="btn btn-primary" href="/admin/orchestrator.php">🧠 Abrir Orchestrator</a>
                    <button class="btn btn-secondary" type="button" id="sv-test-health">🧪 Testar saúde</button>
                </div>
            </div>
            <div id="sv-admin-live-status" class="sv-admin-live-status" role="status" aria-live="polite"></div>

            <div class="admin-content">
                <?php
                $pageFile = __DIR__ . '/' . $pages[$page];
                if (is_file($pageFile)) {
                    include $pageFile;
                } else {
                    echo '<section class="section"><h2>Página indisponível</h2><p>O módulo solicitado não foi encontrado.</p></section>';
                }
                ?>
            </div>
        </main>
    </div>

    <script src="/js/admin-automation.js?v=20260813-1"></script>
</body>
</html>
