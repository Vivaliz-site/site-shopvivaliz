<?php
declare(strict_types=1);

/**
 * Renova o token OAuth Olist/Tiny com lock exclusivo, persistência atômica e
 * logs redigidos. Pode ser executado por CLI ou por HTTP autenticado.
 */
require_once __DIR__ . '/../../config/require-agent-key.php';
sv_require_agent_key();

set_time_limit(30);
ignore_user_abort(true);
error_reporting(E_ALL);
ini_set('display_errors', '0');

function svrt_root(): string
{
    return dirname(__DIR__, 2);
}

function svrt_lock_path(): string
{
    $dir = svrt_root() . '/storage/locks';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create lock directory');
    }

    return $dir . '/olist-refresh.lock';
}

function svrt_monitor_dir(): string
{
    $dir = svrt_root() . '/storage/monitoring/olist-token';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create monitoring directory');
    }

    return $dir;
}

function svrt_atomic_write(string $path, string $content, int $mode = 0600): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create destination directory');
    }

    $tmp = tempnam($dir, '.svrt-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary file');
    }

    try {
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary file');
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish atomic file');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function svrt_log(array $payload): void
{
    try {
        $dir = svrt_monitor_dir();
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        file_put_contents(
            $dir . '/refresh-' . gmdate('Y-m-d') . '.jsonl',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        svrt_atomic_write(
            $dir . '/latest.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL,
            0640
        );
    } catch (Throwable $error) {
        error_log('olist token monitoring write failed: ' . $error->getMessage());
    }
}

function svrt_http_status(int $exitCode): int
{
    return match ($exitCode) {
        0 => 200,
        2 => 503,
        6 => 423,
        default => 500,
    };
}

function svrt_finish($lockHandle, string $status, string $message, array $extra = [], int $exitCode = 0): never
{
    $payload = array_merge([
        'status' => $status,
        'message' => $message,
        'timestamp' => gmdate('c'),
        'host' => gethostname() ?: 'unknown',
        'pid' => getmypid(),
    ], $extra);

    svrt_log($payload);

    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(svrt_http_status($exitCode));
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($exitCode);
}

function svrt_load_env(string $envFile): void
{
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function svrt_update_env(string $envFile, array $replacements): void
{
    $target = $envFile;
    if (is_link($envFile)) {
        $resolved = realpath($envFile);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve .env symlink');
        }
        $target = $resolved;
    }

    $content = is_file($target) ? (string)file_get_contents($target) : '';

    foreach ($replacements as $key => $value) {
        $key = (string)$key;
        $value = (string)$value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $content) === 1) {
            $content = (string)preg_replace($pattern, $key . '=' . $value, $content, 1);
        } else {
            $content = rtrim($content) . ($content === '' ? '' : PHP_EOL) . $key . '=' . $value . PHP_EOL;
        }
    }

    svrt_atomic_write($target, rtrim($content) . PHP_EOL, 0600);
}

$lockHandle = null;

try {
    $envFile = svrt_root() . '/.env';
    svrt_load_env($envFile);

    $clientId = getenv('OLIST_CLIENT_ID') ?: getenv('TINY_CLIENT_ID') ?: getenv('CLIENT_ID_API_OLIST');
    $clientSecret = getenv('OLIST_CLIENT_SECRET') ?: getenv('TINY_CLIENT_SECRET') ?: getenv('CLIENT_SECRET_OLIST');
    $refreshToken = getenv('OLIST_REFRESH_TOKEN') ?: getenv('TINY_REFRESH_TOKEN');

    $lockHandle = fopen(svrt_lock_path(), 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        svrt_finish($lockHandle, 'locked', 'Another Olist token refresh is already running', [], 6);
    }

    if (!$clientId || !$clientSecret || !$refreshToken) {
        svrt_finish($lockHandle, 'error', 'Missing Olist OAuth credentials', [
            'has_client_id' => is_string($clientId) && $clientId !== '',
            'has_client_secret' => is_string($clientSecret) && $clientSecret !== '',
            'has_refresh_token' => is_string($refreshToken) && $refreshToken !== '',
        ], 2);
    }

    $curl = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
    if ($curl === false) {
        svrt_finish($lockHandle, 'error', 'Unable to initialize OAuth request', [], 3);
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        svrt_finish($lockHandle, 'error', 'Token refresh request failed at transport layer', [
            'http_code' => $httpCode,
            'transport_error' => $curlError !== '',
        ], 3);
    }

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $oauthError = is_array($data) ? (string)($data['error'] ?? '') : '';
        svrt_finish($lockHandle, 'error', 'Token refresh was rejected by the OAuth provider', [
            'http_code' => $httpCode,
            'oauth_error' => $oauthError,
            'is_invalid_grant' => $oauthError === 'invalid_grant',
        ], 5);
    }

    if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
        svrt_finish($lockHandle, 'error', 'OAuth provider returned no access token', [
            'http_code' => $httpCode,
        ], 4);
    }

    $newAccess = (string)$data['access_token'];
    $newRefresh = is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== ''
        ? (string)$data['refresh_token']
        : (string)$refreshToken;

    svrt_update_env($envFile, [
        'OLIST_ACCESS_TOKEN' => $newAccess,
        'OLIST_REFRESH_TOKEN' => $newRefresh,
        'TINY_ACCESS_TOKEN' => $newAccess,
        'TINY_REFRESH_TOKEN' => $newRefresh,
        'TOKEN_API_OLIST' => $newAccess,
    ]);

    svrt_finish($lockHandle, 'ok', 'Olist tokens refreshed and persisted', [
        'http_code' => $httpCode,
        'refresh_rotated' => $newRefresh !== (string)$refreshToken,
        'expires_in' => max(0, (int)($data['expires_in'] ?? 0)),
        'token_type' => is_string($data['token_type'] ?? null) ? (string)$data['token_type'] : '',
        'monitoring_file' => 'storage/monitoring/olist-token/latest.json',
    ]);
} catch (Throwable $error) {
    error_log('olist token refresh failed: ' . $error->getMessage());
    svrt_finish($lockHandle, 'error', 'Unexpected token refresh failure', [
        'exception' => get_class($error),
    ], 7);
}
