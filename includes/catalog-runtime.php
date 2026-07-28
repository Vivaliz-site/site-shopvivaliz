<?php
declare(strict_types=1);

function svcr_slug(string $name, string $sku = ''): string
{
    $text = $name !== '' ? $name : $sku;
    if ($text === '') {
        return '';
    }

    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim((string)$text));
    return trim((string)$text, '-');
}

function svcr_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Normaliza valores monetários vindos da API, cache ou banco.
 * Retorna null para qualquer valor vazio, inválido, zero ou negativo.
 */
function shopvivaliz_price_value(mixed $price): ?float
{
    if ($price === null || $price === '' || is_bool($price) || is_array($price) || is_object($price)) {
        return null;
    }

    if (is_string($price)) {
        $normalized = trim($price);
        $normalized = str_ireplace('R$', '', $normalized);
        $normalized = preg_replace('/\s+/', '', $normalized);

        if ($normalized === '' || !preg_match('/\d/', $normalized)) {
            return null;
        }

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        $price = (float)$normalized;
    }

    if (!is_numeric($price)) {
        return null;
    }

    $value = (float)$price;
    return is_finite($value) && $value > 0 ? $value : null;
}

function shopvivaliz_format_price(mixed $price): ?string
{
    $value = shopvivaliz_price_value($price);
    return $value === null ? null : 'R$ ' . number_format($value, 2, ',', '.');
}

function shopvivaliz_discount_value(mixed $discount): int
{
    if ($discount === null || $discount === '' || !is_numeric($discount)) {
        return 0;
    }

    $value = (float)$discount;
    return is_finite($value) && $value > 0 ? (int)floor($value) : 0;
}

/**
 * A loja não comercializa itens marcados como encomenda antecipada.
 */
function svcr_is_preorder(array $item): bool
{
    $stockInfo = is_array($item['estoque'] ?? null)
        ? $item['estoque']
        : (is_array($item['stock_detail'] ?? null) ? $item['stock_detail'] : []);

    foreach (['preorder', 'pre_order', 'pre_venda', 'preVenda', 'made_to_order', 'sob_encomenda'] as $field) {
        $value = $item[$field] ?? $stockInfo[$field] ?? null;
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'yes', 'sim', 'preorder', 'pre-order', 'pre-venda', 'sob encomenda'], true)) {
            return true;
        }
    }

    $availability = strtolower(trim((string)($item['availability'] ?? $item['disponibilidade'] ?? $item['sale_status'] ?? '')));
    return in_array($availability, ['preorder', 'pre-order', 'pre_venda', 'pre-venda', 'made_to_order', 'sob_encomenda', 'sob encomenda'], true);
}

function svcr_is_excluded_product(array $item): bool
{
    $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
    if ($sku === 'Parafuso5x16') {
        return true;
    }

    foreach (['exclude_from_site', 'exclude_from_catalog', 'exclude_from_feeds', 'non_sellable', 'raw_material', 'materia_prima', 'materiaPrima'] as $field) {
        $value = $item[$field] ?? null;
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if (is_string($value) && in_array(svcr_lower(trim($value)), ['true', 'yes', 'sim', 'raw_material', 'materia_prima', 'matéria-prima', 'non_sellable'], true)) {
            return true;
        }
    }

    $name = svcr_lower(trim((string)($item['name'] ?? $item['nome'] ?? $item['descricao'] ?? '')));
    $category = svcr_lower(trim((string)($item['category'] ?? $item['categoria']['nome'] ?? $item['categoria'] ?? '')));
    foreach (['matéria-prima', 'materia-prima', 'materia prima', 'raw material', 'insumo interno'] as $keyword) {
        if (($name !== '' && str_contains($name, $keyword)) || ($category !== '' && str_contains($category, $keyword))) {
            return true;
        }
    }

    return false;
}

function svcr_filter_storefront_rows(array $rows): array
{
    return array_values(array_filter($rows, static function ($row): bool {
        if (!is_array($row) || svcr_is_preorder($row) || svcr_is_excluded_product($row)) {
            return false;
        }

        $isPublished = $row['is_published'] ?? true;
        if ($isPublished === 'false' || $isPublished === false || $isPublished === 0 || $isPublished === '0') {
            return false;
        }

        $prices = is_array($row['precos'] ?? null) ? $row['precos'] : (is_array($row['prices'] ?? null) ? $row['prices'] : []);
        $price = $prices['preco'] ?? $prices['preco_venda'] ?? $prices['price'] ?? $prices['sale_price'] ?? $row['preco'] ?? $row['price'] ?? null;
        $promotional = $prices['precoPromocional'] ?? $prices['preco_promocional'] ?? $prices['promotional_price'] ?? $row['preco_promocional'] ?? $row['promotional_price'] ?? null;

        return shopvivaliz_price_value($promotional) !== null || shopvivaliz_price_value($price) !== null;
    }));
}

