<?php
declare(strict_types=1);

/**
 * Backoff per SP-API operation, not an instant retry loop.
 *
 * The quota is shared by other ShopVivaliz integrations with the same
 * Amazon selling partner/application. The final failed attempt is handled
 * by the durable database queue's cooldown instead of tight retry loops.
 */
final class SvAmazonMarginRateLimitPolicy
{
    private const DELAYS_SECONDS = [5, 12, 25, 45, 80];

    public static function retryable(int $httpStatus): bool
    {
        return $httpStatus === 429 || $httpStatus === 503;
    }

    /** @return list<int> */
    public static function delays(): array
    {
        return self::DELAYS_SECONDS;
    }

    public static function totalWaitSeconds(): int
    {
        return array_sum(self::DELAYS_SECONDS);
    }
}
