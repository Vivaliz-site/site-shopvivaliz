<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function sva_root(): string
{
    return dirname(__DIR__, 2);
}

function sva_path(string $path): array
{
    $full = sva_root() . '/' . ltrim($path, '/');
    return array(
        'path' => $path,
        'exists' => file_exists($full),
        'type' => is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'missing'),
        'readable' => is_readable($full),
        'writable' => file_exists($full) ? is_writable($full) : is_writable(dirname($full)),
        'mtime' => file_exists($full) ? date('c', (int)filemtime($full)) : null,
    );
}

function sva_probe(string $path): array
{
    $url = 'https://dev.shopvivaliz.com.br/' . ltrim($path, '/');
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 8, 'ignore_errors' => true)));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m)) {
        $status = (int)$m[1];
    }
    return array('path' => $path, 'status' => $status, 'ok' => $status >= 200 && $status < 400, 'bytes' => is_string($body) ? strlen($body) : 0);
}

function sva_agent_checkout(): array
{
    return array(
        'agent' => 'cliente_checkout_audit',
        'mode' => 'safe_dry_run',
        'description' => 'Testa fluxo de compra, CEP, frete e disponibilidade de Pix/boleto sem criar pedido real.',
        'checks' => array(sva_probe('/'), sva_probe('/cnpj-rodape-teste.html'), sva_probe('api/melhorenvio/diagnostic.php?cep=35500025')),
        'approval_required_before_real_order' => true,
        'suggestions' => array('Mapear URL real do checkout para teste ponta a ponta.', 'Exibir erro detalhado quando frete depender de token ausente.', 'Separar modo sandbox e modo producao para meios de pagamento.'),
    );
}

function sva_agent_designer(): array
{
    return array(
        'agent' => 'designer_banners_categorias',
        'mode' => 'brief_generator',
        'description' => 'Gera pautas/briefings para banners, capas de categorias e imagens comerciais.',
        'outputs' => array('banner_home', 'capa_categoria', 'hero_produto', 'thumb_categoria'),
        'suggestions' => array('Criar padrao 1920x620 para banner principal.', 'Criar padrao 1200x420 para capa de categoria.', 'Criar padrao 1080x1080 para chamada socialcatalogo.', 'Manter sem texto quando a imagem for usada como foto de produto.'),
    );
}

function sva_agent_product_content(): array
{
    return array(
        'agent' => 'auditor_cadastro_produtos',
        'mode' => 'suggest_only_admin_approval',
        'description' => 'Audita cadastro e gera sugestoes para titulo, descricao, SEO, slug, tags e palavras-chave.',
        'fields' => array('titulo', 'descricao', 'descricao_seo', 'titulo_seo', 'palavras_chave', 'slug', 'tags'),
        'admin_approval_required' => true,
        'rules' => array('Nunca aplicar sugestao automaticamente no produto.', 'Registrar antes/depois para revisao.', 'Priorizar clareza, marca, modelo, medida, material e uso.'),
    );
}

function sva_agent_product_images(): array
{
    return array(
        'agent' => 'auditor_imagens_produtos',
        'mode' => 'audit_only',
        'description' => 'Audita capa, imagens secundarias, imagens ausentes, repetidas, quebradas ou suspeitas.',
        'checks' => array('imagem_capa', 'galeria', 'url_quebrada', 'duplicada', 'baixa_resolucao', 'produto_incorreto'),
        'suggestions' => array('Marcar imagem suspeita para fila de revisao.', 'Reimportar imagens via Olist quando houver vazio.', 'Evitar aplicar substituicao sem aprovacao do admin.'),
    );
}

function sva_agent_routines(): array
{
    return array(
        'agent' => 'auditor_rotinas',
        'mode' => 'route_and_file_audit',
        'description' => 'Testa rotinas existentes por arquivos e endpoints conhecidos.',
        'files' => array(sva_path('api'), sva_path('api/agent'), sva_path('api/melhorenvio/diagnostic.php'), sva_path('api/agent/olist-zero-id-repair.php'), sva_path('api/agent/admin-manual-audit.php')),
        'probes' => array(sva_probe('api/agent/admin-manual-audit.php'), sva_probe('api/agent/olist-zero-id-repair.php'), sva_probe('api/melhorenvio/diagnostic.php?cep=35500025')),
    );
}

function sva_agent_integrations(): array
{
    return array(
        'agent' => 'auditor_integracoes_api',
        'mode' => 'diagnostic',
        'description' => 'Audita rotinas de integracoes e APIs: Olist, Melhor Envio, Pagar.me, webhooks e callbacks.',
        'targets' => array('olist', 'melhorenvio', 'pagarme', 'webhooks', 'callbacks', 'tokens', 'logs'),
        'suggestions' => array('Padronizar endpoints de diagnostico por integracao.', 'Validar token_detected sem expor token.', 'Registrar status HTTP, payload mascarado e ultimo erro.', 'Separar callback, webhook e calculo em testes independentes.'),
    );
}

