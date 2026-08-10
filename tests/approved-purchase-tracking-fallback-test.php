<?php
declare(strict_types=1);

function sv_purchase_tracking_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents(dirname(__DIR__) . '/checkout-return.php');
sv_purchase_tracking_assert($source !== '', 'checkout-return.php must be readable.');

sv_purchase_tracking_assert(
    str_contains($source, "\$serverApproved = (string)(\$orderData['status'] ?? '') === 'payment_approved';"),
    'Purchase tracking must depend on the persisted payment_approved order state.'
);
sv_purchase_tracking_assert(
    str_contains($source, "if (\$serverApproved && !\$serverAnalyticsSent"),
    'GA4 browser fallback must run only for approved orders whose canonical server event was not sent.'
);
sv_purchase_tracking_assert(
    str_contains($source, "if (\$serverApproved && \$orderNumber !== '' && \$googleAdsId !== '' && \$googleAdsLabel !== '')"),
    'Direct Google Ads conversion must be gated by server approval and configured conversion IDs.'
);
sv_purchase_tracking_assert(
    str_contains($source, "\$shouldRetryConfirmation = \$reportedApproved && !\$serverApproved"),
    'Provider-approved return should retry server confirmation instead of immediately counting revenue.'
);
sv_purchase_tracking_assert(
    str_contains($source, "min(4, max(0, (int)(\$_GET['confirm_check'] ?? 0)))"),
    'Confirmation retry loop must have a hard upper bound.'
);
sv_purchase_tracking_assert(
    str_contains($source, "sv_ga4_purchase_"),
    'GA4 browser fallback must use transaction-level browser deduplication.'
);
sv_purchase_tracking_assert(
    str_contains($source, "sv_ads_conversion_"),
    'Google Ads direct conversion must use transaction-level browser deduplication.'
);
sv_purchase_tracking_assert(
    !preg_match('/if \(\$reportedApproved[^\n]*GOOGLE_ADS/s', $source),
    'A gateway query-string approval must never directly gate Google Ads revenue.'
);

fwrite(STDOUT, "PASS: approved purchase tracking fallback contract.\n");
