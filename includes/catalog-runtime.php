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
    $slug = trim((string)$text, '-');
    $skuPart = svcr_lower((string)(preg_replace('/[^\p{L}\p{N}]+/u', '', $sku) ?? ''));
    if ($name !== '' && $skuPart !== '' && !str_ends_with(str_replace('-', '', $slug), $skuPart)) {
        $slug .= '-' . $skuPart;
    }
    return trim($slug, '-');
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
    $rawNameForExclusion = svcr_lower(trim((string)($item['name'] ?? $item['nome'] ?? $item['descricao'] ?? '')));
    if ($rawNameForExclusion === 'stretch manual 500x25 c/3 kg' || str_starts_with($rawNameForExclusion, 'stretch manual 500x25 c/3 kg ')) return true;

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
    // ERP-only cadastro rule: legacy fallback snapshots cannot publish or
    // enrich product registrations. The function remains for compatibility.
    return [];
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

function svcr_content_key(mixed $value): string
{
    return svcr_lower(trim((string)$value));
}

/**
 * Conteudo editorial indexado por SKU/ID.
 *
 * Estes arquivos sao snapshots historicos e, portanto, nunca podem fornecer
 * preco, estoque, status ou qualquer outro dado comercial. Eles sao usados
 * somente para completar o conteudo da fonte canonica de itens ativos.
 *
 * @return array<string, array<string, mixed>>
 */
function svcr_content_index(string $root): array
{
    // ERP-only cadastro rule: do not read historical product snapshots or
    // legacy fallback snapshots to complete product name, category,
    // description, media or SEO. These files are historical snapshots.
    return [];
}

function svcr_content_for_item(array $item, array $index): array
{
    return [];
}

function svcr_is_weak_description(string $description, string $name): bool
{
    $description = trim(strip_tags($description));
    $name = trim(strip_tags($name));
    if ($description === '' || strlen($description) < 45) return true;
    if ($name !== '' && svcr_lower($description) === svcr_lower($name)) return true;
    return preg_match('/^(confira|compre|produto de qualidade|produto vivaliz)\b/iu', $description) === 1;
}

function svcr_infer_category(string $name, string $description = ''): string
{
    $haystack = svcr_lower($name . ' ' . $description);
    $rules = [
        'Rodízios' => ['rodízio', 'rodizio', 'roldana'],
        'Ferramentas' => ['ferramenta', 'alicate', 'chave ', 'martelo', 'broca', 'serrote', 'trena'],
        'Ferragens' => ['parafuso', 'porca', 'arruela', 'dobradiça', 'dobradica', 'fechadura', 'trinco', 'cadeado'],
        'Organização' => ['organizador', 'prateleira', 'suporte', 'gancho', 'cesto'],
        'Jardim' => ['jardim', 'mangueira', 'regador', 'vaso ', 'pulverizador'],
        'Hidráulica' => ['torneira', 'registro', 'sifão', 'sifao', 'hidráulic', 'hidraulic', 'engate flexível', 'engate flexivel'],
        'Elétrica' => ['tomada', 'interruptor', 'elétric', 'eletric', 'lâmpada', 'lampada', 'extensão', 'extensao'],
        'Limpeza' => ['limpeza', 'vassoura', 'rodo', 'escova', 'pano '],
    ];
    foreach ($rules as $category => $terms) {
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) return $category;
        }
    }
    return 'Casa e manutenção';
}

function svcr_first_text(array $sources): string
{
    foreach ($sources as $value) {
        if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
    }
    return '';
}

