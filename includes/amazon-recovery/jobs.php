<?php
declare(strict_types=1);

function sv_ar_job_backoff_seconds(int $attempt): int
{
    if ($attempt<=0) return 30;
    return min(3600,60*(2**($attempt-1)));
}

function sv_ar_job_priority(array $job): int
{
    $action=strtoupper(trim((string)($job['action_type'] ?? $job['job_type'] ?? 'RECONCILE')));
    $hours=max(0,(int)($job['hours_to_deadline'] ?? 9999));
    $risk=max(0.0,(float)($job['risk_amount'] ?? 0));
    if (in_array($action,['RESPOND_INFO_REQUEST','APPEAL_SAFE_T'],true) && $hours<=24) return 0;
    $base=match($action){'RESPOND_INFO_REQUEST'=>10,'APPEAL_SAFE_T'=>15,'OPEN_SAFE_T'=>30,'RECONCILE'=>80,default=>60};
    $deadlineBonus=min(30,intdiv(max(0,168-min($hours,168)),6));
    $riskBonus=min(25,(int)floor($risk/100.0));
    return max(1,$base-$deadlineBonus-$riskBonus);
}
