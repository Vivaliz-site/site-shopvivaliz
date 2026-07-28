<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: text/html; charset=UTF-8');
$version = is_file(__DIR__ . '/../config/shopvivaliz-version.php') ? require __DIR__ . '/../config/shopvivaliz-version.php' : [];
$appVersion = (string)($version['version'] ?? '0.0.0');

$quickLinks = [
    ['📋', 'Pedidos', '/admin/pedidos.php', 'Visualizar e gerenciar todos os pedidos.'],
    ['📦', 'Produtos', '/admin/produtos.php', 'Gerenciar o catálogo de produtos.'],
    ['👥', 'Clientes', '/admin/clientes.php', 'Visualizar e administrar clientes.'],
    ['📝', 'Blog', '/admin/blog.php', 'Criar e publicar conteúdos.'],
    ['🎟️', 'Cupons', '/admin/cupons.php', 'Gerenciar promoções e cupons.'],
    ['🏢', 'Empresa e redes sociais', '/admin/company-profile.php', 'Editar dados da empresa e links sociais.'],
    ['🔗', 'Integrações', '/admin/integrations.php', 'Configurar serviços e marketplaces.'],
    ['🤖', 'Automação e IA', '/admin/automacao-ia-multicanal/', 'Gerenciar automações multicanal.'],
    ['📊', 'Monitoramento', '/admin/monitor/', 'Acompanhar saúde e operação do site.'],
    ['🧰', 'Todas as funções', '/admin/menu-completo.php', 'Abrir a central com todos os botões administrativos.'],
    ['🏠', 'Abrir loja', '/', 'Acessar a loja como cliente.'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <style>
        .dashboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-top:2rem}.dashboard-card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:1.4rem;transition:.25s;text-decoration:none;color:inherit;min-height:145px}.dashboard-card:hover{transform:translateY(-4px);box-shadow:0 6px 18px rgba(0,0,0,.11);border-color:#0066cc}.dashboard-card h3{margin:.6rem 0 .45rem;font-size:1.15rem}.dashboard-card p{margin:0;color:#666;font-size:.9rem;line-height:1.45}.dashboard-card .icon{font-size:2rem}.featured{border:2px solid #4f46e5;background:#eef2ff}.featured h3{color:#3730a3}
    </style>
    <?php require_once __DIR__ . '/../includes/load-custom-css.php'; ?>
</head>
<body>
<nav class="navbar" style="background:#1a1a2e;padding:1rem 0"><div class="container nav-inner" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap"><a class="brand-link" href="/admin/" style="color:white;font-weight:bold;font-size:1.2rem">🛍️ ShopVivaliz Admin v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></a><div class="navbar-menu" style="display:flex;gap:.5rem;flex-wrap:wrap"><a href="/admin/" style="color:white;text-decoration:none;padding:.5rem 1rem">Dashboard</a><a href="/admin/menu-completo.php" style="color:white;text-decoration:none;padding:.5rem 1rem;background:rgba(255,255,255,.15);border-radius:6px">Todas as funções</a><a href="/auth/logout.php" style="color:#ff6b6b;text-decoration:none;padding:.5rem 1rem">Sair</a></div></div></nav>
<main class="catalog-page"><section class="catalog-header"><div class="container"><h1>📊 Dashboard Admin</h1><p>Gerenciamento central da ShopVivaliz</p></div></section><div class="container"><div class="dashboard-grid">
<?php foreach ($quickLinks as [$icon, $title, $url, $description]): ?>
<a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="dashboard-card<?= $url === '/admin/menu-completo.php' ? ' featured' : '' ?>"><div class="icon"><?= $icon ?></div><h3><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p></a>
<?php endforeach; ?>
</div></div></main>
</body>
</html>
