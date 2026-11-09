<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/tiny-price-owner-review.php';

function clickAssert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function clickThrows(callable $callback, string $expected): void {
    try { $callback(); } catch (Throwable $e) {
        clickAssert($e->getMessage() === $expected, 'Unexpected error: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $expected . ' to be rejected.');
}
$private = sys_get_temp_dir() . '/sv-price-click-' . bin2hex(random_bytes(7));
clickAssert(mkdir($private, 0700) === true, 'Private dir creation failed.');
putenv('SHOPVIVALIZ_PRICE_REVIEW_DIR=' . $private);
putenv('SHOPVIVALIZ_PRICE_OWNER_USER_ID=1010');

$before = ['id' => 39, 'sku' => 'SKU-CLICK-TEST', 'price' => 100.0,
    'prices' => ['cost_price' => 23.40, 'promotional_price' => 0.0]];
$after = $before;
$after['price'] = 110.50;
$after['prices']['cost_price'] = 0.0;

try {
    $req = svtpp_request_price_owner_review($before, $after, 202);
    clickAssert(is_array($req) && $req['cost_zero'], 'Zero-cost owner review not persisted.');
    $calls = [];
    $price = ['sale' => 100.0, 'promo' => 0.0];
    $fake = static function (string $verb, string $path, ?array $payload) use (&$calls, &$price): array {
        $calls[] = [$verb, $path, $payload];
        if ($verb === 'GET') {
            return ['status' => 200, 'json' => [
                'id' => 39, 'sku' => 'SKU-CLICK-TEST',
                'precos' => ['preco' => $price['sale'], 'precoPromocional' => $price['promo']],
            ]];
        }
        clickAssert($verb === 'PUT' && $path === '/produtos/39/preco',
            'Only official product price PUT is allowed.');
        clickAssert(array_keys($payload) === ['preco', 'precoPromocional'],
            'No product cost or other field may be sent by this approval.');
        $price = ['sale' => $payload['preco'], 'promo' => $payload['precoPromocional']];
        return ['status' => 200, 'json' => []];
    };
    clickThrows(
        static fn() => svtpp_apply_owner_approved_price($req['request_id'], 202, $fake),
        'PRICE_OWNER_IDENTITY_NOT_VERIFIED'
    );
    clickAssert($calls === [], 'Unauthorized user caused external request.');
    $ok = svtpp_apply_owner_approved_price($req['request_id'], 1010, $fake);
    clickAssert($ok['status'] === 'applied' && count($calls) === 3
        && $calls[0][0] === 'GET' && $calls[1][0] === 'PUT' && $calls[2][0] === 'GET',
        'Owner click must preflight, write exactly once and read back.');
    $again = svtpp_apply_owner_approved_price($req['request_id'], 1010, $fake);
    clickAssert($again['status'] === 'already_applied' && count($calls) === 3,
        'Repeat click must not send another PUT.');

    $stale = $after;
    $stale['price'] = 111.0;
    $pending = svtpp_request_price_owner_review($before, $stale, 202);
    $count = 0;
    $fakeStale = static function (string $verb, string $path, ?array $payload) use (&$count): array {
        $count++;
        clickAssert($verb === 'GET', 'Stale proposal must not send PUT.');
        return ['status' => 200, 'json' => [
            'id' => 39, 'sku' => 'SKU-CLICK-TEST',
            'precos' => ['preco' => 250, 'precoPromocional' => 0],
        ]];
    };
    clickThrows(
        static fn() => svtpp_apply_owner_approved_price($pending['request_id'], 1010, $fakeStale),
        'PRICE_REVIEW_STALE_UPSTREAM_PRICE'
    );
    clickAssert($count === 1, 'Stale proposal must perform read only.');

    $ambiguous = $after;
    $ambiguous['price'] = 109;
    $ambiguousRequest = svtpp_request_price_owner_review($before, $ambiguous, 202);
    $writes = 0;
    $transport = static function (string $verb, string $path, ?array $payload) use (&$writes): array {
        if ($verb === 'PUT') { $writes++; return ['status' => 0, 'json' => []]; }
        return ['status' => 200, 'json' => [
            'id' => 39, 'sku' => 'SKU-CLICK-TEST',
            'precos' => ['preco' => 100, 'precoPromocional' => 0],
        ]];
    };
    $first = svtpp_apply_owner_approved_price($ambiguousRequest['request_id'], 1010, $transport);
    clickAssert($first['status'] === 'readback_required' && $writes === 1,
        'Ambiguous external write must not be silently retried.');
    clickThrows(
        static fn() => svtpp_apply_owner_approved_price($ambiguousRequest['request_id'], 1010, $transport),
        'PRICE_REVIEW_REQUIRES_RECONCILIATION'
    );
    clickAssert($writes === 1, 'Second click replayed ambiguous PUT.');
    $needsReconciliation = array_values(array_filter(
        svtpp_pending_price_owner_reviews(),
        static fn(array $record): bool =>
            ($record['request_id'] ?? '') === $ambiguousRequest['request_id']
            && ($record['status'] ?? '') === 'readback_required'
    ));
    clickAssert(count($needsReconciliation) === 1,
        'Ambiguous external write must remain visible on the owner review page.');

    $onlyCost = $before;
    $onlyCost['prices']['cost_price'] = 0;
    $costRequest = svtpp_request_price_owner_review($before, $onlyCost, 202);
    clickThrows(
        static fn() => svtpp_apply_owner_approved_price($costRequest['request_id'], 1010, $fake),
        'PRICE_REVIEW_NO_PRICE_CHANGE'
    );
    clickAssert(count($calls) === 3, 'Cost-only changes may not write product price.');
    echo "PASS: explicit owner click writes once; zero cost supported; stale/ambiguous/duplicate blocked.\n";
} finally {
    putenv('SHOPVIVALIZ_PRICE_REVIEW_DIR');
    putenv('SHOPVIVALIZ_PRICE_OWNER_USER_ID');
    foreach (glob($private . '/price-*.json') ?: [] as $file) @unlink($file);
    @rmdir($private);
}
