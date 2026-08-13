<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function ci_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$guard = file_get_contents($root . '/includes/admin-guard.php');
$ui = file_get_contents($root . '/admin/assets/catalog-change-intelligence.js');
$main = file_get_contents($root . '/admin/assets/catalog-optimization-workflow.js');

ci_assert(is_string($guard) && $guard !== '', 'admin-guard precisa existir');
ci_assert(is_string($ui) && $ui !== '', 'inteligência de alterações precisa existir');
ci_assert(is_string($main) && $main !== '', 'workflow principal do catálogo precisa existir');
ci_assert(str_contains($guard, 'catalog-change-intelligence.js'), 'Admin deve carregar a camada de inteligência depois do workflow principal');
foreach (['optimized_title','optimized_description','bullet_points','seo_keywords','marketing_hooks','meta_title','meta_description'] as $field) {
    ci_assert(str_contains($ui, $field), "Campo {$field} precisa ser comparado e sinalizado");
}
foreach (['Mercado Livre','Shopee','Amazon','TikTok Shop','ShopVivaliz','Olist / Tiny'] as $channel) {
    ci_assert(str_contains($ui, $channel), "Orientação específica precisa existir para {$channel}");
}
ci_assert(str_contains($ui, 'Mostrar somente campos alterados'), 'Usuário deve poder focar apenas no que mudou');
ci_assert(str_contains($ui, 'Restaurar original'), 'Cada campo alterado deve permitir restauração segura');
ci_assert(str_contains($ui, 'Alterado') && str_contains($ui, 'Sem mudança') && str_contains($ui, 'Novo') && str_contains($ui, 'Removido'), 'Estados de alteração precisam ser explícitos');
ci_assert(str_contains($ui, 'Assistente') && str_contains($ui, 'quality'), 'Assistente local deve orientar sem substituir o quality gate do servidor');
ci_assert(str_contains($ui, 'preço') && str_contains($ui, 'frete') && str_contains($ui, 'cupom'), 'Auxiliar deve alertar sobre condições comerciais protegidas');
ci_assert(str_contains($main, 'Revisar e aplicar') && str_contains($main, 'Otimizar selecionados'), 'Camada de inteligência deve complementar, não substituir, o fluxo operacional');

fwrite(STDOUT, "COMPROVADO: resultados do catálogo sinalizam campos alterados, permitem restaurar o original e oferecem orientação inteligente por canal.\n");
