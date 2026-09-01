<?php
declare(strict_types=1);

/** @return array{action:string,reason:string,write_external:bool} */
function sv_ar_decide_case(array $case,DateTimeImmutable $now,array $flags=[]): array
{
    $writeBlocked=(bool)($flags['kill_switch'] ?? false);
    if ($writeBlocked || (bool)($case['requires_human_review'] ?? false)) return ['action'=>'REVIEW_REQUIRED','reason'=>$writeBlocked?'kill_switch':'policy_or_data_uncertain','write_external'=>false];
    if ((bool)($case['physical_received'] ?? false)) return ['action'=>'RECONCILE_RETURN','reason'=>'physical_return_received','write_external'=>false];
    if ((bool)($case['is_reimbursed'] ?? false)) return ['action'=>'CLOSE_REIMBURSED','reason'=>'amazon_reimbursement_detected','write_external'=>false];
    $safeTId=trim((string)($case['safe_t_id'] ?? ''));
    $safeTStatus=strtoupper(trim((string)($case['safe_t_status'] ?? '')));
    if ($safeTId!=='' && (bool)($case['info_required'] ?? false)) return ['action'=>'RESPOND_INFO_REQUEST','reason'=>'safe_t_information_required','write_external'=>true];
    if ($safeTId!=='' && $safeTStatus==='DENIED') return ['action'=>'APPEAL_SAFE_T','reason'=>'safe_t_denied','write_external'=>true];
    if ($safeTId!=='') return ['action'=>'WAIT_SAFE_T','reason'=>'safe_t_already_exists','write_external'=>false];
    $eligibilityRaw=trim((string)($case['eligibility_at'] ?? ''));
    if ($eligibilityRaw==='') return ['action'=>'REVIEW_REQUIRED','reason'=>'eligibility_missing','write_external'=>false];
    $eligibility=new DateTimeImmutable($eligibilityRaw);
    if ($now<$eligibility) return ['action'=>'WAIT','reason'=>'not_yet_eligible','write_external'=>false];
    return ['action'=>'OPEN_SAFE_T','reason'=>'eligible_unreimbursed_return','write_external'=>true];
}
