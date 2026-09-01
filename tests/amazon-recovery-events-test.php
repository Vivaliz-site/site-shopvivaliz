<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/events.php';
$key=sv_ar_case_key(['amazon_order_id'=>'702-1111111-2222222','amazon_order_item_id'=>'item-1','refund_event_id'=>'refund-1','claim_reason'=>'RETURN_NOT_RECEIVED']);
ar_t_eq(hash('sha256','702-1111111-2222222|item-1|refund-1|RETURN_NOT_RECEIVED'),$key,'case key deterministic');
$events=[['event_type'=>'RETURN_AUTHORIZED','effective_at'=>'2026-07-01T10:00:00Z'],['event_type'=>'REFUND_DETECTED','effective_at'=>'2026-07-10T10:00:00Z','payload'=>['amount'=>100]],['event_type'=>'SAFE_T_OPENED','effective_at'=>'2026-08-24T10:00:00Z','payload'=>['safe_t_id'=>'11111-22222-3333333']],['event_type'=>'SAFE_T_DENIED','effective_at'=>'2026-08-25T10:00:00Z']];
$state=sv_ar_reduce_events($events);ar_t_eq('SAFE_T_DENIED_APPEAL_PENDING',$state['case_state'],'denied claim goes to appeal');ar_t_eq('11111-22222-3333333',$state['safe_t_id'],'SAFE-T id retained');
$events[]=['event_type'=>'AMAZON_REIMBURSEMENT_DETECTED','effective_at'=>'2026-08-26T10:00:00Z'];ar_t_eq('AMAZON_PROACTIVE_REIMBURSED',sv_ar_reduce_events($events)['case_state'],'proactive reimbursement closes claim path');
$events[]=['event_type'=>'CREDIT_REVERSED','effective_at'=>'2026-08-27T10:00:00Z'];ar_t_eq('RECONCILIATION_PENDING',sv_ar_reduce_events($events)['case_state'],'reversal reopens case');
ar_t_ok('event reducer');
