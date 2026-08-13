<?php

declare(strict_types=1);

/** @param array<string,mixed> $data @param array<string,array<string,mixed>> $checks */
function sv_guideline_audit_channel_rules(array $data, string $channel, array &$checks): void
{
    $title = (string)$data['title'];
    $description = (string)$data['description'];
    $keywords = (array)$data['seo_keywords'];
    $hooks = (array)$data['marketing_hooks'];
    if ($channel === 'ml') {
        $condition = preg_match('/\b(?:novo|usado|recondicionado)\b/iu', $title) === 1;
        sv_guideline_audit_add($checks, 'ml_condition_absent_from_title', !$condition, 'hard', 'Condicao do item nao pertence ao titulo.');
        sv_guideline_audit_add($checks, 'ml_keywords_internal_only', count($keywords) <= 12, 'soft', 'Keywords sao apenas apoio interno.', count($keywords), '<= 12');
    } elseif ($channel === 'shopee') {
        $hashtagCount = preg_match_all('/#[\p{L}\p{N}_-]+/u', $description, $matches) ?: 0;
        sv_guideline_audit_add($checks, 'shopee_hashtag_volume', $hashtagCount <= 5, 'soft', 'Nao dependa de volume de hashtags.', $hashtagCount, '<= 5');
        sv_guideline_audit_add($checks, 'shopee_hooks_count', count($hooks) <= 2, 'soft', 'Mantenha no maximo dois hooks factuais.', count($hooks), 2);
    } elseif ($channel === 'tiktok') {
        sv_guideline_audit_add($checks, 'tiktok_hooks_count', count($hooks) <= 2, 'hard', 'Use no maximo dois hooks neste fluxo.', count($hooks), 2);
    } elseif ($channel === 'erp') {
        sv_guideline_audit_add($checks, 'erp_keywords_empty', $keywords === [], 'hard', 'ERP nao deve receber keywords editoriais.');
        sv_guideline_audit_add($checks, 'erp_hooks_empty', $hooks === [], 'hard', 'ERP nao deve receber hooks promocionais.');
    }
}
