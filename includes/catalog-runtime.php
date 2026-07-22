<?php
declare(strict_types=1);

/**
 * Generate URL-friendly slug from product name/SKU
 */
function svcr_slug(string $name, string $sku = ''): string
{
    $text = $name !== '' ? $name : $sku;
    if ($text === '') {
        return '';
    }

    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim($text));
    $text = trim($text, '-');

    return $text !== '' ? $text : '';
}

/**
 * Canonical read-only catalog source for storefront, checkout and health APIs.
 * Prefer the live Olist/Tiny detail cache when populated; use the curated
 * fallback only as a complement for fields the live row does not carry.
 */
function svcr_products(): array
{
    $root = dirname(__DIR__);
    $fallbackRows = svcr_extract_rows($root . '/api/catalog/fallback-products.json');
    $liveRows = svcr_extract_rows($root . '/storage/products-cache-ativos.json');

    $rows = svcr_merge_rows($fallbackRows, $liveRows);
    if ($rows === []) {
        $rows = $liveRows !== [] ? $liveRows : $fallbackRows;
    }

    return svcr_normalize_product_rows($rows);
}

function svcr_extract_rows(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $payload = json_decode((string)file_get_contents($file), true);
    if (!is_array($payload)) {
        return [];
    }

    if (array_is_list($payload)) {
        return array_values(array_filter($payload, 'is_array'));
    }

    foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            continue;
        }

        $rows = $payload[$key];
        if (isset($rows['itens']) && is_array($rows['itens'])) {
            $rows = $rows['itens'];
        } elseif (isset($rows['items']) && is_array($rows['items'])) {
            $rows = $rows['items'];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    $numericRows = [];
    foreach ($payload as $key => $row) {
        if (is_numeric($key) && is_array($row)) {
            $numericRows[] = $row;
        }
    }
    if ($numericRows !== []) {
        return $numericRows;
    }

    $keys = array_keys($payload);
    $numericObject = $keys !== [] && array_reduce($keys, static function (bool $ok, $key): bool {
        return $ok && is_numeric($key);
    }, true);
    if ($numericObject) {
        return array_values(array_filter($payload, 'is_array'));
    }

    return [];
}

function svcr_row_key(array $item): string
{
    $sku = strtoupper(trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? '')));
    if ($sku !== '') {
        return 'sku:' . $sku;
    }

    $id = trim((string)($item['id'] ?? $item['olist_product_id'] ?? ''));
    return $id !== '' ? 'id:' . $id : '';
}

function svcr_raw_price(array $item): float
{
    $prices = is_array($item['precos'] ?? null) ? $item['precos'] : [];
    foreach ([
        $prices['preco'] ?? null,
        $prices['preco_venda'] ?? null,
        $prices['price'] ?? null,
        $item['preco'] ?? null,
        $item['preco_venda'] ?? null,
        $item['price'] ?? null,
        $item['valor'] ?? null,
    ] as $value) {
        if (is_numeric($value) && (float)$value > 0) {
            return (float)$value;
        }
    }

    return 0.0;
}

function svcr_merge_rows(array $fallbackRows, array $liveRows): array
{
    $merged = [];
    foreach ($fallbackRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = svcr_row_key($row);
        if ($key !== '') {
            $merged[$key] = $row;
        }
    }

    foreach ($liveRows as $live) {
        if (!is_array($live)) {
            continue;
        }
        $key = svcr_row_key($live);
        if ($key === '') {
            continue;
        }

        $base = $merged[$key] ?? [];
        $combined = array_replace_recursive($base, $live);
        $livePrice = svcr_raw_price($live);
        $fallbackPrice = svcr_raw_price($base);
        if ($livePrice <= 0 && $fallbackPrice > 0) {
            $combined['preco'] = $fallbackPrice;
            $combined['preco_venda'] = $fallbackPrice;
            $combined['price'] = $fallbackPrice;
            $combined['precos'] = is_array($combined['precos'] ?? null) ? $combined['precos'] : [];
            $combined['precos']['preco'] = $fallbackPrice;
        }
        $merged[$key] = $combined;
    }

    return array_values($merged);
}

function svcr_normalize_product_rows(array $items): array
{
    $products = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $situation = strtoupper(trim((string)($item['situacao'] ?? $item['status'] ?? 'A')));
        if (!in_array($situation, ['A', 'ATIVO', 'ACTIVE'], true)) {
            continue;
        }

        $imagesList = svcr_collect_image_urls($item);

        $image = trim((string)($item['imagem_principal_url'] ?? $item['image_url'] ?? $item['imagem'] ?? ''));
        if ($image === '') {
            $image = $imagesList[0] ?? '';
        }

        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : [];
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : [];
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        if ($sku === '') {
            continue;
        }

        $name = trim((string)($item['descricao'] ?? $item['nome'] ?? $item['name'] ?? $sku));
        $price = svcr_raw_price($item);
        $stock = max(0, (int)($item['estoque_disponivel'] ?? $stockInfo['quantidade'] ?? $item['stock'] ?? 0));

        if ($price < 1 || $image === '') {
            continue;
        }

        $products[] = [
            'id' => (string)($item['id'] ?? $sku),
            'sku' => $sku,
            'olist_product_id' => (string)($item['id'] ?? $item['olist_product_id'] ?? ''),
            'name' => $name,
            'slug' => trim((string)($item['slug'] ?? '')) ?: svcr_slug($name, $sku),
            'description' => trim((string)($item['descricaoComplementar'] ?? $item['descricao_complementar'] ?? $item['description'] ?? $item['descricao'] ?? '')),
            'price' => $price,
            'stock' => $stock,
            'image_url' => $image,
            'images' => $imagesList,
            'images_count' => count($imagesList),
            'category' => trim((string)($category['nome'] ?? $category['caminhoCompleto'] ?? $item['category'] ?? '')),
            'weight' => (float)($dimensions['pesoLiquido'] ?? $dimensions['peso_liquido'] ?? $item['peso'] ?? $item['weight'] ?? 0),
            'width' => (float)($dimensions['largura'] ?? $item['width'] ?? 0),
            'height' => (float)($dimensions['altura'] ?? $item['height'] ?? 0),
            'length' => (float)($dimensions['comprimento'] ?? $item['length'] ?? 0),
            'status' => 'active',
        ];
    }

    return $products;
}

function svcr_collect_image_urls(array $item): array
{
    $images = [];
    $fields = [
        'images',
        'imagens',
        'gallery',
        'galeria',
        'fotos',
        'photos',
        'attachments',
        'anexos',
    ];

    $push = static function (string $candidate) use (&$images): void {
        $candidate = trim($candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate) && !in_array($candidate, $images, true)) {
            $images[] = $candidate;
        }
    };

    foreach ($fields as $field) {
        $value = $item[$field] ?? null;
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
                $candidate = trim((string)($entry[$key] ?? ''));
                if ($candidate !== '') {
                    $push($candidate);
                }
            }
        }
    }

    for ($i = 1; $i <= 12; $i++) {
        foreach (["imagem{$i}", "image{$i}", "foto{$i}", "photo{$i}"] as $key) {
            $candidate = trim((string)($item[$key] ?? ''));
            if ($candidate !== '') {
                $push($candidate);
            }
        }
    }

    return array_slice($images, 0, 12);
}
