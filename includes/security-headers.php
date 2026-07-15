<?php
declare(strict_types=1);

/**
 * Security Headers Configuration
 *
 * Sets essential HTTP security headers to protect against common web vulnerabilities:
 * - XSS (Cross-Site Scripting)
 * - Clickjacking
 * - MIME type sniffing
 * - SSL stripping
 * - Third-party content risks
 */

/**
 * Set all security headers
 *
 * Should be called early in application bootstrap, before any output
 *
 * @param array $options Configuration options
 * @return void
 */
function set_security_headers(array $options = []): void
{
    // Prevent execution of inline scripts and only allow from same origin
    $csp = 'default-src \'self\'; '
        . 'script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; '
        . 'style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; '
        . 'img-src \'self\' data: https: http:; '
        . 'font-src \'self\' https://fonts.gstatic.com; '
        . 'connect-src \'self\' https:; '
        . 'frame-ancestors \'none\'; '
        . 'base-uri \'self\'; '
        . 'form-action \'self\'';

    // Allow custom CSP if provided
    if (isset($options['csp'])) {
        $csp = (string)$options['csp'];
    }

    // Standard security headers
    $headers = [
        // Prevent clickjacking attacks
        'X-Frame-Options' => 'DENY',

        // Prevent MIME type sniffing
        'X-Content-Type-Options' => 'nosniff',

        // Enable XSS protection in older browsers
        'X-XSS-Protection' => '1; mode=block',

        // Content Security Policy (strict)
        'Content-Security-Policy' => $csp,

        // Referrer Policy - limit referrer information
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // Permissions Policy (Feature Policy)
        'Permissions-Policy' => implode(', ', [
            'accelerometer=()',
            'ambient-light-sensor=()',
            'autoplay=()',
            'camera=()',
            'encrypted-media=()',
            'fullscreen=()',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'picture-in-picture=()',
            'usb=()',
        ]),

        // HTTPS enforcement
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',

        // Prevent information leakage
        'X-Powered-By' => '',  // Empty to unset
    ];

    // Remove X-Powered-By header
    header_remove('X-Powered-By');

    // Set all security headers
    foreach ($headers as $name => $value) {
        if ($value !== '') {
            header("{$name}: {$value}");
        }
    }
}

/**
 * Enable HTTPS-only mode
 *
 * Forces all requests to HTTPS and sets HSTS header
 *
 * @param int $maxAge HSTS max-age in seconds (default: 1 year)
 * @return void
 */
function enforce_https(int $maxAge = 31536000): void
{
    // Set HSTS header
    header("Strict-Transport-Security: max-age={$maxAge}; includeSubDomains; preload");

    // Redirect HTTP to HTTPS if needed
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header("Location: {$url}", true, 301);
        exit(0);
    }
}

/**
 * Set cache control headers
 *
 * @param string $type Cache type: 'public', 'private', 'no-store', etc.
 * @param int|null $maxAge Time in seconds for max-age directive
 * @return void
 */
function set_cache_control(string $type = 'private', ?int $maxAge = null): void
{
    $cacheControl = $type;

    if ($maxAge !== null && $maxAge > 0) {
        $cacheControl .= ", max-age={$maxAge}";
    }

    header("Cache-Control: {$cacheControl}");
}

/**
 * Disable caching
 *
 * Sets headers to prevent client/browser caching
 *
 * @return void
 */
function disable_cache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Set JSON response headers
 *
 * @param int $statusCode HTTP status code
 * @return void
 */
function set_json_response(int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

/**
 * Get current request security level
 *
 * Returns array of security metrics
 *
 * @return array Security assessment data
 */
function get_security_assessment(): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $hasHSTS = !empty($_SERVER['HTTP_STRICT_TRANSPORT_SECURITY']);
    $hasCSP = !empty($_SERVER['HTTP_CONTENT_SECURITY_POLICY']);
    $hasXFrame = !empty($_SERVER['HTTP_X_FRAME_OPTIONS']);

    return [
        'https' => $https,
        'hsts' => $hasHSTS,
        'csp' => $hasCSP,
        'x_frame_options' => $hasXFrame,
        'security_score' => (int)($https * 25 + $hasHSTS * 25 + $hasCSP * 25 + $hasXFrame * 25),
    ];
}
