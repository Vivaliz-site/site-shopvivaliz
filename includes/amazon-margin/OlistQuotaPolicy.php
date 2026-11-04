<?php
declare(strict_types=1);

/**
 * Olist v3 exposes per-account read quotas via x-ratelimit-* headers.
 * It is shared with all applications under this merchant, not only this audit.
 * Never retry authentication or authorization errors.
 */
final class SvAmazonMarginOlistQuotaPolicy
{
    public static function header(string $line, array &$out): void
    {
        if (preg_match('/^\s*(x-ratelimit-limit|x-ratelimit-remaining|x-ratelimit-reset|retry-after):\s*(\d+)\s*$/i', trim($line), $m)) {
            $out[strtolower($m[1])] = min(3600,(int)$m[2]);
        }
    }

    public static function retryable(int $status): bool
    {
        return in_array($status, [0, 429, 500, 502, 503, 504], true);
    }

    /** A 429 indicates shared-quota exhaustion; honor Olist's reset header. */
    public static function retryDelay(int $status, array $headers, int $attempt): int
    {
        if ($status===429) {
            foreach (['retry-after','x-ratelimit-reset'] as $name) {
                if (isset($headers[$name]) && is_numeric($headers[$name])) {
                    return min(125,max(2,(int)$headers[$name]+2));
                }
            }
            return [15,30,60,90,120][min(4,max(0,$attempt))];
        }
        return [2,6,12,25,45][min(4,max(0,$attempt))];
    }

    /**
     * Reserve a little capacity for the ERP's other integrations.
     * No fixed account plan or app-specific independent quota is assumed.
     */
    public static function cooloffAfterSuccess(array $headers): int
    {
        $limit=$headers['x-ratelimit-limit']??null;
        $remaining=$headers['x-ratelimit-remaining']??null;
        $reset=$headers['x-ratelimit-reset']??null;
        if (!is_numeric($limit)||!is_numeric($remaining)||!is_numeric($reset)) return 0;
        if ((int)$limit<=0 || (int)$reset<=0) return 0;
        $reserved=max(5,(int)ceil((int)$limit*0.10));
        if ((int)$remaining > $reserved) return 0;
        return min(125,max(1,(int)$reset+2));
    }
}
