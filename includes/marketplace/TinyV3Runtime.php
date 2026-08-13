<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tiny-order-push.php';

/**
 * Runtime resiliente para chamadas Tiny/Olist API v3 usadas pelos publicadores.
 *
 * Regras:
 * - usa um cache privado compartilhado entre releases e o daemon renovador;
 * - renova preventivamente quando o access token entra na janela de 30 minutos;
 * - em HTTP 401 tenta renovar o OAuth uma unica vez e repete a chamada;
 * - persiste access/refresh tokens rotacionados atomicamente, sem logar segredos.
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

/** @param array<string,mixed> $tokens */
function sv_market_tiny_persist_tokens(array $tokens): void
{
    $path = sv_market_tiny_token_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar o diretorio privado de tokens Tiny/Olist.');
    }

    $payload = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Nao foi possivel serializar os tokens Tiny/Olist.');
    }
    $payload .= PHP_EOL;

    $tmp = tempnam($dir, '.tiny-v3-token-');
    if ($tmp === false) {
        throw new RuntimeException('Nao foi possivel criar arquivo temporario de token Tiny/Olist.');
    }
    try {
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Nao foi possivel gravar o token Tiny/Olist.');
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Nao foi possivel publicar o token Tiny/Olist atomicamente.');
        }
        @chmod($path, 0660);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
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
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN'] as $key) {
        $value = sv_market_tiny_env($key);
        if ($value !== '') {
            return $value;
        }
    }
    return function_exists('svtop_tiny_get_token') ? trim((string)svtop_tiny_get_token()) : '';
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
    if (!is_array($json)) {
        return 0;
    }
    $expiry = (int)($json['exp'] ?? 0);
    return $expiry > 0 ? $expiry : 0;
}

