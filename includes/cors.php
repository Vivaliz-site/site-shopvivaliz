<?php
/**
 * CORS (Cross-Origin Resource Sharing) Configuration
 * Restrict API access to trusted origins only
 */

declare(strict_types=1);

class CorsManager
{
    private static array $trustedOrigins = [
        'https://shopvivaliz.com.br',
        'https://www.shopvivaliz.com.br',
        'http://localhost:3000',
        'http://localhost:8080',
        'http://127.0.0.1:3000'
    ];

    private static array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
    private static array $allowedHeaders = [
        'Content-Type',
        'Authorization',
        'Idempotency-Key',
        'X-Requested-With',
        'X-API-Key'
    ];

    /**
     * Check if origin is trusted
     *
     * @param string|null $origin Origin header value
     * @return bool True if origin is allowed
     */
    public static function isTrustedOrigin(?string $origin): bool
    {
        if ($origin === null) {
            return true; // Same-origin requests don't have Origin header
        }

        return in_array($origin, self::$trustedOrigins, true);
    }

    /**
     * Add trusted origin
     *
     * @param string $origin Origin URL
     */
    public static function addTrustedOrigin(string $origin): void
    {
        if (!in_array($origin, self::$trustedOrigins, true)) {
            self::$trustedOrigins[] = $origin;
        }
    }

    /**
     * Set CORS headers for response
     *
     * @param string|null $origin Origin to allow (null = reject)
     */
    public static function setHeaders(?string $origin = null): void
    {
        if ($origin === null || !self::isTrustedOrigin($origin)) {
            return; // Don't set CORS headers for untrusted origins
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . implode(', ', self::$allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', self::$allowedHeaders));
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
    }

    /**
     * Handle preflight (OPTIONS) requests
     *
     * @return bool True if was preflight, false otherwise
     */
    public static function handlePreflight(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            return false;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if (!self::isTrustedOrigin($origin)) {
            http_response_code(403);
            exit;
        }

        self::setHeaders($origin);
        http_response_code(200);
        exit;
    }

    /**
     * Validate request origin
     *
     * @param string $requiredOrigin Optional specific origin to require
     * @return bool True if origin valid
     */
    public static function validateOrigin(?string $requiredOrigin = null): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        // If required origin specified, enforce it
        if ($requiredOrigin !== null) {
            return $origin === $requiredOrigin;
        }

        // Otherwise check if origin is trusted
        return $origin === null || self::isTrustedOrigin($origin);
    }
}

/**
 * Initialize CORS for API endpoints
 *
 * Call this at top of API endpoint files
 */
function init_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

    // Handle preflight requests
    if (CorsManager::handlePreflight()) {
        return; // Already handled
    }

    // Validate origin for actual requests
    if (!CorsManager::validateOrigin()) {
        http_response_code(403);
        exit;
    }

    // Set CORS headers
    CorsManager::setHeaders($origin);
}

/**
 * Require specific origin
 *
 * @param string $origin Required origin URL
 */
function require_origin(string $origin): void
{
    if (!CorsManager::validateOrigin($origin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid origin']);
        exit;
    }
}
