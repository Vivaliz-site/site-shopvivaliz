<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/gmail-parser.php';
$refund=sv_ar_parse_amazon_email(['id'=>'m1','from'=>'donotreply@amazon.com','subject'=>'Reembolso de 128.25 BRL iniciado para o pedido 701-1433354-4637803','body'=>'Iniciou um reembolso no valor de BRL 128.25.']);
ar_t_eq('REFUND_DETECTED',$refund['event_type'],'refund normalized');ar_t_eq('701-1433354-4637803',$refund['amazon_order_id'],'order parsed');ar_t_eq(128.25,$refund['amount'],'amount parsed');
$ret=sv_ar_parse_amazon_email(['id'=>'m2','from'=>'donotreply@amazon.com','subject'=>'Notificação de autorização de devolução referente ao pedido de número 701-4236758-7416240','body'=>"Código ASIN\nB0CJ87928X\nSku\n1C7Q-LKWG-J7K0\nQuantidade da devolução\n1\nMotivo da devolução\nComprado por engano"]);
ar_t_eq('RETURN_AUTHORIZED',$ret['event_type'],'return normalized');ar_t_eq('B0CJ87928X',$ret['asin'],'asin parsed');ar_t_eq('1C7Q-LKWG-J7K0',$ret['seller_sku'],'sku parsed');
$safe=sv_ar_parse_amazon_email(['id'=>'m3','from'=>'donotreply@amazon.com','subject'=>'Sua solicitação do SAFE-T 98143-99485-9285859 foi registrada para o pedido 702-9582024-4340203','body'=>'confirmada']);ar_t_eq('SAFE_T_OPENED',$safe['event_type'],'SAFE-T opened normalized');ar_t_eq('98143-99485-9285859',$safe['safe_t_id'],'SAFE-T id parsed');
$unknown=sv_ar_parse_amazon_email(['id'=>'m4','from'=>'donotreply@amazon.com','subject'=>'Mensagem Amazon sem padrão conhecido','body'=>'x']);ar_t_eq('UNKNOWN_AMAZON_MESSAGE',$unknown['event_type'],'unknown Amazon mail retained');
ar_t_ok('gmail parser');
