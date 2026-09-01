<?php
declare(strict_types=1);

/** @return array{status:string,violations:list<string>,metrics:array<string,int>} */
function sv_ar_health_from_metrics(array $metrics): array
{
    $keys=['unclassified_cases','eligible_without_action','missed_deadlines','unreconciled_credits','uncertain_actions','dlq_unresolved','orphan_events','unknown_active_policies'];
    $normalized=[];$violations=[];
    foreach ($keys as $key) { $normalized[$key]=max(0,(int)($metrics[$key] ?? 0)); if ($normalized[$key]>0) $violations[]=$key; }
    $status='healthy';
    foreach (['missed_deadlines','eligible_without_action','uncertain_actions','unknown_active_policies'] as $key) if ($normalized[$key]>0) { $status='critical'; break; }
    if ($status==='healthy' && $violations!==[]) $status='degraded';
    return ['status'=>$status,'violations'=>$violations,'metrics'=>$normalized];
}
