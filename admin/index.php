<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

header('Content-Type: text/html; charset=UTF-8');
$version = is_file(__DIR__ . '/../config/shopvivaliz-version.php') ? require __DIR__ . '/../config/shopvivaliz-version.php' : [];
$appVersion = (string)($version['version'] ?? '0.0.0');
$codename = (string)($version['codename'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Central ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <?php require_once __DIR__ . '/../includes/load-custom-css.php'; ?>
</head>
<body>
    <nav class="navbar" style="background: #1a1a2e; padding: 1rem 0;">
        <div class="container nav-inner" style="display: flex; justify-content: space-between; align-items: center;">
            <a class="brand-link" href="/admin/" style="color: white; font-weight: bold; font-size: 1.2rem;">🛍️ ShopVivaliz Admin</a>
            <div class="navbar-menu" style="display: flex; gap: 0.5rem; flex-wrap: wrap; font-size: 0.9rem;">
                <a href="/admin/menu-completo.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px; font-weight: bold; background: rgba(255,255,255,0.2);">📋 Menu Completo</a>
                <a href="/admin/produtos.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">📦 Produtos</a>
                <a href="/admin/pedidos.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">📋 Pedidos</a>
                <a href="/admin/clientes.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">👥 Clientes</a>
                <a href="/admin/admin-back.php" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">🗂️ Legado</a>
                <a href="/admin/monitor/" style="color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">📊 Monitor</a>
                <a href="/auth/logout.php" style="color: #ff6b6b; text-decoration: none; padding: 0.5rem 1rem; border-radius: 4px;">🚪 Sair</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow">Central do site</p>
                    <h1>Onde configurar e operar a ShopVivaliz</h1>
                    <p class="muted">Painel único para loja, catálogo, integrações Olist/Tiny, diagnósticos de publicação e áreas técnicas do projeto.</p>
                </div>
                <form class="catalog-search" role="search">
                    <input id="catalog-search" type="search" placeholder="Buscar SKU ou produto" autocomplete="off">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </section>

        <section class="container admin-overview" style="margin-top: 2rem;">
            <!-- Menu Principal -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <a href="/admin/pedidos.php" style="background: #007bff; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    📋 Pedidos
                </a>
                <a href="/admin/produtos.php" style="background: #28a745; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    📦 Produtos
                </a>
                <a href="/admin/clientes.php" style="background: #17a2b8; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    👥 Clientes
                </a>
                <a href="/admin/cupons.php" style="background: #10b981; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    🎟️ Cupons
                </a>
                <a href="/admin/menu-completo.php" style="background: #6c757d; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    🎯 Menu Completo
                </a>
                <a href="/admin/monitor/" style="background: #ffc107; color: black; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    📊 Monitor
                </a>
                <a href="/admin/integrations.php" style="background: #e83e8c; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; font-weight: bold; transition: all 0.3s;">
                    ⚙️ Integrações
                </a>
            </div>

            <article class="admin-card admin-card-wide">
                <div class="admin-card-head">
                    <div>
                        <p class="eyebrow">Publicação</p>
                        <h2>Versão ativa</h2>
                    </div>
                    <span class="version">v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <p class="muted">Release atual no projeto: <strong><?= htmlspecialchars($codename !== '' ? $codename : 'sem codename', ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                <div class="admin-link-list">
                    <a class="btn btn-primary" href="/" target="_blank" rel="noreferrer">Abrir loja</a>
                    <a class="btn btn-secondary" href="/catalogo.php" target="_blank" rel="noreferrer">Abrir catálogo</a>
                    <a class="btn btn-secondary" href="/produto.php" target="_blank" rel="noreferrer">Abrir produto</a>
                    <a class="btn btn-secondary" href="/checkout" target="_blank" rel="noreferrer">Abrir checkout</a>
                </div>
            </article>

            <article class="admin-card">
                <div class="admin-card-head">
                    <div>
                        <p class="eyebrow">Integrações</p>
                        <h2>Olist / Tiny</h2>
                    </div>
                    <span class="admin-pill" id="olist-status-pill">Validando...</span>
                </div>
                <ul class="admin-checklist" id="olist-status-list">
                    <li>Carregando status operacional...</li>
                </ul>
                <div class="admin-link-list">
                    <a class="btn btn-secondary" href="/olist/connect.php" target="_blank" rel="noreferrer">Reconectar OAuth</a>
                    <a class="btn btn-secondary" href="/olist/sync-products.php?dry_run=1&expected=200&limit=50" target="_blank" rel="noreferrer">Testar sync</a>
                    <a class="btn btn-secondary" href="/api/catalog/products.php?limit=200" target="_blank" rel="noreferrer">Ver catálogo JSON</a>
                </div>
            </article>

            <article class="admin-card">
                <div class="admin-card-head">
                    <div>
                        <p class="eyebrow">Marketplace</p>
                        <h2>Mercado Livre</h2>
                    </div>
                    <span class="admin-pill" id="ml-status-pill">Verificando...</span>
                </div>
                <ul class="admin-checklist" id="ml-status-list">
                    <li>Carregando status ML...</li>
                </ul>
                <div class="admin-link-list">
                    <a class="btn btn-primary" href="/admin/mercadolivre">Painel ML</a>
                    <a class="btn btn-secondary" href="/api/ml/login" target="_blank">Conectar OAuth</a>
                    <a class="btn btn-secondary" href="/api/ml/products" target="_blank">Produtos JSON</a>
                </div>
            </article>

            <article class="admin-card">
                <div class="admin-card-head">
                    <div>
                        <p class="eyebrow">Diagnóstico</p>
                        <h2>Saúde do site</h2>
                    </div>
                    <span class="admin-pill" id="health-status-pill">Verificando...</span>
                </div>
                <ul class="admin-checklist" id="health-status-list">
                    <li>Carregando health checks...</li>
                </ul>
                <div class="admin-link-list">
                    <a class="btn btn-secondary" href="/installer/update-applied-check.php" target="_blank" rel="noreferrer">Update check</a>
                    <a class="btn btn-secondary" href="/installer/auto-routines.php?expected=200&limit=50" target="_blank" rel="noreferrer">Auto routines</a>
                    <a class="btn btn-secondary" href="/api/melhorenvio/diagnostic.php?cep=35500025" target="_blank" rel="noreferrer">Diag frete</a>
                    <a class="btn btn-secondary" href="/api/health.php" target="_blank" rel="noreferrer">API health</a>
                </div>
            </article>

            <article class="admin-card admin-card-wide">
                <div class="admin-card-head">
                    <div>
                        <p class="eyebrow">Onde configurar</p>
                        <h2>Mapa rápido do projeto</h2>
                    </div>
                </div>
                <div class="admin-map-grid">
                    <div class="admin-map-item">
                        <strong>Loja pública</strong>
                        <span>`/`, `/catalogo`, `/produto`, `/checkout`</span>
                        <small>Texto, navegação, vitrine e jornada do cliente.</small>
                    </div>
                    <div class="admin-map-item">
                        <strong>Configuração global</strong>
                        <span>`config/constants.php`</span>
                        <small>URLs, e-mail, integrações, flags e defaults do ambiente.</small>
                    </div>
                    <div class="admin-map-item">
                        <strong>Versão / release</strong>
                        <span>`config/shopvivaliz-version.php`</span>
                        <small>Versão ativa, codename e notas cumulativas.</small>
                    </div>
                    <div class="admin-map-item">
                        <strong>Integração Olist/Tiny</strong>
                        <span>`olist/connect.php`, `olist/sync-products.php`, `api/olist/`</span>
                        <small>OAuth, sync, proxy V2 e detalhe de produtos.</small>
                    </div>
                    <div class="admin-map-item">
                        <strong>Painéis técnicos</strong>
                        <span>`/admin/monitor/`, `/admin/admin-back.php`, `/admin/menu-completo.php`</span>
                        <small>Monitoramento central, legado e rotinas principais do admin.</small>
                    </div>
                    <div class="admin-map-item">
                        <strong>Pedidos e eventos</strong>
                        <span>`api/orders/create.php`, `autodev/`, `api/monitor/`</span>
                        <small>Checkout, captura de eventos e trilha operacional.</small>
                    </div>
                </div>
            </article>
        </section>

        <section class="container catalog-tools">
            <div id="catalog-status" class="status-line">Carregando catálogo...</div>
            <div class="admin-link-list">
                <a class="btn btn-secondary" href="/admin/monitor/" target="_blank" rel="noreferrer">Monitor</a>
                <a class="btn btn-secondary" href="/admin/admin-back.php" target="_blank" rel="noreferrer">Legado</a>
                <a class="btn btn-secondary" href="/api/catalog/products.php?limit=200" target="_blank" rel="noreferrer">Ver JSON</a>
            </div>
        </section>

        <section class="container section-heading">
            <div>
                <h2>Catálogo operacional</h2>
                <p class="muted">Busca rápida para conferir SKU, imagem e prontidão de venda.</p>
            </div>
        </section>
        <section class="container product-grid admin-grid" id="product-grid" aria-live="polite"></section>
    </main>

    <script>
    // ML status check
    (async () => {
        const pill = document.getElementById('ml-status-pill');
        const list = document.getElementById('ml-status-list');
        try {
            const r = await fetch('/api/ml/me', { signal: AbortSignal.timeout(5000) });
            if (r.ok) {
                const d = await r.json();
                pill.textContent = 'Conectado';
                pill.style.cssText = 'background:#dcfce7;color:#166534';
                list.innerHTML = `<li>Conta: <strong>${d.nickname || d.email || 'OK'}</strong></li><li>ID: ${d.id || '—'}</li>`;
            } else {
                pill.textContent = 'Desconectado';
                pill.style.cssText = 'background:#fee2e2;color:#991b1b';
                list.innerHTML = '<li>Token ML não configurado ou expirado.</li>';
            }
        } catch(e) {
            pill.textContent = 'Desconectado';
            pill.style.cssText = 'background:#fee2e2;color:#991b1b';
            list.innerHTML = '<li>Não conectado ao Mercado Livre.</li>';
        }
    })();
    </script>
    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
    <script src="/js/admin-dashboard.js"></script>
</body>
</html>
