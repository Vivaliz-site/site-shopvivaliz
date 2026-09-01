<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/policies.php';
$standard=['marketplace_id'=>'A2Q3Y263D00KWC','amazon_program'=>'STANDARD_RETURN_TO_SELLER','fulfillment_channel'=>'MFN','claim_reason'=>'RETURN_NOT_RECEIVED','order_date'=>'2026-07-01T10:00:00-03:00','refund_at'=>'2026-07-10T12:00:00-03:00'];
$p=sv_ar_policy_for_case($standard,new DateTimeImmutable('2026-09-01T12:00:00-03:00'));
ar_t_eq(45,$p['wait_days'],'standard return must use D+45');
ar_t_eq('2026-08-24',sv_ar_eligibility_at($standard,$p)->format('Y-m-d'),'D+45 from refund_at');
$dba=$standard;$dba['amazon_program']='DELIVERY_BY_AMAZON';$dba['order_date']='2026-05-01T10:00:00-03:00';
ar_t_eq(60,sv_ar_policy_for_case($dba,new DateTimeImmutable())['wait_days'],'DBA post cutover must use D+60');
$dba['order_date']='2026-04-20T10:00:00-03:00';ar_t_eq(45,sv_ar_policy_for_case($dba,new DateTimeImmutable())['wait_days'],'DBA before cutover stays D+45');
$unknown=$standard;$unknown['claim_reason']='UNMAPPED_REASON';ar_t_eq(true,sv_ar_policy_for_case($unknown,new DateTimeImmutable())['requires_human_review'],'unknown policy requires review');
ar_t_ok('policy engine');
