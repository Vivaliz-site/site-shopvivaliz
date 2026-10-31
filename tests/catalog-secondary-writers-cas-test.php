<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-cache-write-guard.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . '/sv-secondary-writers-' . bin2hex(random_bytes(5));
mkdir($root, 0770, true);
$path = $root . '/products-cache-ativos.json';
$initial = [
    'price_list_id' => 991,
    'price_source' => 'olist_tiny_amazon',
    'total' => 1,
    'timestamp' => '2026-10-29T21:00:00Z',
    'itens' => [[
        'sku' => 'TEST-AMAZON-991',
        '_price_list_id' => 991,
        '_price_source' => 'olist_tiny_amazon',
        'precos' => ['preco' => 45.01, 'precoPromocional' => 0],
        'estoque_disponivel' => 142,
        'image_url' => '/images/old.webp',
        'descricao' => 'Name from ERP',
    ]],
];
$json = static fn(array $x): string => json_encode($x, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
try {
    $original = $json($initial);
    file_put_contents($path, $original);

    $image = $initial;
    $image['itens'][0]['image_url'] = '/images/new.webp';
    svcc_write_presentation_if_current($path, $original, $json($image));
    $assert(is_file($root . '/olist-erp-webhook.lock'), 'secondary writer must use canonical cross-language lock');
    $assert((fileperms($path) & 0777) === 0660, 'new cache must be group writable by Olist PHP and Ubuntu daemon');

    $after = json_decode((string)file_get_contents($path), true);
    $assert(($after['itens'][0]['precos']['preco'] ?? 0) === 45.01, 'presentation update changed ERP price');

    $stale = $original;
    $metadata = $image;
    $metadata['itens'][0]['descricao'] = 'Repaired name';
    try {
        svcc_write_presentation_if_current($path, $stale, $json($metadata));
        throw new RuntimeException('stale writer unexpectedly succeeded');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'catalog_changed_during_presentation_update_retry_later',
            'stale writer must fail via CAS');
    }
    $assert(file_get_contents($path) === $json($image), 'stale writer altered price or cache after CAS conflict');

    $forged = $image;
    $forged['itens'][0]['precos']['preco'] = 1.00;
    try {
        svcc_write_presentation_if_current($path, $json($image), $json($forged));
        throw new RuntimeException('forged commercial price unexpectedly accepted');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'catalog_commercial_or_identity_change_forbidden', 'price mutation guard incorrect');
    }

    $inserted = $image;
    $inserted['itens'][] = $image['itens'][0];
    try {
        svcc_write_presentation_if_current($path, $json($image), $json($inserted));
        throw new RuntimeException('new product unexpectedly accepted');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'catalog_commercial_or_identity_change_forbidden',
            'secondary writer cannot append products');
    }

    $wrongList = $image;
    $wrongList['price_list_id'] = 0;
    try {
        svcc_write_presentation_if_current($path, $json($image), $json($wrongList));
        throw new RuntimeException('unverified price list unexpectedly accepted');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'catalog_not_authoritative_amazon_991', 'must preserve Amazon table provenance');
    }

    $before = (string)file_get_contents($path);
    $assert($before === $json($image), 'failed updates must preserve last valid catalog');

    $metadataSrc = (string)file_get_contents(dirname(__DIR__) . '/agents/v9.2.84/app/CatalogMetadataAgent.php');
    $imageSrc = (string)file_get_contents(dirname(__DIR__) . '/admin/ai-image-studio/src/OmnichannelImagePublisher.php');
    $hookSrc = (string)file_get_contents(dirname(__DIR__) . '/includes/admin-product-cache-hook.php');
    foreach ([$metadataSrc, $imageSrc] as $source) {
        $assert(str_contains($source, 'svcc_write_presentation_if_current'), 'all presentation writers must use CAS');
    }
    $assert(!str_contains($imageSrc, 'appendStorefrontImageItem(')
        && str_contains($imageSrc, 'catalog_product_not_synced_from_erp_amazon_991'),
        'image studio must not synthesize a new product or price');
    $assert(!str_contains($hookSrc, '@unlink($cacheFile)')
        && str_contains($hookSrc, 'authoritative ERP Amazon catalog preserved'),
        'admin update must never delete authoritative catalog');

    foreach (['fix-broken-descriptions.php', 'fix-encoding-descriptions.php', 'fix-product-images.php'] as $name) {
        $script = (string)file_get_contents(dirname(__DIR__) . '/scripts/' . $name);
        $assert(str_contains($script, 'legacy_catalog_repair_retired')
            && !str_contains($script, 'file_put_contents('),
            "unsafe direct writer still reachable: {$name}");
    }
    echo "catalog_secondary_writers_cas=GREEN\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $file) @unlink($file);
    @rmdir($root);
}
