<?php
declare(strict_types=1);
// The ERP may contain a real association even when its product identity
// cannot be proven from the Amazon and Olist records the auditor has read.
$page=(string)file_get_contents(__DIR__.'/../../admin/amazon-margin.php');
$expected='<option value="unmapped">Vínculo ERP não identificado</option>';
if (!str_contains($page,$expected)) {
    throw new RuntimeException('unmapped filter needs evidence-based wording');
}
if (str_contains($page,'<option value="unmapped">Sem vínculo ERP</option>')) {
    throw new RuntimeException('never claim a product has no ERP link');
}
if (!str_contains($page,'Produto relacionado ainda não identificado pela auditoria')) {
    throw new RuntimeException('cost display must not claim absence of ERP product');
}
if (!str_contains($page,'Produto ERP não identificado por EAN, SKU, pedido Amazon ou exportação atual Olist')) {
    throw new RuntimeException('diagnostics must cover all supported mapping sources');
}
echo "PASS unmapped ERP is an audit evidence gap, not absence of an Olist linked product\n";