function sv_market_tiny_expiry_epoch(string $token): int
{
    $tokens = sv_market_tiny_token_store();
    $storedToken = trim((string)($tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? ''));
    if ($storedToken !== '' && hash_equals($storedToken, $token)) {
        $storedExpiry = (int)($tokens['expires_at_epoch'] ?? 0);
        if ($storedExpiry > 0) {
            return $storedExpiry;
        }
        $storedIso = trim((string)($tokens['expires_at'] ?? ''));
        if ($storedIso !== '') {
            $parsed = strtotime($storedIso);
            if (is_int($parsed) && $parsed > 0) {
                return $parsed;
            }
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
        return true;
    }
    $current = $now ?? time();
    return ($expiry - $current) <= SV_MARKET_TINY_REFRESH_MARGIN_SECONDS;
}

/**
 * @return array{access_token:string,refresh_token:string,credential_alias:string,refresh_alias:string}
 */
function sv_market_tiny_refresh_access_token(): array
{
    $tokens = sv_market_tiny_token_store();

    $refreshCandidates = [];
    foreach (['OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'] as $key) {
        $value = trim((string)($tokens[$key] ?? ''));
        if ($value !== '') {
            $refreshCandidates['token_store:' . $key] = $value;
        }
    }
    foreach (['OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'] as $key) {
        $value = sv_market_tiny_env($key);
        if ($value !== '') {
            $refreshCandidates['env:' . $key] = $value;
        }
    }

    $credentialPairs = [];
    foreach ([
        'olist' => ['OLIST_CLIENT_ID', 'OLIST_CLIENT_SECRET'],
        'tiny' => ['TINY_CLIENT_ID', 'TINY_CLIENT_SECRET'],
        'legacy' => ['CLIENT_ID_API_OLIST', 'CLIENT_SECRET_OLIST'],
    ] as $alias => [$idKey, $secretKey]) {
        $clientId = sv_market_tiny_env($idKey);
        $clientSecret = sv_market_tiny_env($secretKey);
        if ($clientId !== '' && $clientSecret !== '') {
            $credentialPairs[$alias] = [$clientId, $clientSecret];
        }
    }

    if ($refreshCandidates === [] || $credentialPairs === []) {
        throw new RuntimeException('Credenciais de renovacao Tiny/Olist incompletas.');
    }

    $seen = [];
    $lastHttp = 0;
    $lastOauthError = '';
    foreach ($refreshCandidates as $refreshAlias => $refreshToken) {
        foreach ($credentialPairs as $credentialAlias => [$clientId, $clientSecret]) {
            $signature = hash('sha256', $refreshToken . '|' . $clientId . '|' . $clientSecret);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar renovacao OAuth Tiny/Olist.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ], '', '&', PHP_QUERY_RFC3986),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
            ]);
            $raw = curl_exec($ch);
            $lastHttp = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $json = is_array($decoded) ? $decoded : [];
            $accessToken = trim((string)($json['access_token'] ?? ''));
            $lastOauthError = trim((string)($json['error'] ?? ''));
            if ($lastHttp !== 200 || $accessToken === '') {
                continue;
            }

            $newRefresh = trim((string)($json['refresh_token'] ?? ''));
            if ($newRefresh === '') {
                $newRefresh = $refreshToken;
            }
            $tokens['OLIST_ACCESS_TOKEN'] = $accessToken;
            $tokens['TINY_ACCESS_TOKEN'] = $accessToken;
            $tokens['OLIST_REFRESH_TOKEN'] = $newRefresh;
            $tokens['TINY_REFRESH_TOKEN'] = $newRefresh;
            $tokens['updated_at'] = gmdate('c');
            $expiresIn = max(0, (int)($json['expires_in'] ?? 0));
            if ($expiresIn > 0) {
                $expiresAt = time() + $expiresIn;
                $tokens['expires_in'] = $expiresIn;
                $tokens['expires_at_epoch'] = $expiresAt;
                $tokens['expires_at'] = gmdate('c', $expiresAt);
            }
            sv_market_tiny_persist_tokens($tokens);

            putenv('OLIST_ACCESS_TOKEN=' . $accessToken);
            putenv('TINY_ACCESS_TOKEN=' . $accessToken);
            putenv('OLIST_REFRESH_TOKEN=' . $newRefresh);
            putenv('TINY_REFRESH_TOKEN=' . $newRefresh);
            $_ENV['OLIST_ACCESS_TOKEN'] = $accessToken;
            $_ENV['TINY_ACCESS_TOKEN'] = $accessToken;
            $_ENV['OLIST_REFRESH_TOKEN'] = $newRefresh;
            $_ENV['TINY_REFRESH_TOKEN'] = $newRefresh;

            return [
                'access_token' => $accessToken,
                'refresh_token' => $newRefresh,
                'credential_alias' => (string)$credentialAlias,
                'refresh_alias' => (string)$refreshAlias,
            ];
        }
    }

    $safeError = $lastOauthError !== '' ? ' oauth_error=' . preg_replace('/[^a-z0-9_\-]/i', '', $lastOauthError) : '';
    throw new RuntimeException('Renovacao OAuth Tiny/Olist recusada: HTTP ' . $lastHttp . $safeError . '. Gere novas chaves do aplicativo no ERP e atualize os secrets de producao.');
}

function sv_market_tiny_ensure_access_token(): string
{
    $token = sv_market_tiny_access_token();
    if (!sv_market_tiny_token_requires_refresh($token)) {
        return $token;
    }

    $expiry = $token !== '' ? sv_market_tiny_expiry_epoch($token) : 0;
    try {
        $refreshed = sv_market_tiny_refresh_access_token();
        return $refreshed['access_token'];
    } catch (Throwable $error) {
        // Se o refresh preventivo falhar transitoriamente, continue usando o
        // token ainda valido; o daemon tenta novamente a cada poucos minutos.
        if ($token !== '' && $expiry > (time() + 60)) {
            return $token;
        }
        throw $error;
    }
}

/**
 * @param array<string,mixed>|null $payload
 * @return array{status:int,body:string,json:array<string,mixed>}
 */
function sv_market_tiny_request_v3(string $method, string $path, ?array $payload = null): array
{
    $token = sv_market_tiny_ensure_access_token();

    $response = svtop_tiny_request($method, $path, $token, $payload);
    if ((int)$response['status'] !== 401) {
        return $response;
    }

    $refreshed = sv_market_tiny_refresh_access_token();
    return svtop_tiny_request($method, $path, $refreshed['access_token'], $payload);
}
