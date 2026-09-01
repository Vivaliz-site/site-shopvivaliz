<?php
declare(strict_types=1);

function sv_ar_case_key(array $identity): string
{
    $parts=[trim((string)($identity['amazon_order_id'] ?? '')),trim((string)($identity['amazon_order_item_id'] ?? '')),trim((string)($identity['refund_event_id'] ?? '')),strtoupper(trim((string)($identity['claim_reason'] ?? '')))];
    if ($parts[0]==='' || $parts[3]==='') throw new InvalidArgumentException('amazon_order_id and claim_reason are required for case identity');
    return hash('sha256',implode('|',$parts));
}

/** @return array<string,mixed> */
function sv_ar_reduce_events(array $events): array
{
    usort($events,static fn(array $a,array $b):int=>strcmp((string)($a['effective_at'] ?? ''),(string)($b['effective_at'] ?? '')));
    $state=['case_state'=>'OBSERVING','safe_t_id'=>null,'physical_received'=>false,'reimbursed'=>false,'has_reversal'=>false];
    foreach ($events as $event) {
        $type=strtoupper(trim((string)($event['event_type'] ?? '')));
        $payload=is_array($event['payload'] ?? null)?$event['payload']:[];
        switch ($type) {
            case 'RETURN_AUTHORIZED': $state['case_state']='RETURN_AUTHORIZED'; break;
            case 'REFUND_DETECTED': $state['case_state']='WAITING_RETURN'; break;
            case 'PHYSICAL_RETURN_RECEIVED': $state['physical_received']=true; $state['case_state']='RETURN_RECEIVED'; break;
            case 'SAFE_T_OPENED': $state['safe_t_id']=trim((string)($payload['safe_t_id'] ?? $state['safe_t_id'] ?? '')) ?: $state['safe_t_id']; $state['case_state']='SAFE_T_OPEN'; break;
            case 'SAFE_T_INFO_REQUIRED': $state['case_state']='SAFE_T_INFO_REQUIRED'; break;
            case 'SAFE_T_DENIED': $state['case_state']='SAFE_T_DENIED_APPEAL_PENDING'; break;
            case 'SAFE_T_APPROVED': $state['case_state']='SAFE_T_APPROVED_WAITING_CREDIT'; break;
            case 'APPEAL_OPENED': $state['case_state']='SAFE_T_APPEAL_OPEN'; break;
            case 'AMAZON_REIMBURSEMENT_DETECTED': $state['reimbursed']=true; $state['case_state']='AMAZON_PROACTIVE_REIMBURSED'; break;
            case 'SAFE_T_REIMBURSEMENT_DETECTED': $state['reimbursed']=true; $state['case_state']='SAFE_T_RECOVERED'; break;
            case 'APPEAL_REIMBURSEMENT_DETECTED': $state['reimbursed']=true; $state['case_state']='APPEAL_RECOVERED'; break;
            case 'CREDIT_REVERSED': $state['reimbursed']=false; $state['has_reversal']=true; $state['case_state']='RECONCILIATION_PENDING'; break;
        }
    }
    return $state;
}

function sv_ar_event_payload_hash(array $event): string
{
    $json=json_encode($event['payload'] ?? [],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    return hash('sha256',is_string($json)?$json:'{}');
}
