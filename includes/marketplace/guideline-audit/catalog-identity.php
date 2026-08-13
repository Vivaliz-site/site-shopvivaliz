<?php

declare(strict_types=1);

/** @param array<string,mixed> $data @param array<string,mixed> $product @param array<string,array<string,mixed>> $checks */
function sv_guideline_audit_identity(array $data, array $product, string $channel, array $policy, array &$checks): void
{
    $title = (string)$data['title'];
    $brand = trim((string)($product['brand'] ?? ''));
    $model = trim((string)($product['model'] ?? ''));
    if ($brand !== '') sv_guideline_audit_add($checks, 'brand_preserved', sv_marketplace_guideline_stripos($title, $brand) !== false, in_array($channel, ['amazon','ml'], true) ? 'hard' : 'soft', 'Preserve a marca factual no titulo.');
    if ($model !== '') sv_guideline_audit_add($checks, 'model_preserved', sv_marketplace_guideline_stripos($title, $model) !== false, in_array($channel, ['amazon','ml','erp'], true) ? 'hard' : 'soft', 'Preserve modelo/MPN factual no titulo.');
    $counts = sv_marketplace_guideline_title_word_counts($title);
    $maxRepeat = $counts === [] ? 0 : max($counts);
    $repeatLimit = (int)($policy['title_word_repeat_max'] ?? 3);
    sv_guideline_audit_add($checks, 'title_word_repetition', $maxRepeat <= $repeatLimit, $channel === 'amazon' ? 'hard' : 'soft', 'Reduza repeticao de palavras no titulo.', $maxRepeat, $repeatLimit);
    sv_guideline_audit_add($checks, 'meta_title_length', sv_marketplace_guideline_length((string)$data['meta_title']) <= (int)($policy['meta_title_max'] ?? 70), 'soft', 'Ajuste o meta title.');
    sv_guideline_audit_add($checks, 'meta_description_length', sv_marketplace_guideline_length((string)$data['meta_description']) <= (int)($policy['meta_description_max'] ?? 160), 'soft', 'Ajuste a meta description.');
}
