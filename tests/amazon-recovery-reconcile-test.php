<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/reconciler.php';
$case=['amazon_order_id'=>'702-1111111-2222222','amazon_program'=>'STANDARD_RETURN_TO_SELLER','claim_reason'=>'RETURN_NOT_RECEIVED','order_date'=>'2026-07-01T10:00:00-03:00','refund_at'=>'2026-07-10T12:00:00-03:00','risk_amount'=>150.0,'requires_human_review'=>false];
$events=[['event_type'=>'RETURN_AUTHORIZED','effective_at'=>'2026-07-01T10:00:00Z'],['event_type'=>'REFUND_DETECTED','effective_at'=>'2026-07-10T15:00:00Z']];$ledger=[['type'=>'SELLER_DEBIT','amount'=>150.0,'transaction_id'=>'d1']];$now=new DateTimeImmutable('2026-09-01T12:00:00-03:00');
$r=sv_ar_reconcile_snapshot($case,$events,$ledger,$now);ar_t_eq('2026-08-24',substr($r['eligibility_at'],0,10),'standard eligibility');ar_t_eq(false,$r['is_reimbursed'],'unreimbursed open');ar_t_eq('OPEN_SAFE_T',$r['decision']['action'],'eligible opens claim');
$events[]=['event_type'=>'PHYSICAL_RETURN_RECEIVED','effective_at'=>'2026-08-20T12:00:00Z'];ar_t_eq('RECONCILE_RETURN',sv_ar_reconcile_snapshot($case,$events,$ledger,$now)['decision']['action'],'physical return prevents claim');
$events=array_slice($events,0,2);$ledger[]=['type'=>'AMAZON_PROACTIVE_REIMBURSEMENT','amount'=>150.0,'transaction_id'=>'c1'];ar_t_eq('CLOSE_REIMBURSED',sv_ar_reconcile_snapshot($case,$events,$ledger,$now)['decision']['action'],'proactive reimbursement closes');
ar_t_ok('snapshot reconciler');
