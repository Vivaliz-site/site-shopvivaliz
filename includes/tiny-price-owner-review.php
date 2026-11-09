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
        || (fileperms($dir) & 0077) !== 0
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
    // Ensure there is no moment in which a cost file is world-readable.
    $oldUmask = umask(0077);
    try {
        $handle = @fopen($file, 'x');
    } finally {
        umask($oldUmask);
    }
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
            && in_array(($row['status'] ?? ''), ['pending_owner_review', 'readback_required', 'applying'], true)) {
            $rows[] = $row;
        }
    }
    usort($rows, static fn(array $a, array $b): int =>
        strcmp((string)($b['requested_at_utc'] ?? ''), (string)($a['requested_at_utc'] ?? '')));
    return $rows;
}

/**
 * Applies ONE explicitly owner-approved sale-price change through the official
 * Tiny V3 product-price endpoint. No cron, queue worker or API client is allowed
 * to invoke this directly. The caller must have validated owner session + CSRF.
 *
 * A crash after entering applying/readback_required never causes automatic
 * replay. The private record is the durable single-use execution ledger.
 * The supplied transport is injected so tests never contact a marketplace.
 *
 * @param callable(string,string,?array):array $transport
 * @return array{status:string,request_id:string}
 */
function svtpp_apply_owner_approved_price(
    string $requestId,
    int $actorId,
    callable $transport
): array {
    $configuredOwner = filter_var(getenv('SHOPVIVALIZ_PRICE_OWNER_USER_ID'), FILTER_VALIDATE_INT);
    if ($configuredOwner === false || $configuredOwner === null || $configuredOwner < 1
        || $actorId !== (int)$configuredOwner) {
        throw new RuntimeException('PRICE_OWNER_IDENTITY_NOT_VERIFIED');
    }
    if (preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
        throw new InvalidArgumentException('PRICE_REVIEW_INVALID_ID');
    }

    $file = svtpp_price_owner_review_dir() . '/price-' . $requestId . '.json';
    $fp = @fopen($file, 'r+');
    if ($fp === false) throw new RuntimeException('PRICE_REVIEW_NOT_FOUND');
    try {
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('PRICE_REVIEW_ALREADY_PROCESSING');
        }
        $record = json_decode((string)stream_get_contents($fp), true);
        if (!is_array($record)
            || ($record['schema'] ?? '') !== 'SHOPVIVALIZ_PRICE_OWNER_REVIEW_V1'
            || ($record['request_id'] ?? '') !== $requestId) {
            throw new RuntimeException('PRICE_REVIEW_INVALID_RECORD');
        }

        if (($record['status'] ?? '') === 'applied') {
            return ['status' => 'already_applied', 'request_id' => $requestId];
        }
        if (($record['status'] ?? '') !== 'pending_owner_review') {
            throw new RuntimeException('PRICE_REVIEW_REQUIRES_RECONCILIATION');
        }
        $id = filter_var($record['product_id'] ?? null, FILTER_VALIDATE_INT);
        $sku = (string)($record['sku'] ?? '');
        $before = $record['before'] ?? null;
        $proposed = $record['proposed'] ?? null;
        if ($id === false || $id === null || $id < 1 || $sku === ''
            || !is_array($before) || !is_array($proposed)) {
            throw new RuntimeException('PRICE_REVIEW_INVALID_TARGET');
        }

        // Amounts are strings with exactly two decimal places. Cents avoid
        // floats when comparing authoritative upstream data.
        $cents = static function (mixed $value): int {
            if (!is_string($value)
                || preg_match('/^(?:0|[1-9][0-9]{0,6})\\.[0-9]{2}$/D', $value) !== 1) {
                throw new RuntimeException('PRICE_REVIEW_INVALID_AMOUNT');
            }
            [$integer, $fraction] = explode('.', $value, 2);
            return (int)$integer * 100 + (int)$fraction;
        };
        $saleCents = $cents($proposed['sale_price'] ?? null);
        $promoCents = $cents($proposed['promotional_price'] ?? null);
        if ($saleCents <= 0) {
            throw new RuntimeException('PRICE_REVIEW_ZERO_SALE_PRICE');
        }
        $oldSale = $cents($before['sale_price'] ?? null);
        $oldPromo = $cents($before['promotional_price'] ?? null);
        if ($saleCents === $oldSale && $promoCents === $oldPromo) {
            throw new RuntimeException('PRICE_REVIEW_NO_PRICE_CHANGE');
        }

        $persist = static function (array $next) use ($fp): void {
            $encoded = json_encode($next, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            rewind($fp);
            if (!ftruncate($fp, 0)
                || fwrite($fp, $encoded) !== strlen($encoded)
                || !fflush($fp)
                || (function_exists('fsync') && !fsync($fp))) {
                throw new RuntimeException('PRICE_REVIEW_STATE_PERSIST_FAILED');
            }
        };
        $read = static function (array $response) use ($sku, $id, $cents): array {
            if ((int)($response['status'] ?? 0) !== 200) {
                throw new RuntimeException('PRICE_REVIEW_TINY_READ_FAILED');
            }
            $json = $response['json'] ?? null;
            if (!is_array($json) || (int)($json['id'] ?? 0) !== $id
                || (string)($json['sku'] ?? '') !== $sku
                || !is_array($json['precos'] ?? null)) {
                throw new RuntimeException('PRICE_REVIEW_TINY_PRODUCT_MISMATCH');
            }
            $prices = $json['precos'];
            $normalize = static function (mixed $value) use ($cents): int {
                if (is_int($value) || is_float($value) || is_string($value)) {
                    $candidate = number_format((float)$value, 2, '.', '');
                    return $cents($candidate);
                }
                throw new RuntimeException('PRICE_REVIEW_TINY_PRICE_UNVERIFIED');
            };
            return [
                'sale' => $normalize($prices['preco'] ?? null),
                'promo' => $normalize($prices['precoPromocional'] ?? 0),
            ];
        };

        // Compare-and-swap against the actual ERP price. A stale report or a
        // site price derived from table 991 must not silently overwrite base ERP.
        $current = $read($transport('GET', '/produtos/' . $id, null));
        if ($current['sale'] !== $oldSale || $current['promo'] !== $oldPromo) {
            $record['last_error'] = 'PRICE_REVIEW_STALE_UPSTREAM_PRICE';
            $persist($record);
            throw new RuntimeException('PRICE_REVIEW_STALE_UPSTREAM_PRICE');
        }
        $record['status'] = 'applying';
        $record['approved_by_user_id'] = $actorId;
        $record['approved_at_utc'] = gmdate('c');
        $persist($record); // Must happen BEFORE the first external write.

        try {
            $write = $transport('PUT', '/produtos/' . $id . '/preco', [
                'preco' => $saleCents / 100,
                'precoPromocional' => $promoCents / 100,
            ]);
            $record['provider_status'] = (int)($write['status'] ?? 0);
            if (!in_array($record['provider_status'], [200, 204], true)) {
                $record['status'] = 'readback_required';
                $record['last_error'] = 'PRICE_REVIEW_EXTERNAL_WRITE_NOT_CONFIRMED';
                $persist($record);
                return ['status' => 'readback_required', 'request_id' => $requestId];
            }
            $after = $read($transport('GET', '/produtos/' . $id, null));
            if ($after['sale'] !== $saleCents || $after['promo'] !== $promoCents) {
                throw new RuntimeException('PRICE_REVIEW_READBACK_MISMATCH');
            }
            $record['status'] = 'applied';
            $record['applied_at_utc'] = gmdate('c');
            $record['verified_price'] = $proposed['sale_price'];
            $record['verified_promotional_price'] = $proposed['promotional_price'];
            $persist($record);
            return ['status' => 'applied', 'request_id' => $requestId];
        } catch (Throwable $e) {
            $record['status'] = 'readback_required';
            $record['last_error'] = $e->getMessage();
            $persist($record);
            return ['status' => 'readback_required', 'request_id' => $requestId];
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
