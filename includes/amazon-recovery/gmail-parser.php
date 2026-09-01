<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function sv_ar_parse_amazon_email(array $message): array
{
    $id=trim((string)($message['id'] ?? ''));
    $from=strtolower(trim((string)($message['from'] ?? $message['from_'] ?? '')));
    $subject=trim((string)($message['subject'] ?? ''));
    $body=(string)($message['body'] ?? '');
    $base=['source'=>'gmail','source_id'=>$id,'event_type'=>'UNKNOWN_AMAZON_MESSAGE','amazon_order_id'=>null,'safe_t_id'=>null,'amount'=>null,'asin'=>null,'seller_sku'=>null,'quantity'=>null,'raw_subject'=>$subject];
    if (!str_contains($from,'amazon.')) return $base;
    if (preg_match('/Reembolso de\s+([0-9]+(?:[.,][0-9]+)?)\s+BRL iniciado para o pedido\s+(70[12]-\d{7}-\d{7})/iu',$subject,$m)) {
        $base['event_type']='REFUND_DETECTED'; $base['amount']=(float)str_replace(',','.',$m[1]); $base['amazon_order_id']=$m[2]; return $base;
    }
    if (preg_match('/autoriza[cç][aã]o de devolu[cç][aã]o referente ao pedido de n[uú]mero\s+(70[12]-\d{7}-\d{7})/iu',$subject,$m)) {
        $base['event_type']='RETURN_AUTHORIZED'; $base['amazon_order_id']=$m[1];
        if (preg_match('/\b(B0[A-Z0-9]{8})\b/u',$body,$asin)) $base['asin']=$asin[1];
        if (preg_match('/Sku\s*\R\s*([^\r\n]+)/iu',$body,$sku)) $base['seller_sku']=trim($sku[1]);
        if (preg_match('/Quantidade da devolu[cç][aã]o\s*\R\s*(\d+)/iu',$body,$qty)) $base['quantity']=(int)$qty[1];
        return $base;
    }
    if (preg_match('/SAFE-T\s+(\d{5}-\d{5}-\d{7})\s+foi registrada para o pedido\s+(70[12]-\d{7}-\d{7})/iu',$subject,$m)) {
        $base['event_type']='SAFE_T_OPENED'; $base['safe_t_id']=$m[1]; $base['amazon_order_id']=$m[2]; return $base;
    }
    if (preg_match('/Atualiza[cç][aã]o da solicita[cç][aã]o do SAFE-T\s+(\d{5}-\d{5}-\d{7})\s+para o pedido\s+(70[12]-\d{7}-\d{7})/iu',$subject,$m)) {
        $base['event_type']='SAFE_T_UPDATED'; $base['safe_t_id']=$m[1]; $base['amazon_order_id']=$m[2]; return $base;
    }
    $combined=$subject.' '.$body;
    $lower=function_exists('mb_strtolower')?mb_strtolower($combined,'UTF-8'):strtolower($combined);
    if (str_contains($lower,'alteração na política') || str_contains($lower,'alteracao na politica')) $base['event_type']='POLICY_CHANGE_CANDIDATE';
    return $base;
}
