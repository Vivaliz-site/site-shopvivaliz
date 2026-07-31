<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/catalog-runtime.php';
require_once __DIR__ . '/../includes/product-seo.php';

$reportsDir = __DIR__ . '/../reports';
if (!is_dir($reportsDir) && !mkdir($reportsDir, 0775, true) && !is_dir($reportsDir)) {
    fwrite(STDERR, "FALHOU: nao foi possivel criar reports\n");
    exit(1);
}

$path = $reportsDir . '/product-seo-audit-' . gmdate('Ymd-His') . '.csv';
$fh = fopen($path, 'wb');
if (!$fh) {
    fwrite(STDERR, "FALHOU: nao foi possivel abrir relatorio\n");
    exit(1);
}

fputcsv($fh, [
    'sku',
    'slug',
    'nome_original',
    'titulo_seo',
    'descricao_seo',
    'marca',
    'tipo_produto',
    'preco',
    'estoque',
    'imagem',
    'alertas',
]);

$total = 0;
$withAlerts = 0;
$badTitles = 0;
$products = svcr_products();

foreach ($products as $product) {
    if (!is_array($product)) {
        continue;
    }

    $sku = trim((string)($product['sku'] ?? ''));
    $slug = trim((string)($product['slug'] ?? ''));
    $originalName = trim((string)($product['name'] ?? ''));
    $title = svseo_title($product);
    $description = svseo_description($product);
    $brand = svseo_brand($product);
    $type = svseo_product_type($product, svseo_human_name($product));
    $price = (float)($product['price'] ?? 0);
    $stock = (int)($product['stock'] ?? 0);
    $image = trim((string)($product['image_url'] ?? ''));
    $alerts = [];

    if ($sku === '') {
        $alerts[] = 'sku_ausente';
    }
    if ($slug === '') {
        $alerts[] = 'slug_ausente';
    }
    if ($price <= 0) {
        $alerts[] = 'preco_invalido';
    }
    if ($image === '') {
        $alerts[] = 'imagem_ausente';
    }
    if (preg_match('/PRODUTO_\d+/i', $title) === 1) {
        $alerts[] = 'titulo_com_sku_generico';
        $badTitles++;
    }
    if (strlen($description) < 80) {
        $alerts[] = 'descricao_curta';
    }
    if ($stock <= 0) {
        $alerts[] = 'sem_estoque';
    }

    if ($alerts !== []) {
        $withAlerts++;
    }
    $total++;

    fputcsv($fh, [
        $sku,
        $slug,
        $originalName,
        $title,
        $description,
        $brand,
        $type,
        number_format($price, 2, '.', ''),
        (string)$stock,
        $image,
        implode('|', $alerts),
    ]);
}

fclose($fh);

echo json_encode([
    'status' => 'COMPROVADO',
    'report' => str_replace('\\', '/', realpath($path) ?: $path),
    'total' => $total,
    'with_alerts' => $withAlerts,
    'bad_titles' => $badTitles,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
