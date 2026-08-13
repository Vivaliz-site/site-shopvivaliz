<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function admin_ux_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

function admin_ux_read(string $root, string $relative): string
{
    $path = $root . '/' . ltrim($relative, '/');
    admin_ux_assert(is_file($path), "Arquivo ausente: {$relative}");
    $content = file_get_contents($path);
    admin_ux_assert(is_string($content) && $content !== '', "Arquivo vazio: {$relative}");
    return $content;
}

$guard = admin_ux_read($root, 'includes/admin-guard.php');
$catalogUi = admin_ux_read($root, 'admin/assets/catalog-effective-results.js');
$imageUi = admin_ux_read($root, 'admin/assets/image-generation-usability-v2.js');
$overviewUi = admin_ux_read($root, 'admin/assets/admin-ai-overview.js');
$overviewApi = admin_ux_read($root, 'admin/api/ai-routine-summary.php');
$responsiveCss = admin_ux_read($root, 'css/admin-zoom-responsive.css');
$login = admin_ux_read($root, 'auth/login.php');
$googleStart = admin_ux_read($root, 'auth/google-start.php');

foreach ([
    'catalog-effective-results.js',
    'image-generation-usability-v2.js',
    'admin-ai-overview.js',
] as $asset) {
    admin_ux_assert(str_contains($guard, $asset), "O carregador do Admin precisa incluir {$asset}.");
}

admin_ux_assert(str_contains($catalogUi, '/admin/catalog-optimization/api/effective_changes.php'), 'Resultados devem consultar o impacto efetivo do rascunho.');
admin_ux_assert(str_contains($catalogUi, 'changes.effective'), 'O resumo principal deve usar somente mudancas efetivas.');
admin_ux_assert(str_contains($catalogUi, 'sv-candidate-collapsible'), 'A lista de candidatos deve ser recolhivel.');
admin_ux_assert(str_contains($catalogUi, 'input[name="selected_ids[]"]'), 'Resultados devem preservar a selecao em lote existente.');
admin_ux_assert(str_contains($catalogUi, 'data-effective-filter="selected"'), 'Resultados devem oferecer filtro de selecionados.');
admin_ux_assert(str_contains($catalogUi, 'sv-effective-mobile-bar'), 'Resultados devem oferecer acoes moveis persistentes.');
admin_ux_assert(str_contains($catalogUi, 'Sem alterações efetivas'), 'Rascunhos sem impacto devem ser sinalizados e bloqueados.');

admin_ux_assert(str_contains($imageUi, 'sv-img-preflight'), 'Geracao de imagens deve confirmar o lote antes de enfileirar.');
admin_ux_assert(str_contains($imageUi, 'svImageDraftV3'), 'Selecao de imagens deve sobreviver a recarga da pagina.');
admin_ux_assert(str_contains($imageUi, 'sv-retry-missing'), 'Geracao de imagens deve permitir repetir somente o que falhou.');
admin_ux_assert(str_contains($imageUi, '@media(max-width:720px)'), 'Geracao de imagens deve ter layout dedicado ao celular.');

admin_ux_assert(str_contains($overviewUi, '/admin/api/ai-routine-summary.php'), 'A central deve carregar um resumo operacional das rotinas.');
admin_ux_assert(str_contains($overviewUi, 'AI Image Studio'), 'A central deve destacar a rotina de imagens.');
admin_ux_assert(str_contains($overviewUi, 'Otimização de Cadastro'), 'A central deve destacar a rotina de cadastro.');
admin_ux_assert(str_contains($overviewApi, 'product_images_staging'), 'O resumo de imagens deve usar a fila real.');
admin_ux_assert(str_contains($overviewApi, 'catalog_optimizations_staging'), 'O resumo de cadastro deve usar os rascunhos reais.');

admin_ux_assert(str_contains($responsiveCss, 'min-height: 44px'), 'Controles moveis precisam de alvo de toque adequado.');
admin_ux_assert(str_contains($responsiveCss, 'env(safe-area-inset-bottom)'), 'O layout deve respeitar a area segura do aparelho.');
admin_ux_assert(str_contains($responsiveCss, 'grid-template-columns: repeat(2, minmax(0, 1fr))'), 'Atalhos do Admin devem ficar compactos no celular.');

admin_ux_assert(str_contains($login, '/auth/google-start.php?'), 'A tela de login deve exibir um inicio dedicado para o Google.');
admin_ux_assert(str_contains($login, 'Entrar com Google'), 'O botao do Google deve permanecer visivel.');
admin_ux_assert(str_contains($googleStart, "sv_social_google_auth_url('login'"), 'O inicio do Google deve usar o fluxo OAuth canonico.');
admin_ux_assert(str_contains($googleStart, 'session_write_close'), 'O estado OAuth deve ser persistido antes do redirecionamento.');

fwrite(STDOUT, "COMPROVADO: central, rotinas de IA, resultados recolhiveis, selecao e acesso Google possuem contratos de interface e carregamento.\n");
