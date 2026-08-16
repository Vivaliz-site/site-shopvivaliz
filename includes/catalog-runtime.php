<?php
declare(strict_types=1);

require_once __DIR__ . '/public-trust-guard.php';
svptg_register();

function svcr_slug(string $name, string $sku = ''): string
{
    $text = $name !== '' ? $name : $sku;
    if ($text === '') return '';
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim((string)$text));
    return trim((string)$text, '-');
}

function svcr_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function svcr_parse_price(mixed $value): float
{
    if ($value === null || $value === '' || is_bool($value)) return 0.0;
    if (is_int($value) || is_float($value)) {
        $price = (float)$value;
        return is_finite($price) && $price > 0 ? $price : 0.0;
    }
    if (!is_string($value)) return 0.0;

    $normalized = trim($value);
    if ($normalized === '') return 0.0;
    $normalized = str_ireplace(['R$', 'BRL', "\xc2\xa0", ' '], '', $normalized);
    $normalized = preg_replace('/[^0-9,\.\-]/', '', $normalized) ?? '';
    if ($normalized === '' || $normalized === '-') return 0.0;

    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');
    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($lastComma !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif (substr_count($normalized, '.') > 1) {
        $parts = explode('.', $normalized);
        $decimal = array_pop($parts);
        $normalized = implode('', $parts) . '.' . $decimal;
    }

    if (!is_numeric($normalized)) return 0.0;
    $price = (float)$normalized;
    return is_finite($price) && $price > 0 ? $price : 0.0;
}

function svcr_item_price(array $item): float
{
    $prices = is_array($item['precos'] ?? null)
        ? $item['precos']
        : (is_array($item['prices'] ?? null) ? $item['prices'] : []);

    $regular = svcr_parse_price(
        $prices['preco']
        ?? $prices['preco_venda']
        ?? $prices['price']
        ?? $prices['sale_price']
        ?? $item['preco']
        ?? $item['price']
        ?? null
    );
    $promotional = svcr_parse_price(
        $prices['precoPromocional']
        ?? $prices['preco_promocional']
        ?? $prices['promotional_price']
        ?? $item['preco_promocional']
        ?? $item['promotional_price']
        ?? null
    );

    return $promotional > 0 && ($regular <= 0 || $promotional < $regular)
        ? $promotional
        : $regular;
}

function svcr_clean_description(string $description): string
{
    $description = trim($description);
    if ($description === '') return '';

    $description = str_ireplace(
        [
            'Comprar Pronto!',
            'Comprar pronto!',
            'Incluindo Características adicionales:',
            'Incluindo características adicionales:',
            'Características adicionales:',
            'Caracteristicas adicionales:',
        ],
        [
            '',
            '',
            'Características:',
            'Características:',
            'Características:',
            'Características:',
        ],
        $description
    );

    $description = preg_replace('/(?:\s*Não acumulável com algumas promoções\.?\s*){2,}/iu', ' Não acumulável com outras promoções. ', $description) ?? $description;
    $description = preg_replace('/\s{2,}/u', ' ', $description) ?? $description;
    return trim($description);
}

/** @return list<string> */
function svcr_string_list(mixed $value, string $separatorPattern = '/[,|\r\n]+/u'): array
{
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : (preg_split($separatorPattern, $raw) ?: []);
    } else {
        return [];
    }

    $normalized = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $item = $item['value'] ?? $item['text'] ?? $item['name'] ?? '';
        }
        $text = trim((string)$item);
        if ($text !== '' && !in_array($text, $normalized, true)) {
            $normalized[] = $text;
        }
    }
    return $normalized;
}

function svcr_is_preorder(array $item): bool
{
    $stockInfo = is_array($item['estoque'] ?? null)
        ? $item['estoque']
        : (is_array($item['stock_detail'] ?? null) ? $item['stock_detail'] : []);

    foreach (['preorder', 'pre_order', 'pre_venda', 'preVenda', 'made_to_order', 'sob_encomenda'] as $field) {
        $value = $item[$field] ?? $stockInfo[$field] ?? null;
        if ($value === true || $value === 1 || $value === '1') return true;
        if (is_string($value) && in_array(svcr_lower(trim($value)), ['true', 'yes', 'sim', 'preorder', 'pre-order', 'pre-venda', 'pre venda', 'sob encomenda'], true)) return true;
    }

    foreach (['availability', 'disponibilidade', 'sale_status', 'status_venda', 'situacao_venda', 'price_label', 'preco_label'] as $field) {
        $value = svcr_lower(trim((string)($item[$field] ?? $stockInfo[$field] ?? '')));
        if ($value !== '' && preg_match('/\b(pre[\s_-]?venda|pre[\s_-]?order|sob[\s_-]?encomenda|made[\s_-]?to[\s_-]?order)\b/u', $value)) {
            return true;
        }
    }

    return false;
}

