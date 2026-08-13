<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/catalog-optimization/api/optimize_catalog.php';
require_once dirname(__DIR__) . '/admin/catalog-optimization/src/CatalogGeneratedDataNormalizer.php';

function catalog_normalizer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

function catalog_normalizer_hard_failures(array $quality): array
{
    $soft = array_flip(ai_catalog_soft_quality_checks());
    return array_values(array_filter(
        array_keys(array_filter((array)$quality['checks'], static fn(bool $ok): bool => !$ok)),
        static fn(string $check): bool => !isset($soft[$check])
    ));
}

final class CatalogNormalizerFakeProvider extends CatalogAiRotatingProvider
{
    /** @param array<string,mixed> $fixture */
    public function __construct(private array $fixture)
    {
        parent::__construct(['fake-key'], 'fake-model', 1);
    }

    protected function providerName(): string { return 'Fake'; }
    protected function missingKeyMessage(): string { return 'Fake key missing.'; }

    /** @return array<string,mixed> */
    protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array
    {
        return $this->fixture;
    }
}

$product = [
    'name' => 'Suporte Veicular Vivaliz VX10 para Celular',
    'description' => 'Suporte veicular para celular com fixacao no painel.',
    'category' => 'Acessorio automotivo',
    'brand' => 'Vivaliz',
    'model' => 'VX10',
    'sku' => 'VX10-PTO',
    'gtin' => '',
    'color' => 'Preto',
    'size' => '',
    'material' => '',
    'specs' => 'Fixacao no painel',
    'olist_id' => '',
];

$raw = [
    'optimized_title' => 'Imperdivel Suporte para Celular Vivaliz VX10 100% Original!',
    'optimized_description' => 'Produto 100% original. Suporte veicular para celular com fixacao no painel. Preco especial com desconto.',
    'bullet_points' => [
        '100% original',
        'Fixacao no painel',
        'Cor preta',
        'Modelo VX10',
        str_repeat('A', 280),
        'Item excedente que deve ser removido',
    ],
    'seo_keywords' => ['suporte veicular', 'produto original', 'VX10'],
    'marketing_hooks' => ['Garanta ja o original', 'Fixacao no painel', 'Modelo VX10'],
    'meta_title' => str_repeat('Meta muito longa ', 8) . 'Original',
    'meta_description' => str_repeat('Descricao factual para teste. ', 12) . '100% original.',
];

$normalized = catalog_generated_normalize($raw, 'tiktok', $product);
$quality = ai_catalog_quality_report($normalized, 'tiktok', $product);
$hard = catalog_normalizer_hard_failures($quality);

catalog_normalizer_assert(ai_catalog_title_starts_with_identity((string)$normalized['optimized_title'], $product), 'titulo deve iniciar pela identidade factual');
catalog_normalizer_assert(!ai_catalog_title_has_hype_prefix((string)$normalized['optimized_title']), 'prefixo promocional deve ser removido');
catalog_normalizer_assert(mb_strlen((string)$normalized['optimized_title'], 'UTF-8') <= 300, 'titulo deve respeitar teto TikTok');
catalog_normalizer_assert(mb_strlen((string)$normalized['meta_title'], 'UTF-8') <= 70, 'meta title deve ser truncado');
catalog_normalizer_assert(mb_strlen((string)$normalized['meta_description'], 'UTF-8') <= 160, 'meta description deve ser truncada');
catalog_normalizer_assert(count((array)$normalized['bullet_points']) <= 5, 'bullets devem respeitar maximo do canal');
catalog_normalizer_assert(count((array)$normalized['marketing_hooks']) <= 2, 'hooks TikTok devem respeitar maximo');
catalog_normalizer_assert(stripos(ai_catalog_text_blob($normalized), 'original') === false, 'claim de originalidade nao comprovado deve desaparecer');
catalog_normalizer_assert(preg_match(catalog_generated_protected_commerce_pattern(), ai_catalog_text_blob($normalized)) !== 1, 'preco/estoque/desconto devem permanecer ausentes');
catalog_normalizer_assert($hard === [], 'normalizacao objetiva deve eliminar hard failures deterministicas: ' . implode(', ', $hard));

