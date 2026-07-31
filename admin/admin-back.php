<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
header('Content-Type: text/html; charset=UTF-8');

$legacyGroups = [
    [
        'id' => 'dashboards',
        'title' => 'Dashboards antigos',
        'description' => 'Painéis que foram substituídos pelo admin atual, mas continuam acessíveis para consulta.',
        'items' => [
            ['label' => 'Monitor Completo', 'href' => '/admin/monitor-completo.php', 'desc' => 'Painel consolidado de monitoramento legado.'],
            ['label' => 'Monitor v2', 'href' => '/admin/monitor-v2.html', 'desc' => 'Versão HTML antiga do monitor principal.'],
            ['label' => 'Monitor Completo v2', 'href' => '/admin/monitor-completo-v2.html', 'desc' => 'Dashboard experimental com abas antigas.'],
            ['label' => 'Monitor Real', 'href' => '/admin/monitor-real.html', 'desc' => 'Interface alternativa de observação em tempo real.'],
            ['label' => 'AI System Monitor', 'href' => '/admin/ai-system-monitor.php', 'desc' => 'Painel técnico antigo do sistema de IA.'],
            ['label' => 'Menu Dashboard', 'href' => '/admin/menu-dashboard.php', 'desc' => 'Menu visual antigo com vários atalhos internos.'],
            ['label' => 'Trio Dashboard', 'href' => '/admin/trio-dashboard.html', 'desc' => 'Dashboard experimental em HTML preservado como referência.'],
        ],
    ],
    [
        'id' => 'automacao',
        'title' => 'Automação antiga',
        'description' => 'Fluxos e dashboards da automação multicanal anteriores ao menu central.',
        'items' => [
            ['label' => 'Automação IA', 'href' => '/admin/automacao-ia-multicanal/', 'desc' => 'Entrada principal do módulo legado de automação.'],
            ['label' => 'Dashboard da automação', 'href' => '/admin/automacao-ia-multicanal/pages/dashboard.php', 'desc' => 'Visão resumida da automação antiga.'],
            ['label' => 'Fila de automações', 'href' => '/admin/automacao-ia-multicanal/pages/automacoes.php', 'desc' => 'Lista histórica das automações registradas.'],
            ['label' => 'Monitor de agentes', 'href' => '/admin/monitor_agentes.php', 'desc' => 'Painel de agentes autônomos legado.'],
            ['label' => 'Agents Monitor', 'href' => '/admin/agents-monitor.php', 'desc' => 'Versão simples de monitoramento de agentes.'],
            ['label' => 'Squad Chat', 'href' => '/admin/squad-chat.html', 'desc' => 'Interface HTML original do chat com agentes.'],
        ],
    ],
    [
        'id' => 'manutencao',
        'title' => 'Manutenção',
        'description' => 'Rotinas manuais de sincronização, reparo e auditoria do catálogo e do ambiente.',
        'items' => [
            ['label' => 'Configuração de frete', 'href' => '/admin/configuracoes-frete.php', 'desc' => 'Tela antiga de ajuste de frete e entrega.'],
            ['label' => 'Reparar catálogo', 'href' => '/admin/reparar-catalogo-olist.php', 'desc' => 'Rotina manual para corrigir inconsistências Olist/Tiny.'],
            ['label' => 'Auditoria de imagens', 'href' => '/admin/olist-images-audit.php', 'desc' => 'Auditar imagens importadas do ERP.'],
            ['label' => 'Sync Olist para produtos', 'href' => '/admin/sync-olist-para-products.php', 'desc' => 'Migração/sincronização antiga de produtos.'],
            ['label' => 'Sync critical files', 'href' => '/admin/sync-critical-files.php', 'desc' => 'Sincronização manual de arquivos críticos.'],
        ],
    ],
    [
        'id' => 'diagnostico',
        'title' => 'Diagnóstico',
        'description' => 'Ferramentas que ajudam a validar banco, monitor e fallback da operação.',
        'items' => [
            ['label' => 'Diagnóstico banco', 'href' => '/admin/diagnostico-banco.php', 'desc' => 'Verificação detalhada do banco de dados.'],
            ['label' => 'Teste banco', 'href' => '/admin/teste-banco.php', 'desc' => 'Teste rápido de conexão com o banco.'],
            ['label' => 'Monitor completo', 'href' => '/admin/monitor/index.php', 'desc' => 'Entrada clássica do monitor público/técnico.'],
            ['label' => 'Monitor raíz', 'href' => '/admin/monitor/index.html', 'desc' => 'Versão HTML completa do monitor técnico.'],
            ['label' => 'Backup v2', 'href' => '/admin/monitor/index-v2-backup.html', 'desc' => 'Cópia de segurança do monitor antigo.'],
            ['label' => 'Monitor v1', 'href' => '/admin/monitor/index-v1.html', 'desc' => 'Versão mais antiga ainda preservada.'],
        ],
    ],
    [
        'id' => 'scripts',
        'title' => 'Scripts',
        'description' => 'Rotinas manuais para sincronização e recuperação quando a automação não fecha sozinha.',
        'items' => [
            ['label' => 'Force Git Pull', 'href' => '/admin/force-git-pull.php', 'desc' => 'Sincronização manual quando o auto-sync falha.'],
            ['label' => 'Teste auto-sync', 'href' => '/admin/test-auto-sync.sh', 'desc' => 'Script de validação do serviço de sincronização.'],
        ],
    ],
];

