<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function sv_ar_build_dossier(array $case,array $evidence): array
{
    $index=[];
    foreach ($evidence as $item) {
        if (!is_array($item)) continue;
        $id=trim((string)($item['id'] ?? ''));
        if ($id==='') continue;
        foreach ((array)($item['supports'] ?? []) as $field) {
            $field=(string)$field; $index[$field] ??=[]; $index[$field][]=$id;
        }
    }
    $facts=[];
    foreach ($case as $field=>$value) $facts[(string)$field]=['value'=>$value,'evidence'=>array_values(array_unique($index[(string)$field] ?? []))];
    return ['amazon_order_id'=>(string)($case['amazon_order_id'] ?? ''),'facts'=>$facts,'evidence'=>array_values($evidence),'dossier_hash'=>hash('sha256',json_encode([$case,$evidence],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '')];
}

function sv_ar_fact_supported(array $dossier,string $field): bool
{
    $fact=$dossier['facts'][$field] ?? null;
    return is_array($fact) && !empty($fact['evidence']);
}

function sv_ar_fact_value(array $dossier,string $field): mixed
{
    return $dossier['facts'][$field]['value'] ?? null;
}
