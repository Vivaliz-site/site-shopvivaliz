<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/KitCost.php';

function bomAssert(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('Olist kit BOM: '.$message);
}
$components=[
    ['id'=>344671209,'quantity'=>2],
    ['id'=>344670767,'quantity'=>2],
];
$costs=[
    344671209=>['active'=>true,'erp_product_type'=>'S','last_cost'=>4.03],
    344670767=>['active'=>true,'erp_product_type'=>'S','last_cost'=>3.44],
];
$load=static fn(int $id):array=>$costs[$id]??[];
$good=SvAmazonMarginKitCost::checkBillOfMaterials(343577587,$components,14.94,$load);
bomAssert($good['status']==='confirmed','two with brakes plus two without = 14.94');
bomAssert($good['sum_cost']===14.94 && $good['total_units']===4,'exact cent arithmetic');
$other=SvAmazonMarginKitCost::checkBillOfMaterials(353697603,$components,33.08,$load);
bomAssert($other['status']==='unverified','identical BOM with 33.08 history MUST NOT certify');
bomAssert($other['sum_cost']===14.94,'discrepant historical value must disclose live BOM');
bomAssert($other['reason']==='kit_registered_cost_disagrees_with_component_costs',
    'historical/actual discrepancy classified distinctly');
bomAssert($good['sum_cost']!==33.08,'never trust a historical K price as its live BOM');
$component5=[['id'=>342902653,'quantity'=>5]];
$load5=static fn(int $id):array=>['active'=>true,'erp_product_type'=>'S','last_cost'=>3.01];
$five=SvAmazonMarginKitCost::checkBillOfMaterials(342902707,$component5,15.05,$load5);
bomAssert($five['status']==='confirmed' && $five['sum_cost']===15.05,
    'existing Olist 5-piece K product consistent');
$six=SvAmazonMarginKitCost::checkBillOfMaterials(342849898,
    [['id'=>344671209,'quantity'=>6]],24.18,$load);
bomAssert($six['status']==='confirmed' && $six['total_units']===6,
    'existing Olist 6-piece K product consistent');
$bad=[
    ['empty',[],14.94,'missing_kit_bill_of_materials_or_registered_cost'],
    ['duplicate',[$components[0],$components[0]],8.06,'duplicate_kit_component_id'],
    ['fractional', [['id'=>344671209,'quantity'=>1.5]],6.05,
        'invalid_kit_component_identity_or_quantity'],
    ['self', [['id'=>353697603,'quantity'=>1]],4.03,
        'invalid_kit_component_identity_or_quantity'],
    ['missing', [['id'=>344670767,'quantity'=>2]],null,
        'missing_kit_bill_of_materials_or_registered_cost'],
];
foreach($bad as [$name,$parts,$registered,$reason]) {
    $result=SvAmazonMarginKitCost::checkBillOfMaterials(353697603,$parts,
        (float)$registered,$load);
    bomAssert($result['status']==='unverified'&&$result['reason']===$reason,
        $name.' fails closed '.json_encode($result));
}
$notActive=static fn(int $id):array=>['active'=>false,'erp_product_type'=>'S',
    'last_cost'=>3.01];
bomAssert(SvAmazonMarginKitCost::checkBillOfMaterials(11,
    [['id'=>12,'quantity'=>2]],6.02,$notActive)['reason']
    ==='kit_component_inactive_nested_or_cost_missing','inactive component rejected');
$nested=static fn(int $id):array=>['active'=>true,'erp_product_type'=>'K',
    'last_cost'=>15.05];
bomAssert(SvAmazonMarginKitCost::checkBillOfMaterials(11,
    [['id'=>12,'quantity'=>1]],15.05,$nested)['status']
    ==='unverified','nested kit not assumed proven');

echo "PASS live Olist identical BOM discrepancy: 14.94 vs registered 33.08 held; valid 5/6-piece kits and malformed BOM fail-closed\n";
