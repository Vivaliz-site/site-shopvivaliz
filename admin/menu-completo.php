<?php
/**
 * Central completa de rotinas administrativas.
 * Regra do projeto: toda tela administrativa navegavel deve possuir acesso aqui.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';

$sections = [
    'Gestão da loja' => [
        ['📊', 'Dashboard principal', '/admin/', 'Visão geral e atalhos principais.'],
        ['📦', 'Produtos', '/admin/produtos.php', 'Gerenciar o catálogo de produtos.'],
        ['✏️', 'Editar produto', '/admin/editar-produto.php', 'Tela auxiliar para edição de produto.'],
        ['📋', 'Pedidos', '/admin/pedidos.php', 'Consultar e administrar pedidos.'],
        ['👥', 'Clientes', '/admin/clientes.php', 'Consultar a base de clientes.'],
        ['🎟️', 'Cupons', '/admin/cupons.php', 'Cadastrar e administrar cupons.'],
        ['🎮', 'Gamificação', '/gamificacao.php', 'Acompanhar pontos, níveis e recompensas da loja.'],
        ['🏢', 'Perfil da empresa e redes sociais', '/admin/company-profile.php', 'Dados institucionais e links das redes sociais.'],
        ['⚙️', 'Configurações gerais', '/admin/settings.php', 'Configurações gerais da loja.'],
    ],
    'Conteúdo e aparência' => [
        ['📝', 'Blog', '/admin/blog.php', 'Criar, editar e publicar artigos.'],
        ['👁️', 'Prévia do blog', '/admin/blog-preview.php', 'Visualizar a apresentação do blog.'],
        ['🎨', 'Editor visual', '/admin/visual-editor.php', 'Editar elementos visuais do site.'],
        ['🖌️', 'Editor CSS', '/admin/css-editor.php', 'Personalizar estilos do site.'],
    ],
    'Frete e pagamentos' => [
        ['🚚', 'Configurações de frete', '/admin/configuracoes-frete.php', 'Configurar regras e provedores de frete.'],
        ['📦', 'Frete grátis', '/admin/settings-frete.php', 'Administrar condições de frete grátis.'],
        ['💳', 'Mercado Pago Sandbox', '/admin/mercadopago-sandbox.php', 'Testar a integração de pagamentos em sandbox.'],
    ],
    'Integrações e marketplaces' => [
        ['🔗', 'Integrações', '/admin/integrations.php', 'Configurar integrações externas.'],
        ['🛒', 'Mercado Livre', '/admin/mercadolivre.php', 'Administrar a integração com Mercado Livre.'],
        ['🔄', 'Sincronizar Olist para produtos', '/admin/sync-olist-para-products.php', 'Importar e atualizar produtos da Olist.'],
        ['🖼️', 'Auditoria de imagens Olist', '/admin/olist-images-audit.php', 'Verificar imagens e inconsistências.'],
        ['🧰', 'Reparar catálogo Olist', '/admin/reparar-catalogo-olist.php', 'Corrigir inconsistências do catálogo.'],
        ['🗂️', 'Sincronizar arquivos críticos', '/admin/sync-critical-files.php', 'Sincronizar arquivos críticos do projeto.'],
        ['🧪', 'Teste de webhook', '/admin/webhook-test.php', 'Testar recebimento e processamento de webhooks.'],
    ],
    'Automação e inteligência artificial' => [
        ['🤖', 'Automação IA multicanal', '/admin/automacao-ia-multicanal/', 'Central de automações multicanal.'],
        ['📈', 'Dashboard da automação IA', '/admin/automacao-ia-multicanal/pages/dashboard.php', 'Indicadores do módulo de automação.'],
        ['⚡', 'Automações', '/admin/automacao-ia-multicanal/pages/automacoes.php', 'Criar e administrar automações.'],
        ['🧠', 'Orchestrator', '/admin/orchestrator.php', 'Orquestrar agentes e fluxos.'],
        ['💬', 'Squad Chat', '/admin/squad-chat.php', 'Conversar com o conjunto de agentes.'],
        ['🩺', 'Monitor do sistema de IA', '/admin/ai-system-monitor.php', 'Acompanhar saúde e uso dos serviços de IA.'],
        ['🖼️', 'AI Image Studio — Gerar imagens', '/admin/ai-image-studio/admin_dashboard.php', 'Disparar geração de imagens de produto (OpenAI/Google/Claude) a partir da foto real.'],
        ['✅', 'AI Image Studio — Validar imagens', '/admin/ai-image-studio/admin_validate.php', 'Aprovar ou rejeitar as imagens geradas antes de publicar.'],
        ['📝', 'Otimização de Cadastro (SEO/GEO)', '/admin/catalog-optimization/admin_catalog.php', 'Reescrever título, descrição e SEO de produtos por canal de venda.'],
    ],
    'Monitoramento e auditoria' => [
        ['📡', 'Monitor principal', '/admin/monitor/', 'Painel central de saúde do sistema.'],
        ['📊', 'Monitor completo', '/admin/monitor-completo.php', 'Métricas e verificações detalhadas.'],
        ['🤖', 'Monitor de agentes', '/admin/agents-monitor.php', 'Status dos agentes autônomos.'],
        ['🛰️', 'Monitor de agentes alternativo', '/admin/monitor_agentes.php', 'Painel alternativo de monitoramento dos agentes.'],
        ['📋', 'Auditoria', '/admin/audit-dashboard.php', 'Auditar rotinas e operações administrativas.'],
        ['⏱️', 'SLA', '/admin/sla-dashboard.php', 'Acompanhar indicadores de disponibilidade e prazo.'],
    ],
    'Diagnóstico, manutenção e desenvolvimento' => [
        ['🩻', 'Diagnóstico do banco', '/admin/diagnostico-banco.php', 'Analisar estrutura e conectividade do banco.'],
        ['🧪', 'Teste do banco', '/admin/teste-banco.php', 'Executar teste de conexão com o banco.'],
        ['⬇️', 'Forçar Git pull', '/admin/force-git-pull.php', 'Ação de manutenção: atualizar código no servidor.'],
        ['🗃️', 'Admin legado', '/admin/admin-back.php', 'Acessar rotinas e painéis antigos.'],
        ['🧭', 'Menu dashboard legado', '/admin/menu-dashboard.php', 'Navegação administrativa alternativa.'],
        ['📚', 'Menu completo', '/admin/menu-completo.php', 'Esta central de todas as funções administrativas.'],
        ['🏠', 'Dashboard alternativo', '/admin/dashboard.php', 'Versão alternativa do dashboard administrativo.'],
    ],
    'Loja pública e APIs' => [
        ['🏪', 'Abrir loja', '/', 'Visualizar a página inicial pública.'],
        ['🛍️', 'Abrir catálogo', '/catalogo.php', 'Visualizar o catálogo público.'],
        ['🛒', 'Abrir carrinho', '/carrinho', 'Testar o carrinho de compras.'],
        ['💰', 'Abrir checkout', '/checkout', 'Testar o fluxo de checkout.'],
        ['❤️', 'Health da API', '/api/health.php', 'Consultar o estado da API em JSON.'],
        ['📦', 'Produtos em JSON', '/api/catalog/products.php?limit=200', 'Consultar a API do catálogo.'],
        ['🛒', 'Produtos Mercado Livre em JSON', '/api/ml/products', 'Consultar produtos do Mercado Livre.'],
    ],
];

$total = array_sum(array_map('count', $sections));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Admin Completo - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <style>
        *{box-sizing:border-box} body{margin:0;background:#f3f4f6;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        .topbar{background:#111827;color:#fff;padding:16px 20px;position:sticky;top:0;z-index:10;box-shadow:0 2px 10px rgba(0,0,0,.15)}
        .topbar-inner{max-width:1500px;margin:auto;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
        .topbar a{color:#fff;text-decoration:none}.top-actions{display:flex;gap:10px;flex-wrap:wrap}.top-actions a{padding:9px 12px;border-radius:8px;background:rgba(255,255,255,.12)}
        .container{max-width:1500px;margin:28px auto;padding:0 18px}.intro{display:flex;justify-content:space-between;gap:20px;align-items:end;flex-wrap:wrap;margin-bottom:24px}
        h1{margin:0 0 6px}.muted{color:#6b7280;margin:0}.search{width:min(440px,100%);padding:12px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:16px}
        .section{background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-bottom:22px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
        .section h2{margin:0;padding:16px 20px;background:#eef2ff;border-bottom:1px solid #e5e7eb;font-size:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px;padding:18px}
        .card{display:block;text-decoration:none;color:inherit;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:16px;min-height:120px;transition:.2s}
        .card:hover{transform:translateY(-2px);border-color:#4f46e5;box-shadow:0 8px 20px rgba(79,70,229,.12)}.icon{font-size:25px}.title{font-weight:700;margin:8px 0 5px}.desc{font-size:14px;color:#6b7280;line-height:1.4}
        .danger{border-left:4px solid #dc2626}.footer{text-align:center;color:#6b7280;padding:20px}.hidden{display:none!important}
    </style>
    <?php require_once __DIR__ . '/../includes/load-custom-css.php'; ?>
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>🛍️ ShopVivaliz — Administração</strong><nav class="top-actions"><a href="/admin/">Dashboard</a><a href="/">Loja</a><a href="/auth/logout.php">Sair</a></nav></div></header>
<main class="container">
    <div class="intro"><div><h1>Todas as funções administrativas</h1><p class="muted"><?= $total ?> acessos organizados por área.</p></div><input class="search" id="menu-search" type="search" placeholder="Buscar função administrativa..." autocomplete="off"></div>
    <?php foreach ($sections as $sectionTitle => $items): ?>
        <section class="section" data-section>
            <h2><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="grid">
                <?php foreach ($items as [$icon, $title, $url, $description]): ?>
                    <?php $danger = in_array($url, ['/admin/force-git-pull.php', '/admin/sync-critical-files.php', '/admin/reparar-catalogo-olist.php'], true); ?>
                    <a class="card<?= $danger ? ' danger' : '' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" data-item data-search="<?= htmlspecialchars(mb_strtolower($sectionTitle . ' ' . $title . ' ' . $description), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="icon"><?= $icon ?></div><div class="title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div><div class="desc"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <div class="footer">Central administrativa atualizada — <?= $total ?> botões disponíveis.</div>
</main>
<script>
(() => {
    const input = document.getElementById('menu-search');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    input.addEventListener('input', () => {
        const query = normalize(input.value);
        document.querySelectorAll('[data-section]').forEach(section => {
            let visible = 0;
            section.querySelectorAll('[data-item]').forEach(item => {
                const match = !query || normalize(item.dataset.search || '').includes(query);
                item.classList.toggle('hidden', !match);
                if (match) visible++;
            });
            section.classList.toggle('hidden', visible === 0);
        });
    });
})();
</script>
</body>
</html>
