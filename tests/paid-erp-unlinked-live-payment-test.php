<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/quality/audit-paid-erp-readonly.php';
function no_erp_assert(bool $expected, array $payment, string $erpId, string $case): void
{
    $result=svfra_approved_payment_without_erp($payment,'SV20260727000000001',12.51,$erpId);
    if($result!==$expected){fwrite(STDERR,"FAIL $case\n");exit(1);}
}
$real=[
  'status'=>'approved','external_reference'=>'SV20260727000000001',
  'transaction_amount'=>12.51,'transaction_amount_refunded'=>0.0,
  'live_mode'=>true,'currency_id'=>'BRL','refunds'=>[]
];
no_erp_assert(true,$real,'','approved live payment with missing ERP');
no_erp_assert(true,$real,'   ','blank ERP link');
no_erp_assert(false,$real,'388356916','has ERP link, should follow ERP classification');
no_erp_assert(false,array_replace($real,['live_mode'=>false]),'','sandbox cannot be treated as verified real-money discrepancy');
no_erp_assert(false,array_replace($real,['status'=>'pending']),'','pending is not paid');
no_erp_assert(false,array_replace($real,['external_reference'=>'SV-OTHER']),'','wrong order reference');
no_erp_assert(false,array_replace($real,['transaction_amount'=>9.99]),'','wrong payment total');
no_erp_assert(false,array_replace($real,['transaction_amount_refunded'=>12.51]),'','refunded payment');
no_erp_assert(false,array_replace($real,['refunds'=>[['status'=>'approved']]]),'','refunded by provider');
no_erp_assert(false,array_replace($real,['live_mode'=>null]),'','unknown live mode requires review');

$src=(string)file_get_contents(dirname(__DIR__).'/scripts/quality/audit-paid-erp-readonly.php');
foreach(['provider_approved_without_erp','svfra_approved_payment_without_erp(','$erpId === \'\''] as $s){
 if(!str_contains($src,$s)){fwrite(STDERR,"FAIL missing live audit branch $s\n");exit(1);}
}
if(str_contains($src,"WHERE olist_order_id IS NOT NULL AND olist_order_id <> ''")){
 fwrite(STDERR,"FAIL paid rows without ERP must be included in audit\n");exit(1);
}
echo "PASS: 10 offline approved-payment-without-ERP cases, read-only guards.\n";
