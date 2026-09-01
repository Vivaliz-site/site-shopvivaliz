<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/ledger.php';
$entries=[['type'=>'SELLER_DEBIT','amount'=>150.0,'transaction_id'=>'t1'],['type'=>'CUSTOMER_REFUND','amount'=>150.0,'transaction_id'=>'t2']];
$s=sv_ar_summarize_ledger($entries,150.0);ar_t_eq(150.0,$s['outstanding_recovery'],'debit stays outstanding');ar_t_eq(false,$s['is_reimbursed'],'not reimbursed');
$entries[]=['type'=>'AMAZON_PROACTIVE_REIMBURSEMENT','amount'=>150.0,'transaction_id'=>'t3'];$s=sv_ar_summarize_ledger($entries,150.0);ar_t_eq(0.0,$s['outstanding_recovery'],'proactive reimbursement clears risk');
$partial=[['type'=>'SELLER_DEBIT','amount'=>200.0,'transaction_id'=>'a1'],['type'=>'SAFE_T_APPROVED','amount'=>200.0,'transaction_id'=>'a2'],['type'=>'SAFE_T_REIMBURSEMENT','amount'=>120.0,'transaction_id'=>'a3']];
$s2=sv_ar_summarize_ledger($partial,200.0);ar_t_eq(80.0,$s2['outstanding_recovery'],'partial credit remains open');ar_t_eq(200.0,$s2['approved_recovery'],'approved tracked separately');
$partial[]=['type'=>'REVERSAL','amount'=>20.0,'transaction_id'=>'a4'];$s3=sv_ar_summarize_ledger($partial,200.0);ar_t_eq(100.0,$s3['outstanding_recovery'],'reversal reopens exposure');ar_t_eq(true,$s3['has_reversal'],'reversal flagged');
ar_t_ok('financial ledger');
