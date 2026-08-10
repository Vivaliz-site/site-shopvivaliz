<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guardPath = $root . '/includes/admin-guard.php';
$scriptPath = $root . '/admin/assets/product-collapsible-ui.js';

$guard = file_get_contents($guardPath);
$script = file_get_contents($scriptPath);

if (!is_string($guard) || !is_string($script)) {
    fwrite(STDERR, "Falha ao ler os arquivos da interface colapsavel.\n");
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
    'Limpar seleção',
    'input[data-product-check]',
    'input[data-candidate-id]',
    'input[name="selected_ids[]"]',
    'data-sv-result-check',
    'data-sv-validation-check',
    'MutationObserver',
];
foreach ($uiRequirements as $needle) {
    if (!str_contains($script, $needle)) {
        fwrite(STDERR, "Comportamento colapsavel ausente: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: listas e resultados de IA possuem cobertura colapsavel com checkbox.\n");
