<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-runtime.php';
require_once dirname(__DIR__) . '/includes/testimonial-purchase-verifier.php';

function sv_conversion_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$index = svcr_content_index($root);
$content = svcr_content_for_item(['sku' => 'KIT4R-SOPRÃO'], $index);
sv_conversion_assert(trim((string)($content['description'] ?? '')) !== '', 'conteudo rico deve ser encontrado por SKU');
sv_conversion_assert(trim((string)($content['category'] ?? '')) === 'Rodízios', 'categoria curada deve ser encontrada por SKU');
sv_conversion_assert(svcr_infer_category('Rodízio giratório 35 mm') === 'Rodízios', 'inferência deve cobrir item sem categoria curada');

$activeCatalogPath = $root . '/storage/products-cache-ativos.json';
$activeCatalogBackup = is_file($activeCatalogPath) ? file_get_contents($activeCatalogPath) : null;
$activeFixture = [
    'itens' => [[
        'id' => '341440872',
        'sku' => 'KIT4R-SOPRÃO',
        'descricao' => 'KIT4R-SOPRÃO',
        'preco' => 99.99,
        'estoque_disponivel' => 2,
        'situacao' => 'A',
    ]],
];
file_put_contents($activeCatalogPath, json_encode($activeFixture, JSON_UNESCAPED_UNICODE), LOCK_EX);
try {
    $products = svcr_products();
    sv_conversion_assert(count($products) === 1, 'fixture canônica deve publicar um produto');
    $product = $products[0];
    sv_conversion_assert((float)$product['price'] === 99.99, 'preço deve vir somente da fonte canônica ativa');
    sv_conversion_assert((int)$product['stock'] === 2, 'estoque deve vir somente da fonte canônica ativa');
    sv_conversion_assert(trim((string)$product['description']) !== '', 'descrição deve ser enriquecida por SKU');
    sv_conversion_assert((string)$product['category'] === 'Rodízios', 'categoria deve ser enriquecida por SKU');
    sv_conversion_assert(trim((string)$product['image_url']) !== '', 'imagem deve ser enriquecida por SKU');
    sv_conversion_assert((string)$product['gtin'] === '7904013466354', 'GTIN deve ser enriquecido por SKU');
    sv_conversion_assert(str_contains((string)$product['slug'], 'kit4r'), 'slug deve carregar identidade estável do SKU');

    ob_start();
    include $root . '/google-merchant-feed.php';
    $feed = (string)ob_get_clean();
    sv_conversion_assert(str_contains($feed, '<item>'), 'feed Merchant deve conter item ativo enriquecido');
    sv_conversion_assert(str_contains($feed, '<g:gtin>7904013466354</g:gtin>'), 'feed Merchant deve publicar GTIN recuperado');
} finally {
    if (is_string($activeCatalogBackup)) {
        file_put_contents($activeCatalogPath, $activeCatalogBackup, LOCK_EX);
    } else {
        @unlink($activeCatalogPath);
    }
}

$orderReference = 'SV' . gmdate('YmdHis') . random_int(1000, 9999);
$orderPath = $root . '/storage/orders/' . $orderReference . '.json';
$testOrder = [
    'order_number' => $orderReference,
    'status' => 'payment_approved',
    'customer' => ['email' => 'cliente-verificado@example.com'],
    'items' => [['sku' => 'SKU-VERIFICADO']],
];
file_put_contents($orderPath, json_encode($testOrder, JSON_UNESCAPED_SLASHES), LOCK_EX);
try {
    sv_conversion_assert(
        svtpv_verify($orderReference, 'cliente-verificado@example.com', 'SKU-VERIFICADO'),
        'pedido pago, e-mail e SKU correspondentes devem verificar a compra'
    );
    sv_conversion_assert(
        !svtpv_verify($orderReference, 'outro@example.com', 'SKU-VERIFICADO'),
        'e-mail divergente não pode verificar a compra'
    );
    sv_conversion_assert(
        !svtpv_verify($orderReference, 'cliente-verificado@example.com', 'OUTRO-SKU'),
        'SKU fora do pedido não pode verificar avaliação de produto'
    );
} finally {
    @unlink($orderPath);
}

$checkout = (string)file_get_contents($root . '/checkout.php');
sv_conversion_assert(str_contains($checkout, "expiresAt <= now"), 'checkout deve invalidar cotação de frete vencida');
sv_conversion_assert(!str_contains($checkout, 'triggerGoogleAdsConversion('), 'criação de pedido não pode disparar conversão de Ads');
sv_conversion_assert(!str_contains($checkout, 'triggerGooglePurchaseEvent('), 'criação de pedido não pode disparar purchase');

$analytics = (string)file_get_contents($root . '/includes/analytics-tracking.php');
sv_conversion_assert(
    str_contains($analytics, 'SHOPVIVALIZ_ENABLE_SERVER_SIDE_NONPURCHASE_EVENTS'),
    'eventos server-side não transacionais devem ser explicitamente opt-in'
);

$home = (string)file_get_contents($root . '/index.php');
sv_conversion_assert(!str_contains($home, "'count' => 42"), 'home não pode inventar contagem de categoria');
sv_conversion_assert(!str_contains($home, '4.9 / 5.0'), 'home não pode inventar nota');

$catalog = (string)file_get_contents($root . '/catalogo.php');
sv_conversion_assert(!str_contains($catalog, 'no PIX (5% OFF)'), 'catálogo não pode inventar desconto no Pix');
sv_conversion_assert(!str_contains($catalog, 'sem juros'), 'catálogo não pode inventar parcelamento sem juros');

$catalogJs = (string)file_get_contents($root . '/js/catalog.js');
sv_conversion_assert(
    str_contains($catalogJs, "if (initialSort !== 'relevance')"),
    'catálogo não deve trocar a primeira renderização por skeletons e nova chamada à API'
);

$legacyLiz = (string)file_get_contents($root . '/api/agent/squad-chat.php');
sv_conversion_assert(!str_contains($legacyLiz, 'VOLTEI5'), 'endpoint legado da Liz não pode prometer cupom fixo');
sv_conversion_assert(!str_contains($legacyLiz, 'liz_learning_base.json'), 'endpoint legado não pode misturar conversas de visitantes');
sv_conversion_assert(!str_contains($legacyLiz, 'Frete Grátis: Em compras acima'), 'Liz não pode inventar limiar de frete grátis');

fwrite(STDOUT, "conversion-recovery-regression: ok\n");
