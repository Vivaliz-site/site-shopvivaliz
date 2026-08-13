<?php

declare(strict_types=1);

/** @param array<string,mixed> $data @param array<string,mixed> $product @param array<string,array<string,mixed>> $checks */
function sv_guideline_audit_content(array $data, array $product, array $policy, array &$checks): void
{
    $title = (string)$data['title'];
    $description = (string)$data['description'];
    $bullets = (array)$data['bullet_points'];
    $keywords = (array)$data['seo_keywords'];
    $hooks = (array)$data['marketing_hooks'];
    $source = sv_marketplace_guideline_lower(implode("\n", array_map('strval', array_filter($product, 'is_scalar'))));
    $generated = implode("\n", array_merge([$title, $description, $data['meta_title'], $data['meta_description']], $bullets, $keywords, $hooks));
    $titleLength = sv_marketplace_guideline_length($title);
    $descriptionLength = sv_marketplace_guideline_length($description);

    sv_guideline_audit_add($checks, 'title_present', $title !== '', 'hard', 'Informe um titulo factual.', $titleLength, '> 0');
    sv_guideline_audit_add($checks, 'description_present', $description !== '', 'hard', 'Informe uma descricao factual.', $descriptionLength, '> 0');
    if (isset($policy['title_min'])) sv_guideline_audit_add($checks, 'title_min', $titleLength >= (int)$policy['title_min'], 'hard', 'Titulo abaixo do minimo tecnico.', $titleLength, (int)$policy['title_min']);
    if (isset($policy['title_max'])) sv_guideline_audit_add($checks, 'title_max', $titleLength <= (int)$policy['title_max'], 'hard', 'Titulo acima do limite.', $titleLength, (int)$policy['title_max']);
    if (isset($policy['title_recommended_min'], $policy['title_recommended_max'])) {
        sv_guideline_audit_add($checks, 'title_recommended_range', $titleLength >= (int)$policy['title_recommended_min'] && $titleLength <= (int)$policy['title_recommended_max'], 'soft', 'Ajuste o titulo para a faixa recomendada.', $titleLength, [(int)$policy['title_recommended_min'], (int)$policy['title_recommended_max']]);
    }
    if (isset($policy['description_max'])) sv_guideline_audit_add($checks, 'description_max', $descriptionLength <= (int)$policy['description_max'], 'hard', 'Descricao acima do limite tecnico.', $descriptionLength, (int)$policy['description_max']);
    if ((int)($policy['description_recommended_min'] ?? 0) > 0) sv_guideline_audit_add($checks, 'description_recommended_min', $descriptionLength >= (int)$policy['description_recommended_min'], 'soft', 'Aprofunde a descricao somente com fatos.', $descriptionLength, (int)$policy['description_recommended_min']);

    $bulletCount = count($bullets);
    sv_guideline_audit_add($checks, 'bullet_count', $bulletCount >= (int)($policy['bullets_min'] ?? 0) && $bulletCount <= (int)($policy['bullets_max'] ?? 99), 'hard', 'Ajuste a quantidade de bullets.', $bulletCount, [(int)($policy['bullets_min'] ?? 0), (int)($policy['bullets_max'] ?? 99)]);
    sv_guideline_audit_add($checks, 'bullets_unique', $bulletCount === count(sv_marketplace_guideline_string_list($bullets)), 'hard', 'Remova bullets duplicados.');
    sv_guideline_audit_add($checks, 'marketing_hooks_unique', count($hooks) === count(sv_marketplace_guideline_string_list($hooks)), 'soft', 'Remova hooks duplicados.');
    if (isset($policy['bullet_max'])) {
        $longest = 0;
        foreach ($bullets as $bullet) $longest = max($longest, sv_marketplace_guideline_length((string)$bullet));
        sv_guideline_audit_add($checks, 'bullet_length', $longest <= (int)$policy['bullet_max'], 'hard', 'Selling point acima do limite.', $longest, (int)$policy['bullet_max']);
    }

    sv_guideline_audit_add($checks, 'protected_commerce_absent', !sv_marketplace_guideline_has_protected_commerce($generated), 'hard', 'Remova preco, estoque, desconto, cupom, parcelamento ou frete.');
    sv_guideline_audit_add($checks, 'time_sensitive_claims_absent', !sv_marketplace_guideline_has_time_sensitive_claim($generated), 'hard', 'Remova urgencia, disponibilidade ou promocao nao comprovada.');
    sv_guideline_audit_add($checks, 'unsourced_claims_absent', !sv_marketplace_guideline_has_unsourced_claim($generated, $source), 'hard', 'Remova garantia, originalidade, certificacao, ranking ou resultado nao comprovado.');
    sv_guideline_audit_add($checks, 'title_has_no_hashtag', !str_contains($title, '#'), 'hard', 'Hashtags nao pertencem ao titulo.');
}
