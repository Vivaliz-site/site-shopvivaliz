<?php

declare(strict_types=1);

/**
 * Read/ingestion facade for Amazon Returns recovery.
 *
 * There is intentionally no SAFE-T filing/appeal operation here. Amazon does
 * not expose a verified public SP-API operation for those workflows.
 */
final class SvAmazonReturnsSpApi
{
    private object $client;

    public function __construct(?object $client = null)
    {
        if ($client === null) {
            require_once __DIR__ . '/../marketplace/AmazonPublisher.php';
            $client = new SvAmazonClient();
        }
        foreach (['request', 'marketplaceId'] as $method) {
            if (!method_exists($client, $method)) {
                throw new InvalidArgumentException('Amazon transport missing method: ' . $method);
            }
        }
        $this->client = $client;
    }

    /** @return array<string,mixed> */
    public function syncOrder(string $amazonOrderId): array
    {
        $orderId = self::requiredId($amazonOrderId, 'Amazon order ID');
        $response = $this->client->request(
            'GET',
            '/orders/2026-01-01/orders/' . rawurlencode($orderId),
            ['includedData' => 'FULFILLMENT,PROCEEDS,EXPENSE,PACKAGES']
        );
        self::assertSuccess($response, 'Orders getOrder');
        $data = self::responseData($response);
        $order = self::firstArray($data, ['payload', 'order']) ?? $data;
        $normalizedOrderId = trim((string)($order['orderId'] ?? $order['amazonOrderId'] ?? $orderId));
        $salesChannel = is_array($order['salesChannel'] ?? null) ? $order['salesChannel'] : [];
        $items = is_array($order['orderItems'] ?? null) ? array_values(array_filter($order['orderItems'], 'is_array')) : [];
        $programs = is_array($order['programs'] ?? null) ? array_values(array_map('strval', $order['programs'])) : [];

        return [
            'source' => 'SP_API_ORDERS',
            'request_id' => (string)($response['request_id'] ?? ''),
            'order_id' => $normalizedOrderId,
            'marketplace_id' => trim((string)($salesChannel['marketplaceId'] ?? $order['marketplaceId'] ?? '')),
            'created_at' => self::nullableString($order['createdTime'] ?? $order['purchaseDate'] ?? null),
            'last_updated_at' => self::nullableString($order['lastUpdatedTime'] ?? $order['lastUpdateDate'] ?? null),
            'programs' => $programs,
            'fulfillment' => is_array($order['fulfillment'] ?? null) ? $order['fulfillment'] : [],
            'packages' => is_array($order['packages'] ?? null) ? array_values($order['packages']) : [],
            'order_items' => $items,
        ];
    }

    /** @return array{source:string,financial_truth:bool,request_ids:list<string>,transactions:list<array<string,mixed>>} */
    public function listTransactions(string $amazonOrderId): array
    {
        $orderId = self::requiredId($amazonOrderId, 'Amazon order ID');
        $query = [
            'relatedIdentifierName' => 'ORDER_ID',
            'relatedIdentifierValue' => $orderId,
            'marketplaceId' => (string)$this->client->marketplaceId(),
        ];
        $transactions = [];
        $requestIds = [];
        $nextToken = null;
        $pages = 0;

        do {
            $requestQuery = $query;
            if (is_string($nextToken) && $nextToken !== '') {
                $requestQuery['nextToken'] = $nextToken;
            }
            $response = $this->client->request('GET', '/finances/2024-06-19/transactions', $requestQuery);
            self::assertSuccess($response, 'Finances listTransactions');
            $requestId = trim((string)($response['request_id'] ?? ''));
            if ($requestId !== '') $requestIds[] = $requestId;
            $data = self::responseData($response);
            $payload = self::firstArray($data, ['payload']) ?? $data;
            $pageTransactions = is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [];
            foreach ($pageTransactions as $transaction) {
                if (is_array($transaction)) $transactions[] = self::normalizeTransaction($transaction);
            }
            $nextToken = self::nullableString($payload['nextToken'] ?? $data['nextToken'] ?? null);
            $pages++;
            if ($pages >= 50 && $nextToken !== null) {
                throw new RuntimeException('Finances pagination exceeded safety limit.');
            }
        } while ($nextToken !== null && $nextToken !== '');

        return [
            'source' => 'SP_API_FINANCES',
            'financial_truth' => true,
            'request_ids' => $requestIds,
            'transactions' => $transactions,
        ];
    }

