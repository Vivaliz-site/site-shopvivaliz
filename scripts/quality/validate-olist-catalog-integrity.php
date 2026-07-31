<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/api/catalog/fallback-products.json';

if (!is_file($path)) {
    fwrite(STDERR, "Catalogo nao encontrado: {$path}\n");
    exit(1);
}

try {
    $catalog = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "JSON invalido: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($catalog) || !array_is_list($catalog) || $catalog === []) {
    fwrite(STDERR, "Catalogo invalido ou vazio.\n");
    exit(1);
}

$errors = [];
$warnings = [];
$seenSku = [];
$seenId = [];
$withImages = 0;
$withPrice = 0;
$withStock = 0;

foreach ($catalog as $index => $product) {
    $line = $index + 1;
    if (!is_array($product)) {
        $errors[] = "linha {$line}: produto nao e objeto";
        continue;
    }

    $sku = trim((string)($product['sku'] ?? ''));
    $id = trim((string)($product['olist_product_id'] ?? $product['id'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));

    if ($sku === '') {
        $errors[] = "linha {$line}: SKU ausente";
    } else {
        $key = strtoupper($sku);
        if (isset($seenSku[$key])) {
            $errors[] = "linha {$line}: SKU duplicado {$sku}";
        }
        $seenSku[$key] = true;
    }

    if ($id !== '') {
        if (isset($seenId[$id])) {
            $errors[] = "linha {$line}: ID Olist duplicado {$id}";
        }
        $seenId[$id] = true;
    }

    if ($name === '') {
        $errors[] = "linha {$line}: nome ausente";
    }

    $price = (float)($product['price'] ?? 0);
    $promotional = (float)($product['promotional_price'] ?? 0);
    if ($price < 0 || $promotional < 0) {
        $errors[] = "linha {$line}: preco negativo";
    }
    if ($price > 0) {
        $withPrice++;
    } else {
        $warnings[] = "linha {$line}: produto sem preco positivo ({$sku})";
    }

    $stock = $product['stock'] ?? null;
    if ($stock !== null && !is_numeric($stock)) {
        $errors[] = "linha {$line}: estoque nao numerico";
    } elseif ($stock !== null && (int)$stock < 0) {
        $errors[] = "linha {$line}: estoque negativo";
    } elseif ($stock !== null && (int)$stock > 0) {
        $withStock++;
    }

    $imageUrl = trim((string)($product['image_url'] ?? ''));
    $images = is_array($product['images'] ?? null) ? $product['images'] : [];
    $validImages = [];
    foreach ($images as $image) {
        if (!is_scalar($image)) {
            continue;
        }
        $url = trim((string)$image);
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $validImages[] = $url;
        }
    }

    if ($imageUrl !== '' && !preg_match('~^https?://~i', $imageUrl)) {
        $errors[] = "linha {$line}: image_url invalida ({$sku})";
    }
    if ($imageUrl !== '' && $validImages !== [] && $validImages[0] !== $imageUrl) {
        $errors[] = "linha {$line}: image_url difere da primeira imagem ({$sku})";
    }
    if ((int)($product['images_count'] ?? count($validImages)) !== count($validImages)) {
        $errors[] = "linha {$line}: images_count divergente ({$sku})";
    }
    if ($validImages !== []) {
        $withImages++;
    } else {
        $warnings[] = "linha {$line}: produto sem imagem valida ({$sku})";
    }

    $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
    if ($prices !== []) {
        $base = (float)($prices['price'] ?? 0);
        $promo = (float)($prices['promotional_price'] ?? 0);
        $expected = $promo > 0 && ($base <= 0 || $promo < $base) ? $promo : $base;
        if ($expected > 0 && abs($price - $expected) > 0.001) {
            $errors[] = "linha {$line}: price nao corresponde ao preco efetivo ({$sku})";
        }
    }
}

$total = count($catalog);
$imageRatio = $withImages / $total;
$priceRatio = $withPrice / $total;

if ($imageRatio < 0.50) {
    $errors[] = sprintf('cobertura de imagens abaixo de 50%%: %.1f%%', $imageRatio * 100);
}
if ($priceRatio < 0.50) {
    $errors[] = sprintf('cobertura de precos abaixo de 50%%: %.1f%%', $priceRatio * 100);
}

$result = [
    'ok' => $errors === [],
    'products' => $total,
    'with_images' => $withImages,
    'with_price' => $withPrice,
    'with_stock' => $withStock,
    'image_coverage' => round($imageRatio * 100, 2),
    'price_coverage' => round($priceRatio * 100, 2),
    'errors' => array_slice($errors, 0, 100),
    'warnings_count' => count($warnings),
    'warnings_sample' => array_slice($warnings, 0, 20),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