function sva_agent_marketing(): array
{
    return array(
        'agent' => 'marketing_ads_planner',
        'mode' => 'draft_only',
        'description' => 'Cria rascunhos de campanhas, grupos, anuncios e criativos para revisao antes de vincular em Google Ads.',
        'admin_approval_required' => true,
        'outputs' => array('campanha', 'grupo_anuncio', 'copy_anuncio', 'palavras_chave', 'landing_page', 'orcamento_sugerido'),
        'rules' => array('Nao publicar campanha automaticamente.', 'Nao alterar conta de anuncios sem aprovacao.', 'Gerar rascunhos por categoria/produto.'),
    );
}

function sva_agent_google_shopping(): array
{
    return array(
        'agent' => 'google_shopping_audit',
        'mode' => 'compliance_audit',
        'description' => 'Audita prontidao para Google Shopping: produto, categoria, feed, sitemap, imagens, preco e disponibilidade.',
        'checks' => array('titulo', 'descricao', 'imagem', 'preco', 'estoque', 'gtin_mpn_marca', 'categoria_google', 'sitemap', 'feed_xml', 'shipping', 'return_policy'),
        'suggestions' => array('Criar feed de produtos validavel.', 'Mapear categorias internas para taxonomia Google.', 'Garantir imagem principal sem marca d agua e acessivel.', 'Incluir dados de frete e disponibilidade consistentes.'),
    );
}

function sva_agent_finance_margin(): array
{
    return array(
        'agent' => 'finance_margin_audit',
        'mode' => 'audit_only',
        'description' => 'Audita margem, preco, custo, frete, comissao, taxas de pagamento e produtos com risco de prejuizo.',
        'checks' => array('preco_venda', 'custo_produto', 'custo_frete', 'comissao_marketplace', 'taxa_pagamento', 'impostos_estimados', 'margem_minima', 'margem_real'),
        'admin_approval_required' => true,
        'suggestions' => array('Criar regra de margem minima por categoria.', 'Sinalizar produto com preco abaixo do minimo.', 'Simular margem por Pix, boleto e cartao.', 'Nunca alterar preco automaticamente sem aprovacao.'),
    );
}

function sva_agent_inventory(): array
{
    return array(
        'agent' => 'inventory_pricing_audit',
        'mode' => 'audit_only',
        'description' => 'Audita estoque, disponibilidade, divergencias entre Olist/site e produtos vendaveis sem imagem, preco ou frete.',
        'checks' => array('estoque_site', 'estoque_olist', 'produto_ativo_sem_estoque', 'produto_com_estoque_sem_imagem', 'produto_sem_preco', 'produto_sem_peso_dimensoes'),
        'suggestions' => array('Bloquear compra de produto sem estoque confirmado.', 'Criar fila de divergencias Olist x site.', 'Priorizar correcao de produtos ativos sem imagem.'),
    );
}

function sva_agent_lgpd(): array
{
    return array(
        'agent' => 'lgpd_privacy_audit',
        'mode' => 'compliance_audit',
        'description' => 'Audita privacidade, cookies, formularios, dados pessoais, politicas e exposicao indevida de informacoes.',
        'checks' => array('politica_privacidade', 'politica_cookies', 'termos_uso', 'consentimento_cookies', 'formularios_com_dados_pessoais', 'logs_publicos', 'tokens_expostos'),
        'suggestions' => array('Publicar politica de privacidade acessivel no rodape.', 'Evitar gravar tokens em logs publicos.', 'Mascarar CPF, telefone, email e chaves em diagnosticos.', 'Criar rotina de exclusao/anonimizacao quando aplicavel.'),
    );
}

function sva_agent_security(): array
{
    return array(
        'agent' => 'security_permissions_audit',
        'mode' => 'security_audit',
        'description' => 'Audita arquivos sensiveis publicados, permissoes, endpoints abertos, logs publicos e configuracoes perigosas.',
        'checks' => array('arquivos_sql_publicos', 'arquivos_zip_publicos', 'config_publica', 'env_publico', 'logs_publicos', 'endpoints_sem_protecao', 'permissoes_escrita'),
        'files' => array(sva_path('.env'), sva_path('config.php'), sva_path('pagar.me.txt'), sva_path('token-chat-gpt.txt'), sva_path('logs'), sva_path('storage')),
        'suggestions' => array('Nunca publicar .env, .sql, .zip ou arquivos de token.', 'Proteger endpoints apply=1 com chave administrativa.', 'Mascarar segredos em respostas JSON.', 'Criar allowlist para agentes destrutivos.'),
    );
}

