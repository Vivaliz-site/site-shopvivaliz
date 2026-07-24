<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/api/catalog/fallback-products.json';

if (!is_file($path)) {
    fwrite(STDERR, "Catalogo nao encontrado: {$path}\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Falha ao ler catalogo.\n");
    exit(1);
}

try {
    $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "JSON invalido: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($catalog) || !array_is_list($catalog)) {
    fwrite(STDERR, "Catalogo invalido: raiz deve ser uma lista JSON.\n");
    exit(1);
}

$stats = [
    'products' => 0,
    'images_repaired' => 0,
    'prices_repaired' => 0,
    'stock_repaired' => 0,
    'invalid_rows' => 0,
];

$collectImages = static function (array $product): array {
    $images = [];
    $push = static function (mixed $value) use (&$images): void {
        if (!is_scalar($value)) {
            return;
        }
        $url = trim((string)$value);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return;
        }
        if (!in_array($url, $images, true)) {
            $images[] = $url;
        }
    };

    foreach (['image_url', 'imagem_principal_url', 'primary_image_url', 'imagem', 'foto'] as $field) {
        $push($product[$field] ?? null);
    }

    foreach (['images', 'imagens', 'gallery', 'galeria', 'fotos', 'photos', 'attachments', 'anexos'] as $field) {
        $value = $product[$field] ?? null;
        if (is_string($value)) {
            $push($value);
            continue;
        }
        if (!is_array($value)) {
            continue;
        }
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $push($entry);
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['url', 'link', 'src', 'image', 'imagem', 'image_url'] as $key) {
                $push($entry[$key] ?? null);
            }
        }
    }

    return array_slice($images, 0, 24);
};

foreach ($catalog as $index => &$product) {
    if (!is_array($product)) {
        $stats['invalid_rows']++;
        continue;
    }

    $stats['products']++;

    $imagesBefore = is_array($product['images'] ?? null) ? $product['images'] : [];
    $imageBefore = trim((string)($product['image_url'] ?? ''));
    $images = $collectImages($product);
    if ($images !== []) {
        $product['images'] = $images;
        $product['image_url'] = $images[0];
        $product['images_count'] = count($images);
    } else {
        $product['images'] = [];
        $product['image_url'] = '';
        $product['images_count'] = 0;
    }
    if ($imagesBefore !== $product['images'] || $imageBefore !== $product['image_url']) {
        $stats['images_repaired']++;
    }

    $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
    $basePrice = (float)($prices['price'] ?? $prices['preco'] ?? $product['price'] ?? $product['preco'] ?? 0);
    $promotional = (float)(
        $prices['promotional_price']
        ?? $prices['precoPromocional']
        ?? $prices['preco_promocional']
        ?? $product['promotional_price']
        ?? $product['preco_promocional']
        ?? 0
    );

    $effectivePrice = $basePrice;
    if ($promotional > 0 && ($basePrice <= 0 || $promotional < $basePrice)) {
        $effectivePrice = $promotional;
    }

    $oldPrice = (float)($product['price'] ?? 0);
    $oldPromo = (float)($product['promotional_price'] ?? 0);
    $product['price'] = max(0, $effectivePrice);
    $product['promotional_price'] = max(0, $promotional);
    $product['prices'] = array_merge($prices, [
        'price' => max(0, $basePrice),
        'promotional_price' => max(0, $promotional),
    ]);
    if ($oldPrice !== $product['price'] || $oldPromo !== $product['promotional_price']) {
        $stats['prices_repaired']++;
    }

    $stockDetail = is_array($product['stock_detail'] ?? null) ? $product['stock_detail'] : [];
    $rawStock = $product['stock']
        ?? $product['estoque_disponivel']
        ?? $stockDetail['quantity']
        ?? $stockDetail['quantidade']
        ?? $stockDetail['stock']
        ?? null;

    $oldStock = $product['stock'] ?? null;
    if ($rawStock !== null && is_numeric($rawStock)) {
        $normalizedStock = max(0, (int)$rawStock);
        $product['stock'] = $normalizedStock;
        $product['stock_detail'] = array_merge($stockDetail, [
            'quantity' => $normalizedStock,
        ]);
        if ($oldStock !== $normalizedStock) {
            $stats['stock_repaired']++;
        }
    }
}
unset($product);

if ($stats['invalid_rows'] > 0) {
    fwrite(STDERR, "Catalogo contem {$stats['invalid_rows']} linhas invalidas.\n");
    exit(1);
}

try {
    $encoded = json_encode(
        $catalog,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    fwrite(STDERR, "Falha ao serializar catalogo: {$e->getMessage()}\n");
    exit(1);
}

$tmp = $path . '.repair.tmp';
if (file_put_contents($tmp, $encoded . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Falha ao gravar arquivo temporario.\n");
    exit(1);
}

try {
    $check = json_decode((string)file_get_contents($tmp), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    @unlink($tmp);
    fwrite(STDERR, "Validacao final do JSON falhou: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($check) || count($check) !== count($catalog)) {
    @unlink($tmp);
    fwrite(STDERR, "Validacao final falhou: quantidade de produtos divergente.\n");
    exit(1);
}

if (!rename($tmp, $path)) {
    @unlink($tmp);
    fwrite(STDERR, "Falha ao substituir catalogo.\n");
    exit(1);
}

echo json_encode(['ok' => true] + $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
