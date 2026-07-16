<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

function svac_root(): string { return dirname(__DIR__, 2); }
function svac_exists(string $path): array
{
    $full = svac_root() . '/' . ltrim($path, '/');
    return array(
        'path' => $path,
        'exists' => file_exists($full),
        'type' => is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'missing'),
        'mtime' => file_exists($full) ? date('c', (int)filemtime($full)) : null,
    );
}

function svac_agent(string $key, string $name, array $files, array $focus, string $cadence = 'every_version'): array
{
    $present = array();
    foreach ($files as $file) $present[] = svac_exists($file);
    return array(
        'key' => $key,
        'name' => $name,
        'cadence' => $cadence,
        'mode' => 'full_time_logical_agent',
        'files' => $present,
        'focus' => $focus,
        'compile_each_version' => true,
        'admin_approval_required_for_changes' => true,
    );
}

$agents = array(
    svac_agent('designer', 'Designer visual desktop/smartphone', array('api/agent/designer-visual-audit.php', 'config/shopvivaliz-device-variants.php'), array('banners', 'capas de categoria', 'mockups', 'assets desktop', 'assets smartphone')),
    svac_agent('olist_images', 'Olist imagens e planilha diaria', array('api/agent/olist-image-sheet-compare.php', 'api/agent/olist-zero-id-repair.php'), array('comparar planilha do dia', 'vincular imagens por sku/id', 'detectar imagens faltantes')),
    svac_agent('checkout', 'Cliente checkout/frete/pagamento', array('api/melhorenvio/diagnostic.php'), array('cep', 'frete', 'botao comprar', 'pix', 'boleto')),
    svac_agent('integrations', 'Integracoes e APIs', array('api/melhorenvio/diagnostic.php', 'api/agent/autonomous-suite-v1.php'), array('Olist', 'Melhor Envio', 'Pagar.me', 'webhooks', 'tokens mascarados')),
    svac_agent('products', 'Cadastro produtos e SEO', array('api/agent/autonomous-suite-v1.php'), array('titulo', 'descricao', 'seo', 'slug', 'tags', 'palavras chave')),
    svac_agent('security', 'Seguranca e permissoes', array('api/agent/autonomous-suite-v1.php'), array('arquivos sensiveis', 'logs publicos', 'apply protegido', 'segredos mascarados')),
    svac_agent('marketing', 'Marketing e Ads draft', array('api/agent/autonomous-suite-v1.php'), array('campanhas rascunho', 'Google Ads', 'criativos', 'landing pages')),
    svac_agent('shopping', 'Google Shopping compliance', array('api/agent/autonomous-suite-v1.php'), array('feed', 'sitemap', 'categoria Google', 'produto', 'frete')),
    svac_agent('admin', 'Admin e manual operacional', array('api/agent/admin-manual-audit.php'), array('manual admin', 'painel de agentes', 'fila aprovacao', 'rotinas')),
);

$out = array(
    'ok' => true,
    'agent' => 'agent_version_compiler',
    'version' => '1.0.0',
    'generated_at' => date('c'),
    'purpose' => 'Compilar em cada versao o estado dos agentes que trabalham de forma continua por responsabilidade fixa.',
    'safe_mode' => true,
    'agents_total' => count($agents),
    'agents' => $agents,
    'required_report_each_version' => array(
        'versao_commit',
        'arquivos_alterados',
        'designer_desktop_smartphone',
        'olist_imagens_planilha_do_dia',
        'checkout_frete_pagamento',
        'integracoes_api',
        'produtos_seo',
        'seguranca',
        'admin_manual',
        'pendencias_priorizadas'
    ),
    'next_recommended_build' => array(
        'painel_admin_dos_agentes',
        'historico_execucoes_por_versao',
        'fila_de_aprovacao_de_sugestoes',
        'relatorio_visual_desktop_smartphone'
    )
);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
