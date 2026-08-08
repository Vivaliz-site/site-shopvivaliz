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
    // Arquivo de imagem real e com resolução adequada deve ser aceito.
    $good = $tmp . '/good.png';
    iip_write_png($good, 512, 512);
    $meta = ai_studio_validate_image_file($good, 512);
    iip_assert($meta['width'] === 512 && $meta['height'] === 512, '512x512 image metadata');
    iip_assert($meta['mime'] === 'image/png', 'PNG MIME must be detected from file content');
    iip_assert(strlen($meta['sha256']) === 64, 'image fingerprint must be SHA-256');

    // Imagem pequena e arquivo falso devem ser bloqueados.
    $small = $tmp . '/small.png';
    iip_write_png($small, 128, 128);
    iip_expect_failure(fn() => ai_studio_validate_image_file($small, 300), 'small source image');

    $fake = $tmp . '/fake.jpg';
    file_put_contents($fake, '<html>not an image</html>');
    iip_expect_failure(fn() => ai_studio_validate_image_file($fake, 300), 'fake image with jpg extension');

    // Traversal local nunca pode ser usado como foto-base.
    iip_expect_failure(
        fn() => ai_studio_resolve_base_image('../etc/passwd', $tmp, 123),
        'path traversal in base image'
    );

    // Prompts devem manter fidelidade do produto e evitar objetos inventados.
    $prompts = ai_studio_default_prompts('Produto Teste X1');
    foreach (['white', 'hero', 'ambient'] as $type) {
        iip_assert(isset($prompts[$type]), "prompt {$type} must exist");
        iip_assert(str_contains($prompts[$type], 'Preserve the exact product identity'), "prompt {$type} must preserve identity");
        iip_assert(str_contains($prompts[$type], 'Do not invent'), "prompt {$type} must reject invented features");
    }

    // Testa o matcher privado sem abrir conexão de banco.
    $reflection = new ReflectionClass(AiStudioOmnichannelImagePublisher::class);
    $publisher = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('cacheItemMatchesProduct');
    $method->setAccessible(true);

    $exact = ['sku' => 'SKU-1', 'olist_id' => '1001'];
    iip_assert($method->invoke($publisher, $exact, 'SKU-1', '1001') === true, 'exact SKU + Olist ID must match');

    $wrongSku = ['sku' => 'SKU-OUTRO', 'olist_id' => '1001'];
    iip_assert($method->invoke($publisher, $wrongSku, 'SKU-1', '1001') === false, 'matching Olist ID with conflicting SKU must not match');

    $wrongId = ['sku' => 'SKU-1', 'olist_id' => '9999'];
    iip_assert($method->invoke($publisher, $wrongId, 'SKU-1', '1001') === false, 'matching SKU with conflicting Olist ID must not match');

    $onlySku = ['sku' => 'SKU-1'];
    iip_assert($method->invoke($publisher, $onlySku, 'SKU-1', '1001') === false, 'two source identifiers require two cache confirmations');
    iip_assert($method->invoke($publisher, $onlySku, 'SKU-1', '') === true, 'SKU-only source may match exact SKU');

    $onlyId = ['olist_product_id' => '1001'];
    iip_assert($method->invoke($publisher, $onlyId, '', '1001') === true, 'ID-only source may match exact external ID');

    // A sincronização Olist antiga usava OR e um bind incompleto. Ambos devem sumir.
    $syncSource = file_get_contents(__DIR__ . '/../olist/sync-images-to-site.php');
    iip_assert(is_string($syncSource), 'sync source must be readable');
    iip_assert(!str_contains($syncSource, 'WHERE olist_id = ? OR sku = ?'), 'unsafe OR identity query must not exist');
    iip_assert(!str_contains($syncSource, 'bind_param("issi"'), 'old incomplete image bind must not exist');
    iip_assert(str_contains($syncSource, 'correspondência ambígua'), 'sync must explicitly block ambiguity');

    fwrite(STDOUT, "OK image identity and quality policy\n");
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}
