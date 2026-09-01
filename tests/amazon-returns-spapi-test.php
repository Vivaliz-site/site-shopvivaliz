<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApi.php';

function spAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function spSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

final class AmazonReturnsFakeClient {
    public array $calls = [];
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method, string $path, array $query = [], ?array $body = null): array {
        $this->calls[] = compact('method', 'path', 'query', 'body');
        if (str_starts_with($path, '/orders/2026-01-01/orders/')) {
            return ['status'=>200,'request_id'=>'req-order-1','data'=>['orderId'=>'702-1234567-1234567','salesChannel'=>['marketplaceId'=>'A2Q3Y263D00KWC'],'programs'=>['DELIVERY_BY_AMAZON'],'orderItems'=>[['orderItemId'=>'item-1','sellerSku'=>'SKU-1','quantityOrdered'=>2]]]];
        }
        if ($path === '/finances/2024-06-19/transactions') {
            return ['status'=>200,'request_id'=>'req-fin-1','data'=>['transactions'=>[['transactionId'=>'txn-1','transactionType'=>'Refund','postedDate'=>'2026-08-01T12:00:00Z','relatedIdentifiers'=>[['name'=>'ORDER_ID','value'=>'702-1234567-1234567']]]]]];
        }
        if ($path === '/reports/2021-06-30/reports') {
            return ['status'=>202,'request_id'=>'req-report-1','data'=>['reportId'=>'RPT-1']];
        }
        throw new RuntimeException('Unexpected fake request: ' . $method . ' ' . $path);
    }
}

$client = new AmazonReturnsFakeClient();
$api = new SvAmazonReturnsSpApi($client);
$order = $api->syncOrder('702-1234567-1234567');
spSame('/orders/2026-01-01/orders/702-1234567-1234567', $client->calls[0]['path'], 'Orders v2026-01-01 path required.');
spSame('req-order-1', $order['request_id'], 'Order request ID must be retained.');
spSame('702-1234567-1234567', $order['order_id'], 'Order ID must normalize.');
spSame(['DELIVERY_BY_AMAZON'], $order['programs'], 'Order programs must normalize.');

$fin = $api->listTransactions('702-1234567-1234567');
spSame('/finances/2024-06-19/transactions', $client->calls[1]['path'], 'Finances v2024-06-19 path required.');
spSame('ORDER_ID', $client->calls[1]['query']['relatedIdentifierName'] ?? null, 'Finances must filter by ORDER_ID.');
spSame('702-1234567-1234567', $client->calls[1]['query']['relatedIdentifierValue'] ?? null, 'Finances must filter by exact order ID.');
spSame(true, $fin['financial_truth'], 'SP-API Finances must be marked financial truth.');
spSame(['req-fin-1'], $fin['request_ids'], 'Financial request IDs must be retained.');

$report = $api->requestReturnsReport(new DateTimeImmutable('2026-08-01T00:00:00Z'), new DateTimeImmutable('2026-09-01T00:00:00Z'));
spSame('/reports/2021-06-30/reports', $client->calls[2]['path'], 'Reports API v2021-06-30 path required.');
spSame('GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE', $client->calls[2]['body']['reportType'] ?? null, 'Official MFN returns report type required.');
spSame(['A2Q3Y263D00KWC'], $client->calls[2]['body']['marketplaceIds'] ?? null, 'Report must target current marketplace.');
spSame('RPT-1', $report['report_id'], 'Report ID must normalize.');
spSame('req-report-1', $report['request_id'], 'Report request ID must be retained.');

$notification = $api->consumeTransactionUpdate([
    'notificationType' => 'TRANSACTION_UPDATE',
    'notificationId' => 'notif-1',
    'eventTime' => '2026-09-01T12:00:00Z',
    'payload' => ['transaction' => ['transactionId'=>'txn-9','transactionType'=>'Refund','relatedIdentifiers'=>[['name'=>'ORDER_ID','value'=>'702-1234567-1234567']]]],
]);
spSame('SP_API_TRANSACTION_UPDATE', $notification['source'], 'Notification source must normalize.');
spSame('notif-1', $notification['source_event_id'], 'Notification ID must be retained.');
spSame('702-1234567-1234567', $notification['order_id'], 'ORDER_ID must be extracted from related identifiers.');
spSame('txn-9', $notification['transaction_id'], 'Transaction ID must normalize.');

$methods = array_map(static fn(ReflectionMethod $m): string => strtolower($m->getName()), (new ReflectionClass(SvAmazonReturnsSpApi::class))->getMethods(ReflectionMethod::IS_PUBLIC));
foreach ($methods as $method) {
    spAssert(!str_contains($method, 'safe') && !str_contains($method, 'claim') && !str_contains($method, 'appeal'), 'SP-API facade must not expose invented SAFE-T/claim/appeal endpoints.');
}
$serialized = json_encode([$order,$fin,$report,$notification], JSON_THROW_ON_ERROR);
foreach (['access_token','refresh_token','client_secret','x-amz-access-token'] as $secretKey) {
    spAssert(!str_contains(strtolower($serialized), $secretKey), 'Normalized outputs must not expose secrets: ' . $secretKey);
}

echo "amazon-returns-spapi-test: OK\n";
