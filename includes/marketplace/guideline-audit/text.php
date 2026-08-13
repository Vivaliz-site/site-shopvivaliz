<?php

declare(strict_types=1);

function sv_marketplace_guideline_lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function sv_marketplace_guideline_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function sv_marketplace_guideline_stripos(string $haystack, string $needle): int|false
{
    return function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') : stripos($haystack, $needle);
}

function sv_marketplace_guideline_fold(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($folded) && $folded !== '') $text = $folded;
    $text = sv_marketplace_guideline_lower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** @return list<string> */
function sv_marketplace_guideline_tokens(string $text): array
{
    $folded = sv_marketplace_guideline_fold($text);
    return $folded === '' ? [] : array_values(array_filter(preg_split('/\s+/u', $folded) ?: []));
}

/** @return list<string> */
function sv_marketplace_guideline_string_list(mixed $value, string $separator = ',', bool $deduplicate = true): array
{
    if (is_array($value)) $items = $value;
    elseif (is_scalar($value)) {
        $text = trim((string)$value);
        if ($text === '') return [];
        $decoded = json_decode($text, true);
        $items = is_array($decoded) ? $decoded : preg_split('/(?:\R+|' . preg_quote($separator, '/') . ')+/u', $text);
    } else return [];

    $out = [];
    $seen = [];
    foreach ((array)$items as $entry) {
        if (!is_scalar($entry)) continue;
        $candidate = trim((string)$entry);
        $key = sv_marketplace_guideline_fold($candidate);
        if ($candidate === '' || $key === '' || ($deduplicate && isset($seen[$key]))) continue;
        if ($deduplicate) $seen[$key] = true;
        $out[] = $candidate;
    }
    return $out;
}

/** @param array<string,mixed> $content @return array<string,mixed> */
function sv_marketplace_guideline_content(array $content): array
{
    return [
        'title' => trim((string)($content['title'] ?? $content['optimized_title'] ?? '')),
        'description' => trim((string)($content['description'] ?? $content['optimized_description'] ?? '')),
        'bullet_points' => sv_marketplace_guideline_string_list($content['bullet_points'] ?? [], "\n", false),
        'seo_keywords' => sv_marketplace_guideline_string_list($content['seo_keywords'] ?? [], ',', false),
        'marketing_hooks' => sv_marketplace_guideline_string_list($content['marketing_hooks'] ?? [], '|', false),
        'meta_title' => trim((string)($content['meta_title'] ?? '')),
        'meta_description' => trim((string)($content['meta_description'] ?? '')),
    ];
}

/** @return array<string,int> */
function sv_marketplace_guideline_title_word_counts(string $title): array
{
    $ignore = array_flip(['a','o','as','os','de','da','do','das','dos','e','em','com','para','por','no','na']);
    $counts = [];
    foreach (sv_marketplace_guideline_tokens($title) as $token) {
        if (!isset($ignore[$token])) $counts[$token] = ($counts[$token] ?? 0) + 1;
    }
    return $counts;
}

function sv_marketplace_guideline_has_time_sensitive_claim(string $text): bool
{
    return preg_match('/\b(?:pronta\s+entrega|envio\s+imediato|envio\s+hoje|promo[cç][aã]o|oferta|liquida[cç][aã]o|ultimas?\s+unidades?|corra|garanta\s+j[aá]|limitad[oa])\b/iu', $text) === 1;
}

function sv_marketplace_guideline_has_protected_commerce(string $text): bool
{
    return preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', $text) === 1;
}

function sv_marketplace_guideline_has_unsourced_claim(string $text, string $source): bool
{
    foreach ([
        '/\b(?:100%\s*)?(?:original|aut[eê]ntic[oa])\b/iu',
        '/\b(?:garantia|certificad[oa]|homologad[oa])\b/iu',
        '/\b(?:mais\s+vendid[oa]|n[uú]mero\s*1|melhor\s+do\s+mercado|campe[aã]o\s+de\s+vendas)\b/iu',
        '/\b(?:resultado\s+garantido|comprovadamente|clinicamente\s+comprovado)\b/iu',
    ] as $pattern) {
        if (preg_match($pattern, $text) === 1 && preg_match($pattern, $source) !== 1) return true;
    }
    return false;
}
