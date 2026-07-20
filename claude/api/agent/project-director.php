<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

function svpd_root(): string { return dirname(__DIR__, 2); }
function svpd_file(string $path): array
{
    $full = svpd_root() . '/' . ltrim($path, '/');
    return array(
        'path' => $path,
        'exists' => file_exists($full),
        'type' => is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'missing'),
        'mtime' => file_exists($full) ? date('c', (int)filemtime($full)) : null,
        'size' => is_file($full) ? filesize($full) : null,
    );
}

function svpd_probe(string $path): array
{
    $url = 'https://shopvivaliz.com.br/' . ltrim($path, '/');
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 8, 'ignore_errors' => true)));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m)) {
        $status = (int)$m[1];
    }
    return array('path' => $path, 'status' => $status, 'ok' => $status >= 200 && $status < 400, 'bytes' => is_string($body) ? strlen($body) : 0);
}

function svpd_agent(string $key, string $role, array $deliverables, array $files, array $endpoints, array $priorities): array
{
    $fileStatus = array();
    foreach ($files as $file) $fileStatus[] = svpd_file($file);
    $endpointStatus = array();
    foreach ($endpoints as $endpoint) $endpointStatus[] = svpd_probe($endpoint);
    return array(
        'key' => $key,
        'role' => $role,
        'work_mode' => 'tempo_integral_logico_por_ciclo',
        'reports_to' => 'diretor_de_projetos',
        'deliverables_each_version' => $deliverables,
        'files' => $fileStatus,
        'endpoints' => $endpointStatus,
        'priorities' => $priorities,
        'admin_approval_required_for_changes' => true,
    );
}

