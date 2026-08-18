<?php
/**
 * Rate Limiting (Request Throttling)
 * Prevent brute force and DoS attacks
 */

declare(strict_types=1);

require_once __DIR__ . '/order-rate-limit.php';

class RateLimiter
{
    private static string $redisKey = 'rate_limit:';
    private static int $defaultWindow = 60; // 1 minute
    private static int $defaultLimit = 10;

    /**
     * Check if request is allowed
     *
     * Rodada 2 (2026-08-18): a implementacao anterior guardava o contador
     * dentro de $_SESSION, chaveado por um identifier que normalmente inclui
     * o IP (ex: 'login_' . $ip em auth/login.php). O ARMAZENAMENTO, porem,
     * era a sessao do proprio requisitante -- um cliente que simplesmente nao
     * envie o cookie PHPSESSID recebe sessao nova a cada request e o contador
     * reinicia sempre, ou seja, nao havia protecao real contra forca bruta em
     * auth/login.php nem em auth/register.php nem em api/cart/add.php.
     * Agora e um wrapper fino sobre svorl_allow() (includes/order-rate-limit.php),
     * que ja e baseado em arquivo com flock, chaveado por hash(IP+User-Agent) --
     * nao depende de cookie -- e falha fechado quando nao ha armazenamento
     * confiavel. Assinatura publica mantida intacta; nenhum call-site precisou
     * mudar.
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
        return svorl_allow($maxRequests, $windowSeconds, 'legacy-' . $identifier);
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
