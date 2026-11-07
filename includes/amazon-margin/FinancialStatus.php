<?php
declare(strict_types=1);

/**
 * Financial reconciliation is independent from the forward-looking margin.
 * A Shipment without reconciled freight/charges is NOT "no sales".
 * Historical snapshots stay immutable; only reports can relabel that case.
 */
final class SvAmazonMarginFinancialStatus
{
    public static function classify(int $confirmedSales, int $pendingSales): string
    {
        if ($confirmedSales < 0 || $pendingSales < 0) {
            throw new InvalidArgumentException('negative_financial_sale_count');
        }
        if ($confirmedSales > 0) return $pendingSales > 0 ? 'partial' : 'confirmed';
        return $pendingSales > 0 ? 'pending_financial_reconciliation' : 'no_confirmed_sales';
    }

    /** Display-only migration for old immutable snapshots that used the wrong label. */
    public static function forDisplay(array $row): string
    {
        $status = (string)($row['status'] ?? 'unverified');
        if ($status !== 'no_confirmed_sales') return $status;
        $confirmed = $row['confirmed_sales'] ?? 0;
        $pending = $row['pending_sales'] ?? 0;
        if (!is_numeric($confirmed) || !is_numeric($pending)
            || (int)$confirmed < 0 || (int)$pending < 0) return $status;
        return self::classify((int)$confirmed, (int)$pending);
    }
}
