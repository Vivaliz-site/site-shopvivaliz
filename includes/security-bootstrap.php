<?php
declare(strict_types=1);

/**
 * Security Bootstrap
 *
 * Must be included at the very beginning of every request, before any output.
 * Sets up security headers, session configuration, and error handling.
 */

// Load environment variables
require_once __DIR__ . '/../config/bootstrap-env.php';

// Include security utilities
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/csrf-protection.php';
require_once __DIR__ . '/input-validator.php';

/**
 * Initialize security measures
 */
function initialize_security(): void
{
    // Set security headers
    set_security_headers();

    // Enforce HTTPS in production
    // TEMPORARIAMENTE DESABILITADO: causava loop de redirect (2026-07-14)
    // $environment = getenv('APP_ENV') ?: 'development';
    // if ($environment === 'production') {
    //     enforce_https();
    // }

    // Session configuration
    configure_session();

    // Error handling
    set_error_handler('handle_error');
    set_exception_handler('handle_exception');
}

/**
 * Configure session security
 */
function configure_session(): void
{
    $sessionOptions = [
        // Strict mode: Session IDs valid only after explicit start
        'use_strict_mode' => 1,

        // Only transmit session ID over HTTP(S)
        'httponly' => 1,

        // Transmit only over HTTPS in production
        'secure' => getenv('APP_ENV') === 'production' ? 1 : 0,

        // Prevent session fixation
        'use_only_cookies' => 1,

        // SameSite cookie attribute
        'samesite' => 'Strict',

        // Session lifetime (30 minutes)
        'gc_maxlifetime' => 1800,

        // Probability to trigger garbage collection
        'gc_probability' => 1,
        'gc_divisor' => 100,
    ];

    // Apply session options
    foreach ($sessionOptions as $option => $value) {
        if (ini_set("session.{$option}", (string)$value) === false) {
            error_log("Failed to set session.{$option}");
        }
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
}

/**
 * Global error handler
 *
 * @param int $errno Error number
 * @param string $errstr Error string
 * @param string $errfile Error file
 * @param int $errline Error line
 * @return bool
 */
function handle_error(int $errno, string $errstr, string $errfile, int $errline): bool
{
    // Don't handle if error control operator was used
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $errorType = match ($errno) {
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
        default => 'Unknown Error',
    };

    $message = "[{$errorType}] {$errstr} in {$errfile}:{$errline}";
    error_log($message);

    // Don't execute PHP internal error handler
    return true;
}

/**
 * Global exception handler
 *
 * @param Throwable $exception Exception
 * @return void
 */
function handle_exception(Throwable $exception): void
{
    error_log(
        'Uncaught Exception: ' . get_class($exception) . ' - ' .
        $exception->getMessage() . ' in ' .
        $exception->getFile() . ':' . $exception->getLine()
    );

    // Return JSON error response for API requests
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api') !== false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Internal Server Error',
            'code' => 'INTERNAL_ERROR',
        ]);
    } else {
        // Redirect to error page for web requests
        http_response_code(500);
        include __DIR__ . '/../500.php';
    }

    exit(1);
}

/**
 * Get security configuration
 *
 * @return array Configuration array
 */
function get_security_config(): array
{
    return [
        'environment' => getenv('APP_ENV') ?: 'development',
        'https_only' => getenv('HTTPS_ONLY') === 'true',
        'cors_origins' => explode(',', (string)getenv('CORS_ORIGINS') ?: ''),
        'rate_limit_enabled' => getenv('RATE_LIMIT') === 'true',
        'rate_limit_requests' => (int)getenv('RATE_LIMIT_REQUESTS') ?: 60,
        'rate_limit_window' => (int)getenv('RATE_LIMIT_WINDOW') ?: 3600,
    ];
}

// Initialize security on first load
initialize_security();