    /** @return array{source:string,request_id:string,report_id:string,report_type:string} */
    public function requestReturnsReport(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromUtc = $from->setTimezone(new DateTimeZone('UTC'));
        $toUtc = $to->setTimezone(new DateTimeZone('UTC'));
        if ($toUtc <= $fromUtc) throw new InvalidArgumentException('Returns report end must be after start.');

        $body = [
            'reportType' => 'GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE',
            'dataStartTime' => $fromUtc->format('Y-m-d\TH:i:s\Z'),
            'dataEndTime' => $toUtc->format('Y-m-d\TH:i:s\Z'),
            'marketplaceIds' => [(string)$this->client->marketplaceId()],
        ];
        $response = $this->client->request('POST', '/reports/2021-06-30/reports', [], $body);
        self::assertSuccess($response, 'Reports createReport');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        $reportId = trim((string)($payload['reportId'] ?? ''));
        if ($reportId === '') throw new RuntimeException('Amazon Reports did not return reportId.');

        return [
            'source' => 'SP_API_REPORTS',
            'request_id' => (string)($response['request_id'] ?? ''),
            'report_id' => $reportId,
            'report_type' => $body['reportType'],
        ];
    }

    /** @return array<string,mixed> */
    public function consumeTransactionUpdate(array $notification): array
    {
        $type = strtoupper(trim((string)($notification['notificationType'] ?? $notification['NotificationType'] ?? '')));
        if ($type !== 'TRANSACTION_UPDATE') {
            throw new InvalidArgumentException('Expected TRANSACTION_UPDATE notification.');
        }
        $payload = is_array($notification['payload'] ?? null) ? $notification['payload'] : [];
        $transaction = is_array($payload['transaction'] ?? null)
            ? $payload['transaction']
            : (is_array($payload['Transaction'] ?? null) ? $payload['Transaction'] : $payload);
        $normalized = self::normalizeTransaction($transaction);

        return [
            'source' => 'SP_API_TRANSACTION_UPDATE',
            'source_event_id' => trim((string)($notification['notificationId'] ?? $notification['notificationMetadata']['notificationId'] ?? '')),
            'occurred_at' => self::nullableString($notification['eventTime'] ?? $notification['EventTime'] ?? null),
            'transaction_id' => $normalized['transaction_id'],
            'transaction_type' => $normalized['transaction_type'],
            'order_id' => $normalized['order_id'],
            'transaction' => $normalized,
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeTransaction(array $transaction): array
    {
        $orderId = '';
        $related = $transaction['relatedIdentifiers'] ?? $transaction['relatedIdentifier'] ?? [];
        if (is_array($related)) {
            foreach ($related as $identifier) {
                if (!is_array($identifier)) continue;
                $name = strtoupper(trim((string)($identifier['name'] ?? $identifier['type'] ?? '')));
                if ($name === 'ORDER_ID') {
                    $orderId = trim((string)($identifier['value'] ?? $identifier['id'] ?? ''));
                    break;
                }
            }
        }
        return [
            'transaction_id' => trim((string)($transaction['transactionId'] ?? $transaction['id'] ?? '')),
            'transaction_type' => trim((string)($transaction['transactionType'] ?? $transaction['type'] ?? '')),
            'transaction_status' => self::nullableString($transaction['transactionStatus'] ?? $transaction['status'] ?? null),
            'posted_at' => self::nullableString($transaction['postedDate'] ?? $transaction['postedTime'] ?? null),
            'order_id' => $orderId,
            'total_amount' => self::moneyOnly($transaction['totalAmount'] ?? $transaction['amount'] ?? null),
        ];
    }

    /** @return array{amount:string,currency:string}|null */
    private static function moneyOnly(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $amount = trim((string)($value['amount'] ?? $value['currencyAmount'] ?? ''));
        $currency = trim((string)($value['currencyCode'] ?? $value['currency'] ?? ''));
        if ($amount === '' && $currency === '') return null;
        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function requiredId(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191) throw new InvalidArgumentException($label . ' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $response */
    private static function assertSuccess(array $response, string $operation): void
    {
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($operation . ' failed with HTTP ' . $status . '.');
        }
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private static function responseData(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /** @param array<string,mixed> $data @param list<string> $keys @return array<string,mixed>|null */
    private static function firstArray(array $data, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (is_array($data[$key] ?? null)) return $data[$key];
        }
        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