// Prova que toda saida dos providers rotativos passa pela mesma normalizacao.
// Isso cobre API direta, bulk/cron, smoke, reparo e "Regenerar e reauditar".
ai_catalog_build_user_prompt($product, 'tiktok');
$providerNormalized = (new CatalogNormalizerFakeProvider($raw))->complete('system', 'user');
$providerQuality = ai_catalog_quality_report($providerNormalized, 'tiktok', $product);
$providerHard = catalog_normalizer_hard_failures($providerQuality);
catalog_normalizer_assert($providerHard === [], 'saida do provider deve chegar sem hard failures deterministicas: ' . implode(', ', $providerHard));
catalog_normalizer_assert(ai_catalog_title_starts_with_identity((string)$providerNormalized['optimized_title'], $product), 'provider deve corrigir identidade antes de devolver a saida');
catalog_normalizer_assert(stripos(ai_catalog_text_blob($providerNormalized), 'original') === false, 'provider deve remover claim de originalidade nao comprovado');

// Regressao do incidente real: o nome-fonte legado pode carregar simbolo/emoji
// e mais de uma chamada promocional antes da identidade real, sem marca/modelo.
$legacyProduct = [
    'name' => '🔥 Imperdivel! Transforme Suporte Veicular Vivaliz VX10 para Celular',
    'description' => 'Suporte veicular para celular com fixacao no painel.',
    'category' => 'Acessorio automotivo',
    'brand' => '',
    'model' => '',
    'sku' => 'VX10-LEGACY',
    'gtin' => '',
    'color' => 'Preto',
    'size' => '',
    'material' => '',
    'specs' => 'Fixacao no painel',
    'olist_id' => '',
];
$legacyRaw = [
    'optimized_title' => '🔥 Imperdivel! Transforme Suporte Veicular Vivaliz VX10!',
    'optimized_description' => 'Suporte veicular para celular com fixacao no painel.',
    'bullet_points' => ['Fixacao no painel', 'Cor preta', 'Uso veicular'],
    'seo_keywords' => ['suporte veicular', 'celular'],
    'marketing_hooks' => ['Fixacao no painel'],
    'meta_title' => 'Suporte Veicular Vivaliz',
    'meta_description' => 'Suporte veicular para celular com fixacao no painel.',
];
$legacyNormalized = catalog_generated_normalize($legacyRaw, 'site', $legacyProduct);
$legacyQuality = ai_catalog_quality_report($legacyNormalized, 'site', $legacyProduct);
$legacyHard = catalog_normalizer_hard_failures($legacyQuality);
$legacyIdentity = ai_catalog_identity_candidates($legacyProduct);

catalog_normalizer_assert(!ai_catalog_title_has_hype_prefix((string)$legacyNormalized['optimized_title']), 'nome legado nao pode reintroduzir hype no titulo normalizado');
catalog_normalizer_assert(ai_catalog_title_starts_with_identity((string)$legacyNormalized['optimized_title'], $legacyProduct), 'nome legado limpo deve continuar satisfazendo identidade');
catalog_normalizer_assert(in_array('suporte veicular vivaliz', $legacyIdentity, true), 'fallback de identidade deve ignorar simbolos e preambulo promocional do nome-fonte');
catalog_normalizer_assert($legacyHard === [], 'cadastro legado com hype no nome deve ficar sem hard failures: ' . implode(', ', $legacyHard));

// Caminho real do provider: o produto original nao e reescrito por referencia.
// Mesmo assim o validador precisa reconhecer a identidade factual limpa.
$legacyProviderProduct = $legacyProduct;
ai_catalog_build_user_prompt($legacyProviderProduct, 'site');
$legacyProviderData = (new CatalogNormalizerFakeProvider($legacyRaw))->complete('system', 'user');
$legacyProviderQuality = ai_catalog_quality_report($legacyProviderData, 'site', $legacyProviderProduct);
$legacyProviderHard = catalog_normalizer_hard_failures($legacyProviderQuality);
catalog_normalizer_assert($legacyProviderProduct['name'] === $legacyProduct['name'], 'provider nao deve depender de mutar o nome original para passar no gate');
catalog_normalizer_assert($legacyProviderHard === [], 'provider com produto original deve sair sem hard failure: ' . implode(', ', $legacyProviderHard));

