<?php
/**
 * Dashboard com menu completo de rotinas admin
 * Centraliza TODAS as funcionalidades em um só lugar
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        .navbar {
            background: rgba(0,0,0,0.8);
            padding: 1rem;
            color: white;
        }
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        .page-header {
            color: white;
            margin-bottom: 3rem;
        }
        .page-header h1 {
            font-size: 2.5rem;
            margin: 0 0 0.5rem 0;
        }
        .page-header p {
            margin: 0;
            opacity: 0.9;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        .menu-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .menu-section:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.15);
        }
        .menu-section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            font-weight: bold;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .menu-section-header::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
        }
        .menu-section-items {
            padding: 1rem;
        }
        .menu-item {
            display: block;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border: 2px solid transparent;
            border-radius: 8px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.95rem;
        }
        .menu-item:hover {
            background: #e9ecef;
            border-color: #667eea;
            color: #667eea;
            font-weight: 500;
        }
        .menu-item:last-child {
            margin-bottom: 0;
        }
        .menu-description {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
            font-weight: normal;
            padding-left: 1rem;
        }
        .external-link::after {
            content: ' ↗';
        }
        .logout-btn {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.75rem 1.5rem;
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div class="navbar-brand">🛍️ ShopVivaliz Admin</div>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1>Painel de Administração</h1>
            <p>Acesse todas as rotinas operacionais da plataforma</p>
        </div>

        <div class="menu-grid">
            <!-- LOJA PÚBLICA -->
            <div class="menu-section">
                <div class="menu-section-header">🏪 Loja Pública</div>
                <div class="menu-section-items">
                    <a href="/" class="menu-item external-link">Home</a>
                    <a href="/catalogo.php" class="menu-item external-link">Catálogo</a>
                    <a href="/produto.php" class="menu-item external-link">Página de Produto</a>
                    <a href="/checkout" class="menu-item external-link">Checkout</a>
                    <a href="/carrinho" class="menu-item external-link">Carrinho</a>
                </div>
            </div>

            <!-- PRODUTOS E CATÁLOGO -->
            <div class="menu-section">
                <div class="menu-section-header">📦 Produtos & Catálogo</div>
                <div class="menu-section-items">
                    <a href="/catalogo.php" class="menu-item">Visualizar Catálogo</a>
                    <a href="/api/catalog/products.php?limit=200" class="menu-item external-link">JSON Produtos</a>
                    <a href="/admin/olist-images-audit.php" class="menu-item external-link">Auditoria de Imagens</a>
                    <a href="/admin/reparar-catalogo-olist.php" class="menu-item external-link">Reparar Catálogo</a>
                </div>
            </div>

            <!-- INTEGRAÇÕES -->
            <div class="menu-section">
                <div class="menu-section-header">🔗 Integrações</div>
                <div class="menu-section-items">
                    <a href="/olist/connect.php" class="menu-item external-link">Conectar Olist (OAuth)</a>
                    <a href="/olist/sync-products.php?dry_run=1" class="menu-item external-link">Testar Sync Olist</a>
                    <a href="/admin/sync-olist-para-products.php" class="menu-item external-link">Sync Produtos</a>
                </div>
            </div>

            <!-- MERCADO LIVRE -->
            <div class="menu-section">
                <div class="menu-section-header">📱 Mercado Livre</div>
                <div class="menu-section-items">
                    <a href="/admin/mercadolivre" class="menu-item">Painel Mercado Livre</a>
                    <a href="/api/ml/login" class="menu-item external-link">Conectar OAuth</a>
                    <a href="/api/ml/products" class="menu-item external-link">Produtos JSON</a>
                </div>
            </div>

            <!-- PAGAMENTOS -->
            <div class="menu-section">
                <div class="menu-section-header">💳 Pagamentos</div>
                <div class="menu-section-items">
                    <a href="/api/pagarme/diagnostic.php" class="menu-item external-link">Diag Pagar.me</a>
                    <a href="/admin/force-git-pull.php" class="menu-item external-link">Forçar Git Pull</a>
                </div>
            </div>

            <!-- FRETE E ENTREGA -->
            <div class="menu-section">
                <div class="menu-section-header">🚚 Frete & Entrega</div>
                <div class="menu-section-items">
                    <a href="/api/melhorenvio/diagnostic.php?cep=35500025" class="menu-item external-link">Diag MelhorEnvio</a>
                </div>
            </div>

            <!-- MONITORAMENTO -->
            <div class="menu-section">
                <div class="menu-section-header">📊 Monitoramento</div>
                <div class="menu-section-items">
                    <a href="/admin/monitor/" class="menu-item external-link">Monitor Completo</a>
                    <a href="/admin/audit-dashboard.php" class="menu-item external-link">Audit Dashboard</a>
                    <a href="/admin/agents-monitor.php" class="menu-item external-link">Agentes</a>
                    <a href="/api/health.php" class="menu-item external-link">Health Check</a>
                </div>
            </div>

            <!-- DIAGNÓSTICO -->
            <div class="menu-section">
                <div class="menu-section-header">🔧 Diagnóstico</div>
                <div class="menu-section-items">
                    <a href="/installer/update-applied-check.php" class="menu-item external-link">Update Check</a>
                    <a href="/installer/auto-routines.php?expected=200&limit=50" class="menu-item external-link">Auto Routines</a>
                    <a href="/admin/diagnostico-banco.php" class="menu-item external-link">Diag Banco</a>
                </div>
            </div>

            <!-- CONFIGURAÇÃO -->
            <div class="menu-section">
                <div class="menu-section-header">⚙️ Configuração</div>
                <div class="menu-section-items">
                    <a href="/admin/company-profile.php" class="menu-item external-link">Perfil Empresa</a>
                    <a href="/admin/integrations.php" class="menu-item external-link">Integrações</a>
                </div>
            </div>

            <!-- AUTOMAÇÃO -->
            <div class="menu-section">
                <div class="menu-section-header">🤖 Automação & IA</div>
                <div class="menu-section-items">
                    <a href="/admin/monitor/" class="menu-item external-link">Pipeline IA</a>
                    <a href="/admin/agents-monitor.php" class="menu-item external-link">Agentes Autônomos</a>
                </div>
            </div>
        </div>

        <a href="/auth/logout.php" class="logout-btn">Sair do Admin</a>
    </div>
</body>
</html>
