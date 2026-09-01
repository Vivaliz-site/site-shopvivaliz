<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/jobs.php';
require __DIR__.'/../includes/amazon-recovery/outbox.php';
ar_t_eq(60,sv_ar_job_backoff_seconds(1),'attempt 1 backoff');ar_t_eq(120,sv_ar_job_backoff_seconds(2),'attempt 2 backoff');ar_t_eq(3600,sv_ar_job_backoff_seconds(10),'backoff capped');
$key=sv_ar_action_idempotency_key(['amazon_order_id'=>'701-1111111-2222222','amazon_order_item_id'=>'item','refund_event_id'=>'r1','claim_reason'=>'RETURN_NOT_RECEIVED'],'OPEN_SAFE_T');
ar_t_eq(hash('sha256','701-1111111-2222222|item|r1|RETURN_NOT_RECEIVED|OPEN_SAFE_T'),$key,'action key deterministic');
ar_t_eq(0,sv_ar_job_priority(['action_type'=>'RESPOND_INFO_REQUEST','hours_to_deadline'=>3,'risk_amount'=>50]),'urgent info request top priority');
ar_t_assert(sv_ar_job_priority(['action_type'=>'OPEN_SAFE_T','hours_to_deadline'=>72,'risk_amount'=>2000])<sv_ar_job_priority(['action_type'=>'RECONCILE','hours_to_deadline'=>999,'risk_amount'=>10]),'deadline/high value outranks background reconciliation');
ar_t_ok('jobs and idempotency');