function sva_agent_performance(): array
{
    return array(
        'agent' => 'performance_core_web_vitals',
        'mode' => 'performance_audit',
        'description' => 'Audita velocidade, imagens pesadas, cache, HTML/CSS/JS e experiencia mobile.',
        'checks' => array('lcp', 'cls', 'inp', 'imagens_pesadas', 'lazy_loading', 'cache_statico', 'css_js_minificado', 'mobile_first'),
        'probes' => array(sva_probe('/'), sva_probe('/cnpj-rodape-teste.html')),
        'suggestions' => array('Converter imagens grandes para WebP quando adequado.', 'Aplicar lazy loading em galerias.', 'Criar cache para assets estaticos.', 'Evitar scripts bloqueantes no checkout.'),
    );
}

function sva_agent_legal_docs(): array
{
    return array(
        'agent' => 'fiscal_documental_audit',
        'mode' => 'compliance_audit',
        'description' => 'Audita CNPJ, razao social, politicas de troca, entrega, privacidade e dados obrigatorios da loja.',
        'checks' => array('cnpj_rodape', 'razao_social', 'politica_troca', 'politica_entrega', 'politica_privacidade', 'contato_sac', 'dados_empresa', 'prazo_entrega'),
        'suggestions' => array('Manter CNPJ visivel no rodape.', 'Publicar paginas de troca, entrega e privacidade.', 'Padronizar contato de atendimento.', 'Conferir dados legais antes de campanhas.'),
    );
}

function sva_agent_sac(): array
{
    return array(
        'agent' => 'atendimento_sac_audit',
        'mode' => 'service_audit',
        'description' => 'Audita WhatsApp, email, respostas automaticas, FAQ, jornada de suporte e pos-venda.',
        'checks' => array('whatsapp', 'email_sac', 'formularios_contato', 'faq', 'resposta_pedido', 'rastreamento', 'troca_devolucao'),
        'suggestions' => array('Criar FAQ com entrega, troca, pagamento e rastreamento.', 'Exibir canais de atendimento no rodape e checkout.', 'Criar respostas padrao para pedido aprovado, enviado e entregue.'),
    );
}

function sva_agent_marketplace_competition(): array
{
    return array(
        'agent' => 'marketplace_competition_audit',
        'mode' => 'research_brief_only',
        'description' => 'Gera roteiro de comparacao de preco, cadastro e padroes de marketplace sem copiar conteudo de terceiros.',
        'checks' => array('preco_competitivo', 'titulo_padrao_marketplace', 'atributos_obrigatorios', 'frete', 'prazo', 'imagens', 'diferenciais'),
        'rules' => array('Nao copiar descricoes de concorrentes.', 'Usar comparacao apenas para melhorar cadastro proprio.', 'Registrar fonte quando pesquisa externa for usada.'),
        'suggestions' => array('Criar score competitivo por produto.', 'Priorizar categorias com maior margem e demanda.', 'Ajustar titulo e atributos para padrao de marketplace.'),
    );
}

$agent = strtolower((string)($_GET['agent'] ?? 'all'));
$all = array(
    'checkout' => sva_agent_checkout(),
    'designer' => sva_agent_designer(),
    'products' => sva_agent_product_content(),
    'images' => sva_agent_product_images(),
    'routines' => sva_agent_routines(),
    'integrations' => sva_agent_integrations(),
    'marketing' => sva_agent_marketing(),
    'shopping' => sva_agent_google_shopping(),
    'finance' => sva_agent_finance_margin(),
    'inventory' => sva_agent_inventory(),
    'lgpd' => sva_agent_lgpd(),
    'security' => sva_agent_security(),
    'performance' => sva_agent_performance(),
    'legal' => sva_agent_legal_docs(),
    'sac' => sva_agent_sac(),
    'competition' => sva_agent_marketplace_competition(),
);

$out = array(
    'ok' => true,
    'suite' => 'autonomous_business_agents_v2',
    'generated_at' => date('c'),
    'agent' => $agent,
    'safe_mode' => true,
    'admin_approval_required_for_changes' => true,
    'data' => $agent === 'all' ? $all : ($all[$agent] ?? array('error' => 'unknown_agent', 'available' => array_keys($all))),
    'available_agents' => array_keys($all),
    'next_recommended_build' => array('painel_admin_agentes', 'fila_aprovacao_sugestoes', 'historico_execucoes_agentes', 'protecion_apply_com_chave_admin'),
);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
