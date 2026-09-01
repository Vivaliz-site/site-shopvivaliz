<?php
declare(strict_types=1);
require_once __DIR__.'/policies.php';
require_once __DIR__.'/ledger.php';
require_once __DIR__.'/events.php';
require_once __DIR__.'/decision-engine.php';

/** @return array<string,mixed> */
function sv_ar_reconcile_snapshot(array $case,array $events,array $ledger,DateTimeImmutable $now,array $flags=[]): array
{
    $eventState=sv_ar_reduce_events($events);
    $policy=sv_ar_policy_for_case($case,$now);
    $eligibility=null;
    if (!(bool)($policy['requires_human_review'] ?? false)) $eligibility=sv_ar_eligibility_at($case,$policy);
    $financial=sv_ar_summarize_ledger($ledger,(float)($case['risk_amount'] ?? 0.0));
    $safeTStatus='';
    if (($eventState['case_state'] ?? '')==='SAFE_T_DENIED_APPEAL_PENDING') $safeTStatus='DENIED';
    elseif (($eventState['case_state'] ?? '')==='SAFE_T_APPROVED_WAITING_CREDIT') $safeTStatus='APPROVED';
    elseif (!empty($eventState['safe_t_id'])) $safeTStatus='OPEN';
    $snapshot=array_merge($case,['case_state'=>(string)($eventState['case_state'] ?? $case['case_state'] ?? 'OBSERVING'),'physical_received'=>(bool)($eventState['physical_received'] ?? false),'safe_t_id'=>$eventState['safe_t_id'] ?? ($case['safe_t_id'] ?? null),'safe_t_status'=>$safeTStatus,'info_required'=>(($eventState['case_state'] ?? '')==='SAFE_T_INFO_REQUIRED'),'eligibility_at'=>$eligibility?->format(DATE_ATOM),'requires_human_review'=>(bool)($case['requires_human_review'] ?? false)||(bool)($policy['requires_human_review'] ?? false),'is_reimbursed'=>(bool)$financial['is_reimbursed'],'policy'=>$policy,'financial'=>$financial]);
    $snapshot['decision']=sv_ar_decide_case($snapshot,$now,$flags);
    return $snapshot;
}
