<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/CurrentProductLink.php';
require_once __DIR__.'/../../includes/amazon-margin/CurrentPriceMargin.php';
require_once __DIR__.'/../../includes/amazon-margin/ReportExport.php';

function checkLink(bool $ok,string $name):void {
    if(!$ok)throw new RuntimeException('current_product_link_'.$name);
}
$link=[
    'status'=>'mapped','method'=>'OLIST_AUTHENTICATED_PRODUCT_TAB_UI',
    'id'=>342902707,'erp_sku'=>'KIT-5','integration'=>'Amazon Onsite',
    'observed_at'=>'2026-11-12T15:00:00Z',
];
$listing=[
    'seller_sku'=>'KIT5ROD35CFREIOFBA','title'=>'Kit com 5 Rodizios 35 mm',
    'fulfillment_channel'=>'AMAZON','amazon_status'=>['BUYABLE'],
    'price'=>49,'program'=>'FBA_ONSITE','status'=>'ambiguous_erp',
    'last_cost'=>null,'margin_weighted_bps'=>null,
    'amazon_package'=>['weight_kg'=>0.25,'length_cm'=>10,'width_cm'=>10,'height_cm'=>10],
];
$product=[
    'erp_id'=>342902707,'erp_sku'=>'KIT-5','active'=>true,
    'erp_product_type'=>'K','erp_description'=>'Kit 5 Rodizios',
    'last_cost'=>15.05,'last_cost_date'=>'2026-10-26',
    'erp_package'=>['weight_kg'=>0.25,'length_cm'=>10,'width_cm'=>10,'height_cm'=>10],
    'erp_kit_components'=>[['id'=>100,'quantity'=>5]],
];
$readPart=static fn(int $id):array=>[
    'erp_id'=>$id,'erp_product_type'=>'S','active'=>true,'last_cost'=>3.01
];
$ref=SvAmazonMarginCurrentProductLink::reference($listing,$link,$product,$readPart);
checkLink(($ref['status']??'')==='offer_cost_verified','matched_kit_cost');
checkLink(($ref['recorded_cost']??null)===15.05
    && ($ref['offer_cost']??null)===15.05,'registered_vs_sold_unit');
checkLink(($ref['program']??'')==='FBA_ONSITE','program_from_onsite');
checkLink(($ref['source']??'')==='OLIST_AUTHENTICATED_PRODUCT_TAB_UI_X_CURRENT_OLIST_V3','source');
checkLink(($ref['recorded_cost_date']??'')==='2026-10-26','cost_date');
checkLink(SvAmazonMarginCurrentProductLink::reference($listing,
    array_replace($link,['id'=>999]),$product,$readPart)===null,'id_mismatch');
checkLink(SvAmazonMarginCurrentProductLink::reference($listing,
    array_replace($link,['status'=>'historical']),$product,$readPart)===null,'expired_not_current');

$old=$listing;
$old['current_product_link_reference']=$ref;
$projected=SvAmazonMarginCurrentProductLink::projectionInput($old);
checkLink($old['last_cost']===null && $old['margin_weighted_bps']===null,
    'historical_identity_not_mutated');
checkLink($projected['last_cost']===15.05
    && $projected['status']==='ambiguous_erp','current_projection_separated');
$fees=['commission_cents'=>735,'price_cents'=>4900];
$margin=SvAmazonCurrentPriceMargin::calculate($projected,$fees,1400);
checkLink(($margin['current_profit_cents']??null)===1324,'projection_profit_cents');
checkLink(($margin['current_margin_status']??'')==='estimated','current_estimate_only');

$badBOM=SvAmazonMarginCurrentProductLink::reference($listing,$link,$product,
    static fn(int $id):array=>array_replace($readPart($id),['last_cost'=>3.02]));
// Owner decision 14/11/2026: the registered ERP kit cost is authoritative;
// the BOM mismatch stays visible as a diagnostic but no longer blocks the cost.
checkLink(($badBOM['status']??'')==='offer_cost_verified'
    && ($badBOM['recorded_cost']??null)===15.05
    && ($badBOM['offer_cost']??null)===15.05
    && ($badBOM['reason']??'')==='kit_registered_cost_disagrees_with_component_costs',
    'erp_kit_cost_authoritative_with_bom_diagnostic');
$verifiedFromErpKit=SvAmazonMarginCurrentProductLink::projectionInput(
    array_replace($old,['current_product_link_reference'=>$badBOM]));
checkLink($verifiedFromErpKit['last_cost']===15.05,'erp_kit_cost_projects_directly');

$simple=array_replace($product,[
    'erp_product_type'=>'S','erp_description'=>'Rodizio 35 mm',
    'erp_kit_components'=>[],'last_cost'=>3.01
]);
$multiplied=SvAmazonMarginCurrentProductLink::reference($listing,$link,$simple,$readPart);
checkLink(($multiplied['offer_cost']??null)===15.05
    && ($multiplied['kit_cost_multiplier']??null)===5,'simple_component_times_five');
$noHistory=SvAmazonMarginCurrentProductLink::reference($listing,$link,
    array_replace($product,['last_cost'=>null]),$readPart);
checkLink(($noHistory['status']??'')==='cost_history_missing'
    && ($noHistory['recorded_cost']??null)===null,'actual_history_absent');

$report=SvAmazonMarginExport::rows(['rows'=>[
    $old + ['current_margin_cost_used'=>15.05,
        'current_profit_cents'=>1324,
        'current_margin_bps'=>$margin['current_margin_bps'],
        'current_margin_status'=>$margin['current_margin_status']]
]],[]);
checkLink(count($report)===1,'report_row');
checkLink($report[0]['Custo ERP por conjunto vendido (R$)']===null
    && $report[0]['Custo registrado no produto ERP atual (R$)']===15.05
    && $report[0]['Custo atual por conjunto vinculado (R$)']===15.05,
    'historical_cost_not_represented_as_confirmed');
checkLink($report[0]['Margem atual calculada com custo do vinculo']==='Sim'
    && $report[0]['Situacao']==='ambiguous_erp','report_preserves_historical_status');
$pdf=SvAmazonMarginExport::pdf($report,'2026-11-12');
checkLink(str_contains($pdf,'(15.05~) Tj ET'),'pdf_marks_current_not_historical');
$xlsx=SvAmazonMarginExport::xlsx($report);
checkLink(str_contains($xlsx,'PK'),'xlsx_binary_zip');
echo "PASS Amazon current ERP product-tab cost separated from historical identity and exports\n";
