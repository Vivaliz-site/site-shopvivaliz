<?php

declare(strict_types=1);

require_once __DIR__ . '/../../admin/catalog-optimization/src/CatalogDraftEditor.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogChannelProfile.php';
require_once __DIR__ . '/../../admin/ai-image-studio/src/ImageChannelProfile.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$payload = catalog_draft_payload([
    'optimized_title' => 'Produto Teste',
    'optimized_description' => 'Descricao factual do produto.',
    'bullet_points' => "Ponto A\nPonto B",
    'seo_keywords' => "termo um\ntermo dois, termo tres",
    'marketing_hooks' => "Hook A\nHook B | Hook C",
    'meta_title' => 'Meta teste',
    'meta_description' => 'Meta descricao teste',
], [
    'quality_score' => 100,
    'quality_checks' => ['old_check' => true],
    'policy_version' => 'kept-version',
]);

$meta = json_decode($payload['meta_data_json'], true);
test_assert(is_array($meta), 'meta_data_json deve permanecer JSON valido');
test_assert(($meta['quality_score'] ?? null) === 100, 'edicao manual deve preservar quality_score ate a reauditoria');
test_assert(($meta['quality_checks']['old_check'] ?? null) === true, 'edicao manual deve preservar quality_checks ate a reauditoria');
test_assert(($meta['policy_version'] ?? null) === 'kept-version', 'edicao manual deve preservar policy_version');
test_assert(($meta['manual_edit_pending_quality_recheck'] ?? null) === true, 'edicao manual deve marcar reauditoria pendente');
test_assert($payload['seo_keywords'] === 'termo um, termo dois, termo tres', 'keywords multiline devem ser normalizadas');
test_assert($payload['marketing_hooks'] === 'Hook A | Hook B | Hook C', 'hooks multiline devem ser normalizados');

$amazon = sv_catalog_channel_profile('amazon');
test_assert(($amazon['field_map']['seo_keywords']['target'] ?? '') === 'Listings Items -> generic_keyword', 'Amazon search terms devem mapear para generic_keyword');
test_assert(($amazon['field_map']['meta_title']['mode'] ?? '') === 'internal', 'Amazon meta_title nao deve fingir publicacao externa');

$ml = sv_catalog_channel_profile('ml');
test_assert(($ml['field_map']['bullet_points']['mode'] ?? '') === 'embedded', 'ML bullets devem estar marcados como incorporados na descricao');
test_assert(($ml['field_map']['seo_keywords']['mode'] ?? '') === 'internal', 'ML keywords nao devem fingir campo externo');

$shopee = sv_catalog_channel_profile('shopee');
test_assert(($shopee['field_map']['marketing_hooks']['mode'] ?? '') === 'embedded', 'Shopee hooks devem ser incorporados na descricao, nao publicados como campo proprio');

$tiktok = sv_catalog_channel_profile('tiktok');
test_assert((int)($tiktok['limits']['title_max'] ?? 0) === 300, 'TikTok deve manter limite de titulo especifico do canal');
test_assert((int)($tiktok['limits']['title_recommended_min'] ?? 0) === 40, 'TikTok deve exibir alvo minimo editorial');
test_assert((int)($tiktok['limits']['title_recommended_max'] ?? 0) === 150, 'TikTok deve exibir alvo maximo editorial');

$site = sv_catalog_channel_profile('site');
test_assert((int)($site['limits']['title_recommended_min'] ?? 0) === 45, 'Site deve exibir alvo minimo editorial');
test_assert((int)($site['limits']['title_recommended_max'] ?? 0) === 65, 'Site deve exibir alvo maximo editorial');

$tiktokImage = ai_studio_channel_profile('tiktok');
test_assert((int)($tiktokImage['recommended_side'] ?? 0) === 1600, 'TikTok image profile deve exibir alvo recomendado de 1600px');
test_assert(!empty($tiktokImage['white_first']), 'TikTok deve exigir white/capa primeiro no fluxo atual');

$amazonImage = ai_studio_channel_profile('amazon');
$mlImage = ai_studio_channel_profile('ml');
test_assert((int)($amazonImage['recommended_side'] ?? 0) === 1600, 'Amazon deve possuir alvo visual proprio');
test_assert((int)($mlImage['max_gallery'] ?? 0) !== (int)($amazonImage['max_gallery'] ?? 0), 'perfis visuais de marketplaces diferentes nao devem ser um unico preset generico');

