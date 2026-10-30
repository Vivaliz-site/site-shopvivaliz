<?php
declare(strict_types=1);

/**
 * Convert a non-negative BRL amount to cents once, at the input boundary.
 * Decimal strings are rounded half up without floating-point multiplication.
 */
function svopa_cents(mixed $amount): int
{
    if (is_float($amount)) {
        if (!is_finite($amount) || $amount < 0) {
            throw new InvalidArgumentException('invalid_payment_amount');
        }
        $amount = number_format($amount, 2, '.', '');
    } elseif (is_int($amount)) {
        $amount = (string)$amount;
    }
    if (!is_string($amount) || preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', trim($amount), $match) !== 1) {
        throw new InvalidArgumentException('invalid_payment_amount');
    }
    $fraction = str_pad($match[2] ?? '', 3, '0');
    $digits = ltrim($match[1] . substr($fraction, 0, 2), '0');
    $digits = $digits !== '' ? $digits : '0';
    $limit = (string)PHP_INT_MAX;
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        throw new InvalidArgumentException('payment_amount_out_of_range');
    }
    $cents = (int)$digits;
    if ((int)$fraction[2] >= 5) {
        if ($cents === PHP_INT_MAX) {
            throw new InvalidArgumentException('payment_amount_out_of_range');
        }
        $cents++;
    }
    return $cents;
}

/**
 * Produce positive payment lines whose sum is the validated order total.
 *
 * The saved order is authoritative: this does not reprice products or reapply
 * promotion rules. Shipping is unchanged. Existing discounts are apportioned
 * across products using integer cents and largest remainders. One cent per
 * purchased unit is reserved first so no gateway line is zero or negative.
 *
 * A remainder within a multi-unit item creates two price tiers for the same
 * product. Their quantities still add up to the purchased quantity.
 *
 * @return array{items:list<array{item:array,quantity:int,unit_cents:int}>,shipping_cents:int,total_cents:int}
 */
function svopa_payment_lines(array $order): array
{
    if (!array_key_exists('total', $order) || !is_array($order['items'] ?? null) || $order['items'] === []) {
        throw new InvalidArgumentException('invalid_payment_order');
    }
    $total = svopa_cents($order['total']);
    $shipping = svopa_cents($order['shipping_total'] ?? 0);
    if ($total <= $shipping) {
        throw new InvalidArgumentException('invalid_payment_order_total');
    }

    $products = [];
    $gross = 0;
    $unitCount = 0;
    foreach ($order['items'] as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('invalid_payment_item');
        }
        $quantity = $item['quantity'] ?? 1;
        if (is_string($quantity) && preg_match('/^[0-9]+$/D', $quantity) === 1) {
            $digits = ltrim($quantity, '0');
            $limit = (string)PHP_INT_MAX;
            if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
                throw new InvalidArgumentException('invalid_payment_quantity');
            }
            $quantity = (int)$digits;
        }
        if (!is_int($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException('invalid_payment_quantity');
        }
        $unitCents = svopa_cents($item['price'] ?? null);
        if ($unitCents <= 0 || $unitCents > intdiv(PHP_INT_MAX, $quantity)) {
            throw new InvalidArgumentException('invalid_payment_item_price');
        }
        $lineGross = $unitCents * $quantity;
        if ($lineGross > PHP_INT_MAX - $gross || $quantity > PHP_INT_MAX - $unitCount) {
            throw new InvalidArgumentException('payment_amount_out_of_range');
        }
        $products[] = ['item' => $item, 'quantity' => $quantity, 'gross' => $lineGross, 'net' => $lineGross];
        $gross += $lineGross;
        $unitCount += $quantity;
    }

    $productTotal = $total - $shipping;
    // There is no positive-cent representation below one cent per unit.
    // Refuse an inconsistent order instead of increasing the charge or
    // silently dropping units. No gateway request has been made at this point.
    if ($productTotal < $unitCount || $productTotal > $gross) {
        throw new InvalidArgumentException('invalid_payment_order_total');
    }

    if ($productTotal < $gross) {
        $capacityTotal = $gross - $unitCount;
        $remaining = $productTotal - $unitCount;
        $allocated = 0;
        $remainders = [];
        foreach ($products as $index => &$product) {
            $capacity = $product['gross'] - $product['quantity'];
            // Never let PHP promote an overflowing integer product to float.
            if ($capacity > 0 && $remaining > intdiv(PHP_INT_MAX, $capacity)) {
                throw new InvalidArgumentException('payment_amount_out_of_range');
            }
            $numerator = $capacity * $remaining;
            $extra = intdiv($numerator, $capacityTotal);
            $product['net'] = $product['quantity'] + $extra;
            $allocated += $product['net'];
            $remainders[] = ['index' => $index, 'remainder' => $numerator % $capacityTotal];
        }
        unset($product);
        usort($remainders, static function (array $left, array $right): int {
            return ($right['remainder'] <=> $left['remainder']) ?: ($left['index'] <=> $right['index']);
        });
        $unallocated = $productTotal - $allocated;
        foreach ($remainders as $remainder) {
            if ($unallocated === 0) {
                break;
            }
            $products[$remainder['index']]['net']++;
            $unallocated--;
        }
        if ($unallocated !== 0) {
            throw new InvalidArgumentException('invalid_payment_allocation');
        }
    }

    $lines = [];
    $sum = $shipping;
    foreach ($products as $product) {
        $quantity = $product['quantity'];
        $unitCents = intdiv($product['net'], $quantity);
        $higherPriceUnits = $product['net'] % $quantity;
        $lowerPriceUnits = $quantity - $higherPriceUnits;
        if ($lowerPriceUnits > 0) {
            $lines[] = ['item' => $product['item'], 'quantity' => $lowerPriceUnits, 'unit_cents' => $unitCents];
        }
        if ($higherPriceUnits > 0) {
            $lines[] = ['item' => $product['item'], 'quantity' => $higherPriceUnits, 'unit_cents' => $unitCents + 1];
        }
        if ($product['net'] > PHP_INT_MAX - $sum) {
            throw new InvalidArgumentException('payment_amount_out_of_range');
        }
        $sum += $product['net'];
    }
    if ($sum !== $total) {
        throw new InvalidArgumentException('invalid_payment_allocation');
    }
    return ['items' => $lines, 'shipping_cents' => $shipping, 'total_cents' => $total];
}