function svcr_products(): array
{
    $root = dirname(__DIR__);
    $cache = $root . '/storage/products-cache-ativos.json';
    $payload = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
    $items = [];

    if (is_array($payload)) {
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
    }

    if ($items === []) {
        $fallback = $root . '/api/catalog/fallback-products.json';
        $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
        return is_array($rows) ? svcr_filter_storefront_rows($rows) : [];
    }

    $products = [];
    foreach ($items as $item) {
        if (!is_array($item) || svcr_is_preorder($item) || svcr_is_excluded_product($item)) {
            continue;
        }

        $isPublished = $item['is_published'] ?? true;
        if ($isPublished === 'false' || $isPublished === false || $isPublished === 0 || $isPublished === '0') {
            continue;
        }

        $situation = strtoupper(trim((string)($item['situacao'] ?? 'A')));
        if (!in_array($situation, ['A', 'ATIVO', 'ACTIVE'], true)) {
            continue;
        }

        $prices = is_array($item['precos'] ?? null) ? $item['precos'] : (is_array($item['prices'] ?? null) ? $item['prices'] : []);
        $regularPrice = shopvivaliz_price_value($prices['preco'] ?? $prices['preco_venda'] ?? $prices['price'] ?? $prices['sale_price'] ?? $item['preco'] ?? $item['price'] ?? null);
        $promotionalPrice = shopvivaliz_price_value($prices['precoPromocional'] ?? $prices['preco_promocional'] ?? $prices['promotional_price'] ?? $item['preco_promocional'] ?? $item['promotional_price'] ?? null);
        $price = $promotionalPrice !== null && ($regularPrice === null || $promotionalPrice < $regularPrice) ? $promotionalPrice : $regularPrice;

        if ($price === null) {
            continue;
        }

        $imagesList = svcr_collect_image_urls($item);
        $image = trim((string)($item['imagem_principal_url'] ?? $item['primary_image_url'] ?? $item['image_url'] ?? $item['imagem'] ?? ''));
        if ($image === '') {
            $image = $imagesList[0] ?? '';
        }

        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : (is_array($item['stock_detail'] ?? null) ? $item['stock_detail'] : []);
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : (is_array($item['dimensions'] ?? null) ? $item['dimensions'] : []);
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        if ($sku === '') {
            continue;
        }

        $name = trim((string)($item['descricao'] ?? $item['nome'] ?? $item['name'] ?? $sku));
        $products[] = [
            'id' => (string)($item['id'] ?? $sku),
            'sku' => $sku,
            'olist_product_id' => (string)($item['id'] ?? $item['olist_product_id'] ?? ''),
            'name' => $name,
            'slug' => svcr_slug($name, $sku),
            'description' => trim((string)($item['descricaoComplementar'] ?? $item['descricao_complementar'] ?? $item['description'] ?? $item['descricao'] ?? '')),
            'price' => $price,
            'stock' => max(0, (int)($item['estoque_disponivel'] ?? $stockInfo['quantidade'] ?? $stockInfo['stock'] ?? $item['stock'] ?? 0)),
            'image_url' => $image,
            'images' => $imagesList,
            'images_count' => count($imagesList),
            'category' => trim((string)($category['nome'] ?? $category['caminhoCompleto'] ?? $item['category'] ?? '')),
            'weight' => (float)($dimensions['pesoLiquido'] ?? $dimensions['peso_liquido'] ?? $dimensions['net_weight'] ?? $item['peso'] ?? $item['weight'] ?? 0),
            'width' => (float)($dimensions['largura'] ?? $dimensions['width'] ?? $item['width'] ?? 0),
            'height' => (float)($dimensions['altura'] ?? $dimensions['height'] ?? $item['height'] ?? 0),
            'length' => (float)($dimensions['comprimento'] ?? $dimensions['length'] ?? $item['length'] ?? 0),
            'status' => 'active',
        ];
    }

    if ($products !== []) {
        return $products;
    }

    $fallback = $root . '/api/catalog/fallback-products.json';
    $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
    return is_array($rows) ? svcr_filter_storefront_rows($rows) : [];
}

function svcr_collect_image_urls(array $item): array
{
    $images = [];
    $push = static function (string $candidate) use (&$images): void {
        $candidate = trim($candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate) && !in_array($candidate, $images, true)) {
            $images[] = $candidate;
        }
    };

    foreach (['images', 'imagens', 'gallery', 'galeria', 'fotos', 'photos', 'attachments', 'anexos'] as $field) {
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