$totalLegacyItems = array_sum(array_map(static fn(array $group): int => count($group['items']), $legacyGroups));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Back - Legado | ShopVivaliz</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f3f6fb;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --line: #dbe3ee;
            --accent: #1d4ed8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(180deg, #eef4ff 0%, var(--bg) 35%, #eef2f7 100%);
            color: var(--text);
        }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        }
        .hero-top { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
        .hero h1 { margin: 0 0 8px; font-size: 34px; line-height: 1.1; }
        .hero p { margin: 0; max-width: 860px; color: rgba(255,255,255,0.86); }
        .pill-row, .tabbar { display: flex; gap: 10px; flex-wrap: wrap; }
        .pill-row { margin-top: 18px; }
        .tab-shell { margin-top: 18px; display: grid; gap: 10px; }
        .pill, .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid rgba(255,255,255,0.12);
        }
        .pill { padding: 8px 12px; background: rgba(255,255,255,0.14); color: #fff; }
        .pill.primary { background: #fff; color: #0f172a; }
        .tab-btn {
            padding: 10px 14px;
            background: rgba(255,255,255,0.10);
            color: #fff;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
        }
        .tab-btn:hover { transform: translateY(-1px); background: rgba(255,255,255,0.18); }
        .tab-btn.active { background: #fff; color: #0f172a; }
        .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            color: inherit;
            font-size: 12px;
        }
        .tab-btn.active .tab-count { background: #dbeafe; color: #1e3a8a; }
        .tab-hint { margin: 0; color: rgba(255,255,255,0.78); font-size: 13px; }
        .alert {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.92);
        }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin-top: 22px; }
        .section {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 14px 30px rgba(15,23,42,0.06);
        }
        .section.is-hidden { display: none; }
        .section-head {
            padding: 18px 20px;
            background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
            border-bottom: 1px solid var(--line);
        }
        .section-head h2 { margin: 0 0 4px; font-size: 20px; }
        .section-head p { margin: 0; color: var(--muted); font-size: 14px; }
        .list { padding: 16px; display: grid; gap: 12px; }
        .item {
            display: block;
            padding: 14px 16px;
            border-radius: 16px;
            background: var(--panel-soft);
            border: 1px solid #e5ebf4;
            text-decoration: none;
            color: var(--text);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .item:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.10);
            border-color: #bfd0ff;
        }
        .item-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .item strong { display: block; font-size: 15px; margin-bottom: 4px; }
        .item code {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1e3a8a;
            font-size: 12px;
            white-space: nowrap;
        }
        .item p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.45; }
        .footer {
            margin: 22px 0 6px;
            display: flex;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
            color: var(--muted);
            font-size: 14px;
        }
        .footer a { text-decoration: none; color: var(--accent); font-weight: 700; }
        @media (max-width: 640px) {
            .wrap { padding: 14px; }
            .hero { padding: 20px; border-radius: 20px; }
            .hero h1 { font-size: 28px; }
        }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <div class="wrap">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <h1>Admin Back / Legado</h1>
                    <p>Central única para rotas antigas, painéis experimentais e rotinas de suporte que continuam disponíveis por compatibilidade. Use esta área quando precisar acessar algo que ainda não foi absorvido pelo admin principal.</p>
                </div>
            </div>
            <div class="pill-row">
                <a class="pill primary" href="/admin/">Voltar ao admin principal</a>
                <a class="pill" href="/admin/menu-completo.php">Abrir menu completo</a>
                <a class="pill" href="/admin/monitor/">Abrir monitor</a>
            </div>
            <div class="tab-shell">
                <div class="tabbar" role="tablist" aria-label="Categorias do legado">
                    <button type="button" class="tab-btn active" data-tab-target="all">Todos <span class="tab-count"><?= $totalLegacyItems ?></span></button>
                    <?php foreach ($legacyGroups as $group): ?>
                        <button type="button" class="tab-btn" data-tab-target="<?= htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="tab-count"><?= count($group['items']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="tab-hint">Clique numa aba para filtrar os blocos abaixo. O conteúdo continua no mesmo endereço.</p>
            </div>
            <div class="alert">
                As rotas abaixo são mantidas como legado, fallback ou suporte técnico. Elas não substituem o fluxo principal do admin.
            </div>
        </section>

        <div class="grid">
            <?php foreach ($legacyGroups as $group): ?>
                <section class="section" data-tab="<?= htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="section-head">
                        <h2><?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><?= htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="list">
                        <?php foreach ($group['items'] as $item): ?>
                            <a class="item" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">
                                <div class="item-top">
                                    <div>
                                        <strong><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <code><?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?></code>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="footer">
            <span>Área criada para concentrar o que é legado sem poluir o admin principal.</span>
            <span><a href="/admin/menu-completo.php">Ir para o menu completo</a> · <a href="/admin/">Ir para o início</a></span>
        </div>
    </div>

    <script>
    (() => {
        const buttons = Array.from(document.querySelectorAll('[data-tab-target]'));
        const sections = Array.from(document.querySelectorAll('[data-tab]'));

        const activate = (tab) => {
            const chosen = buttons.some((button) => button.dataset.tabTarget === tab) ? tab : 'all';
            buttons.forEach((button) => {
                const active = button.dataset.tabTarget === chosen;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            sections.forEach((section) => {
                const visible = chosen === 'all' || section.dataset.tab === chosen;
                section.classList.toggle('is-hidden', !visible);
            });
        };

        buttons.forEach((button) => {
            button.addEventListener('click', () => activate(button.dataset.tabTarget || 'all'));
        });

        activate((window.location.hash || '').replace('#', ''));
    })();
    </script>
</body>
</html>
