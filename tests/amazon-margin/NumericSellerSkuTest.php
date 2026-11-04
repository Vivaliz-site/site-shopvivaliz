<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/amazon-margin/AuditCollector.php';

$cases=[
    [123456789,'123456789'],
    ['000123','000123'],
    ['A21','A21'],
    ['1C7Q-LKVS-KOPL','1C7Q-LKVS-KOPL'],
    [str_repeat('9',100),str_repeat('9',80)],
];
foreach($cases as [$input,$expected]){
    $got=SvAmazonMarginCollector::skuExcerpt($input);
    if($got!==$expected)throw new RuntimeException('Seller SKU warning excerpt changed');
}
echo "PASS margin collector: integer/string Seller SKUs and bounded warning excerpts\n";