function svcr_first_positive_number(array $sources): float
{
    foreach ($sources as $value) {
        if (!is_numeric($value)) continue;
        $number = (float)$value;
        if (is_finite($number) && $number > 0) return $number;
    }
    return 0.0;
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
        $erpSnapshot = $root . '/api/catalog/fallback-products.json';
        $snapshotPayload = is_file($erpSnapshot) ? json_decode((string)file_get_contents($erpSnapshot), true) : [];
        if (is_array($snapshotPayload) && array_is_list($snapshotPayload)) {
            $allTinyV3 = true;
            foreach ($snapshotPayload as $candidateItem) {
                if (!is_array($candidateItem)) {
                    $allTinyV3 = false;
                    break;
                }
                $source = strtolower(trim((string)($candidateItem['sync_source'] ?? '')));
                if ($source !== 'tiny_v3') {
                    $allTinyV3 = false;
                    break;
                }
            }
            if ($allTinyV3) {
                $items = $snapshotPayload;
            }
        }
    }

    if ($items === []) {
        // Sem snapshot canonico de ativos nao existe evidencia suficiente
        // para publicar produtos. Falhar fechado evita ressuscitar itens
        // inativos/excluidos por meio de um fallback historico.
        return [];
    }

    $contentIndex = svcr_content_index($root);
    $products = [];
    foreach ($items as $item) {
        if (!is_array($item) || !svcr_is_active_product($item) || svcr_is_preorder($item) || svcr_is_excluded_product($item)) continue;

        $content = svcr_content_for_item($item, $contentIndex);

        $imagesList = svcr_collect_image_urls($item);
        $imagesList = array_slice($imagesList, 0, 12);
        $image = trim((string)($item['imagem_principal_url'] ?? $item['primary_image_url'] ?? $item['image_url'] ?? $item['imagem'] ?? ''));
        if ($image === '') $image = svcr_first_text([$imagesList[0] ?? '']);

        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : (is_array($item['stock_detail'] ?? null) ? $item['stock_detail'] : []);
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : (is_array($item['dimensions'] ?? null) ? $item['dimensions'] : []);
        $contentDimensions = is_array($content['dimensions'] ?? null) ? $content['dimensions'] : [];
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        if ($sku === '') continue;

        $name = trim((string)($item['descricao'] ?? $item['nome'] ?? $item['name'] ?? $sku));
        $contentName = svcr_first_text([$content['name'] ?? '', $content['nome'] ?? '']);
        if (($name === '' || svcr_lower($name) === svcr_lower($sku)) && $contentName !== '') $name = $contentName;
        $price = svcr_item_price($item);
        if ($price <= 0) continue;

        $description = svcr_first_text([$item['descricaoComplementar'] ?? '', $item['descricao_complementar'] ?? '', $item['description'] ?? '']);
        $contentDescription = svcr_first_text([$content['description'] ?? '', $content['descricaoComplementar'] ?? '', $content['descricao_complementar'] ?? '']);
        if (svcr_is_weak_description($description, $name) && !svcr_is_weak_description($contentDescription, $name)) {
            $description = $contentDescription;
        }

        $categoryName = trim((string)($category['nome'] ?? $category['caminhoCompleto'] ?? $item['category'] ?? ''));
        if ($categoryName === '') $categoryName = svcr_first_text([$content['category'] ?? '', $content['categoria'] ?? '']);
        if ($categoryName === '') $categoryName = svcr_infer_category($name, $description);

        $bulletPoints = svcr_string_list($item['bullet_points'] ?? $item['bullet_points_json'] ?? $content['bullet_points'] ?? []);
        $seoKeywords = svcr_string_list($item['seo_keywords'] ?? $item['seo_keywords_json'] ?? $content['seo_keywords'] ?? $content['keywords'] ?? []);
        $marketingHooks = svcr_string_list($item['marketing_hooks'] ?? $item['marketing_hooks_json'] ?? $content['marketing_hooks'] ?? []);
        $existingTags = svcr_string_list($item['tags'] ?? $content['tags'] ?? []);
        $brandValue = $item['brand'] ?? $item['marca'] ?? $content['brand'] ?? $content['marca'] ?? '';
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
            'description' => svcr_clean_description($description),
            'price' => $price,
            'stock' => max(0, (int)($item['estoque_disponivel'] ?? $stockInfo['quantidade'] ?? $stockInfo['stock'] ?? $item['stock'] ?? 0)),
            'image_url' => $image,
            'images' => $imagesList,
            'images_count' => count($imagesList),
            'category' => $categoryName,
            'brand' => $brand,
            'bullet_points' => $bulletPoints,
            'seo_keywords' => $seoKeywords,
            'marketing_hooks' => $marketingHooks,
            'meta_title' => svcr_first_text([$item['meta_title'] ?? '', $item['seo_title'] ?? '', $content['meta_title'] ?? '', $content['seo_title'] ?? '']),
            'meta_description' => svcr_first_text([$item['meta_description'] ?? '', $item['seo_description'] ?? '', $content['meta_description'] ?? '', $content['seo_description'] ?? '']),
            'tags' => array_slice(array_values(array_unique(array_merge($existingTags, $seoKeywords))), 0, 20),
            'gtin' => preg_replace('/\D+/', '', svcr_first_text([$item['gtin'] ?? '', $item['ean'] ?? '', $item['barcode'] ?? '', $content['gtin'] ?? '', $content['ean'] ?? ''])) ?: '',
            'ncm' => svcr_first_text([$item['ncm'] ?? '', $content['ncm'] ?? '']),
            'warranty' => svcr_first_text([$item['warranty'] ?? '', $item['garantia'] ?? '', $content['warranty'] ?? '', $content['garantia'] ?? '']),
            'weight' => svcr_first_positive_number([$dimensions['pesoLiquido'] ?? null, $dimensions['peso_liquido'] ?? null, $dimensions['net_weight'] ?? null, $item['peso'] ?? null, $item['weight'] ?? null, $contentDimensions['net_weight'] ?? null]),
            'gross_weight' => svcr_first_positive_number([$dimensions['pesoBruto'] ?? null, $dimensions['peso_bruto'] ?? null, $dimensions['gross_weight'] ?? null, $contentDimensions['gross_weight'] ?? null]),
            'width' => svcr_first_positive_number([$dimensions['largura'] ?? null, $dimensions['width'] ?? null, $item['width'] ?? null, $contentDimensions['width'] ?? null]),
            'height' => svcr_first_positive_number([$dimensions['altura'] ?? null, $dimensions['height'] ?? null, $item['height'] ?? null, $contentDimensions['height'] ?? null]),
            'length' => svcr_first_positive_number([$dimensions['comprimento'] ?? null, $dimensions['length'] ?? null, $item['length'] ?? null, $contentDimensions['length'] ?? null]),
            'video_url' => svcr_first_text([$item['video_url'] ?? '', $item['linkVideo'] ?? '', is_array($item['seo'] ?? null) ? ($item['seo']['linkVideo'] ?? '') : '', is_array($item['seo'] ?? null) ? ($item['seo']['urlVideo'] ?? '') : '', $content['video_url'] ?? '']),
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

// Rodada 5 (2026-08-19): movida de index.php para catalog-runtime.php.
// facebook-shop-feed.php chamava sv_home_catalog_source_rows() sem nunca
// incluir index.php (so includes/catalog-runtime.php), causando fatal error
// "Call to undefined function" e feed do Facebook/Instagram Shop sempre
// vazio. Guardada com function_exists() por seguranca, caso algum ponto
// ainda inclua index.php diretamente. Ver R5-4 no relatorio da Rodada 5.
if (!function_exists('sv_home_catalog_source_rows')) {
function sv_home_catalog_source_rows(): array
{
    static $localCache = null;
    if ($localCache !== null) {
        return $localCache;
    }

    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_home_catalog_source_rows_v1';
    $fileCache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-home-catalog-source-v1.json';
    if (!$apcu && is_file($fileCache) && (time() - (int)@filemtime($fileCache)) <= 30) {
        $cached = json_decode((string)@file_get_contents($fileCache), true);
        if (is_array($cached) && $cached !== []) {
            $localCache = $cached;
            return $localCache;
        }
    }
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            $localCache = $stored;
            return $localCache;
        }
    }

    $runtime = svcr_products();


    if ($runtime !== []) {
        $localCache = $runtime;
        if ($apcu) {
            apcu_store($apcuKey, $localCache, 300);
        } else {
            $encoded = json_encode($localCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $tmpCache = $fileCache . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmpCache, $encoded, LOCK_EX) !== false) {
                    @rename($tmpCache, $fileCache);
                } else {
                    @unlink($tmpCache);
                }
            }
        }
        return $localCache;
    }

    // Sem a fonte canonica de produtos ativos, a vitrine falha fechada. CSV e
    // snapshots historicos podem enriquecer imagem/conteudo, nunca provar que
    // um item ainda pode ser vendido.
    return [];
}
}
