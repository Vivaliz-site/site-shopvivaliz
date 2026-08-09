<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/admin-sections.php';

header('Content-Type: text/html; charset=UTF-8');

$version = is_file(__DIR__ . '/../config/shopvivaliz-version.php')
    ? require __DIR__ . '/../config/shopvivaliz-version.php'
    : [];
$appVersion = (string)($version['version'] ?? '0.0.0');
$codename = (string)($version['codename'] ?? '');
$sections = sv_admin_sections();
$totalItems = sv_admin_total_items($sections);
$sectionCount = count($sections);

$spotlights = [
    [
        'eyebrow' => 'Catalogo protegido',
        'title' => 'Otimizacao de cadastro por marketplace',
        'href' => '/admin/catalog-optimization/admin_catalog.php',
        'desc' => 'Selecionar produtos ativos, gerar SEO e aprovar em lote sem tocar em preco ou estoque.',
        'tone' => 'orange',
    ],
    [
        'eyebrow' => 'Imagem real',
        'title' => 'AI Image Studio com selecao por listagem',
        'href' => '/admin/ai-image-studio/admin_dashboard.php',
        'desc' => 'Escolher produtos ativos, gerar imagens por canal e revisar antes de publicar.',
        'tone' => 'violet',
    ],
    [
        'eyebrow' => 'Operacao viva',
        'title' => 'Conexoes, monitor e diagnostico',
        'href' => '/admin/connections.php',
        'desc' => 'Validar OAuth, tokens e pontos criticos sem depender de paineis duplicados.',
        'tone' => 'teal',
    ],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Central Unica ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7fb;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --surface-strong: #0f172a;
            --line: #d7e0ea;
            --line-strong: #b8c6d8;
            --text: #112033;
            --muted: #5a6b80;
            --navy: #14213d;
            --blue: #2563eb;
            --cyan: #0891b2;
            --orange: #ea580c;
            --violet: #7c3aed;
            --teal: #0f766e;
            --emerald: #0f9f6e;
            --amber: #d97706;
            --rose: #db2777;
            --shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            --radius-xl: 28px;
            --radius-lg: 20px;
            --radius-md: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.10), transparent 28%),
                radial-gradient(circle at top right, rgba(234, 88, 12, 0.10), transparent 22%),
                linear-gradient(180deg, #eef4fb 0%, var(--bg) 32%, #eef3f8 100%);
            color: var(--text);
        }
        .container { width: min(1380px, calc(100% - 32px)); margin: 0 auto; }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(14px);
            background: rgba(15, 23, 42, 0.94);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 0;
            flex-wrap: wrap;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            text-decoration: none;
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 52%, #ea580c 100%);
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.28);
            font-size: 18px;
        }
        .brand strong { display: block; font-size: 1rem; }
        .brand span { display: block; color: rgba(255, 255, 255, 0.72); font-size: 0.82rem; }
        .nav-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .nav-links a {
            color: rgba(255, 255, 255, 0.88);
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            font-size: 0.92rem;
            transition: background .2s ease, transform .2s ease;
        }
        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.14);
            transform: translateY(-1px);
        }
        .nav-links a.exit { color: #fecaca; }
        .hero {
            padding: 32px 0 16px;
        }
        .hero-shell {
            background:
                linear-gradient(135deg, rgba(20, 33, 61, 0.98) 0%, rgba(17, 94, 89, 0.96) 42%, rgba(124, 58, 237, 0.92) 100%);
            border-radius: var(--radius-xl);
            padding: 28px;
            color: #fff;
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }
        .hero-shell::after {
            content: "";
            position: absolute;
            inset: auto -8% -38% auto;
            width: 340px;
            height: 340px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.20), transparent 64%);
            pointer-events: none;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
            gap: 26px;
            align-items: start;
        }
        .eyebrow {
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.74rem;
            font-weight: 800;
            color: rgba(255, 255, 255, 0.74);
        }
        .hero h1 {
            margin: 0 0 14px;
            font-size: clamp(2rem, 5vw, 4rem);
            line-height: 0.94;
            max-width: 700px;
        }
        .hero-copy {
            max-width: 720px;
            color: rgba(255, 255, 255, 0.84);
            font-size: 1.02rem;
            line-height: 1.62;
            margin: 0 0 20px;
        }
        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            max-width: 760px;
        }
        .hero-metric {
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            padding: 16px;
        }
        .hero-metric strong {
            display: block;
            font-size: 1.6rem;
            margin-bottom: 6px;
        }
        .hero-metric span {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.88rem;
        }
        .hero-side {
            display: grid;
            gap: 14px;
        }
        .hero-search {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 22px;
            padding: 18px;
        }
        .hero-search label {
            display: block;
            font-size: 0.86rem;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.78);
        }
        .hero-search input {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 1rem;
            background: rgba(11, 18, 32, 0.46);
            color: #fff;
        }
        .hero-search input::placeholder { color: rgba(255, 255, 255, 0.58); }
        .hero-shortcuts {
            display: grid;
            gap: 12px;
        }
        .hero-shortcut {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            text-decoration: none;
            border-radius: 18px;
            padding: 16px;
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            transition: transform .2s ease, background .2s ease;
        }
        .hero-shortcut:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.14);
        }
        .hero-shortcut-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 18px;
            background: rgba(255, 255, 255, 0.12);
            flex-shrink: 0;
        }
        .hero-shortcut strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.98rem;
        }
        .hero-shortcut span {
            color: rgba(255, 255, 255, 0.76);
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .spotlight-grid,
        .status-grid,
        .admin-function-grid,
        .product-grid.admin-grid {
            display: grid;
            gap: 18px;
        }
        .spotlight-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 18px;
        }
        .spotlight-card,
        .status-card,
        .section-card,
        .empty-card,
        .map-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        .spotlight-card {
            padding: 22px;
            text-decoration: none;
            color: inherit;
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
            position: relative;
            overflow: hidden;
        }
        .spotlight-card:hover {
            transform: translateY(-3px);
            border-color: var(--line-strong);
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
        }
        .spotlight-card::after {
            content: "";
            position: absolute;
            inset: auto -18px -18px auto;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.44);
            opacity: 0.32;
        }
        .spotlight-card[data-tone="orange"] { background: linear-gradient(135deg, #9a3412 0%, #ea580c 100%); color: #fff; }
        .spotlight-card[data-tone="violet"] { background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%); color: #fff; }
        .spotlight-card[data-tone="teal"] { background: linear-gradient(135deg, #115e59 0%, #0f766e 100%); color: #fff; }
        .spotlight-card small,
        .spotlight-card p { color: rgba(255, 255, 255, 0.82); }
        .spotlight-card h2 {
            margin: 8px 0 10px;
            font-size: 1.28rem;
            line-height: 1.2;
            position: relative;
            z-index: 1;
        }
        .spotlight-card p {
            margin: 0;
            line-height: 1.55;
            font-size: 0.94rem;
            position: relative;
            z-index: 1;
        }
        .spotlight-card small {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 800;
            position: relative;
            z-index: 1;
        }
        .status-section { padding: 16px 0 10px; }
        .status-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .status-card {
            padding: 22px;
        }
        .status-card.is-wide { grid-column: span 2; }
        .status-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 14px;
        }
        .status-head h2,
        .section-head h2 {
            margin: 0;
            font-size: 1.18rem;
        }
        .status-head p,
        .section-head p,
        .muted {
            margin: 6px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }
        .version-badge,
        .admin-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            background: #e0ecff;
            color: #19406c;
        }
        .link-row,
        .section-chip-row,
        .map-grid,
        .admin-link-list {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .link-row a,
        .admin-link-list .btn,
        .section-chip {
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 0.9rem;
        }
        .link-row a,
        .admin-link-list .btn {
            background: var(--surface-soft);
            color: var(--text);
            border: 1px solid var(--line);
        }
        .section-map {
            margin-top: 16px;
        }
        .map-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 14px;
        }
        .map-card {
            padding: 18px;
        }
        .map-card strong {
            display: block;
            margin-bottom: 8px;
            font-size: 0.96rem;
        }
        .map-card span {
            display: block;
            margin-bottom: 8px;
            color: #19406c;
            font-family: Consolas, monospace;
            font-size: 0.84rem;
            overflow-wrap: anywhere;
        }
        .map-card small {
            color: var(--muted);
            line-height: 1.5;
        }
        .functions-section {
            padding: 20px 0 18px;
        }
        .functions-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .functions-toolbar label {
            display: grid;
            gap: 8px;
            min-width: min(420px, 100%);
            font-weight: 700;
        }
        .functions-toolbar input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 1rem;
            background: var(--surface);
        }
        .section-chip-row {
            margin-top: 12px;
        }
        .section-chip {
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
            font-weight: 700;
        }
        .section-chip.is-active {
            background: var(--surface-strong);
            color: #fff;
            border-color: var(--surface-strong);
        }
        .admin-function-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .section-card {
            padding: 22px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .section-count {
            min-width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #edf3ff;
            color: #174075;
            font-weight: 800;
        }
        .admin-links {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .admin-link-card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .admin-link-card:hover {
            transform: translateY(-2px);
            border-color: var(--line-strong);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
        }
        .admin-link-card[data-tone="orange"] { background: linear-gradient(180deg, #fff5ed 0%, #fff 100%); }
        .admin-link-card[data-tone="violet"] { background: linear-gradient(180deg, #f6f0ff 0%, #fff 100%); }
        .admin-link-card[data-tone="teal"] { background: linear-gradient(180deg, #ecfeff 0%, #fff 100%); }
        .admin-link-card[data-tone="emerald"] { background: linear-gradient(180deg, #ecfdf5 0%, #fff 100%); }
        .admin-link-card[data-tone="amber"] { background: linear-gradient(180deg, #fff7ed 0%, #fff 100%); }
        .admin-link-card[data-tone="rose"] { background: linear-gradient(180deg, #fff1f6 0%, #fff 100%); }
        .admin-link-card[data-tone="blue"] { background: linear-gradient(180deg, #eff6ff 0%, #fff 100%); }
        .admin-link-card[data-tone="sky"] { background: linear-gradient(180deg, #f0f9ff 0%, #fff 100%); }
        .admin-link-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }
        .admin-link-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #fff;
            border: 1px solid rgba(17, 32, 51, 0.08);
            font-size: 1.08rem;
        }
        .admin-link-card strong {
            display: block;
            font-size: 0.98rem;
            margin-bottom: 6px;
        }
        .admin-link-card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.48;
            font-size: 0.9rem;
        }
        .empty-card {
            padding: 28px;
            text-align: center;
            color: var(--muted);
        }
        .catalog-page {
            padding-bottom: 42px;
        }
        .catalog-tools {
            margin: 10px auto 0;
            padding: 20px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        .status-line {
            font-weight: 700;
            margin-bottom: 14px;
        }
        .section-heading {
            margin: 18px auto 0;
        }
        .section-heading > div {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 20px;
        }
        .section-heading h2 {
            margin: 0 0 8px;
            font-size: 1.32rem;
        }
        @media (max-width: 1180px) {
            .hero-grid,
            .spotlight-grid,
            .status-grid,
            .admin-function-grid,
            .map-grid {
                grid-template-columns: 1fr;
            }
            .status-card.is-wide { grid-column: auto; }
            .admin-links { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .container { width: min(100% - 20px, 1380px); }
            .hero-shell,
            .status-card,
            .section-card,
            .catalog-tools,
            .section-heading > div {
                border-radius: 22px;
            }
            .hero-shell { padding: 22px; }
            .hero-metrics { grid-template-columns: 1fr; }
            .topbar-inner { padding: 12px 0; }
            .functions-toolbar label { min-width: 100%; }
        }
    </style>
    <?php require_once __DIR__ . '/../includes/load-custom-css.php'; ?>
</head>
<body>
    <nav class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="/admin/">
                <span class="brand-mark">SV</span>
                <span>
                    <strong>ShopVivaliz Admin</strong>
                    <span>Painel unico para operacao, IA e diagnostico</span>
                </span>
            </a>
            <div class="nav-links">
                <a href="#status-operacional">Status</a>
                <a href="#admin-functions">Funcoes</a>
                <a href="/admin/catalog-optimization/admin_catalog.php">Otimizacao</a>
                <a href="/admin/ai-image-studio/admin_dashboard.php">Imagens IA</a>
                <a href="/admin/connections.php">Conexoes</a>
                <a href="/admin/monitor/">Monitor</a>
                <a class="exit" href="/auth/logout.php">Sair</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="hero">
            <div class="container">
                <div class="hero-shell">
                    <div class="hero-grid">
                        <div>
                            <p class="eyebrow">Central unica do site</p>
                            <h1>Operar a ShopVivaliz sem pular entre telas duplicadas.</h1>
                            <p class="hero-copy">
                                Esta pagina concentra as funcoes administrativas, rotinas de IA, verificacoes operacionais
                                e atalhos de smoke test. O legado duplicado saiu do fluxo principal para que o time use um
                                ponto unico de controle.
                            </p>
                            <div class="hero-metrics">
                                <div class="hero-metric">
                                    <strong><?= $totalItems ?></strong>
                                    <span>funcoes publicadas nesta central</span>
                                </div>
                                <div class="hero-metric">
                                    <strong><?= $sectionCount ?></strong>
                                    <span>blocos organizados por area</span>
                                </div>
                                <div class="hero-metric">
                                    <strong><?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars($codename !== '' ? $codename : 'release sem codename', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                        </div>

                        <aside class="hero-side">
                            <div class="hero-search">
                                <label for="admin-function-search">Buscar funcao, rotina ou area</label>
                                <input id="admin-function-search" type="search" placeholder="Ex.: Olist, blog, imagens, SEO, pedidos">
                            </div>

                            <div class="hero-shortcuts">
                                <a class="hero-shortcut" href="/admin/monitor/">
                                    <span class="hero-shortcut-mark">📊</span>
                                    <span>
                                        <strong>Monitor central</strong>
                                        <span>Saude do site, operacao e pontos de atencao.</span>
                                    </span>
                                </a>
                                <a class="hero-shortcut" href="/admin/connections.php">
                                    <span class="hero-shortcut-mark">🔐</span>
                                    <span>
                                        <strong>Conexoes e tokens</strong>
                                        <span>OAuth, credenciais e diagnostico de integracoes.</span>
                                    </span>
                                </a>
                                <a class="hero-shortcut" href="/admin/pedidos.php">
                                    <span class="hero-shortcut-mark">📋</span>
                                    <span>
                                        <strong>Pedidos e operacao</strong>
                                        <span>Fluxo comercial, clientes e catalogo em um clique.</span>
                                    </span>
                                </a>
                            </div>
                        </aside>
                    </div>
                </div>

                <div class="spotlight-grid">
                    <?php foreach ($spotlights as $spotlight): ?>
                        <a
                            class="spotlight-card"
                            data-tone="<?= htmlspecialchars($spotlight['tone'], ENT_QUOTES, 'UTF-8') ?>"
                            href="<?= htmlspecialchars($spotlight['href'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <small><?= htmlspecialchars($spotlight['eyebrow'], ENT_QUOTES, 'UTF-8') ?></small>
                            <h2><?= htmlspecialchars($spotlight['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($spotlight['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="status-section" id="status-operacional">
            <div class="container status-grid">
                <article class="status-card">
                    <div class="status-head">
                        <div>
                            <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Publicacao</p>
                            <h2>Versao ativa</h2>
                            <p>Release de referencia do admin unificado.</p>
                        </div>
                        <span class="version-badge">v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <p class="muted">Codename atual: <strong><?= htmlspecialchars($codename !== '' ? $codename : 'sem codename', ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                    <div class="link-row">
                        <a href="/" target="_blank" rel="noreferrer">Abrir loja</a>
                        <a href="/catalogo.php" target="_blank" rel="noreferrer">Abrir catalogo</a>
                        <a href="/checkout" target="_blank" rel="noreferrer">Abrir checkout</a>
                    </div>
                </article>

                <article class="status-card">
                    <div class="status-head">
                        <div>
                            <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Integracoes</p>
                            <h2>Olist / Tiny</h2>
                            <p>Verificacao automatica do sync e das credenciais.</p>
                        </div>
                        <span class="admin-pill" id="olist-status-pill">Validando...</span>
                    </div>
                    <ul class="admin-checklist" id="olist-status-list">
                        <li>Carregando status operacional...</li>
                    </ul>
                    <div class="link-row">
                        <a href="/admin/connections.php">Painel de conexoes</a>
                        <a href="/olist/connect.php" target="_blank" rel="noreferrer">Reconectar OAuth</a>
                        <a href="/olist/admin.php" target="_blank" rel="noreferrer">Painel de sync</a>
                    </div>
                </article>

                <article class="status-card">
                    <div class="status-head">
                        <div>
                            <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Marketplace</p>
                            <h2>Mercado Livre</h2>
                            <p>Status rapido da conta e dos endpoints principais.</p>
                        </div>
                        <span class="admin-pill" id="ml-status-pill">Verificando...</span>
                    </div>
                    <ul class="admin-checklist" id="ml-status-list">
                        <li>Carregando status ML...</li>
                    </ul>
                    <div class="link-row">
                        <a href="/admin/mercadolivre">Painel ML</a>
                        <a href="/api/ml/login" target="_blank" rel="noreferrer">Conectar OAuth</a>
                        <a href="/api/ml/products" target="_blank" rel="noreferrer">Produtos JSON</a>
                    </div>
                </article>

                <article class="status-card">
                    <div class="status-head">
                        <div>
                            <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Diagnostico</p>
                            <h2>Saude do site</h2>
                            <p>Checks de aplicacao, update e rotinas automatas.</p>
                        </div>
                        <span class="admin-pill" id="health-status-pill">Verificando...</span>
                    </div>
                    <ul class="admin-checklist" id="health-status-list">
                        <li>Carregando health checks...</li>
                    </ul>
                    <div class="link-row">
                        <a href="/api/health.php" target="_blank" rel="noreferrer">API health</a>
                        <a href="/installer/update-applied-check.php" target="_blank" rel="noreferrer">Update check</a>
                        <a href="/installer/auto-routines.php?expected=200&limit=50" target="_blank" rel="noreferrer">Auto routines</a>
                    </div>
                </article>

                <article class="status-card is-wide">
                    <div class="status-head">
                        <div>
                            <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Mapa rapido</p>
                            <h2>Onde cada frente fica agora</h2>
                            <p>Rotas canonicas para operacao sem depender de paineis antigos.</p>
                        </div>
                    </div>
                    <div class="map-grid">
                        <div class="map-card">
                            <strong>Admin unico</strong>
                            <span>/admin/</span>
                            <small>Entrada oficial com todas as funcoes administrativas agrupadas.</small>
                        </div>
                        <div class="map-card">
                            <strong>Rotinas IA</strong>
                            <span>/admin/catalog-optimization/ · /admin/ai-image-studio/</span>
                            <small>Otimizacao de cadastro, geracao e validacao de imagens por marketplace.</small>
                        </div>
                        <div class="map-card">
                            <strong>Observabilidade</strong>
                            <span>/admin/monitor/ · /admin/connections.php</span>
                            <small>Monitoramento geral, integracoes, tokens e diagnosticos de ambiente.</small>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="functions-section" id="admin-functions">
            <div class="container">
                <div class="functions-toolbar">
                    <div>
                        <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Central completa</p>
                        <h2 style="margin: 0;">Todas as funcoes administrativas em uma tela</h2>
                        <p class="muted">As rotas de menu duplicado e dashboards alternativos sairam do fluxo principal.</p>
                    </div>
                    <div>
                        <div class="section-chip-row">
                            <button class="section-chip is-active" type="button" data-section-filter="all" aria-pressed="true">Todas</button>
                            <?php foreach ($sections as $section): ?>
                                <button
                                    class="section-chip"
                                    type="button"
                                    data-section-filter="<?= htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-pressed="false"
                                >
                                    <?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-function-grid">
                    <?php foreach ($sections as $section): ?>
                        <article
                            class="section-card"
                            data-admin-section
                            data-section-id="<?= htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <div class="section-head">
                                <div>
                                    <h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <p><?= htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="section-count"><span data-visible-count><?= count($section['items']) ?></span></div>
                            </div>
                            <div class="admin-links">
                                <?php foreach ($section['items'] as $item): ?>
                                    <?php
                                    $searchText = implode(' ', [
                                        $section['title'],
                                        $section['description'],
                                        $item['title'],
                                        $item['desc'],
                                        $item['href'],
                                    ]);
                                    ?>
                                    <a
                                        class="admin-link-card"
                                        href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-admin-item
                                        data-tone="<?= htmlspecialchars((string)($item['tone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-admin-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <div class="admin-link-meta">
                                            <span class="admin-link-icon"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <div>
                                                <strong><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>
                                        </div>
                                        <p><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <article class="empty-card" id="admin-functions-empty" hidden>
                    Nenhuma funcao encontrada para este filtro. Ajuste a busca ou volte para "Todas".
                </article>
            </div>
        </section>

        <section class="container catalog-tools">
            <div id="catalog-status" class="status-line">Carregando catalogo operacional...</div>
            <div class="admin-link-list">
                <a class="btn btn-secondary" href="/admin/monitor/" target="_blank" rel="noreferrer">Monitor</a>
                <a class="btn btn-secondary" href="/admin/connections.php" target="_blank" rel="noreferrer">Conexoes</a>
                <a class="btn btn-secondary" href="/api/catalog/products.php?limit=200" target="_blank" rel="noreferrer">Ver JSON</a>
            </div>
        </section>

        <section class="container section-heading">
            <div>
                <p class="eyebrow" style="color: var(--muted); margin-bottom: 8px;">Conferencia rapida</p>
                <h2>Catalogo operacional</h2>
                <p class="muted">
                    Busca ativa para conferir SKU, imagem e prontidao de venda.
                    <strong id="products-count">0</strong> produto(s) carregado(s) na grade.
                </p>
                <form class="catalog-search" role="search" style="margin-top: 16px; display: grid; gap: 10px; max-width: 720px;">
                    <input id="catalog-search" type="search" placeholder="Buscar SKU, nome ou categoria no catalogo operacional" autocomplete="off">
                    <button type="submit" class="btn btn-primary" style="justify-self: start;">Buscar no catalogo</button>
                </form>
            </div>
        </section>
        <section class="container product-grid admin-grid" id="product-grid" aria-live="polite"></section>
    </main>

    <script>
    (async () => {
        const pill = document.getElementById('ml-status-pill');
        const list = document.getElementById('ml-status-list');
        try {
            const response = await fetch('/api/ml/me', { signal: AbortSignal.timeout(5000) });
            if (response.ok) {
                const payload = await response.json();
                pill.textContent = 'Conectado';
                pill.style.cssText = 'background:#dcfce7;color:#166534';
                list.innerHTML = `<li>Conta: <strong>${payload.nickname || payload.email || 'OK'}</strong></li><li>ID: ${payload.id || '—'}</li>`;
            } else {
                pill.textContent = 'Desconectado';
                pill.style.cssText = 'background:#fee2e2;color:#991b1b';
                list.innerHTML = '<li>Token ML nao configurado ou expirado.</li>';
            }
        } catch (error) {
            pill.textContent = 'Desconectado';
            pill.style.cssText = 'background:#fee2e2;color:#991b1b';
            list.innerHTML = '<li>Nao conectado ao Mercado Livre.</li>';
        }
    })();
    </script>
    <script src="/autodev/client.js"></script>
    <script src="/js/admin-dashboard.js"></script>
    <script src="/js/admin-hub.js"></script>
    <script src="/js/catalog.js"></script>
</body>
</html>
