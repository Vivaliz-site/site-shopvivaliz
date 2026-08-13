<?php

declare(strict_types=1);

function admin_mobile_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$guard = (string)file_get_contents($root . '/includes/admin-guard.php');
$home = (string)file_get_contents($root . '/admin/index.php');
$catalog = (string)file_get_contents($root . '/admin/assets/catalog-effective-results.js');
$catalogWorkflow = (string)file_get_contents($root . '/admin/assets/catalog-optimization-workflow.js');
$image = (string)file_get_contents($root . '/admin/assets/image-generation-usability-v2.js');
$mobile = (string)file_get_contents($root . '/admin/assets/admin-mobile-operations.js');

admin_mobile_assert(str_contains($guard, 'admin-mobile-operations.js'), 'O painel principal deve carregar a camada mobile administrativa.');
admin_mobile_assert(str_contains($guard, 'catalog-effective-results.js'), 'A tela de otimizacao deve carregar a comparacao efetiva.');
admin_mobile_assert(str_contains($guard, 'image-generation-usability-v2.js'), 'O AI Image Studio deve carregar a camada de selecao e retomada.');

admin_mobile_assert(str_contains($home, '/admin/ai-image-studio/admin_dashboard.php'), 'O painel deve manter o atalho do AI Image Studio.');
admin_mobile_assert(str_contains($home, '/admin/catalog-optimization/admin_catalog.php'), 'O painel deve manter o atalho da otimizacao de cadastro.');
admin_mobile_assert(str_contains($mobile, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'Os atalhos devem usar duas colunas no celular.');
admin_mobile_assert(str_contains($mobile, 'sv-admin-mobile-dock'), 'O painel deve oferecer dock de acesso rapido no celular.');
admin_mobile_assert(str_contains($mobile, 'safe-area-inset-bottom'), 'A navegacao fixa deve respeitar a area segura do aparelho.');
admin_mobile_assert(str_contains($mobile, 'sv-admin-card-accordion'), 'Os blocos administrativos devem ser recolhiveis.');

admin_mobile_assert(str_contains($catalogWorkflow, 'sv-review-card'), 'Os resultados do catalogo devem continuar recolhiveis.');
admin_mobile_assert(str_contains($catalogWorkflow, 'input[name="selected_ids[]"]'), 'Os resultados do catalogo devem preservar selecao em lote.');
admin_mobile_assert(str_contains($catalog, '.ci-field.changed'), 'A interface deve identificar campos alterados na revisao.');
admin_mobile_assert(str_contains($catalog, 'dataset.ciOriginal'), 'A interface deve comparar o valor atual com o original preservado.');
admin_mobile_assert(str_contains($catalog, 'O que realmente será alterado'), 'O resultado deve priorizar o impacto real.');
admin_mobile_assert(str_contains($catalog, 'Detalhes avançados'), 'Dados auxiliares devem ficar recolhidos.');
admin_mobile_assert(str_contains($catalog, "dataset.effectiveFilter = 'selected'"), 'A revisao deve permitir filtrar itens selecionados.');
admin_mobile_assert(str_contains($catalog, "dataset.effectiveFilter = 'fail'"), 'A revisao deve permitir filtrar falhas.');
admin_mobile_assert(str_contains($catalog, 'ci-only-changed'), 'Campos inalterados devem ficar ocultos por padrao.');
admin_mobile_assert(str_contains($catalog, 'sv-bulk-bar'), 'A barra de acoes em lote deve permanecer adaptada ao celular.');

admin_mobile_assert(str_contains($image, 'sv-img-preflight'), 'A geracao de imagens deve confirmar o lote antes do envio.');
admin_mobile_assert(str_contains($image, 'localStorage'), 'A selecao de imagens deve poder ser retomada.');
admin_mobile_assert(str_contains($image, 'safe-area-inset-bottom'), 'A barra de geracao deve respeitar a area segura no celular.');
admin_mobile_assert(str_contains($image, 'selectedRows()'), 'A rotina deve gerar somente produtos e variantes selecionados.');

fwrite(STDOUT, "COMPROVADO: painel mobile, selecao explicita, resultados efetivos e detalhes recolhiveis estao ligados aos assets corretos.\n");
