<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/ApiErrors.php';
function checkApi(bool $ok,string $case):void{if(!$ok)throw new RuntimeException('api_error_mapping_'.$case);}
// Business refusals were reported as 503 "storage unavailable"; they must be 409 with the real code.
foreach(['price_preview_changed_recalculate','price_proposal_not_ready_for_publication',
    'price_request_erp_shared_id_conflict','price_skus_empty_after_cart_exclusion',
    'owner_tool_cart_price_exception'] as $code){
    $m=SvAmazonMarginApiErrors::map(new RuntimeException($code));
    checkApi($m['status']===409&&$m['body']['code']===$code&&$m['body']['error']===$code&&$m['log']===null,'conflict_'.$code);
}
$m=SvAmazonMarginApiErrors::map(new InvalidArgumentException('price_request_invalid_identity_or_nonce'));
checkApi($m['status']===400&&$m['body']['error']==='invalid_input'&&$m['body']['code']==='price_request_invalid_identity_or_nonce','input_code_exposed');
$m=SvAmazonMarginApiErrors::map(new InvalidArgumentException('some internal detail'));
checkApi($m['status']===400&&!isset($m['body']['code']),'unknown_input_message_not_leaked');
$m=SvAmazonMarginApiErrors::map(new JsonException('Syntax error'));
checkApi($m['status']===400&&$m['body']['code']==='invalid_json','json_error');
$m=SvAmazonMarginApiErrors::map(new RuntimeException("db password=secret host=10.0.0.1 \n boom"));
checkApi($m['status']===503&&$m['body']['error']==='audit_storage_unavailable','unknown_is_503');
checkApi(!str_contains(json_encode($m['body']),'secret')&&str_contains((string)$m['log'],'RuntimeException'),'unknown_body_not_leaking_and_logged');
checkApi(preg_match('/^[\x20-\x7e]+$/',(string)$m['log'])===1&&!str_contains((string)$m['log'],"\n"),'log_is_single_line_sanitized');
echo "PASS margin API error mapping: business refusals 409 with code, input 400, unknown 503 sanitized\n";
