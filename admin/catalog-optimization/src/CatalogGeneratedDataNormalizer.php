<?php

declare(strict_types=1);

/**
 * Normalizacao deterministica da saida gerada para regras objetivas do
 * catalogo. A IA continua responsavel pela redacao, mas nao por invariantes
 * que o sistema consegue garantir sem inferir nenhum fato novo.
 *
 * Este arquivo pressupoe que optimize_catalog.php ja foi carregado, pois usa
 * ai_catalog_policy(), ai_catalog_title_starts_with_identity() e
 * ai_catalog_source_blob().
 */

/** @return array<string,string> */
function catalog_generated_unsourced_claim_patterns(): array
{
    return [
        'garantia' => '/\bgarantia\b/iu',
        'original' => '/\b(?:100%\s*)?(?:original|aut[eê]ntic[oa])\b/iu',
        'certified' => '/\b(?:certificad[oa]|homologad[oa])\b/iu',
        'ranking' => '/\b(?:mais\s+vendid[oa]|n[uú]mero\s*1|melhor\s+do\s+mercado)\b/iu',
    ];
}

function catalog_generated_protected_commerce_pattern(): string
{
    return '/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu';
}

function catalog_generated_has_forbidden_fact(string $text, array $product): bool
{
    if ($text === '') return false;
    if (preg_match(catalog_generated_protected_commerce_pattern(), $text) === 1) {
        return true;
    }
    $source = ai_catalog_source_blob($product);
    foreach (catalog_generated_unsourced_claim_patterns() as $pattern) {
        if (preg_match($pattern, $text) === 1 && preg_match($pattern, $source) !== 1) {
            return true;
        }
    }
    return false;
}

function catalog_generated_cleanup_spacing(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
    $text = preg_replace('/([,;:])\s*([,;:])/u', '$1', $text) ?? $text;
    $text = preg_replace('/(?:^|\s)(?:com|e|ou|de|da|do|das|dos)\s*$/iu', '', $text) ?? $text;
    return trim($text, " \t\n\r\0\x0B,;:-");
}

/**
 * Remove apenas termos proibidos/nao comprovados de campos curtos. Isso nao
 * adiciona fatos: apenas elimina claims que o proprio gate rejeitaria.
 */
function catalog_generated_sanitize_short_text(string $text, array $product): string
{
    $source = ai_catalog_source_blob($product);
    $text = preg_replace(catalog_generated_protected_commerce_pattern(), '', $text) ?? $text;
    foreach (catalog_generated_unsourced_claim_patterns() as $pattern) {
        if (preg_match($pattern, $source) !== 1) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
    }
    return catalog_generated_cleanup_spacing($text);
}

/**
 * Em texto longo, elimina sentencas inteiras que carreguem um claim proibido.
 * Isso evita deixar frases gramaticalmente quebradas depois de apagar apenas
 * uma palavra como "garantia" ou "original".
 */
function catalog_generated_sanitize_long_text(string $text, array $product): string
{
    $text = trim($text);
    if ($text === '') return '';
    $parts = preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [$text];
    $kept = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || catalog_generated_has_forbidden_fact($part, $product)) {
            continue;
        }
        $kept[] = $part;
    }
    if ($kept !== []) {
        return catalog_generated_cleanup_spacing(implode(' ', $kept));
    }
    return catalog_generated_sanitize_short_text($text, $product);
}

function catalog_generated_identity_prefix(array $product): string
{
    $brand = trim((string)($product['brand'] ?? ''));
    $model = trim((string)($product['model'] ?? ''));
    if ($brand !== '' && $model !== '') return $brand . ' ' . $model;
    if ($brand !== '') return $brand;
    if ($model !== '') return $model;

    $name = trim((string)($product['name'] ?? ''));
    if ($name === '') return '';
    $tokens = preg_split('/\s+/u', $name) ?: [];
    $tokens = array_values(array_filter(array_map('trim', $tokens), static fn(string $token): bool => $token !== ''));
    return implode(' ', array_slice($tokens, 0, 3));
}

