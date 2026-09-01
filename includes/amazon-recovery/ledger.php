<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function sv_ar_summarize_ledger(array $entries, float $expectedRecovery): array
{
    $seen=[]; $approved=0.0; $received=0.0; $hasReversal=false;
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $transactionId=trim((string)($entry['transaction_id'] ?? ''));
        if ($transactionId !== '') { if (isset($seen[$transactionId])) continue; $seen[$transactionId]=true; }
        $type=strtoupper(trim((string)($entry['type'] ?? '')));
        $amount=round(abs((float)($entry['amount'] ?? 0.0)),2);
        if ($type==='SAFE_T_APPROVED') $approved += $amount;
        elseif (in_array($type,['AMAZON_PROACTIVE_REIMBURSEMENT','SAFE_T_REIMBURSEMENT'],true)) $received += $amount;
        elseif ($type==='REVERSAL') { $received -= $amount; $hasReversal=true; }
    }
    $expectedRecovery=round(max(0.0,$expectedRecovery),2);
    $approved=round(max(0.0,$approved),2);
    $received=round(max(0.0,$received),2);
    $outstanding=round(max(0.0,$expectedRecovery-$received),2);
    return ['risk_amount'=>$expectedRecovery,'expected_recovery'=>$expectedRecovery,'approved_recovery'=>$approved,'received_recovery'=>$received,'outstanding_recovery'=>$outstanding,'is_reimbursed'=>$outstanding<=0.0,'has_reversal'=>$hasReversal];
}
