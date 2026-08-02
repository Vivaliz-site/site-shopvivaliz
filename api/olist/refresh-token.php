<?php
declare(strict_types=1);

/**
 * Renova o token OAuth Olist/Tiny com lock exclusivo, persistencia atomica e
 * evidencia correlacionavel. Pode ser executado por CLI ou HTTP autenticado.
 */
require_once __DIR__ . '/../../config/require-agent-key.php';
sv_require_agent_key();

set_time_limit(30);
ignore_user_abort(true);
error_reporting(E_ALL);
ini_set('display_errors', '0');

function svrt_root(): string
{
    $override = getenv('SVRT_ROOT_OVERRIDE');
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), DIRECTORY_SEPARATOR);
    }

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
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary file');
        }

        try {
            $written = fwrite($handle, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException('Unable to write complete temporary file');
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush temporary file');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to sync temporary file');
            }
        } finally {
            fclose($handle);
        }

        if (!@chmod($tmp, $mode)) {
            throw new RuntimeException('Unable to set file permissions');
        }
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish atomic file');
        }

        $published = @file_get_contents($path);
        if (!is_string($published) || !hash_equals(hash('sha256', $content), hash('sha256', $published))) {
            throw new RuntimeException('Atomic file verification failed');
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function svrt_json(array $payload, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }

    $encoded = json_encode($payload, $flags);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode JSON evidence');
    }

    return $encoded;
}

function svrt_release_sha(): string
{
    $path = svrt_root() . '/.release-sha';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $sha = trim((string)file_get_contents($path));
    return preg_match('/^[a-f0-9]{40}$/i', $sha) === 1 ? strtolower($sha) : '';
}

function svrt_write_monitoring(array $payload): string
{
    $executionId = (string)($payload['execution_id'] ?? '');
    if ($executionId === '') {
        throw new RuntimeException('Monitoring payload requires execution_id');
    }

    $dir = svrt_monitor_dir();
    $dailyPath = $dir . '/refresh-' . gmdate('Y-m-d') . '.jsonl';
    $line = svrt_json($payload) . PHP_EOL;
    $written = @file_put_contents($dailyPath, $line, FILE_APPEND | LOCK_EX);
    if (!is_int($written) || $written !== strlen($line)) {
        throw new RuntimeException('Unable to append monitoring journal');
    }

    $latestPath = $dir . '/latest.json';
    svrt_atomic_write($latestPath, svrt_json($payload, true) . PHP_EOL, 0640);

    $latestRaw = @file_get_contents($latestPath);
    $latest = is_string($latestRaw) ? json_decode($latestRaw, true) : null;
    if (!is_array($latest) || !hash_equals($executionId, (string)($latest['execution_id'] ?? ''))) {
        throw new RuntimeException('Monitoring evidence verification failed');
    }

    return $latestPath;
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

function svrt_finish(
    $lockHandle,
    array $context,
    string $status,
    string $message,
    array $extra = [],
    int $exitCode = 0
): never {
    $payload = array_merge([
        'status' => $status,
        'message' => $message,
        'execution_id' => (string)($context['execution_id'] ?? ''),
        'started_at' => (string)($context['started_at'] ?? ''),
        'timestamp' => gmdate('c'),
        'host' => gethostname() ?: 'unknown',
        'pid' => getmypid(),
        'run_id' => (string)($context['run_id'] ?? ''),
        'trigger' => (string)($context['trigger'] ?? ''),
        'release_sha' => svrt_release_sha(),
    ], $extra);

    try {
        $monitoringPath = svrt_write_monitoring($payload);
        $payload['monitoring_file'] = str_replace(svrt_root() . '/', '', $monitoringPath);
    } catch (Throwable $monitoringError) {
        error_log('olist token monitoring write failed: ' . $monitoringError->getMessage());
        $payload['original_status'] = $status;
        $payload['status'] = 'error';
        $payload['message'] = 'Monitoring evidence could not be persisted';
        $payload['monitoring_error'] = get_class($monitoringError);
        $exitCode = 8;
    }

    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(svrt_http_status($exitCode));
    }

    try {
        echo svrt_json($payload, true) . PHP_EOL;
    } catch (Throwable) {
        echo '{"status":"error","message":"Unable to encode response"}' . PHP_EOL;
        $exitCode = 9;
    }

    exit($exitCode);
}

