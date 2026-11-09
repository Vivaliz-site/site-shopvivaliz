<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/tiny-product-push.php';

function priceCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$before = ['id' => 101, 'sku' => 'TEST-PRICE-1', 'name' => 'Teste',
    'price' => 100.0, 'prices' => ['cost_price' => 33.0, 'promotional_price' => 0.0]];
$zeroCost = $before;
$zeroCost['price'] = 105.0;
$zeroCost['prices']['cost_price'] = 0.0;

$goodCost = $before;
$goodCost['price'] = 105.0;

$zeroPush = svtpp_push_product_update(101, $zeroCost);
$goodPush = svtpp_push_product_update(101, $goodCost);
priceCheck($zeroPush['ok'] === false && $zeroPush['status'] === 0
    && str_contains($zeroPush['error'], 'custo_zerado_ou_ausente'),
    'Zero cost must be queued for owner review, not sent to ERP.');
priceCheck($goodPush['ok'] === false && $goodPush['status'] === 0
    && str_contains($goodPush['error'], 'proprietario'),
    'Positive cost must NOT bypass explicit owner price approval.');
priceCheck(!array_key_exists('precos', svtpp_build_payload(
    ['sku' => 'TEST-PRICE-1', 'name' => 'Teste', 'notes' => 'Editorial']
)), 'Editorial payload must not contain a price subobject.');

$testDir = sys_get_temp_dir() . '/sv-owner-price-' . bin2hex(random_bytes(6));
priceCheck(mkdir($testDir, 0700) === true, 'Could not create isolated private test directory.');
putenv('SHOPVIVALIZ_PRICE_REVIEW_DIR=' . $testDir);
try {
    priceCheck(svtpp_request_price_owner_review($before, $before, 1) === null,
        'No commercial change must not create a proposal.');
    $first = svtpp_request_price_owner_review($before, $zeroCost, 1);
    priceCheck(is_array($first) && $first['cost_zero'] === true,
        'Zero-cost change must create a pending owner request.');
    $file = $testDir . '/price-' . $first['request_id'] . '.json';
    $data = json_decode((string)file_get_contents($file), true);
    priceCheck(is_array($data) && $data['status'] === 'pending_owner_review'
        && $data['before']['sale_price'] === '100.00'
        && $data['proposed']['sale_price'] === '105.00'
        && $data['proposed']['cost_price'] === '0.00',
        'Review request must contain exact old/new prices and cost.');
    $second = svtpp_request_price_owner_review($before, $zeroCost, 1);
    priceCheck($first === $second, 'Identical proposals must be idempotent.');

    $positive = svtpp_request_price_owner_review($before, $goodCost, 1);
    priceCheck(is_array($positive) && $positive['cost_zero'] === false
        && $positive['request_id'] !== $first['request_id'],
        'Positive-cost commercial price change also requires owner review.');
    priceCheck(count(svtpp_pending_price_owner_reviews()) === 2,
        'Owner review screen must list both requests.');
    putenv('SHOPVIVALIZ_PRICE_REVIEW_DIR=' . dirname(__DIR__));
    try {
        svtpp_request_price_owner_review($before, $goodCost, 1);
        throw new RuntimeException('Private review must refuse webroot storage.');
    } catch (RuntimeException $e) {
        priceCheck($e->getMessage() === 'PRICE_REVIEW_PRIVATE_DIRECTORY_INVALID',
            'Unexpected review directory rejection: ' . $e->getMessage());
    }
} finally {
    putenv('SHOPVIVALIZ_PRICE_REVIEW_DIR');
    foreach (glob($testDir . '/price-*.json') ?: [] as $file) @unlink($file);
    @rmdir($testDir);
}
echo "PASS: any price change requires private pending owner review; zero cost allowed for review, no ERP write.\n";
