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
        'checks' => array(
            sva_probe('/'),
            sva_probe('/cnpj-rodape-teste.html'),
            sva_probe('/api/melhorenvio/diagnostic.php?cep=35500025'),
        ),
        'approval_required_before_real_order' => true,
        'suggestions' => array(
            'Mapear URL real do checkout para teste ponta a ponta.',
            'Exibir erro detalhado quando frete depender de token ausente.',
            'Separar modo sandbox e modo producao para meios de pagamento.',
        ),
    );
}

function sva_agent_designer(): array
{
    return array(
        'agent' => 'designer_banners_categorias',
        'mode' => 'brief_generator',
        'description' => 'Gera pautas/briefings para banners, capas de categorias e imagens comerciais.',
        'outputs' => array('banner_home', 'capa_categoria', 'hero_produto', 'thumb_categoria'),
        'suggestions' => array(
            'Criar padrao 1920x620 para banner principal.',
            'Criar padrao 1200x420 para capa de categoria.',
            'Criar padrao 1080x1080 para chamada social/catalogo.',
            'Manter sem texto quando a imagem for usada como foto de produto.',
        ),
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
        'rules' => array(
            'Nunca aplicar sugestao automaticamente no produto.',
            'Registrar antes/depois para revisao.',
            'Priorizar clareza, marca, modelo, medida, material e uso.',
        ),
    );
}

function sva_agent_product_images(): array
{
    return array(
        'agent' => 'auditor_imagens_produtos',
        'mode' => 'audit_only',
        'description' => 'Audita capa, imagens secundarias, imagens ausentes, repetidas, quebradas ou suspeitas.',
        'checks' => array('imagem_capa', 'galeria', 'url_quebrada', 'duplicada', 'baixa_resolucao', 'produto_incorreto'),
        'suggestions' => array(
            'Marcar imagem suspeita para fila de revisao.',
            'Reimportar imagens via Olist quando houver vazio.',
            'Evitar aplicar substituicao sem aprovacao do admin.',
        ),
    );
}

function sva_agent_routines(): array
{
    return array(
        'agent' => 'auditor_rotinas',
        'mode' => 'route_and_file_audit',
        'description' => 'Testa rotinas existentes por arquivos e endpoints conhecidos.',
        'files' => array(
            sva_path('api'),
            sva_path('api/agent'),
            sva_path('api/melhorenvio/diagnostic.php'),
            sva_path('api/agent/olist-zero-id-repair.php'),
            sva_path('api/agent/admin-manual-audit.php'),
        ),
        'probes' => array(
            sva_probe('/api/agent/admin-manual-audit.php'),
            sva_probe('/api/agent/olist-zero-id-repair.php'),
            sva_probe('/api/melhorenvio/diagnostic.php?cep=35500025'),
        ),
    );
}

function sva_agent_integrations(): array
{
    return array(
        'agent' => 'auditor_integracoes_api',
        'mode' => 'diagnostic',
        'description' => 'Audita rotinas de integracoes e APIs: Olist, Melhor Envio, Pagar.me, webhooks e callbacks.',
        'targets' => array('olist', 'melhorenvio', 'pagarme', 'webhooks', 'callbacks', 'tokens', 'logs'),
        'suggestions' => array(
            'Padronizar endpoints de diagnostico por integracao.',
            'Validar token_detected sem expor token.',
            'Registrar status HTTP, payload mascarado e ultimo erro.',
            'Separar callback, webhook e calculo em testes independentes.',
        ),
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
        'suggestions' => array(
            'Criar feed de produtos validavel.',
            'Mapear categorias internas para taxonomia Google.',
            'Garantir imagem principal sem marca d agua e acessivel.',
            'Incluir dados de frete e disponibilidade consistentes.',
        ),
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
);

$out = array(
    'ok' => true,
    'suite' => 'autonomous_business_agents_v1',
    'generated_at' => date('c'),
    'agent' => $agent,
    'safe_mode' => true,
    'data' => $agent === 'all' ? $all : ($all[$agent] ?? array('error' => 'unknown_agent', 'available' => array_keys($all))),
    'recommended_next_agents' => array('finance_margin_audit', 'security_permissions_audit', 'lgpd_privacy_audit', 'performance_core_web_vitals', 'inventory_pricing_audit'),
);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
