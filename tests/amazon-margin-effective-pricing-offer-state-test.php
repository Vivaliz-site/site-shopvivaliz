<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-margin/AuditCollector.php';

/**
 * The SP-API Pricing response may return HTTP 200, status=Success and
 * Product.Offers=[]: that is NOT a verified current sale price.
 * No network requests or commercial writes in this regression.
 */
$cases = [
    'effective_fba_offer' => [
        ['status' => 'Success', 'Product' => ['Offers' => [[
            'BuyingPrice' => ['ListingPrice' => ['Amount' => 295.13]],
            'FulfillmentChannel' => 'AMAZON',
        ]]]],
        ['price' => 295.13, 'price_source' => 'AMAZON_SPAPI_PRICING',
         'price_state' => 'EFFECTIVE_OFFER', 'fulfillment_channel' => 'AMAZON'],
    ],
    'success_but_no_offer' => [
        ['status' => 'Success', 'Product' => ['Offers' => []]],
        ['price' => null, 'price_source' => 'UNVERIFIED',
         'price_state' => 'PRICING_OFFERS_EMPTY', 'fulfillment_channel' => 'UNKNOWN'],
    ],
    'missing_offers_array' => [
        ['status' => 'Success', 'Product' => []],
        ['price' => null, 'price_source' => 'UNVERIFIED',
         'price_state' => 'PRICING_OFFERS_EMPTY', 'fulfillment_channel' => 'UNKNOWN'],
    ],
    'provider_item_error' => [
        ['status' => 'Error', 'Error' => ['Code' => 'InvalidInput']],
        ['price' => null, 'price_source' => 'UNVERIFIED',
         'price_state' => 'PROVIDER_ITEM_ERROR', 'fulfillment_channel' => 'UNKNOWN'],
    ],
    'offer_without_valid_price' => [
        ['status' => 'Success', 'Product' => ['Offers' => [[
            'BuyingPrice' => ['ListingPrice' => ['Amount' => 0]],
            'FulfillmentChannel' => 'AMAZON',
        ]]]],
        ['price' => null, 'price_source' => 'UNVERIFIED',
         'price_state' => 'INVALID_OFFER_PRICE', 'fulfillment_channel' => 'AMAZON'],
    ],
];

foreach ($cases as $name => [$record, $expected]) {
    $actual = SvAmazonMarginCollector::effectivePriceState($record);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$name}: " . json_encode($actual) . PHP_EOL);
        exit(1);
    }
}

echo "PASS: distinct Pricing offer states without false effective-price certification\n";
