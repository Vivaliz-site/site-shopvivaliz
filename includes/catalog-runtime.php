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
 * Prefer the curated fallback when populated; otherwise normalize the live
 * Olist/Tiny detail cache produced by daemon-sync-products.py.
 */
function svcr_products(): array
{
    $root = dirname(__DIR__);
    $fallback = $root . '/api/catalog/fallback-products.json';
    $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
    if (is_array($rows) && $rows !== []) {
        return svcr_normalize_product_rows($rows);
    }

    $cache = $root . '/storage/products-cache-ativos.json';
    $payload = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
    if (!is_array($payload)) {
        return [];
    }

    $items = [];
    foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $candidate = $payload[$key];
            if (isset($candidate['itens']) && is_array($candidate['itens'])) {
                $candidate = $candidate['itens'];
            } elseif (isset($candidate['items']) && is_array($candidate['items'])) {
                $candidate = $candidate['items'];
            }
            $items = $candidate;
            break;
        }
    }

    if ($items === [] && array_is_list($payload)) {
        $items = $payload;
    }

    return svcr_normalize_product_rows($items);
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

        $prices = is_array($item['precos'] ?? null) ? $item['precos'] : [];
        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : [];
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : [];
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        if ($sku === '') {
            continue;
        }

        $name = trim((string)($item['descricao'] ?? $item['nome'] ?? $item['name'] ?? $sku));
        $price = (float)($prices['preco'] ?? $prices['preco_venda'] ?? $prices['price'] ?? $item['preco'] ?? $item['price'] ?? 0);
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
