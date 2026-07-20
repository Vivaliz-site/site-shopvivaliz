<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: text/html; charset=UTF-8');
$version = is_file(__DIR__ . '/../config/shopvivaliz-version.php') ? require __DIR__ . '/../config/shopvivaliz-version.php' : [];
$appVersion = (string)($version['version'] ?? '0.0.0');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .dashboard-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: #0066cc;
        }
        .dashboard-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.2rem;
        }
        .dashboard-card p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }
        .dashboard-card .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <nav class="navbar" style="background: #1a1a2e; padding: 1rem 0;">
        <div class="container nav-inner" style="display: flex; justify-content: space-between; align-items: center;">
            <a class="brand-link" href="/admin/" style="color: white; font-weight: bold; font-size: 1.2rem;">🛍️ ShopVivaliz Admin v<?php echo htmlspecialchars($appVersion); ?></a>
            <div class="navbar-menu" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="/admin/" style="color: white; text-decoration: none; padding: 0.5rem 1rem;">Dashboard</a>
                <a href="/admin/pedidos.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem;">Pedidos</a>
                <a href="/admin/produtos.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem;">Produtos</a>
                <a href="/admin/clientes.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem;">Clientes</a>
                <a href="/auth/logout.php" style="color: #ff6b6b; text-decoration: none; padding: 0.5rem 1rem;">Sair</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container">
                <h1>📊 Dashboard Admin</h1>
                <p>Gerenciamento central da loja ShopVivaliz</p>
            </div>
        </section>

        <div class="container">
            <div class="dashboard-grid">
                <a href="/admin/pedidos.php" class="dashboard-card">
                    <div class="icon">📋</div>
                    <h3>Pedidos</h3>
                    <p>Visualizar e gerenciar todos os pedidos</p>
                </a>
                
                <a href="/admin/produtos.php" class="dashboard-card">
                    <div class="icon">📦</div>
                    <h3>Produtos</h3>
                    <p>Gerenciar catálogo de produtos</p>
                </a>
                
                <a href="/admin/clientes.php" class="dashboard-card">
                    <div class="icon">👥</div>
                    <h3>Clientes</h3>
                    <p>Visualizar dados dos clientes</p>
                </a>
                
                <a href="/monitor/" class="dashboard-card">
                    <div class="icon">📊</div>
                    <h3>Monitor</h3>
                    <p>Status e saúde do site</p>
                </a>

                <a href="/api/" class="dashboard-card">
                    <div class="icon">⚙️</div>
                    <h3>APIs</h3>
                    <p>Integração com Mercado Pago, OlistTiny</p>
                </a>

                <a href="/" class="dashboard-card">
                    <div class="icon">🏠</div>
                    <h3>Voltar para Site</h3>
                    <p>Acessar a loja do cliente</p>
                </a>
            </div>
        </div>
    </main>
</body>
</html>
