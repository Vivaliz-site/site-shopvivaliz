<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/structured-data-trust.php';

function svsdt_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$product = [
    '@type' => 'Product',
    'name' => 'Produto Fercar',
    'sku' => 'SKU-123',
    'mpn' => 'SKU-123',
    'brand' => ['@type' => 'Brand', 'name' => 'Vivaliz'],
    'gtin' => '1234567890123',
    'url' => 'https://shopvivaliz.com.br/produto/teste',
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'BRL',
        'price' => '29.90',
        'availability' => 'https://schema.org/InStock',
    ],
];

$untrusted = svsdt_normalize_node($product, []);
svsdt_assert(is_array($untrusted), 'Product deve continuar sendo array');
svsdt_assert(($untrusted['sku'] ?? '') === 'SKU-123', 'SKU deve ser preservado');
svsdt_assert(($untrusted['offers']['price'] ?? '') === '29.90', 'preco deve ser preservado');
svsdt_assert(($untrusted['offers']['availability'] ?? '') === 'https://schema.org/InStock', 'disponibilidade deve ser preservada');
svsdt_assert(($untrusted['url'] ?? '') === 'https://shopvivaliz.com.br/produto/teste', 'URL deve ser preservada');
svsdt_assert(!isset($untrusted['mpn']), 'MPN copiado do SKU deve ser removido');
svsdt_assert(!isset($untrusted['brand']), 'marca sem fonte confiavel deve ser removida');
svsdt_assert(!isset($untrusted['gtin']), 'GTIN sem fonte confiavel deve ser removido');

$trustedMap = [
    'SKU-123' => [
        'brand' => 'Fercar',
        'mpn' => 'FC-ABC-123',
        'gtin' => '4006381333931',
    ],
];
$trusted = svsdt_normalize_node($product, $trustedMap);
svsdt_assert(($trusted['brand']['name'] ?? '') === 'Fercar', 'brand deve vir do mapa do Merchant');
svsdt_assert(($trusted['mpn'] ?? '') === 'FC-ABC-123', 'MPN real deve ser publicado');
svsdt_assert(($trusted['gtin'] ?? '') === '4006381333931', 'GTIN valido deve ser publicado');

$badMap = [
    'SKU-123' => [
        'brand' => 'Fercar',
        'mpn' => 'SKU-123',
        'gtin' => '4006381333932',
    ],
];
$bad = svsdt_normalize_node($product, $badMap);
svsdt_assert(!isset($bad['mpn']), 'mpn igual ao sku deve falhar fechado');
svsdt_assert(!isset($bad['gtin']), 'GTIN com checksum invalido deve falhar fechado');
svsdt_assert(svgf_normalize_gtin('4006381333932') === '', 'checksum deve reutilizar helper do Merchant');

echo "structured-data-trust: ok\n";
