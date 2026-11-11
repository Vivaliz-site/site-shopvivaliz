<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/scripts/finance/site-pending-gateway-readonly.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$base = [
    'id' => 123456,
    'status' => 'approved',
    'external_reference' => 'SV-123',
    'transaction_amount' => 35.02,
    'live_mode' => true,
    'currency_id' => 'BRL',
    'transaction_amount_refunded' => 0,
    'refunds' => [],
];
check(svpr_payment_class($base, 'SV-123', 35.02) === 'approved_verified', 'valid approved');
foreach ([
    ['external_reference' => 'DIFFERENT'],
    ['transaction_amount' => 34.00],
    ['live_mode' => false],
    ['currency_id' => 'USD'],
    ['transaction_amount_refunded' => 0.01],
    ['refunds' => [['id' => 7]]],
] as $variation) {
    check(svpr_payment_class(array_replace($base, $variation), 'SV-123', 35.02) === 'approved_unverified', 'reject invalid approved');
}
check(svpr_payment_class(array_replace($base, ['status' => 'rejected']), 'SV-123', 35.02) === 'rejected_or_refunded', 'rejected');
$rows = [
    ['order_number' => 'SV-123', 'order_total' => 35.02, 'payment_method' => 'mercado_pago'],
    ['order_number' => 'SV-456', 'order_total' => 11.00, 'payment_method' => 'boleto'],
    ['order_number' => 'SV-789', 'order_total' => 9.00, 'payment_method' => 'whatsapp'],
];
$search = static fn(string $ref): array => $ref === 'SV-123'
    ? ['results' => [['id' => '123456']], 'paging' => ['total' => 1]]
    : ['results' => [], 'paging' => ['total' => 0]];
$detail = static fn(string $id): array => $base;
$r = svpr_inspect_rows($rows, $search, $detail);
check($r['status'] === 'APPROVED_PAYMENT_REQUIRES_RECONCILIATION', 'must detect approved despite pending MySQL');
check($r['counts']['approved_verified'] === 1 && $r['counts']['outside_gateway_scope'] === 1, 'scoped counts');
$r = svpr_inspect_rows($rows, static fn(string $ref): array => ['results' => []], $detail);
check($r['status'] === 'NO_APPROVED_PAYMENT_IN_SCOPED_REFERENCES', 'zero provider results');
check($r['coverage_limited'] === true, 'manual payment outside scope');
$r = svpr_inspect_rows([$rows[0]], static fn(string $ref): array => ['results' => [['id' => '123']], 'paging' => ['total' => 2]], $detail);
check($r['status'] === 'INCOMPLETE_NOT_CERTIFIED', 'partial paging must not pass');
$r = svpr_inspect_rows([$rows[0]], static function(): array { throw new RuntimeException('private'); }, $detail);
check($r['counts']['search_errors'] === 1 && $r['status'] === 'INCOMPLETE_NOT_CERTIFIED', 'API failure fail-incomplete');
echo "PASS 13 gateway-reference assertions\n";