function svcr_is_excluded_product(array $item): bool
{
    $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
    if ($sku === 'Parafuso5x16') return true;
    if (svcr_lower(trim((string)($item['name'] ?? $item['nome'] ?? $item['descricao'] ?? ''))) === 'stretch manual 500x25 c/3 kg') return true;

    foreach (['exclude_from_site', 'exclude_from_catalog', 'exclude_from_feeds', 'non_sellable', 'raw_material', 'materia_prima', 'materiaPrima'] as $field) {
        $value = $item[$field] ?? null;
        if ($value === true || $value === 1 || $value === '1') return true;
        if (is_string($value) && in_array(svcr_lower(trim($value)), ['true', 'yes', 'sim', 'raw_material', 'materia_prima', 'matéria-prima', 'non_sellable'], true)) return true;
    }

    $name = svcr_lower(trim((string)($item['name'] ?? $item['nome'] ?? $item['descricao'] ?? '')));
    $categoryValue = $item['categoria'] ?? '';
    $category = is_array($categoryValue) ? (string)($categoryValue['nome'] ?? '') : (string)$categoryValue;
    $category = svcr_lower(trim((string)($item['category'] ?? $category)));
    foreach (['matéria-prima', 'materia-prima', 'materia prima', 'raw material', 'insumo interno'] as $keyword) {
        if (($name !== '' && str_contains($name, $keyword)) || ($category !== '' && str_contains($category, $keyword))) return true;
    }
    return false;
}

function svcr_flag_bool(mixed $value): ?bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return ((int)$value) !== 0;
    if (!is_string($value)) return null;
    $normalized = svcr_lower(trim($value));
    if ($normalized === '') return null;
    if (in_array($normalized, ['1', 'true', 'yes', 'sim', 's', 'ativo', 'active', 'enabled', 'publicado', 'published'], true)) return true;
    if (in_array($normalized, ['0', 'false', 'no', 'nao', 'não', 'n', 'inativo', 'inactive', 'disabled', 'excluido', 'excluído', 'deleted', 'removed', 'archived', 'arquivado'], true)) return false;
    return null;
}

function svcr_is_active_product(array $item): bool
{
    foreach (['deleted', 'is_deleted', 'excluded', 'is_excluded', 'archived', 'is_archived', 'removed', 'is_removed'] as $field) {
        if (!array_key_exists($field, $item)) continue;
        if (svcr_flag_bool($item[$field]) === true) return false;
    }
    foreach (['deleted_at', 'excluded_at', 'archived_at', 'removed_at'] as $field) {
        if (!array_key_exists($field, $item)) continue;
        $value = trim((string)($item[$field] ?? ''));
        if ($value !== '' && $value !== '0' && $value !== '0000-00-00 00:00:00') return false;
    }
    foreach (['active', 'is_active', 'ativo', 'enabled', 'is_enabled', 'is_published'] as $field) {
        if (!array_key_exists($field, $item)) continue;
        if (svcr_flag_bool($item[$field]) === false) return false;
    }
    $allowed = ['A', 'ATIVO', 'ACTIVE', 'ENABLED', 'PUBLICADO', 'PUBLISHED', 'DISPONIVEL', 'DISPONÍVEL', 'AVAILABLE'];
    foreach (['situacao', 'situação', 'status', 'state', 'product_status', 'situacao_produto'] as $field) {
        if (!array_key_exists($field, $item)) continue;
        $value = trim((string)($item[$field] ?? ''));
        if ($value === '') continue;
        $normalized = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
        if (!in_array($normalized, $allowed, true)) return false;
    }
    return true;
}

function svcr_filter_storefront_rows(array $rows): array
{
    return array_values(array_filter($rows, static function ($row): bool {
        if (!is_array($row) || !svcr_is_active_product($row) || svcr_is_preorder($row) || svcr_is_excluded_product($row) || svcr_item_price($row) <= 0) return false;
        return true;
    }));
}

function svcr_fallback_products(string $root): array
{
    $fallback = $root . '/api/catalog/fallback-products.json';
    $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
    return is_array($rows) ? svcr_filter_storefront_rows($rows) : [];
}