function catalog_generated_strip_hype_prefix(string $title): string
{
    $pattern = '/^(?:chega\s+de|diga\s+adeus|adeus|elimine(?:\s+de\s+vez)?|transforme|potencialize|maximize|imperd[ií]vel|incr[ií]vel|revolucion[aá]rio|ultim[ií]ssima\s+chance|n[aã]o\s+perca|garanta\s+j[aá]|corra)\b[\s:!?.-]*/iu';
    $title = preg_replace($pattern, '', trim($title)) ?? trim($title);
    // O quality gate trata ! e ? em titulo como copy promocional, mesmo quando
    // aparecem no fim. Remover essa pontuacao e uma regra estrutural e nao
    // altera nenhum fato do produto.
    $title = preg_replace('/[!?]+/u', '', $title) ?? $title;
    return catalog_generated_cleanup_spacing($title);
}

function catalog_generated_truncate(string $text, int $max): string
{
    $text = catalog_generated_cleanup_spacing($text);
    if ($max <= 0 || mb_strlen($text, 'UTF-8') <= $max) return $text;
    $cut = rtrim(mb_substr($text, 0, $max, 'UTF-8'));
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($space !== false && $space >= (int)floor($max * 0.55)) {
        $cut = rtrim(mb_substr($cut, 0, $space, 'UTF-8'));
    }
    return rtrim($cut, " \t\n\r\0\x0B,;:-");
}

function catalog_generated_strip_emoji(string $text): string
{
    return catalog_generated_cleanup_spacing(
        preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text) ?? $text
    );
}

/** @return list<string> */
function catalog_generated_normalize_list(mixed $raw, array $product, ?int $maxItems = null, ?int $maxLength = null): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $value) {
        if (!is_string($value)) continue;
        $value = trim($value);
        if ($value === '' || catalog_generated_has_forbidden_fact($value, $product)) continue;
        $value = catalog_generated_cleanup_spacing($value);
        if ($maxLength !== null) $value = catalog_generated_truncate($value, $maxLength);
        if ($value !== '') $out[] = $value;
        if ($maxItems !== null && count($out) >= $maxItems) break;
    }
    return array_values($out);
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function catalog_generated_normalize(array $data, string $channel, array $product): array
{
    $policy = ai_catalog_policy($channel);

    $title = catalog_generated_sanitize_short_text((string)($data['optimized_title'] ?? ''), $product);
    $title = catalog_generated_strip_hype_prefix($title);
    $identity = catalog_generated_identity_prefix($product);
    if ($identity !== '' && !ai_catalog_title_starts_with_identity($title, $product)) {
        // Evita duplicar a mesma identidade se o modelo a colocou no meio do
        // titulo. Remove uma ocorrencia e recoloca no inicio, que e a regra.
        $body = preg_replace('/\b' . preg_quote($identity, '/') . '\b/iu', '', $title, 1) ?? $title;
        $title = catalog_generated_cleanup_spacing($identity . ' ' . $body);
    }
    if (in_array($channel, ['ml', 'amazon', 'erp'], true)) {
        $title = catalog_generated_strip_emoji($title);
    }
    if ($channel === 'amazon') {
        $title = catalog_generated_cleanup_spacing(preg_replace('/[!$?_{}^¬¦]/u', '', $title) ?? $title);
    }
    $data['optimized_title'] = catalog_generated_truncate($title, (int)($policy['title_max'] ?? 300));

    $description = catalog_generated_sanitize_long_text((string)($data['optimized_description'] ?? ''), $product);
    $data['optimized_description'] = $description;

    $bulletMax = (int)($policy['bullets_max'] ?? 5);
    $bulletLength = $channel === 'tiktok' ? 249 : null;
    $data['bullet_points'] = catalog_generated_normalize_list($data['bullet_points'] ?? [], $product, $bulletMax, $bulletLength);

    if ($channel === 'erp') {
        $data['seo_keywords'] = [];
        $data['marketing_hooks'] = [];
    } else {
        $data['seo_keywords'] = catalog_generated_normalize_list($data['seo_keywords'] ?? [], $product);
        $hookMax = $channel === 'tiktok' ? 2 : null;
        $data['marketing_hooks'] = catalog_generated_normalize_list($data['marketing_hooks'] ?? [], $product, $hookMax);
    }

    $metaTitle = catalog_generated_sanitize_short_text((string)($data['meta_title'] ?? ''), $product);
    if ($metaTitle === '') $metaTitle = (string)$data['optimized_title'];
    $data['meta_title'] = catalog_generated_truncate($metaTitle, 70);

    $metaDescription = catalog_generated_sanitize_long_text((string)($data['meta_description'] ?? ''), $product);
    if ($metaDescription === '') $metaDescription = $description;
    $data['meta_description'] = catalog_generated_truncate($metaDescription, 160);

    return $data;
}
