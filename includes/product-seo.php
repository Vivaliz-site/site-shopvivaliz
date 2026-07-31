<?php
declare(strict_types=1);

function svseo_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function svseo_trim_words(string $value, int $width, string $suffix = ''): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, $suffix);
    }

    return strlen($value) > $width ? rtrim(substr($value, 0, max(0, $width - strlen($suffix)))) . $suffix : $value;
}

function svseo_plain_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s*(?:FOTOS|IMAGENS)\s+MERAMENTES?\s+ILUSTRATIVAS\.?\s*/i', ' ', $value) ?: $value;
    $value = preg_replace('/\s*Confira as dimensões, compatibilidade e aplicação antes da compra\.?\s*/iu', ' ', $value) ?: $value;
    $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
    return trim($value);
}

function svseo_is_boilerplate_description(string $description): bool
{
    $text = svseo_lower($description);
    return str_contains($text, 'identificação clara de produto')
        || str_contains($text, 'identificacao clara de produto')
        || str_contains($text, 'compra mais segura');
}

function svseo_human_name(array $product): string
{
    $name = trim((string)($product['name'] ?? ''));
    if ($name !== '' && preg_match('/^PRODUTO_\d+$/i', $name) !== 1) {
        return svseo_trim_words($name, 120);
    }

    $description = svseo_plain_text((string)($product['description'] ?? ''));
    return $description !== '' ? svseo_trim_words($description, 120) : $name;
}

function svseo_brand(array $product): string
{
    $haystack = svseo_lower(implode(' ', [
        (string)($product['name'] ?? ''),
        (string)($product['description'] ?? ''),
        (string)($product['category'] ?? ''),
        implode(' ', is_array($product['tags'] ?? null) ? $product['tags'] : []),
    ]));

    foreach (['soprano', 'gedore', 'astra', 'fercar', 'papaiz', 'japi', 'aquatools', 'robust'] as $brand) {
        if (str_contains($haystack, $brand)) {
            return ucfirst($brand);
        }
    }

    return 'Vivaliz';
}

function svseo_intent_terms(array $product, string $name): array
{
    $text = svseo_lower($name . ' ' . (string)($product['description'] ?? '') . ' ' . (string)($product['category'] ?? ''));
    $terms = [];

    $rules = [
        'rodizio' => ['rodizio', 'rodízio', 'rodinha', 'gel', 'silicone'],
        'banheiro' => ['banheiro', 'assento sanitario', 'assento sanitário', 'armario banheiro', 'armário banheiro'],
        'ferramenta' => ['ferramenta', 'alicate', 'chave', 'gedore', 'fercar', 'robust'],
        'pet' => ['pet', 'cachorro', 'gato', 'comedouro', 'racao', 'ração'],
        'jardim' => ['jardim', 'floreira', 'cachepot', 'vaso'],
    ];

    foreach ($rules as $term => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $terms[] = $term;
                break;
            }
        }
    }

    if (str_contains($text, 'freio') || str_contains($text, 'trava')) {
        $terms[] = 'com freio';
    }
    if (str_contains($text, 'silicone') || str_contains($text, 'gel')) {
        $terms[] = 'silicone gel';
    }

    return array_values(array_unique($terms));
}

function svseo_attribute_terms(array $product, string $name): array
{
    $text = svseo_lower($name . ' ' . svseo_plain_text((string)($product['description'] ?? '')));
    $attributes = [];

    if (preg_match_all('/\b\d+(?:[,.]\d+)?\s?(?:mm|cm|kg|l|litros?|m)\b/iu', $text, $matches)) {
        foreach ($matches[0] as $match) {
            $attributes[] = preg_replace('/\s+/', ' ', trim($match)) ?: '';
        }
    }

    foreach ([
        'silicone gel',
        'com freio',
        'sem freio',
        'giratorio',
        'giratório',
        'almofadado',
        'branco',
        'azul',
        'preto',
        'dourado',
        'inox',
        'zincado',
        'galvanizado',
        'porta de correr',
        'com espelho',
    ] as $term) {
        if (str_contains($text, $term)) {
            $attributes[] = $term;
        }
    }

    return array_values(array_filter(array_unique($attributes)));
}

function svseo_product_type(array $product, string $name = ''): string
{
    $category = trim((string)($product['category'] ?? ''));
    if ($category !== '') {
        return $category;
    }

    $terms = svseo_intent_terms($product, $name !== '' ? $name : svseo_human_name($product));
    if (in_array('rodizio', $terms, true)) {
        return 'Casa e jardim > Ferragens > Rodizios';
    }
    if (in_array('banheiro', $terms, true)) {
        return 'Casa e jardim > Banheiro';
    }
    if (in_array('ferramenta', $terms, true)) {
        return 'Ferramentas';
    }
    if (in_array('pet', $terms, true)) {
        return 'Pet shop';
    }
    if (in_array('jardim', $terms, true)) {
        return 'Casa e jardim > Jardim';
    }

    return 'Casa, jardim e utilidades';
}

function svseo_title(array $product, int $width = 150): string
{
    $name = svseo_human_name($product);
    $brand = svseo_brand($product);
    $category = svseo_product_type($product, $name);
    $sku = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $attributes = svseo_attribute_terms($product, $name);
    $parts = [];

    if ($brand !== '' && $brand !== 'Vivaliz' && stripos($name, $brand) === false) {
        $parts[] = $brand;
    }
    $parts[] = $name;
    foreach (array_slice($attributes, 0, 4) as $attribute) {
        if ($attribute !== '' && stripos($name, $attribute) === false) {
            $parts[] = $attribute;
        }
    }
    if ($sku !== '' && preg_match('/^PRODUTO_\d+$/i', $sku) !== 1 && stripos($name, $sku) === false) {
        $parts[] = $sku;
    }

    $title = preg_replace('/\b(\w[\wÀ-ÿ-]{2,})(?:\s+\1\b)+/iu', '$1', implode(' ', array_filter($parts))) ?: '';
    return svseo_trim_words($title, $width);
}

function svseo_description(array $product, int $width = 5000): string
{
    $name = svseo_human_name($product);
    $description = svseo_plain_text((string)($product['description'] ?? ''));
    $category = svseo_product_type($product, $name);
    $brand = svseo_brand($product);
    $stock = (int)($product['stock'] ?? 0);
    $terms = svseo_intent_terms($product, $name);
    $attributes = svseo_attribute_terms($product, $name);

    if ($description === '' || svseo_is_boilerplate_description($description)) {
        $description = $name;
    }

    $parts = [];
    $parts[] = $description;
    if ($attributes !== []) {
        $parts[] = 'Principais atributos: ' . implode(', ', array_slice($attributes, 0, 8)) . '.';
    }
    if ($brand !== 'Vivaliz') {
        $parts[] = 'Marca ' . $brand . '.';
    }
    unset($category, $terms);
    $parts[] = $stock > 0 ? 'Disponível em estoque para venda online.' : 'Produto temporariamente sem estoque.';

    return svseo_trim_words(implode(' ', array_filter($parts)), $width);
}

function svseo_meta_description(array $product): string
{
    return svseo_description($product, 155);
}