function svcr_has_available_product(array $products): bool
{
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        if (svcr_item_price($product) > 0 && (int)($product['stock'] ?? $product['estoque_disponivel'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

function svcr_select_catalog_products(array $products, array $fallbackProducts): array
{
    // Regra fail-closed: snapshots/fallbacks antigos nao comprovam o
    // status atual no ERP. Somente a fonte canonica pode alimentar a
    // vitrine, inclusive quando todos os itens ativos estao sem estoque.
    return $products;
}
function svcr_products(): array
{
    static $cachedProducts = null;
    if ($cachedProducts !== null) {
        return $cachedProducts;
    }

    $root = dirname(__DIR__);
    $cache = $root . '/storage/products-cache-ativos.json';
    $payload = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
    $items = [];

    if (is_array($payload)) {
        foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $candidate = $payload[$key];
                if (isset($candidate['itens']) && is_array($candidate['itens'])) $candidate = $candidate['itens'];
                elseif (isset($candidate['items']) && is_array($candidate['items'])) $candidate = $candidate['items'];
                $items = $candidate;
                break;
            }
        }
        if ($items === [] && array_is_list($payload)) $items = $payload;
    }

    if ($items === []) {
    // Sem snapshot canonico de ativos nao existe evidencia suficiente
    // para publicar produtos. Falhar fechado evita ressuscitar itens
    // inativos/excluidos por meio de um fallback historico.
    return [];
}

    $products = [];
    foreach ($items as $item) {
        if (!is_array($item) || !svcr_is_active_product($item) || svcr_is_preorder($item) || svcr_is_excluded_product($item)) continue;

        $imagesList = svcr_collect_image_urls($item);
        $image = trim((string)($item['imagem_principal_url'] ?? $item['primary_image_url'] ?? $item['image_url'] ?? $item['imagem'] ?? ''));
        if ($image === '') $image = $imagesList[0] ?? '';

        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : (is_array($item['stock_detail'] ?? null) ? $item['stock_detail'] : []);
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : (is_array($item['dimensions'] ?? null) ? $item['dimensions'] : []);
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        if ($sku === '') continue;

        $name = trim((string)($item['descricao'] ?? $item['nome'] ?? $item['name'] ?? $sku));
        $price = svcr_item_price($item);
        if ($price <= 0) continue;

        $bulletPoints = svcr_string_list($item['bullet_points'] ?? $item['bullet_points_json'] ?? []);
        $seoKeywords = svcr_string_list($item['seo_keywords'] ?? $item['seo_keywords_json'] ?? []);
        $marketingHooks = svcr_string_list($item['marketing_hooks'] ?? $item['marketing_hooks_json'] ?? []);
        $existingTags = svcr_string_list($item['tags'] ?? []);
        $brandValue = $item['brand'] ?? $item['marca'] ?? '';
        if (is_array($brandValue)) {
            $brandValue = $brandValue['nome'] ?? $brandValue['name'] ?? '';
        }
        $brand = is_scalar($brandValue) ? trim((string)$brandValue) : '';

        $products[] = [
            'id' => (string)($item['id'] ?? $sku),
            'sku' => $sku,
            'olist_product_id' => (string)($item['id'] ?? $item['olist_product_id'] ?? ''),
            'name' => $name,
            'slug' => svcr_slug($name, $sku),
            'description' => svcr_clean_description((string)($item['descricaoComplementar'] ?? $item['descricao_complementar'] ?? $item['description'] ?? $item['descricao'] ?? '')),
            'price' => $price,
            'stock' => max(0, (int)($item['estoque_disponivel'] ?? $stockInfo['quantidade'] ?? $stockInfo['stock'] ?? $item['stock'] ?? 0)),
            'image_url' => $image,
            'images' => $imagesList,
            'images_count' => count($imagesList),
            'category' => trim((string)($category['nome'] ?? $category['caminhoCompleto'] ?? $item['category'] ?? '')),
            'brand' => $brand,
            'bullet_points' => $bulletPoints,
            'seo_keywords' => $seoKeywords,
            'marketing_hooks' => $marketingHooks,
            'meta_title' => trim((string)($item['meta_title'] ?? '')),
            'meta_description' => trim((string)($item['meta_description'] ?? '')),
            'tags' => array_slice(array_values(array_unique(array_merge($existingTags, $seoKeywords))), 0, 20),
            'weight' => (float)($dimensions['pesoLiquido'] ?? $dimensions['peso_liquido'] ?? $dimensions['net_weight'] ?? $item['peso'] ?? $item['weight'] ?? 0),
            'width' => (float)($dimensions['largura'] ?? $dimensions['width'] ?? $item['width'] ?? 0),
            'height' => (float)($dimensions['altura'] ?? $dimensions['height'] ?? $item['height'] ?? 0),
            'length' => (float)($dimensions['comprimento'] ?? $dimensions['length'] ?? $item['length'] ?? 0),
            'video_url' => trim((string)($item['video_url'] ?? '')),
            'status' => 'active',
        ];
    }

    $cachedProducts = svcr_select_catalog_products($products, svcr_fallback_products($root));
    return $cachedProducts;
}

function svcr_collect_image_urls(array $item): array
{
    $images = [];
    $push = static function (string $candidate) use (&$images): void {
        $candidate = trim($candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate) && !in_array($candidate, $images, true)) $images[] = $candidate;
    };

    foreach (['images', 'imagens', 'gallery', 'galeria', 'fotos', 'photos', 'attachments', 'anexos'] as $field) {
        $value = $item[$field] ?? null;
        if (is_string($value)) {
            $push($value);
            continue;
        }
        if (!is_array($value)) continue;
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $push($entry);
                continue;
            }
            if (!is_array($entry)) continue;
            foreach (['url', 'link', 'src', 'image', 'imagem', 'image_url'] as $key) {
                $candidate = trim((string)($entry[$key] ?? ''));
                if ($candidate !== '') $push($candidate);
            }
        }
    }

    for ($i = 1; $i <= 12; $i++) {
        foreach (["imagem{$i}", "image{$i}", "foto{$i}", "photo{$i}"] as $key) {
            $candidate = trim((string)($item[$key] ?? ''));
            if ($candidate !== '') $push($candidate);
        }
    }

    return array_slice($images, 0, 12);
}