<?php
declare(strict_types=1);

/**
 * Estrutura canonica da central administrativa.
 *
 * Toda funcao navegavel do admin deve aparecer aqui para que /admin/ seja a
 * unica entrada operacional. Rotas de dashboards duplicados e menus legados
 * foram removidas de proposito para reduzir confusao.
 *
 * @return array<int, array{
 *   id:string,
 *   title:string,
 *   description:string,
 *   items:list<array{
 *     icon:string,
 *     title:string,
 *     href:string,
 *     desc:string,
 *     tone?:string
 *   }>
 * }>
 */
function sv_admin_sections(): array
{
    return [
        [
            'id' => 'gestao-loja',
            'title' => 'Gestao da loja',
            'description' => 'Rotinas do dia a dia da operacao comercial e institucional.',
            'items' => [
                ['icon' => '📦', 'title' => 'Produtos', 'href' => '/admin/produtos.php', 'desc' => 'Gerenciar o catalogo de produtos.', 'tone' => 'emerald'],
                ['icon' => '✏️', 'title' => 'Editar produto', 'href' => '/admin/editar-produto.php', 'desc' => 'Tela auxiliar para edicao de produto.'],
                ['icon' => '📋', 'title' => 'Pedidos', 'href' => '/admin/pedidos.php', 'desc' => 'Consultar e administrar pedidos.', 'tone' => 'blue'],
                ['icon' => '👥', 'title' => 'Clientes', 'href' => '/admin/clientes.php', 'desc' => 'Consultar a base de clientes.'],
                ['icon' => '🎟️', 'title' => 'Cupons', 'href' => '/admin/cupons.php', 'desc' => 'Cadastrar e administrar cupons.'],
                ['icon' => '🎮', 'title' => 'Gamificacao', 'href' => '/gamificacao.php', 'desc' => 'Acompanhar pontos, niveis e recompensas da loja.'],
                ['icon' => '🏢', 'title' => 'Perfil da empresa e redes sociais', 'href' => '/admin/company-profile.php', 'desc' => 'Dados institucionais e links das redes sociais.'],
                ['icon' => '⚙️', 'title' => 'Configuracoes gerais', 'href' => '/admin/settings.php', 'desc' => 'Configuracoes gerais da loja.'],
            ],
        ],
        [
            'id' => 'conteudo-aparencia',
            'title' => 'Conteudo e aparencia',
            'description' => 'Edicao de conteudo, blog e ajustes visuais do site.',
            'items' => [
                ['icon' => '📝', 'title' => 'Blog', 'href' => '/admin/blog.php', 'desc' => 'Criar, editar e publicar artigos.'],
                ['icon' => '👁️', 'title' => 'Previa do blog', 'href' => '/admin/blog-preview.php', 'desc' => 'Visualizar a apresentacao do blog.'],
                ['icon' => '🎨', 'title' => 'Editor visual', 'href' => '/admin/visual-editor.php', 'desc' => 'Editar elementos visuais do site.'],
                ['icon' => '🖌️', 'title' => 'Editor CSS', 'href' => '/admin/css-editor.php', 'desc' => 'Personalizar estilos do site.'],
            ],
        ],
        [
            'id' => 'frete-pagamentos',
            'title' => 'Frete e pagamentos',
            'description' => 'Configuracoes de frete, entrega e regras comerciais.',
            'items' => [
                ['icon' => '🚚', 'title' => 'Configuracoes de frete', 'href' => '/admin/configuracoes-frete.php', 'desc' => 'Configurar regras e provedores de frete.'],
                ['icon' => '📦', 'title' => 'Frete gratis', 'href' => '/admin/settings-frete.php', 'desc' => 'Administrar condicoes de frete gratis.', 'tone' => 'rose'],
            ],
        ],
        [
            'id' => 'integracoes-marketplaces',
            'title' => 'Integracoes e marketplaces',
            'description' => 'Conectores, tokens, sync e reparos operacionais.',
            'items' => [
                ['icon' => '🔐', 'title' => 'Conexoes e tokens', 'href' => '/admin/connections.php', 'desc' => 'Validar credenciais, OAuth e integracoes ativas.', 'tone' => 'teal'],
                ['icon' => '🔗', 'title' => 'Integracoes', 'href' => '/admin/integrations.php', 'desc' => 'Configurar integracoes externas.'],
                ['icon' => '🛒', 'title' => 'Mercado Livre', 'href' => '/admin/mercadolivre', 'desc' => 'Administrar a integracao com Mercado Livre.'],
                ['icon' => '🔄', 'title' => 'Sincronizar Olist para produtos', 'href' => '/admin/sync-olist-para-products.php', 'desc' => 'Importar e atualizar produtos da Olist.', 'tone' => 'amber'],
                ['icon' => '🖼️', 'title' => 'Auditoria de imagens Olist', 'href' => '/admin/olist-images-audit.php', 'desc' => 'Verificar imagens e inconsistencias.'],
                ['icon' => '🧰', 'title' => 'Reparar catalogo Olist', 'href' => '/admin/reparar-catalogo-olist.php', 'desc' => 'Corrigir inconsistencias do catalogo.', 'tone' => 'amber'],
                ['icon' => '🗂️', 'title' => 'Sincronizar arquivos criticos', 'href' => '/admin/sync-critical-files.php', 'desc' => 'Sincronizacao manual de arquivos criticos.', 'tone' => 'amber'],
            ],
        ],
        [
            'id' => 'automacao-ia',
            'title' => 'Automacao e inteligencia artificial',
            'description' => 'Geracao assistida, automacoes e operacao dos agentes.',
            'items' => [
                ['icon' => '🤖', 'title' => 'Automacao IA multicanal', 'href' => '/admin/automacao-ia-multicanal/', 'desc' => 'Central de automacoes multicanal.'],
                ['icon' => '📈', 'title' => 'Dashboard da automacao IA', 'href' => '/admin/automacao-ia-multicanal/pages/dashboard.php', 'desc' => 'Indicadores do modulo de automacao.'],
                ['icon' => '⚡', 'title' => 'Automacoes', 'href' => '/admin/automacao-ia-multicanal/pages/automacoes.php', 'desc' => 'Criar e administrar automacoes.'],
                ['icon' => '🧠', 'title' => 'Orchestrator', 'href' => '/admin/orchestrator.php', 'desc' => 'Orquestrar agentes e fluxos.'],
                ['icon' => '💬', 'title' => 'Squad Chat', 'href' => '/admin/squad-chat.php', 'desc' => 'Conversar com o conjunto de agentes.'],
                ['icon' => '🩺', 'title' => 'Monitor do sistema de IA', 'href' => '/admin/ai-system-monitor.php', 'desc' => 'Acompanhar saude e uso dos servicos de IA.'],
                ['icon' => '🖼️', 'title' => 'AI Image Studio — Gerar imagens', 'href' => '/admin/ai-image-studio/admin_dashboard.php', 'desc' => 'Gerar imagens reais por marketplace a partir da foto do produto.', 'tone' => 'violet'],
                ['icon' => '✅', 'title' => 'AI Image Studio — Validar imagens', 'href' => '/admin/ai-image-studio/admin_validate.php', 'desc' => 'Aprovar, rejeitar ou regenerar imagens antes de publicar.', 'tone' => 'violet'],
                ['icon' => '📝', 'title' => 'Otimizacao de cadastro', 'href' => '/admin/catalog-optimization/admin_catalog.php', 'desc' => 'Reescrever titulo, descricao e SEO por canal de venda.', 'tone' => 'orange'],
            ],
        ],
        [
            'id' => 'monitoramento-auditoria',
            'title' => 'Monitoramento e auditoria',
            'description' => 'Paineis de observabilidade, validacao e acompanhamento.',
            'items' => [
                ['icon' => '📡', 'title' => 'Monitor principal', 'href' => '/admin/monitor/', 'desc' => 'Painel central de saude do sistema.', 'tone' => 'sky'],
                ['icon' => '📊', 'title' => 'Monitor completo', 'href' => '/admin/monitor-completo.php', 'desc' => 'Metricas e verificacoes detalhadas.'],
                ['icon' => '🤖', 'title' => 'Monitor de agentes', 'href' => '/admin/agents-monitor.php', 'desc' => 'Status dos agentes autonomos.'],
                ['icon' => '🛰️', 'title' => 'Monitor de agentes alternativo', 'href' => '/admin/monitor_agentes.php', 'desc' => 'Painel alternativo de monitoramento dos agentes.'],
                ['icon' => '📋', 'title' => 'Auditoria', 'href' => '/admin/audit-dashboard.php', 'desc' => 'Auditar rotinas e operacoes administrativas.'],
                ['icon' => '⏱️', 'title' => 'SLA', 'href' => '/admin/sla-dashboard.php', 'desc' => 'Acompanhar indicadores de disponibilidade e prazo.'],
            ],
        ],
        [
            'id' => 'diagnostico-manutencao',
            'title' => 'Diagnostico, manutencao e desenvolvimento',
            'description' => 'Ferramentas tecnicas para suporte, reparo e validacao.',
            'items' => [
                ['icon' => '🩻', 'title' => 'Diagnostico do banco', 'href' => '/admin/diagnostico-banco.php', 'desc' => 'Analisar estrutura e conectividade do banco.'],
                ['icon' => '🧪', 'title' => 'Teste do banco', 'href' => '/admin/teste-banco.php', 'desc' => 'Executar teste de conexao com o banco.'],
                ['icon' => '⬇️', 'title' => 'Forcar Git pull', 'href' => '/admin/force-git-pull.php', 'desc' => 'Atualizacao manual de codigo no servidor.', 'tone' => 'amber'],
            ],
        ],
        [
            'id' => 'loja-publica-apis',
            'title' => 'Loja publica e APIs',
            'description' => 'Acessos rapidos para smoke tests e validacoes externas.',
            'items' => [
                ['icon' => '🏪', 'title' => 'Abrir loja', 'href' => '/', 'desc' => 'Visualizar a pagina inicial publica.'],
                ['icon' => '🛍️', 'title' => 'Abrir catalogo', 'href' => '/catalogo.php', 'desc' => 'Visualizar o catalogo publico.'],
                ['icon' => '🛒', 'title' => 'Abrir carrinho', 'href' => '/carrinho', 'desc' => 'Testar o carrinho de compras.'],
                ['icon' => '💰', 'title' => 'Abrir checkout', 'href' => '/checkout', 'desc' => 'Testar o fluxo de checkout.'],
                ['icon' => '❤️', 'title' => 'Health da API', 'href' => '/api/health.php', 'desc' => 'Consultar o estado da API em JSON.'],
                ['icon' => '📦', 'title' => 'Produtos em JSON', 'href' => '/api/catalog/products.php?limit=200', 'desc' => 'Consultar a API do catalogo.'],
                ['icon' => '🛒', 'title' => 'Produtos Mercado Livre em JSON', 'href' => '/api/ml/products', 'desc' => 'Consultar produtos do Mercado Livre.'],
            ],
        ],
    ];
}

function sv_admin_total_items(array $sections): int
{
    return array_sum(array_map(
        static fn(array $section): int => count($section['items'] ?? []),
        $sections
    ));
}
