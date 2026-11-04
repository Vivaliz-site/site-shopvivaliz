<?php
declare(strict_types=1);

/**
 * Per-offer identity/lookup problems are isolated to their Seller SKU, but
 * account-wide authentication failures and depleted provider quotas must
 * abort the run so its queue can retry. Continuing would publish hundreds
 * of falsely blank costs and misleading margins.
 */
final class SvAmazonMarginReadErrorPolicy
{
    public static function mustAbortAudit(Throwable $error): bool
    {
        if ($error instanceof SvMarketplaceException) {
            return in_array($error->httpStatus,[401,403,429,500,502,503,504],true);
        }
        return preg_match('/^tiny_http_(0|401|403|429|500|502|503|504)$/',
            $error->getMessage()) === 1;
    }
}
