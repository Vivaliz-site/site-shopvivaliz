<?php
declare(strict_types=1);
require_once __DIR__ . '/dossier.php';

/** @return array{text:string,facts:list<string>,evidence_refs:list<string>,confidence:string,missing_evidence:list<string>} */
function sv_ar_generate_claim_text(array $dossier,array $policy): array
{
    $orderId=trim((string)($dossier['amazon_order_id'] ?? ''));
    $missing=[]; $factsUsed=[]; $refs=[];
    foreach (['refund_at','physical_received_at','refund_initiator'] as $field) if (!sv_ar_fact_supported($dossier,$field)) $missing[]=$field;
    $sentences=["Solicito a análise da reivindicação SAFE-T referente ao pedido {$orderId}."];
    if (sv_ar_fact_supported($dossier,'physical_received_at') && sv_ar_fact_value($dossier,'physical_received_at')===null) {
        $sentences[]='Até a última reconciliação registrada, o produto não foi recebido fisicamente pelo vendedor.'; $factsUsed[]='physical_received_at';
    }
    if (sv_ar_fact_supported($dossier,'refund_initiator') && sv_ar_fact_value($dossier,'refund_initiator')==='AMAZON_AUTOMATIC') {
        $sentences[]='O dossiê financeiro registra reembolso automático da Amazon ao comprador.'; $factsUsed[]='refund_initiator';
    }
    if (sv_ar_fact_supported($dossier,'refund_at')) {
        $sentences[]='A data registrada do reembolso é '.(string)sv_ar_fact_value($dossier,'refund_at').'.'; $factsUsed[]='refund_at';
    }
    $waitDays=(int)($policy['wait_days'] ?? 0);
    if ($waitDays>0) $sentences[]="A elegibilidade foi calculada pelo motor de políticas vigente com janela de {$waitDays} dias, sem substituir a validação final do Seller Central.";
    foreach ($factsUsed as $field) foreach (($dossier['facts'][$field]['evidence'] ?? []) as $ref) $refs[]=(string)$ref;
    $refs=array_values(array_unique($refs));
    $confidence=$missing===[]?'HIGH':(count($missing)===1?'MEDIUM':'LOW');
    return ['text'=>implode(' ',$sentences),'facts'=>$factsUsed,'evidence_refs'=>$refs,'confidence'=>$confidence,'missing_evidence'=>array_values(array_unique($missing))];
}