$agents = array(
    svpd_agent('designer', 'Melhorar visual desktop/smartphone, substituir mockups, criar fila de banners e capas.', array('fila_criativa', 'mockups_detectados', 'banners_ausentes', 'capas_categoria_ausentes', 'pendencias_desktop_smartphone'), array('api/agent/designer-visual-audit.php', 'config/shopvivaliz-device-variants.php'), array('api/agent/designer-visual-audit.php'), array('banners_home', 'capas_categorias', 'visual_mobile', 'visual_desktop')),
    svpd_agent('olist_images', 'Comparar imagens atuais da Olist com banco e planilha diaria.', array('produtos_sem_imagem', 'imagens_sem_vinculo', 'divergencias_planilha', 'fila_reconciliacao'), array('api/agent/olist-image-sheet-compare.php', 'api/agent/olist-zero-id-repair.php'), array('api/agent/olist-image-sheet-compare.php'), array('vinculo_por_sku', 'primary_image_url', 'images_count', 'planilha_do_dia')),
    svpd_agent('checkout_customer', 'Testar jornada cliente, CEP, frete, botao comprar, Pix e boleto em modo seguro.', array('rotas_checkout', 'frete_cep', 'metodos_pagamento_visiveis', 'bloqueios_compra'), array('api/melhorenvio/diagnostic.php', 'api/agent/autonomous-suite-v1.php'), array('api/melhorenvio/diagnostic.php?cep=35500025', 'api/agent/autonomous-suite-v1.php?agent=checkout'), array('botao_comprar', 'cep', 'frete_real', 'pix_boleto')),
    svpd_agent('integrations', 'Auditar APIs e integracoes Olist, Melhor Envio, Pagar.me, webhooks e callbacks.', array('status_tokens_mascarado', 'webhooks', 'callbacks', 'erros_api', 'proximas_correcoes'), array('api/agent/autonomous-suite-v1.php', 'api/melhorenvio/diagnostic.php'), array('api/agent/autonomous-suite-v1.php?agent=integrations'), array('olist', 'melhorenvio', 'pagarme', 'webhooks')),
    svpd_agent('products_seo', 'Auditar produtos e gerar sugestoes aprovaveis de titulo, descricao, SEO, slug e tags.', array('sugestoes_produtos', 'campos_faltantes', 'seo_fraco', 'slugs_problematicos'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=products'), array('titulo', 'descricao', 'seo', 'slug', 'tags')),
    svpd_agent('image_auditor', 'Auditar imagens de cadastro de produtos.', array('imagens_quebradas', 'duplicadas', 'baixa_resolucao', 'capa_ausente', 'imagem_suspeita'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=images'), array('capa', 'galeria', 'duplicidade', 'qualidade')),
    svpd_agent('marketing_ads', 'Criar rascunhos de campanhas Ads para aprovacao.', array('campanhas_rascunho', 'grupos_anuncio', 'copies', 'landing_pages', 'criativos_necessarios'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=marketing'), array('google_ads_draft', 'criativos', 'categorias_prioritarias')),
    svpd_agent('google_shopping', 'Auditar conformidade Google Shopping.', array('feed_status', 'sitemap_status', 'categoria_google', 'imagem_preco_estoque', 'pendencias_compliance'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=shopping'), array('feed', 'sitemap', 'categoria_google', 'frete')),
    svpd_agent('security', 'Auditar seguranca, arquivos sensiveis e permissoes.', array('riscos', 'arquivos_sensiveis', 'endpoints_apply', 'logs_publicos', 'segredos_mascarados'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=security'), array('secrets', 'logs', 'apply_protection', 'permissoes')),
    svpd_agent('admin_ops', 'Manter manual, painel admin, rotinas e fila de aprovacao.', array('manual_admin', 'painel_agentes', 'fila_aprovacao', 'rotinas_admin'), array('api/agent/admin-manual-audit.php', 'api/agent/agent-version-compiler.php'), array('api/agent/agent-version-compiler.php'), array('manual', 'painel', 'aprovacoes', 'operacao')),
    svpd_agent('performance', 'Auditar velocidade desktop/smartphone e Core Web Vitals.', array('assets_pesados', 'lazy_loading', 'cache', 'mobile_first', 'checkout_sem_bloqueio'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=performance'), array('mobile', 'desktop', 'imagens', 'cache')),
    svpd_agent('finance_inventory', 'Auditar margem, preco e estoque.', array('margem_risco', 'produto_sem_custo', 'estoque_divergente', 'produto_vendavel_com_falha'), array('api/agent/autonomous-suite-v1.php'), array('api/agent/autonomous-suite-v1.php?agent=finance', 'api/agent/autonomous-suite-v1.php?agent=inventory'), array('margem', 'preco', 'estoque', 'produto_ativo')),
);

$highPriority = array(
    array('priority' => 1, 'owner' => 'designer', 'task' => 'Substituir mockups/provisorios e criar banners/capas em desktop e smartphone.'),
    array('priority' => 2, 'owner' => 'olist_images', 'task' => 'Comparar planilha diaria da Olist com imagens do banco e corrigir vinculos apos aprovacao.'),
    array('priority' => 3, 'owner' => 'checkout_customer', 'task' => 'Garantir botao comprar, campo CEP, frete e Pix/boleto visiveis.'),
    array('priority' => 4, 'owner' => 'integrations', 'task' => 'Auditar tokens/callbacks/webhooks sem expor segredos.'),
    array('priority' => 5, 'owner' => 'admin_ops', 'task' => 'Criar painel do Diretor de Projetos com fila de aprovacao.'),
);

$out = array(
    'ok' => true,
    'agent' => 'project_director',
    'version' => '1.0.0',
    'generated_at' => date('c'),
    'mode' => 'diretor_de_projetos_recebe_relatorios_dos_agentes',
    'execution_limit_note' => 'A execucao automatica real fica limitada ao ciclo agendado; em cada ciclo todos os agentes compilam seu estado.',
    'agents_total' => count($agents),
    'agents' => $agents,
    'director_decision_queue' => $highPriority,
    'version_report_contract' => array(
        'cada_agente_entrega_status',
        'diretor_consolida_prioridades',
        'alteracoes_so_em_modo_cumulativo',
        'mudancas_sensiveis_exigem_aprovacao_admin',
        'resumo_final_por_versao_para_o_usuario'
    ),
    'next_build' => array(
        'painel_admin_diretor_de_projetos',
        'armazenar_relatorio_por_versao',
        'fila_aprovar_rejeitar_por_agente',
        'teste_pos_deploy_do_project_director'
    )
);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
