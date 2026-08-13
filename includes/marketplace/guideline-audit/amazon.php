<?php

declare(strict_types=1);

/** @param array<string,mixed> $data @param array<string,mixed> $product @param array<string,array<string,mixed>> $checks */
function sv_guideline_audit_amazon(array $data, array $product, array $policy, array &$checks): void
{
    $title = (string)$data['title'];
    $bullets = (array)$data['bullet_points'];
    $keywords = (array)$data['seo_keywords'];
    $hooks = (array)$data['marketing_hooks'];
    $brand = trim((string)($product['brand'] ?? ''));
    $disallowed = (string)($policy['title_disallowed_chars'] ?? '!$?_{}^¬¦');
    $hasDisallowed = preg_match('/[' . preg_quote($disallowed, '/') . ']/u', $title) === 1;
    sv_guideline_audit_add($checks, 'amazon_title_chars', !$hasDisallowed, 'hard', 'Remova caracteres proibidos do titulo Amazon.');

    $keywordString = trim(implode(' ', $keywords));
    $bytes = strlen($keywordString);
    sv_guideline_audit_add($checks, 'amazon_search_terms_bytes', $bytes <= (int)$policy['search_terms_max_bytes'], 'hard', 'Search terms acima do limite seguro.', $bytes, (int)$policy['search_terms_max_bytes']);
    sv_guideline_audit_add($checks, 'amazon_search_terms_punctuation', preg_match('/[,;:|]/u', $keywordString) !== 1, 'hard', 'Use espacos, sem pontuacao, nos search terms.');
    $tokens = sv_marketplace_guideline_tokens($keywordString);
    sv_guideline_audit_add($checks, 'amazon_search_terms_unique', count($tokens) === count(array_unique($tokens)), 'hard', 'Remova palavras repetidas dos search terms.');
    $indexed = array_flip(array_unique(array_merge(
        sv_marketplace_guideline_tokens($title),
        sv_marketplace_guideline_tokens($brand),
        sv_marketplace_guideline_tokens(implode(' ', $bullets))
    )));
    $overlap = array_values(array_unique(array_filter($tokens, static fn(string $token): bool => isset($indexed[$token]))));
    sv_guideline_audit_add($checks, 'amazon_search_terms_non_redundant', $overlap === [], 'hard', 'Remova search terms ja presentes em titulo, marca ou bullets.', $overlap, []);
    $prohibited = preg_match('/\b(?:amazon|best|melhor|barato|cheapest|promo[cç][aã]o|novo|new|sale|oferta|trending|popular|B0[A-Z0-9]{8})\b/iu', $keywordString) === 1;
    sv_guideline_audit_add($checks, 'amazon_search_terms_prohibited_absent', !$prohibited, 'hard', 'Remova termos temporarios, subjetivos, ASIN ou marca da plataforma.');
    sv_guideline_audit_add($checks, 'amazon_marketing_hooks_internal', count($hooks) <= 1, 'soft', 'Mantenha no maximo um hook factual interno.', count($hooks), 1);
}
