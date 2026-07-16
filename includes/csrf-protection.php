<?php
declare(strict_types=1);

/**
 * CSRF Token Protection System
 *
 * Provides Cross-Site Request Forgery protection through token generation and validation.
 * Tokens are stored in session and validated on form submission.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Generate or retrieve CSRF token for session
 *
 * @param string $tokenName Token session key name
 * @return string CSRF token
 */
function csrf_token(string $tokenName = 'csrf_token'): string
{
    if (isset($_SESSION[$tokenName]) && !empty($_SESSION[$tokenName])) {
        return $_SESSION[$tokenName];
    }

    // Generate new token (256 bits / 32 bytes)
    $token = bin2hex(random_bytes(32));
    $_SESSION[$tokenName] = $token;

    return $token;
}

/**
 * Generate HTML input field with CSRF token
 *
 * @param string $tokenName Token session key name
 * @param string $fieldName HTML input field name
 * @return string HTML hidden input
 */
function csrf_field(string $tokenName = 'csrf_token', string $fieldName = '_csrf_token'): string
{
    $token = csrf_token($tokenName);
    return sprintf(
        '<input type="hidden" name="%s" value="%s">',
        htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
    );
}

/**
 * Validate CSRF token from request
 *
 * Checks $_POST, $_REQUEST, and Authorization header (JSON) for token
 *
 * @param string $tokenName Token session key name
 * @param string $fieldName HTML input field name
 * @return bool True if token is valid and matches session
 */
function csrf_verify(string $tokenName = 'csrf_token', string $fieldName = '_csrf_token'): bool
{
    // Skip validation for GET/HEAD/OPTIONS requests
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return true;
    }

    $sessionToken = $_SESSION[$tokenName] ?? null;
    if ($sessionToken === null || trim($sessionToken) === '') {
        return false;
    }

    $requestToken = null;

    // Check POST/REQUEST data
    if (!empty($_POST[$fieldName])) {
        $requestToken = trim((string)$_POST[$fieldName]);
    }

    // Check JSON body for API requests
    if ($requestToken === null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (strpos($contentType, 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);
            if (is_array($data) && isset($data[$fieldName])) {
                $requestToken = trim((string)$data[$fieldName]);
            }
        }
    }

    // Check Authorization header (X-CSRF-Token)
    if ($requestToken === null) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($headerToken !== null) {
            $requestToken = trim((string)$headerToken);
        }
    }

    // Validate token using constant-time comparison
    if ($requestToken === null || trim($requestToken) === '') {
        return false;
    }

    return hash_equals($sessionToken, $requestToken);
}

/**
 * Middleware: Validate CSRF token and die if invalid
 *
 * Should be called early in request handling for protected endpoints
 *
 * @param string $tokenName Token session key name
 * @param string $fieldName HTML input field name
 * @param int $statusCode HTTP status code for failure response
 * @return void Dies with error if validation fails
 */
function csrf_verify_or_die(
    string $tokenName = 'csrf_token',
    string $fieldName = '_csrf_token',
    int $statusCode = 403
): void {
    if (!csrf_verify($tokenName, $fieldName)) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'CSRF token validation failed',
            'code' => 'CSRF_INVALID',
        ]);
        exit(1);
    }
}

/**
 * Regenerate CSRF token (use after login/authentication changes)
 *
 * @param string $tokenName Token session key name
 * @return string New CSRF token
 */
function csrf_regenerate(string $tokenName = 'csrf_token'): string
{
    // Generate new token
    $token = bin2hex(random_bytes(32));
    $_SESSION[$tokenName] = $token;
    return $token;
}

/**
 * Get CSRF token HTML data attribute for JavaScript
 *
 * Useful for AJAX requests
 *
 * @param string $tokenName Token session key name
 * @param string $attrName HTML data attribute name (without 'data-' prefix)
 * @return string HTML data attribute
 */
function csrf_data_attr(string $tokenName = 'csrf_token', string $attrName = 'csrf_token'): string
{
    $token = csrf_token($tokenName);
    return sprintf(
        'data-%s="%s"',
        htmlspecialchars($attrName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
    );
}
