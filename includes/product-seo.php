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
    $value = preg_replace('/([.!?;:])(?=[\p{Lu}\p{N}])/u', '$1 ', $value) ?: $value;
    $value = preg_replace('/\s*•\s*/u', ' • ', $value) ?: $value;
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
    $explicit = trim((string)($product['brand'] ?? $product['marca'] ?? ''));
    if ($explicit !== '' && preg_match('/^PRODUTO_\d+$/i', $explicit) !== 1) {
        return svseo_trim_words($explicit, 70);
    }

    $haystack = svseo_lower(implode(' ', [
        (string)($product['name'] ?? ''),
        (string)($product['description'] ?? ''),
        (string)($product['category'] ?? ''),
        implode(' ', is_array($product['tags'] ?? null) ? $product['tags'] : []),
    ]));

    foreach (['soprano', 'gedore', 'astra', 'fercar', 'papaiz', 'japi', 'aquatools', 'robust', 'ferramix', 'tramontina', 'vonder', 'tigre', 'lorenzetti'] as $brand) {
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
        'jardim' => ['jardim', 'floreira', 'cachepot', 'vaso', 'irrigador'],
        'eletrica' => ['tomada', 'interruptor', 'plugue', 'elétrica', 'eletrica'],
        'seguranca' => ['cadeado', 'fechadura', 'cofre', 'segurança', 'seguranca'],
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
        'silicone gel', 'com freio', 'sem freio', 'giratorio', 'giratório',
        'almofadado', 'branco', 'azul', 'preto', 'dourado', 'inox', 'zincado',
        'galvanizado', 'porta de correr', 'com espelho',
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
    if (in_array('rodizio', $terms, true)) return 'Casa e jardim > Ferragens > Rodizios';
    if (in_array('banheiro', $terms, true)) return 'Casa e jardim > Banheiro';
    if (in_array('ferramenta', $terms, true)) return 'Ferramentas';
    if (in_array('pet', $terms, true)) return 'Pet shop';
    if (in_array('jardim', $terms, true)) return 'Casa e jardim > Jardim';
    if (in_array('eletrica', $terms, true)) return 'Casa e jardim > Eletrica';
    if (in_array('seguranca', $terms, true)) return 'Casa e jardim > Seguranca';

    return 'Casa, jardim e utilidades';
}

function svseo_google_product_category(array $product): string
{
    $candidate = trim((string)(
        $product['google_product_category']
        ?? $product['google_category']
        ?? $product['categoria_google']
        ?? ''
    ));
    if ($candidate === '') return '';
    if (preg_match('/^\d+$/', $candidate) === 1) return $candidate;
    return str_contains($candidate, ' > ') ? svseo_trim_words($candidate, 750) : '';
}

function svseo_title(array $product, int $width = 150): string
{
    // A pagina publica de produto usa historicamente width=70 e acrescenta
    // " | Vivaliz" fora desta funcao. Reserva os 10 caracteres do sufixo
    // e mantem o <title> completo em ate 60 caracteres. Outros consumidores,
    // como o Merchant Feed (150), preservam seus limites proprios.
    if ($width === 70) {
        $width = 50;
    }

    $approvedMetaTitle = trim((string)($product['meta_title'] ?? ''));
    if ($approvedMetaTitle !== '') {
        return svseo_trim_words($approvedMetaTitle, $width);
    }

    $name = svseo_human_name($product);
    $brand = svseo_brand($product);
    $sku = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $attributes = svseo_attribute_terms($product, $name);
    $parts = [];

    if ($brand !== '' && $brand !== 'Vivaliz' && stripos($name, $brand) === false) {
        $parts[] = $brand;
    }
    $parts[] = $name;
    foreach (array_slice($attributes, 0, 3) as $attribute) {
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
    $brand = svseo_brand($product);
    $stock = (int)($product['stock'] ?? 0);
    $attributes = svseo_attribute_terms($product, $name);

    if ($description === '' || svseo_is_boilerplate_description($description)) {
        $description = $name;
    }

    $parts = [$description];
    $bullets = is_array($product['bullet_points'] ?? null) ? $product['bullet_points'] : [];
    if ($bullets !== []) {
        $parts[] = 'Destaques: ' . implode('; ', array_slice(array_map('strval', $bullets), 0, 5)) . '.';
    } elseif ($attributes !== []) {
        $parts[] = 'Principais atributos: ' . implode(', ', array_slice($attributes, 0, 8)) . '.';
    }
    if ($brand !== 'Vivaliz') {
        $parts[] = 'Marca ' . $brand . '.';
    }
    $parts[] = $stock > 0 ? 'Disponível em estoque para venda online.' : 'Produto temporariamente sem estoque.';

    return svseo_trim_words(implode(' ', array_filter($parts)), $width);
}

function svseo_meta_description(array $product): string
{
    $approvedMetaDescription = trim((string)($product['meta_description'] ?? ''));
    if ($approvedMetaDescription !== '') {
        return svseo_trim_words(svseo_plain_text($approvedMetaDescription), 155);
    }
    return svseo_description($product, 155);
}

function svseo_price_band(float $price): string
{
    if ($price < 50) return 'ate-50';
    if ($price < 150) return '50-a-149';
    if ($price < 500) return '150-a-499';
    return '500-ou-mais';
}