function svrt_load_env(string $envFile): void
{
    if (!file_exists($envFile)) {
        return;
    }
    if (!is_file($envFile) || !is_readable($envFile)) {
        throw new RuntimeException('Existing .env is not readable');
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read .env');
    }

    foreach ($lines as $line) {
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

function svrt_env_target(string $envFile): string
{
    if (is_link($envFile)) {
        $resolved = realpath($envFile);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve .env symlink target');
        }
        $envFile = $resolved;
    }

    if (!is_file($envFile) || !is_readable($envFile) || !is_writable($envFile)) {
        throw new RuntimeException('Persistent .env target is not readable and writable');
    }

    return $envFile;
}

function svrt_update_env_target(string $target, array $replacements): void
{
    $content = @file_get_contents($target);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read persistent .env target');
    }

    foreach ($replacements as $key => $value) {
        $key = (string)$key;
        $value = (string)$value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        $matched = preg_match($pattern, $content);
        if ($matched === false) {
            throw new RuntimeException('Unable to inspect .env key ' . $key);
        }

        if ($matched === 1) {
            $updated = preg_replace($pattern, $key . '=' . $value, $content, 1);
            if (!is_string($updated)) {
                throw new RuntimeException('Unable to replace .env key ' . $key);
            }
            $content = $updated;
        } else {
            $content = rtrim($content) . ($content === '' ? '' : PHP_EOL) . $key . '=' . $value . PHP_EOL;
        }
    }

    $content = rtrim($content) . PHP_EOL;
    svrt_atomic_write($target, $content, 0600);

    $verified = @file_get_contents($target);
    if (!is_string($verified)) {
        throw new RuntimeException('Unable to verify persistent .env target');
    }
    foreach ($replacements as $key => $value) {
        if (!str_contains($verified, (string)$key . '=' . (string)$value)) {
            throw new RuntimeException('Persistent .env verification failed for ' . (string)$key);
        }
    }
}

function svrt_validate_evidence(
    array $result,
    array $latest,
    int $workflowStartedEpoch,
    string $expectedRunId
): array {
    foreach (['execution_id', 'timestamp', 'host', 'pid', 'run_id', 'status'] as $field) {
        if (!array_key_exists($field, $result) || !array_key_exists($field, $latest)) {
            throw new RuntimeException('Missing evidence field: ' . $field);
        }
        if ((string)$result[$field] !== (string)$latest[$field]) {
            throw new RuntimeException('Evidence mismatch for field: ' . $field);
        }
    }

    if (($result['status'] ?? '') !== 'ok') {
        throw new RuntimeException('Token refresh did not return status=ok');
    }
    if (!hash_equals($expectedRunId, (string)($result['run_id'] ?? ''))) {
        throw new RuntimeException('Evidence run_id does not match current workflow');
    }

    $timestamp = strtotime((string)$result['timestamp']);
    if ($timestamp === false || $timestamp < ($workflowStartedEpoch - 5) || $timestamp > (time() + 60)) {
        throw new RuntimeException('Evidence timestamp is outside the current execution window');
    }

    $executionId = (string)$result['execution_id'];
    if (preg_match('/^[a-f0-9]{32}$/', $executionId) !== 1) {
        throw new RuntimeException('Invalid execution_id format');
    }

    return [
        'status' => 'ok',
        'execution_id' => $executionId,
        'timestamp' => (string)$result['timestamp'],
        'host' => (string)$result['host'],
        'pid' => (int)$result['pid'],
        'run_id' => (string)$result['run_id'],
        'trigger' => (string)($result['trigger'] ?? ''),
        'release_sha' => (string)($result['release_sha'] ?? ''),
        'monitoring_file' => (string)($result['monitoring_file'] ?? ''),
    ];
}

