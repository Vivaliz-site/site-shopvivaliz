<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tiny-order-push.php';

/**
 * Tiny/Olist v3 runtime consumer.
 *
 * OAuth refresh-token rotation is exclusively owned by daemon-token-renewer.py.
 * This runtime reads the canonical rotating store and never exchanges a refresh
 * token, avoiding concurrent rotation and stale-refresh persistence.
 */
const SV_MARKET_TINY_REFRESH_MARGIN_SECONDS = 1800;

function sv_market_tiny_token_path(): string
{
    $configured = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        return dirname(__DIR__, 2) . '/storage/private/olist-tokens.json';
    }
    return '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}

/** @return array<string,mixed> */
function sv_market_tiny_token_store(): array
{
    $path = sv_market_tiny_token_path();
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function sv_market_tiny_env(string $key): string
{
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
        return trim((string)$_ENV[$key]);
    }
    if (function_exists('svtop_env')) {
        return trim((string)svtop_env($key));
    }
    return '';
}

function sv_market_tiny_access_token(): string
{
    $tokens = sv_market_tiny_token_store();
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN'] as $key) {
        $value = trim((string)($tokens[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'TOKEN_API_OLIST'] as $key) {
        $value = sv_market_tiny_env($key);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function sv_market_tiny_jwt_expiry_epoch(string $token): int
{
    $parts = explode('.', $token);
    if (count($parts) < 2 || trim($parts[1]) === '') {
        return 0;
    }
    $segment = strtr($parts[1], '-_', '+/');
    $padding = strlen($segment) % 4;
    if ($padding !== 0) {
        $segment .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($segment, true);
    if (!is_string($decoded) || $decoded === '') {
        return 0;
    }
    $json = json_decode($decoded, true);
    $expiry = is_array($json) ? (int)($json['exp'] ?? 0) : 0;
    return $expiry > 0 ? $expiry : 0;
}

function sv_market_tiny_expiry_epoch(string $token): int
{
    $tokens = sv_market_tiny_token_store();
    $stored = trim((string)($tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? ''));
    if ($stored !== '' && hash_equals($stored, $token)) {
        $epoch = (int)($tokens['expires_at_epoch'] ?? 0);
        if ($epoch > 0) {
            return $epoch;
        }
    }
    return sv_market_tiny_jwt_expiry_epoch($token);
}

function sv_market_tiny_token_requires_refresh(string $token, ?int $now = null): bool
{
    if ($token === '') {
        return true;
    }
    $expiry = sv_market_tiny_expiry_epoch($token);
    if ($expiry <= 0) {
        return false;
    }
    return ($expiry - ($now ?? time())) <= SV_MARKET_TINY_REFRESH_MARGIN_SECONDS;
}

function sv_market_tiny_ensure_access_token(): string
{
    $token = sv_market_tiny_access_token();
    if ($token === '') {
        throw new RuntimeException('Token Tiny/Olist indisponivel no token store canonico.');
    }
    $expiry = sv_market_tiny_expiry_epoch($token);
    if ($expiry > 0 && $expiry <= time()) {
        throw new RuntimeException('Token Tiny/Olist expirado; aguarde o renovador canonico ou reautorize em /olist/connect.php.');
    }
    return $token;
}

/**
 * @param array<string,mixed>|null $payload
 * @return array{status:int,body:string,json:array<string,mixed>}
 */
function sv_market_tiny_request_v3(string $method, string $path, ?array $payload = null): array
{
    $before = sv_market_tiny_ensure_access_token();
    $response = svtop_tiny_request($method, $path, $before, $payload);
    if ((int)$response['status'] !== 401) {
        return $response;
    }

    // The daemon may have published a new token between request and 401.
    $after = sv_market_tiny_access_token();
    if ($after !== '' && !hash_equals($before, $after)) {
        return svtop_tiny_request($method, $path, $after, $payload);
    }
    return $response;
}
