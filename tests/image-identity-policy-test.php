<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/ai-image-studio/process_item.php';
require_once __DIR__ . '/../admin/ai-image-studio/src/OmnichannelImagePublisher.php';

function iip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function iip_expect_failure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    fwrite(STDERR, "FAIL: expected failure - {$message}\n");
    exit(1);
}

function iip_png_chunk(string $type, string $data): string
{
    $body = $type . $data;
    return pack('N', strlen($data)) . $body . pack('N', crc32($body));
}

function iip_write_png(string $path, int $width, int $height): void
{
    $signature = "\x89PNG\r\n\x1a\n";
    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $scanline = "\x00" . str_repeat("\x00", $width * 3);
    $raw = str_repeat($scanline, $height);
    $png = $signature
        . iip_png_chunk('IHDR', $ihdr)
        . iip_png_chunk('IDAT', gzcompress($raw, 1))
        . iip_png_chunk('IEND', '');
    file_put_contents($path, $png);
}

$tmp = sys_get_temp_dir() . '/shopvivaliz-image-policy-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);

try {
    // Saida 1024x1024 continua tecnicamente valida. O novo perfil separa
    // minimo tecnico de alvo recomendado de cada marketplace.
    $good = $tmp . '/good.png';
    iip_write_png($good, 1024, 1024);
    $meta = ai_studio_validate_image_file($good, 1000);
    iip_assert($meta['width'] === 1024 && $meta['height'] === 1024, '1024x1024 image metadata');
    iip_assert($meta['mime'] === 'image/png', 'PNG MIME must be detected from file content');
    iip_assert(strlen($meta['sha256']) === 64, 'image fingerprint must be SHA-256');

    $providerMeta = AiStudioHttpClient::validateOutputImage($good, 1000);
    iip_assert($providerMeta['mime'] === 'image/png', 'provider output validator must inspect real MIME');
    iip_assert(strlen($providerMeta['sha256']) === 64, 'provider output validator must fingerprint output');

    // Foto-base abaixo de 600px e saida abaixo de 1000px devem ser bloqueadas.
    $weakBase = $tmp . '/weak-base.png';
    iip_write_png($weakBase, 599, 599);
    iip_expect_failure(fn() => ai_studio_validate_image_file($weakBase, 600), 'source image below 600px');

    $weakOutput = $tmp . '/weak-output.png';
    iip_write_png($weakOutput, 999, 999);
    iip_expect_failure(fn() => AiStudioHttpClient::validateOutputImage($weakOutput, 1000), 'output image below 1000px');

    $fake = $tmp . '/fake.jpg';
    file_put_contents($fake, '<html>not an image</html>');
    iip_expect_failure(fn() => ai_studio_validate_image_file($fake, 600), 'fake image with jpg extension');
    iip_expect_failure(fn() => AiStudioHttpClient::validateOutputImage($fake, 1000), 'fake regenerated image with jpg extension');

    iip_expect_failure(
        fn() => ai_studio_resolve_base_image('../etc/passwd', $tmp, 123),
        'path traversal in base image'
    );

    // Prompts devem manter identidade real e composicao quadrada sem inventar
    // especificacoes. Regras visuais passam a depender do destino escolhido.
    $sitePrompts = ai_studio_default_prompts('Produto Teste X1', 'site');
    foreach (['white', 'hero', 'ambient'] as $type) {
        iip_assert(isset($sitePrompts[$type]), "prompt {$type} must exist");
        iip_assert(str_contains($sitePrompts[$type], 'Preserve the exact product identity'), "prompt {$type} must preserve identity");
        iip_assert(str_contains($sitePrompts[$type], 'Do not invent'), "prompt {$type} must reject invented features");
        iip_assert(str_contains($sitePrompts[$type], 'Square 1:1 composition'), "prompt {$type} must request square composition");
        iip_assert(str_contains($sitePrompts[$type], 'Marketplace-specific guidance'), "prompt {$type} must carry marketplace guidance");
        iip_assert(str_contains($sitePrompts[$type], 'Technical target:'), "prompt {$type} must state technical target");
        iip_assert(str_contains($sitePrompts[$type], 'geometry, perspective, scale or color balance'), "prompt {$type} must forbid visual distortion");
    }
    iip_assert(str_contains($sitePrompts['white'], 'RGB 255,255,255'), 'main image must request pure white background');
    iip_assert(str_contains($sitePrompts['white'], '85-95%'), 'main image must request strong product fill');
    iip_assert(str_contains($sitePrompts['white'], 'no badges'), 'main image must reject promotional overlays');

    $amazonPrompts = ai_studio_default_prompts('Produto Teste X1', 'amazon');
    iip_assert(str_contains($amazonPrompts['white'], 'Amazon-compliant main product image'), 'Amazon white prompt must include Amazon guidance');
    iip_assert(str_contains($amazonPrompts['white'], 'first marketplace image'), 'Amazon white prompt must be treated as first image');
    $contextPrompts = ai_studio_default_prompts('Produto Teste X1', 'tiktok', [
        'category' => 'Acessorios',
        'brand' => 'Acme',
        'model' => 'X1',
        'color' => 'Preto',
        'material' => 'Aco',
        'sku' => 'SKU-123',
        'olist_id' => '1001',
    ]);
    iip_assert(str_contains($contextPrompts['white'], 'Factual context from the catalog'), 'image prompt must carry factual catalog context');
    iip_assert(str_contains($contextPrompts['white'], 'category=Acessorios'), 'image prompt must expose category context');
    iip_assert(str_contains($contextPrompts['white'], 'brand=Acme'), 'image prompt must expose brand context');
    iip_assert(str_contains($contextPrompts['white'], 'color=Preto'), 'image prompt must expose color context');
    iip_assert(str_contains($contextPrompts['white'], 'material=Aco'), 'image prompt must expose material context');
    iip_assert(str_contains($contextPrompts['white'], 'Scene hint:'), 'image prompt must include category scene hint');
    iip_assert(str_contains($contextPrompts['white'], 'Appearance cue:'), 'image prompt must include appearance cue');
    iip_assert(ai_studio_normalize_provider('gpt') === 'openai', 'gpt alias must normalize to openai');
    iip_assert(ai_studio_normalize_provider('gemini') === 'google', 'gemini alias must normalize to google');
    iip_assert(ai_studio_provider_fallback_order('claude') === ['openai', 'google'], 'claude image fallback must choose an image engine');
    iip_assert(str_contains(file_get_contents(__DIR__ . '/../admin/ai-image-studio/src/AiServices.php'), 'ai_studio_resolve_image_engine'), 'image engine resolver must exist');

    $tiktokProfile = ai_studio_channel_profile('tiktok');
    iip_assert((int)($tiktokProfile['minimum_side'] ?? 0) === 1000, 'TikTok technical minimum must remain 1000px');
    iip_assert((int)($tiktokProfile['recommended_side'] ?? 0) === 1600, 'TikTok recommended target must be visible as 1600px');
    iip_assert(!empty($tiktokProfile['white_first']), 'TikTok white cover must be reviewed first');

    // Testa funcoes puras/privadas de identidade e ordenacao sem banco.
    $reflection = new ReflectionClass(AiStudioOmnichannelImagePublisher::class);
    $publisher = $reflection->newInstanceWithoutConstructor();

    $matchMethod = $reflection->getMethod('cacheItemMatchesProduct');
    $matchMethod->setAccessible(true);
    $exact = ['sku' => 'SKU-1', 'olist_id' => '1001'];
    iip_assert($matchMethod->invoke($publisher, $exact, 'SKU-1', '1001') === true, 'exact SKU + Olist ID must match');
    $wrongSku = ['sku' => 'SKU-OUTRO', 'olist_id' => '1001'];
    iip_assert($matchMethod->invoke($publisher, $wrongSku, 'SKU-1', '1001') === false, 'matching Olist ID with conflicting SKU must not match');
    $wrongId = ['sku' => 'SKU-1', 'olist_id' => '9999'];
    iip_assert($matchMethod->invoke($publisher, $wrongId, 'SKU-1', '1001') === false, 'matching SKU with conflicting Olist ID must not match');
    $onlySku = ['sku' => 'SKU-1'];
    iip_assert($matchMethod->invoke($publisher, $onlySku, 'SKU-1', '1001') === false, 'two source identifiers require two cache confirmations');
    iip_assert($matchMethod->invoke($publisher, $onlySku, 'SKU-1', '') === true, 'SKU-only source may match exact SKU');
    $onlyId = ['olist_product_id' => '1001'];
    iip_assert($matchMethod->invoke($publisher, $onlyId, '', '1001') === true, 'ID-only source may match exact external ID');

    $orderMethod = $reflection->getMethod('orderImageCandidates');
    $orderMethod->setAccessible(true);
    $ordered = $orderMethod->invoke($publisher, [
        ['type' => 'hero', 'url' => '/hero-new.png', 'order' => 0],
        ['type' => 'white', 'url' => '/white-approved.png', 'order' => 1],
        ['type' => 'ambient', 'url' => '/ambient-approved.png', 'order' => 2],
        ['type' => 'hero', 'url' => '/hero-old.png', 'order' => 3],
    ]);
    iip_assert($ordered === ['/white-approved.png', '/hero-new.png', '/ambient-approved.png'], 'white must always be first and only latest type kept');

    // A sincronizacao Olist antiga usava OR e um bind incompleto. Ambos devem sumir.
    $syncSource = file_get_contents(__DIR__ . '/../olist/sync-images-to-site.php');
    iip_assert(is_string($syncSource), 'sync source must be readable');
    iip_assert(!str_contains($syncSource, 'WHERE olist_id = ? OR sku = ?'), 'unsafe OR identity query must not exist');
    iip_assert(!str_contains($syncSource, 'bind_param("issi"'), 'old incomplete image bind must not exist');
    iip_assert(str_contains($syncSource, 'correspondência ambígua'), 'sync must explicitly block ambiguity');

    // Publicacao multicanal nao pode reaproveitar silenciosamente a galeria do
    // site e nao pode tocar no preco do ERP para publicar imagem gerada.
    $publisherSource = file_get_contents(__DIR__ . '/../admin/ai-image-studio/src/OmnichannelImagePublisher.php');
    iip_assert(is_string($publisherSource), 'publisher source must be readable');
    iip_assert(str_contains($publisherSource, 'approvedChannelUrls'), 'external channels must use same-channel approval provenance');
    iip_assert(str_contains($publisherSource, 'white deve ser aprovada primeiro'), 'secondary image cannot become cover without approved white');
    iip_assert(str_contains($publisherSource, 'amazonUrlsWithExisting'), 'Amazon gallery must preserve pre-existing locators');
    iip_assert(str_contains($publisherSource, 'API V2 exige reenviar preço'), 'ERP image publish must fail closed instead of resending price');
    iip_assert(!str_contains($publisherSource, "new SvTinyPublisher(\$this->db))->publishImages"), 'AI Image Studio must not call Tiny image mutation path');

    $dashboardSource = file_get_contents(__DIR__ . '/../admin/ai-image-studio/admin_dashboard.php');
    iip_assert(is_string($dashboardSource), 'dashboard source must be readable');
    iip_assert(str_contains($dashboardSource, 'selected_products[]'), 'image dashboard must allow product selection');
    iip_assert(str_contains($dashboardSource, 'ais-select-all'), 'image dashboard must support select-all');
    iip_assert(str_contains($dashboardSource, 'ais-selected-count'), 'image dashboard must show selected count');
    iip_assert(str_contains($dashboardSource, 'data-product-check'), 'image dashboard must track each selected product');
    iip_assert(str_contains($dashboardSource, 'p.sku'), 'image dashboard must load SKU in preview data');
    iip_assert(str_contains($dashboardSource, 'p.category'), 'image dashboard must load category in preview data');

    fwrite(STDOUT, "OK image identity and quality policy\n");
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
    @rmdir($tmp);
}