// Se o nome legado for somente chamada promocional e nao houver marca/modelo,
// SKU/GTIN sao identificadores factuais e podem ser usados como ultimo fallback.
$technicalProduct = $legacyProduct;
$technicalProduct['name'] = 'Imperdivel!';
$technicalProduct['sku'] = 'SKU-FACT-123';
$technicalRaw = $legacyRaw;
$technicalRaw['optimized_title'] = 'Imperdivel!';
$technical = catalog_generated_normalize($technicalRaw, 'erp', $technicalProduct);
$technicalQuality = ai_catalog_quality_report($technical, 'erp', $technicalProduct);
$technicalHard = catalog_normalizer_hard_failures($technicalQuality);

catalog_normalizer_assert(str_starts_with(ai_catalog_fold_text((string)$technical['optimized_title']), 'sku fact 123'), 'fallback tecnico deve usar SKU factual quando o nome limpo fica vazio');
catalog_normalizer_assert(!ai_catalog_title_has_hype_prefix((string)$technical['optimized_title']), 'fallback tecnico nao pode carregar hype');
catalog_normalizer_assert($technicalHard === [], 'fallback tecnico deve satisfazer hard gates: ' . implode(', ', $technicalHard));

// A edicao manual continua fail-closed: o normalizador nao e aplicado ao
// validador puro quando nao existe uma nova chamada de provider.
unset($GLOBALS['ai_catalog_validation_context']);
$manualRejected = false;
try {
    ai_catalog_validate_ai_response($raw, 'tiktok', $product);
} catch (CatalogAiApiException) {
    $manualRejected = true;
}
catalog_normalizer_assert($manualRejected, 'conteudo manual invalido deve continuar bloqueado, nao silenciosamente reescrito');

$erpRaw = $raw;
$erpRaw['optimized_title'] = 'Transforme seu cadastro com Vivaliz VX10';
$erpRaw['optimized_description'] = 'Suporte veicular para celular com fixacao no painel.';
$erpRaw['bullet_points'] = array_fill(0, 10, 'Fixacao no painel');
$erpRaw['seo_keywords'] = ['suporte', 'veicular'];
$erpRaw['marketing_hooks'] = ['Compre agora'];
$erpRaw['meta_title'] = 'Vivaliz VX10';
$erpRaw['meta_description'] = 'Suporte veicular VX10';
$erp = catalog_generated_normalize($erpRaw, 'erp', $product);
$erpQuality = ai_catalog_quality_report($erp, 'erp', $product);
$erpHard = catalog_normalizer_hard_failures($erpQuality);

catalog_normalizer_assert((array)$erp['seo_keywords'] === [], 'ERP nao deve carregar SEO keywords');
catalog_normalizer_assert((array)$erp['marketing_hooks'] === [], 'ERP nao deve carregar marketing hooks');
catalog_normalizer_assert(count((array)$erp['bullet_points']) <= 8, 'ERP deve respeitar bullet max');
catalog_normalizer_assert($erpHard === [], 'ERP normalizado nao deve manter hard failure: ' . implode(', ', $erpHard));

// Preserva a expansao do pool Gemini que entrou em paralelo no main. O teste
// usa apenas sentinelas locais e prova agregacao/deduplicacao sem ler segredo.
putenv('GOOGLE_GEMINI_API_KEYS=key-a,key-b');
putenv('GEMINI_API_KEY_1=key-b');
putenv('GOOGLE_API_KEY=key-c');
putenv('GOOGLE_IMAGEN_API_KEYS=["key-d","key-c"]');
$geminiPool = catalog_ai_env_key_pool([
    'GOOGLE_GEMINI_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_IMAGEN_API_KEY',
]);
catalog_normalizer_assert($geminiPool === ['key-a', 'key-b', 'key-c', 'key-d'], 'pool Gemini deve agregar aliases e remover duplicatas');

fwrite(STDOUT, "COMPROVADO: providers auto-normalizam identidade/claims/limites; legado com hype/simbolos, fallback tecnico e pool Gemini multiplo protegidos.\n");
