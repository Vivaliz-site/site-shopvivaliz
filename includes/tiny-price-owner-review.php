<?php
declare(strict_types=1);

/**
 * Durable commercial proposals, NEVER a price-write authorization token.
 * A proposal does not change the catalog or Tiny. The owner reviews it and
 * applies the price separately through the official authenticated ERP flow.
 * Keep this data outside the document root: it includes confidential costs.
 */
function svtpp_price_snapshot(array $product): array
{
    $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
    return [
        'sale_price' => number_format((float)($product['price'] ?? 0), 2, '.', ''),
        'promotional_price' => number_format((float)($prices['promotional_price'] ?? 0), 2, '.', ''),
        'cost_price' => number_format((float)($prices['cost_price'] ?? 0), 2, '.', ''),
    ];
}

function svtpp_price_owner_review_dir(): string
{
    $requested = trim((string)(getenv('SHOPVIVALIZ_PRICE_REVIEW_DIR') ?: ''));
    if ($requested === '') {
        throw new RuntimeException('PRICE_REVIEW_PRIVATE_DIRECTORY_NOT_CONFIGURED');
    }
    $dir = realpath($requested);
    $webRoot = realpath(dirname(__DIR__));
    if ($dir === false || !is_dir($dir) || !is_writable($dir)
        || $webRoot === false || $dir === $webRoot
        || str_starts_with($dir, $webRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('PRICE_REVIEW_PRIVATE_DIRECTORY_INVALID');
    }
    return $dir;
}

/**
 * Idempotently records a pending proposal, with its prior/new values.
 * No approval status in this file may authorize an automatic ERP mutation.
 *
 * @return array{request_id:string,cost_zero:bool}|null
 */
function svtpp_request_price_owner_review(array $before, array $proposed, int $actorId): ?array
{
    $old = svtpp_price_snapshot($before);
    $new = svtpp_price_snapshot($proposed);
    if ($old === $new) return null;
    if ($actorId < 1) throw new InvalidArgumentException('PRICE_REVIEW_ACTOR_REQUIRED');
    $sku = trim((string)($before['sku'] ?? ''));
    $id = (string)($before['olist_product_id'] ?? $before['id'] ?? '');
    if ($sku === '' || $id === '') throw new InvalidArgumentException('PRICE_REVIEW_PRODUCT_ID_REQUIRED');
    if (!is_finite((float)$new['sale_price']) || (float)$new['sale_price'] < 0
        || !is_finite((float)$new['cost_price']) || (float)$new['cost_price'] < 0
        || !is_finite((float)$new['promotional_price']) || (float)$new['promotional_price'] < 0) {
        throw new InvalidArgumentException('PRICE_REVIEW_INVALID_AMOUNT');
    }

    $dir = svtpp_price_owner_review_dir();
    $identity = json_encode([$sku, $id, $old, $new], JSON_THROW_ON_ERROR);
    $requestId = substr(hash('sha256', $identity), 0, 32);
    $file = $dir . '/price-' . $requestId . '.json';
    $costZero = (float)$new['cost_price'] <= 0.0;
    $record = [
        'schema' => 'SHOPVIVALIZ_PRICE_OWNER_REVIEW_V1',
        'request_id' => $requestId,
        'status' => 'pending_owner_review',
        'product_id' => $id,
        'sku' => $sku,
        'before' => $old,
        'proposed' => $new,
        'warning_cost_zero' => $costZero,
        'requested_by_user_id' => $actorId,
        'requested_at_utc' => gmdate('c'),
        'application_mode' => 'owner_manual_erp_only',
    ];

    // Exclusive create makes duplicate submissions idempotent without clobbering
    // a proposal. No web endpoint consumes this document as approval.
    $handle = @fopen($file, 'x');
    if ($handle === false) {
        $existing = @file_get_contents($file);
        $prior = is_string($existing) ? json_decode($existing, true) : null;
        if (!is_array($prior) || ($prior['request_id'] ?? '') !== $requestId
            || ($prior['status'] ?? '') !== 'pending_owner_review') {
            throw new RuntimeException('PRICE_REVIEW_PERSISTENCE_UNAVAILABLE');
        }
    } else {
        try {
            @chmod($file, 0600);
            $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (fwrite($handle, $encoded . "\n") !== strlen($encoded) + 1 || !fflush($handle)) {
                throw new RuntimeException('PRICE_REVIEW_PERSISTENCE_FAILED');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($file);
            throw $e;
        }
        fclose($handle);
    }

    return ['request_id' => $requestId, 'cost_zero' => $costZero];
}

/** @return list<array<string,mixed>> */
function svtpp_pending_price_owner_reviews(): array
{
    $dir = svtpp_price_owner_review_dir();
    $rows = [];
    foreach (glob($dir . '/price-*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($row) && ($row['schema'] ?? '') === 'SHOPVIVALIZ_PRICE_OWNER_REVIEW_V1'
            && ($row['status'] ?? '') === 'pending_owner_review') {
            $rows[] = $row;
        }
    }
    usort($rows, static fn(array $a, array $b): int =>
        strcmp((string)($b['requested_at_utc'] ?? ''), (string)($a['requested_at_utc'] ?? '')));
    return $rows;
}