function svrt_main(): never
{
    $context = [
        'execution_id' => bin2hex(random_bytes(16)),
        'started_at' => gmdate('c'),
        'run_id' => trim((string)(getenv('SHOPVIVALIZ_REFRESH_RUN_ID') ?: 'local-cli')),
        'trigger' => trim((string)(getenv('SHOPVIVALIZ_REFRESH_TRIGGER') ?: (PHP_SAPI === 'cli' ? 'cli' : 'http'))),
    ];
    $lockHandle = null;

    try {
        $lockHandle = @fopen(svrt_lock_path(), 'c+');
        if ($lockHandle === false) {
            svrt_finish($lockHandle, $context, 'error', 'Unable to open Olist refresh lock', [], 7);
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            svrt_finish($lockHandle, $context, 'locked', 'Another Olist token refresh is already running', [], 6);
        }

        $envFile = svrt_root() . '/.env';
        svrt_load_env($envFile);
        $envTarget = svrt_env_target($envFile);

        $clientId = getenv('OLIST_CLIENT_ID') ?: getenv('TINY_CLIENT_ID') ?: getenv('CLIENT_ID_API_OLIST');
        $clientSecret = getenv('OLIST_CLIENT_SECRET') ?: getenv('TINY_CLIENT_SECRET') ?: getenv('CLIENT_SECRET_OLIST');
        $refreshToken = getenv('OLIST_REFRESH_TOKEN') ?: getenv('TINY_REFRESH_TOKEN');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            svrt_finish($lockHandle, $context, 'error', 'Missing Olist OAuth credentials', [
                'has_client_id' => is_string($clientId) && $clientId !== '',
                'has_client_secret' => is_string($clientSecret) && $clientSecret !== '',
                'has_refresh_token' => is_string($refreshToken) && $refreshToken !== '',
            ], 2);
        }

        $curl = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
        if ($curl === false) {
            svrt_finish($lockHandle, $context, 'error', 'Unable to initialize OAuth request', [], 3);
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
            svrt_finish($lockHandle, $context, 'error', 'Token refresh request failed at transport layer', [
                'http_code' => $httpCode,
                'transport_error' => $curlError !== '',
            ], 3);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 200) {
            $oauthError = is_array($data) ? (string)($data['error'] ?? '') : '';
            svrt_finish($lockHandle, $context, 'error', 'Token refresh was rejected by the OAuth provider', [
                'http_code' => $httpCode,
                'oauth_error' => $oauthError,
                'is_invalid_grant' => $oauthError === 'invalid_grant',
            ], 5);
        }

        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            svrt_finish($lockHandle, $context, 'error', 'OAuth provider returned no access token', [
                'http_code' => $httpCode,
            ], 4);
        }

        $newAccess = (string)$data['access_token'];
        $newRefresh = is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== ''
            ? (string)$data['refresh_token']
            : (string)$refreshToken;

        svrt_update_env_target($envTarget, [
            'OLIST_ACCESS_TOKEN' => $newAccess,
            'OLIST_REFRESH_TOKEN' => $newRefresh,
            'TINY_ACCESS_TOKEN' => $newAccess,
            'TINY_REFRESH_TOKEN' => $newRefresh,
            'TOKEN_API_OLIST' => $newAccess,
        ]);

        svrt_finish($lockHandle, $context, 'ok', 'Olist tokens refreshed and persisted', [
            'http_code' => $httpCode,
            'refresh_rotated' => $newRefresh !== (string)$refreshToken,
            'expires_in' => max(0, (int)($data['expires_in'] ?? 0)),
            'token_type' => is_string($data['token_type'] ?? null) ? (string)$data['token_type'] : '',
        ]);
    } catch (Throwable $error) {
        error_log('olist token refresh failed: ' . $error->getMessage());
        svrt_finish($lockHandle, $context, 'error', 'Unexpected token refresh failure', [
            'exception' => get_class($error),
        ], 7);
    }
}

if (!defined('SVRT_LIBRARY_ONLY')) {
    svrt_main();
}
