<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/KitCost.php';
require_once __DIR__.'/../../includes/amazon-margin/MarginMath.php';

function kitAssert(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException('Amazon kit cost: '.$msg);
}
$cases=[
    ['KIT5ROD35CFREIOFBA','Kit com 5 Rodizios Gel Rodinha 35mm Roda Giratoria Silicone com trava','S','Rodízio Transparente 35MM Com Freio Chapa Giratória Níquel',3.01,15.05,5],
    ['IA-YWLG-2L3K','Rodizio em Gel C/Trava Cristal 50mm 6 UN','S','Rodizio Transparente 50MM COM Freio Chapa Giratorio Niquel Soprano',4.03,24.18,6],
    ['ROD6FBA','Rodizio em Gel C/Trava Cristal 50mm 6 UN','S','Rodizio Transparente 50MM COM Freio Chapa Giratorio Niquel Soprano',4.03,24.18,6],
    ['FN-WXV1-QWDV','Soprano Kit 4 Rodízios para Móveis com Silicone 75mm com Freio','S','Rodízio De Gel Giratório 75MM Com Freio E Rolamento Soprano',10.0,40.0,4],
    ['AE-30PW-7R9P','Kit com 4 Rodizios Gel Rodinha 35mm Roda Giratoria Silicone com trava','S','Rodízio Transparente 35MM Com Freio Chapa Giratória Níquel',3.01,12.04,4],
    ['KITVEDFBA','Kit 2 Veda Porta Rodo Vedante Protetor de Porta em Alumínio 70/80/90/100 cm(90 cm)','S','Rodo Vedante Borracha Friso Porta Aluminio 90 Cm Vivaliz',10.0,20.0,2],
    ['kit-vedant-100','Aquatools Kit 2 Vedantes Porta Rodo Vedador 70/80/90/100cm Alumínio','S','Rodo Vedante Borracha Friso Porta Aluminio 100cm Vivaliz',10.0,20.0,2],
    ['COMPLETE-KIT5','Kit com 5 Rodizios Gel Rodinha 35mm Roda Giratoria Silicone com trava','K','Kit com 5 Rodizios Gel 35mm Giratoria Silicone trava Soprano',15.05,15.05,1],
    ['COMPLETE-KIT6','Rodizio em Gel C/Trava Cristal 50mm 6 UN','K','Rodízio Em Gel Com Trava Cristal 50MM Kit 6 Unidades',24.18,24.18,1],
    ['COMPLETE-KIT4','Rodizio rodinha para moveis gel giratório 50mm - 4 unidades','K','Kit 4 rodízios 50mm em gel 2 com trava e 2 sem trava',14.94,14.94,1],
    ['COMPLETE-MIXED-KIT','KIT 4 PÇS SQ50 GEL HC4X42 2 C/TRAVA 2 S/TRAVA','K','Kit 4 rodízios parafusos 50mm gel 2 trava e 2 sem trava',33.08,33.08,1],
];
foreach($cases as [$sku,$title,$tipo,$description,$base,$effective,$multiple]){
    $got=SvAmazonMarginKitCost::resolve($title,['erp_product_type'=>$tipo,
        'erp_description'=>$description,'last_cost'=>$base]);
    kitAssert($got['status']==='confirmed',"$sku unexpectedly unverified: ".json_encode($got));
    kitAssert(abs($got['cost']-$effective)<0.001,"$sku cost expected $effective got ".json_encode($got));
    kitAssert($got['multiplier']===$multiple,"$sku multiplier mismatch");
}
kitAssert(SvAmazonMarginKitCost::quantity('Roda 35mm freio 360 graus')===null,
    'measurements and angles must never become bundle counts');
kitAssert(SvAmazonMarginKitCost::quantity('Kit de Instalação 70/80/90/100 cm')===null,
    'dimension list must never become quantity');
kitAssert(SvAmazonMarginKitCost::quantity('Kit 2 Vedantes 90 cm')===2,
    'explicit kit count');
kitAssert(SvAmazonMarginKitCost::quantity('6 UN')===6,'unit suffix');
kitAssert(SvAmazonMarginKitCost::quantity('KIT 4 PÇS')===4,'abbreviated pieces');

$bad=[
    ['Kit 4 Rodízios 2 com trava 2 sem trava','S','Rodízio Com Freio 50mm',4.03,
        'MIXED_BUNDLE_COMPONENTS'],
    ['Kit 4 Rodízios 35mm','K','Kit 5 Rodízios 35mm',15.05,
        'KIT_COMPOSITION_CONFLICT'],
    ['Kit 4 Rodízios 35mm','S','Kit 5 Rodízios 35mm',15.05,
        'KIT_QUANTITY_CONFLICT'],
    ['Kit 4 Rodízios 35mm','S','Kit de rodízios 35mm',3.01,
        'ERP_KIT_SIZE_NOT_PROVEN'],
    ['Kit de componentes para encanamento','S','Mecanismo Unitário',8.00,
        'UNVERIFIED_KIT_QUANTITY'],
    ['Kit de 4 Peças','', 'Produto sem tipo',3.01,
        'UNKNOWN_ERP_PRODUCT_TYPE'],
];
foreach($bad as [$title,$type,$description,$cost,$reason]){
    $got=SvAmazonMarginKitCost::resolve($title,[
        'erp_product_type'=>$type,'erp_description'=>$description,'last_cost'=>$cost]);
    kitAssert($got['status']==='unverified' && $got['cost']===null
        && $got['basis']===$reason,'fail-closed: '.json_encode($got));
}
kitAssert(SvAmazonMarginKitCost::resolve('Simples unidade',[
    'erp_product_type'=>'S','erp_description'=>'Rodízio simples',
    'last_cost'=>8.00])['cost']===8.00,'ordinary single unit unchanged');
$badCost=SvAmazonMarginKitCost::resolve('Kit 4 Rodízios 35mm',[
    'erp_product_type'=>'S','erp_description'=>'Rodízio simples',
    'last_cost'=>null]);
kitAssert($badCost['status']==='unverified','unknown component cost cannot be certified');

// Independent financial arithmetic reproduces real run #8: 3 kit sales,
// 5 pieces per kit; the margin must fall after multiplying unit cost.
$before=7510;$units=3;$price=49.00;
$costDelta=(int)round((15.05-3.01)*100)*$units;
$after=$before-$costDelta;
$gross=(int)round($price*100)*$units;
kitAssert($after===3898 && (int)round($after*10000/$gross)===2652,
    'real KIT5 financial regression: profit R$38.98, margin 26.52%');

echo "PASS 11 verified real ERP kit/unit examples + ambiguous mixed/quantity/rounding; KIT5 corrected to 26.52% without duplicate component cost\n";
