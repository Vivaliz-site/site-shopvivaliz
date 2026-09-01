<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/dossier.php';
require __DIR__.'/../includes/amazon-recovery/appeal-generator.php';
$dossier=sv_ar_build_dossier(['amazon_order_id'=>'702-1111111-2222222','seller_sku'=>'SKU-1','refund_at'=>'2026-07-10T12:00:00-03:00','physical_received_at'=>null,'refund_initiator'=>'AMAZON_AUTOMATIC','risk_amount'=>100.0],[['id'=>'ev-refund','type'=>'FINANCE_TRANSACTION','supports'=>['refund_at','refund_initiator','risk_amount']],['id'=>'ev-physical','type'=>'WAREHOUSE_RECONCILIATION','supports'=>['physical_received_at']]]);
ar_t_assert(isset($dossier['facts']['refund_at']['evidence'][0]),'refund fact linked to evidence');ar_t_assert(isset($dossier['facts']['physical_received_at']['evidence'][0]),'not-received fact linked to evidence');
$generated=sv_ar_generate_claim_text($dossier,['policy_key'=>'BR_RETURN_NOT_RECEIVED_D45','wait_days'=>45]);ar_t_assert(str_contains($generated['text'],'não foi recebido fisicamente'),'supported missing return can be stated');ar_t_assert(str_contains($generated['text'],'reembolso automático da Amazon'),'supported Amazon refund can be stated');ar_t_eq([],$generated['missing_evidence'],'complete dossier has no gaps');
$weak=sv_ar_build_dossier(['amazon_order_id'=>'702-3333333-4444444','refund_at'=>'2026-07-10T12:00:00-03:00','physical_received_at'=>null,'refund_initiator'=>'UNKNOWN','risk_amount'=>80.0],[['id'=>'ev-refund-date','type'=>'EMAIL','supports'=>['refund_at']]]);
$g2=sv_ar_generate_claim_text($weak,['policy_key'=>'BR_RETURN_NOT_RECEIVED_D45','wait_days'=>45]);ar_t_assert(!str_contains($g2['text'],'reembolso automático da Amazon'),'generator cannot invent refund initiator');ar_t_assert(in_array('physical_received_at',$g2['missing_evidence'],true),'warehouse gap reported');ar_t_assert(in_array('refund_initiator',$g2['missing_evidence'],true),'refund initiator gap reported');
ar_t_ok('dossier and grounded generator');
