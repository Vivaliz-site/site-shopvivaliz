<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/amazon-margin/HistoricalFulfillment.php';

function historical_assert(bool $condition,string $why):void {
    if (!$condition) throw new RuntimeException($why);
}
$fba=SvAmazonMarginHistoricalFulfillment::fromOrder([
    'order'=>['fulfillment'=>['fulfilledBy'=>'AMAZON'],'programs'=>[]],
]);
$dba=SvAmazonMarginHistoricalFulfillment::fromOrder([
    'order'=>['fulfillment'=>['fulfilledBy'=>'MERCHANT'],'programs'=>['DELIVERY_BY_AMAZON']],
]);
$notDba=SvAmazonMarginHistoricalFulfillment::fromOrder([
    'order'=>['fulfillment'=>['fulfilledBy'=>'MERCHANT'],'programs'=>[]],
]);
$unknown=SvAmazonMarginHistoricalFulfillment::fromOrder([
    'order'=>['programs'=>[]],
]);
$contradiction=SvAmazonMarginHistoricalFulfillment::fromOrder([
    'order'=>['fulfillment'=>['fulfilledBy'=>'AMAZON'],'programs'=>['DELIVERY_BY_AMAZON']],
]);
historical_assert($fba===['channel'=>'AMAZON','is_dba'=>false],
    'FBA Onsite Amazon Orders evidence');
historical_assert($dba===['channel'=>'MERCHANT','is_dba'=>true],
    'DBA evidence requires explicit program');
historical_assert(SvAmazonMarginHistoricalFulfillment::resolve([$fba,$fba])==='AMAZON',
    'Two consistent Amazon-fulfilled orders');
historical_assert(SvAmazonMarginHistoricalFulfillment::resolve([$dba,$dba])==='MERCHANT',
    'Two confirmed DBA orders');
foreach ([
    [], [$unknown], [$notDba], [$fba,$dba], [$fba,$unknown],
    [$dba,$notDba], [$contradiction], [['channel'=>'WRONG','is_dba'=>false]]
] as $orders) {
    historical_assert(SvAmazonMarginHistoricalFulfillment::resolve($orders)===null,
        'Unknown/mixed/contradictory historical orders may not be certified: '.
        json_encode($orders));
}
historical_assert(SvAmazonMarginHistoricalFulfillment::fromOrder([
    'payload'=>['fulfillment'=>['fulfilledBy'=>'AMAZON'],'programs'=>[]],
])===$fba,'Orders payload wrapper');
echo "PASS Amazon v2026 historical fulfillment: consistent Onsite/DBA and strict fail-closed\n";
