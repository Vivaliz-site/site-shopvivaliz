<?php
/**
 * Idempotency Keys - Prevent Duplicate Submissions
 * Ensures same request is processed only once
 */

declare(strict_types=1);

class IdempotencyManager
{
    private static string $storagePath = __DIR__ . '/../storage/idempotency/';

    public static function init(): void
    {
        if (!is_dir(self::$storagePath)) {
            @mkdir(self::$storagePath, 0755, true);
        }
    }

    /**
     * Check if request is duplicate or new
     *
     * @param string $key Unique idempotency key (UUID)
     * @return array|false Previous response if duplicate, false if new
     */
    public static function check(string $key): array|false
    {
        self::init();

        $file = self::$storagePath . sha1($key) . '.json';

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            return $data ?: false;
        }

        return false;
    }

    /**
     * Record a successful request
     *
     * @param string $key Idempotency key
     * @param array $response Response to cache
     * @param int $ttl Time to live in seconds (default 24 hours)
     */
    public static function record(string $key, array $response, int $ttl = 86400): void
    {
        self::init();

        $file = self::$storagePath . sha1($key) . '.json';
        $data = [
            'timestamp' => time(),
            'ttl' => $ttl,
            'response' => $response
        ];

        file_put_contents($file, json_encode($data), LOCK_EX);

        // Set file to expire after TTL
        touch($file, time() + $ttl);
    }

    /**
     * Validate idempotency key format (must be UUID-like)
     *
     * @param string|null $key Key to validate
     * @return bool True if valid
     */
    public static function isValidKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        // UUID v4 format: 8-4-4-4-12 hex digits
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $key) === 1;
    }

    /**
     * Generate new idempotency key (UUID v4)
     *
     * @return string UUID v4
     */
    public static function generateKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Clean expired idempotency records
     */
    public static function cleanup(): void
    {
        self::init();

        foreach (glob(self::$storagePath . '*.json') as $file) {
            if (filemtime($file) < time()) {
                @unlink($file);
            }
        }
    }
}

/**
 * Get or validate idempotency key from request
 *
 * @return string Valid idempotency key
 */
function get_idempotency_key(): string
{
    // Check headers first (preferred)
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? null;

    if (!IdempotencyManager::isValidKey($key)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing idempotency key']);
        exit;
    }

    return $key;
}

/**
 * Check for duplicate request, return cached response if found
 *
 * @return bool True if new request, false if duplicate (headers sent)
 */
function check_idempotency(): bool
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? null;

    if (!IdempotencyManager::isValidKey($key)) {
        return true; // Not checking idempotency, proceed
    }

    $cached = IdempotencyManager::check($key);

    if ($cached !== false) {
        // Duplicate request - return cached response
        http_response_code($cached['status'] ?? 200);
        header('Content-Type: application/json');
        header('X-Idempotency-Replayed: true');
        echo json_encode($cached['response']);
        exit;
    }

    return true; // New request, proceed
}

/**
 * Record response for idempotency
 *
 * @param string $key Idempotency key
 * @param array $response Response data
 * @param int $status HTTP status code
 */
function record_idempotent_response(string $key, array $response, int $status = 200): void
{
    IdempotencyManager::record($key, ['status' => $status, 'response' => $response]);
}