$policySource = (string)file_get_contents(__DIR__ . '/../../admin/catalog-optimization/api/optimize_catalog.php');
test_assert(str_contains($policySource, "'title_max' => 300"), 'politica textual deve conter limite TikTok de 300 caracteres');
test_assert(str_contains($policySource, 'procure ultrapassar 300 caracteres'), 'politica TikTok deve orientar descricao 300+ quando factual');
test_assert(str_contains($policySource, 'Comece com a identidade principal do produto'), 'politica textual deve exigir identidade no inicio do titulo');
test_assert(str_contains($policySource, "'title_max' => 60"), 'Mercado Livre deve manter politica de titulo propria');
test_assert(str_contains($policySource, "'title_max' => 120"), 'Shopee/ERP devem manter politica de titulo diferente do Mercado Livre');
test_assert(str_contains($policySource, "'title_max' => 200"), 'Amazon deve manter politica de titulo propria');

$imagePublisherSource = (string)file_get_contents(__DIR__ . '/../../admin/ai-image-studio/src/OmnichannelImagePublisher.php');
test_assert(str_contains($imagePublisherSource, 'count($channels) !== 1'), 'publisher de imagens deve recusar aprovacao para mais de um marketplace por vez');
test_assert(str_contains($imagePublisherSource, 'exatamente um canal de destino'), 'publisher deve declarar explicitamente a invariavel de canal unico');

$imageValidationSource = (string)file_get_contents(__DIR__ . '/../../admin/ai-image-studio/admin_validate.php');
test_assert(str_contains($imageValidationSource, 'Imagem legada sem marketplace de geracao'), 'imagem sem politica de marketplace nao pode ser publicada sem regeneracao');
test_assert(str_contains($imageValidationSource, 'Nenhum outro marketplace sera chamado'), 'Admin deve deixar explicito que a aprovacao chama somente o canal persistido');
test_assert(str_contains($imageValidationSource, '[$intended]'), 'publicacao de imagem deve encaminhar apenas o marketplace persistido no staging');

$imageGenerationSource = (string)file_get_contents(__DIR__ . '/../../admin/ai-image-studio/process_item.php');
test_assert(str_contains($imageGenerationSource, 'json_encode([$targetChannel]'), 'geracao de imagem deve persistir exatamente um marketplace alvo');
test_assert(str_contains($imageGenerationSource, 'ai_studio_channel_guidance($targetChannel'), 'prompt visual deve receber regras especificas do marketplace alvo');

$catalogAdminSource = (string)file_get_contents(__DIR__ . '/../../admin/catalog-optimization/admin_catalog.php');
test_assert(str_contains($catalogAdminSource, 'confirm_channel'), 'aprovacao textual deve exigir confirmacao explicita do canal');
test_assert(str_contains($catalogAdminSource, '!== $channel'), 'aprovacao textual deve comparar a confirmacao com o canal do staging');
test_assert(str_contains($catalogAdminSource, 'somente em'), 'Admin textual deve comunicar publicacao em canal unico');
test_assert(str_contains($catalogAdminSource, 'bulk_action'), 'Admin textual deve suportar acao em lote');
test_assert(str_contains($catalogAdminSource, 'Canal alvo:'), 'Admin textual deve mostrar o canal alvo no cartão do item');
test_assert(str_contains($catalogAdminSource, 'pending_candidates'), 'Admin textual deve carregar candidatos por canal');
test_assert(str_contains($catalogAdminSource, 'Gerar selecionados'), 'Admin textual deve gerar apenas os itens marcados');
test_assert(str_contains($catalogAdminSource, 'Categoria:'), 'Admin textual deve mostrar categoria nos candidatos');
test_assert(str_contains($catalogAdminSource, 'Prioridade:'), 'Admin textual deve mostrar prioridade na fila');
test_assert(str_contains($catalogAdminSource, 'com foto'), 'Admin textual deve mostrar status de foto na fila');
test_assert(str_contains($catalogAdminSource, 'com descricao'), 'Admin textual deve mostrar status de descricao na fila');
test_assert(str_contains($catalogAdminSource, 'candidate-summary'), 'Admin textual deve mostrar resumo vivo da fila');

fwrite(STDOUT, "marketplace_admin_policy_test: OK\n");
