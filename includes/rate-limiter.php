<?php
/**
 * Rate Limiting (Request Throttling)
 * Prevent brute force and DoS attacks
 */

declare(strict_types=1);

class RateLimiter
{
    private static string $redisKey = 'rate_limit:';
    private static int $defaultWindow = 60; // 1 minute
    private static int $defaultLimit = 10;

    /**
     * Check if request is allowed
     *
     * @param string $identifier Unique identifier (IP, user_id, email)
     * @param int $maxRequests Max requests allowed
     * @param int $windowSeconds Time window in seconds
     * @return bool True if request allowed, false if rate limited
     */
    public static function isAllowed(
        string $identifier,
        int $maxRequests = self::defaultLimit,
        int $windowSeconds = self::defaultWindow
    ): bool {
        $key = self::$redisKey . $identifier;
        $now = time();
        $window = $now - $windowSeconds;

        // Use session/file cache if Redis unavailable
        if (!isset($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = [];
        }

        if (!isset($_SESSION['rate_limit'][$identifier])) {
            $_SESSION['rate_limit'][$identifier] = [
                'requests' => [],
                'blocked_until' => 0
            ];
        }

        $record = &$_SESSION['rate_limit'][$identifier];

        // Check if blocked
        if ($record['blocked_until'] > $now) {
            return false;
        }

        // Remove old requests outside window
        $record['requests'] = array_filter(
            $record['requests'],
            fn($t) => $t > $window
        );

        // Check if limit exceeded
        if (count($record['requests']) >= $maxRequests) {
            $record['blocked_until'] = $now + $windowSeconds;
            return false;
        }

        // Record this request
        $record['requests'][] = $now;
        return true;
    }

    /**
     * Get remaining requests
     *
     * @param string $identifier Unique identifier
     * @param int $maxRequests Max requests allowed
     * @param int $windowSeconds Time window in seconds
     * @return int Remaining requests
     */
    public static function getRemaining(
        string $identifier,
        int $maxRequests = self::defaultLimit,
        int $windowSeconds = self::defaultWindow
    ): int {
        if (!isset($_SESSION['rate_limit'][$identifier])) {
            return $maxRequests;
        }

        $now = time();
        $window = $now - $windowSeconds;
        $record = $_SESSION['rate_limit'][$identifier];

        $recentRequests = array_filter(
            $record['requests'],
            fn($t) => $t > $window
        );

        return max(0, $maxRequests - count($recentRequests));
    }

    /**
     * Reset rate limit for identifier
     */
    public static function reset(string $identifier): void
    {
        if (isset($_SESSION['rate_limit'][$identifier])) {
            unset($_SESSION['rate_limit'][$identifier]);
        }
    }
}

/**
 * Check rate limit and return error if exceeded
 *
 * @param string $identifier Unique identifier
 * @param int $maxRequests Max requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return void Dies with 429 if rate limited
 */
function ratelimit_check(
    string $identifier,
    int $maxRequests = 10,
    int $windowSeconds = 60
): void {
    if (!RateLimiter::isAllowed($identifier, $maxRequests, $windowSeconds)) {
        http_response_code(429);
        echo json_encode([
            'error' => 'Too many requests',
            'retry_after' => $windowSeconds
        ]);
        exit;
    }
}
