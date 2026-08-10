<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guardPath = $root . '/includes/admin-guard.php';
$scriptPath = $root . '/admin/assets/product-collapsible-ui.js';
$imageEndpointPath = $root . '/admin/ai-image-studio/api/enqueue_generation.php';
$catalogEndpointPath = $root . '/admin/catalog-optimization/api/optimize_catalog_resilient.php';

$guard = file_get_contents($guardPath);
$script = file_get_contents($scriptPath);
$imageEndpoint = file_get_contents($imageEndpointPath);
$catalogEndpoint = file_get_contents($catalogEndpointPath);

if (!is_string($guard) || !is_string($script) || !is_string($imageEndpoint) || !is_string($catalogEndpoint)) {
    fwrite(STDERR, "Falha ao ler os arquivos da interface/rotinas resilientes.\n");
    exit(1);
}

$requiredPages = [
    '/admin/ai-image-studio/admin_dashboard.php',
    '/admin/ai-image-studio/admin_validate.php',
    '/admin/catalog-optimization/admin_catalog.php',
];
foreach ($requiredPages as $page) {
    if (!str_contains($guard, $page) || !str_contains($script, $page)) {
        fwrite(STDERR, "Pagina sem cobertura colapsavel: {$page}\n");
        exit(1);
    }
}

$guardRequirements = [
    '$svAdminIsAjaxRequest',
    "isset(\$_GET['ajax'])",
    'product-collapsible-ui.js?v=20260810',
];
foreach ($guardRequirements as $needle) {
    if (!str_contains($guard, $needle)) {
        fwrite(STDERR, "Guard sem protecao/carregamento esperado: {$needle}\n");
        exit(1);
    }
}

$uiRequirements = [
    'sv-collapsible-item',
    'sv-collapsible-check',
    'Selecionar tudo',
    'Limpar selecao',
    'Abrir selecionados',
    'input[data-product-check]',
    'input[data-candidate-id]',
    'input[name="selected_ids[]"]',
    'data-sv-result-check',
    'data-sv-validation-check',
    'MutationObserver',
    '/admin/ai-image-studio/api/enqueue_generation.php',
    '/admin/catalog-optimization/api/optimize_catalog_resilient.php',
    'Sua sessao administrativa expirou',
];
foreach ($uiRequirements as $needle) {
    if (!str_contains($script, $needle)) {
        fwrite(STDERR, "Comportamento confiavel ausente: {$needle}\n");
        exit(1);
    }
}

$imageRequirements = [
    'olist_product_images',
    'sv_queue_enqueue',
    'ai_image_studio.process_item',
    'ai_studio_image_provider_candidates',
    'Nenhuma foto real valida',
];
foreach ($imageRequirements as $needle) {
    if (!str_contains($imageEndpoint, $needle)) {
        fwrite(STDERR, "Endpoint de imagem sem protecao esperada: {$needle}\n");
        exit(1);
    }
}

$catalogRequirements = [
    'catalog_resilient_structure_errors',
    'protected',
    'ai_catalog_quality_report',
    'ai_catalog_insert_staging_row',
    'generated_with_quality_warnings',
    'status = \'pending\'',
];
foreach ($catalogRequirements as $needle) {
    if (!str_contains($catalogEndpoint, $needle)) {
        fwrite(STDERR, "Endpoint de catalogo sem comportamento resiliente: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: selecao, listas colapsaveis, fila de imagens e rascunho resiliente de catalogo estao cobertos.\n");
