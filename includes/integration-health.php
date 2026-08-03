<?php
declare(strict_types=1);

/**
 * Monitoramento central das integrações.
 * Tokens nunca são devolvidos em claro: somente presença, máscara e validade.
 */
require_once __DIR__ . '/../config/constants.php';

function svih_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') return trim($_ENV[$key]);
    }
    return '';
}

function svih_mask(string $value): ?string
{
    if ($value === '') return null;
    if (strlen($value) <= 8) return str_repeat('*', strlen($value));
    return substr($value, 0, 4) . str_repeat('*', max(1, strlen($value) - 8)) . substr($value, -4);
}

function svih_http(string $method, string $url, array $headers = [], ?array $fields = null): array
{
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'status' => 0, 'error' => 'curl_init_failed', 'data' => null];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    if ($fields !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $error !== '' ? $error : null, 'data' => is_array($data) ? $data : null];
}

function svih_read_json(string $path): array
{
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function svih_write_json(string $path, array $data): bool
{
    $directory = dirname($path);
    if (!is_dir($directory)) @mkdir($directory, 0750, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) && file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function svih_token_meta(string $token, string $source, ?int $expiresAt = null): array
{
    return ['configured' => $token !== '', 'mask' => svih_mask($token), 'source' => $source, 'expires_at' => $expiresAt ? gmdate('c', $expiresAt) : null];
}

function svih_save_env_tokens(array $values): void
{
    $path = BASE_PATH . '/.env';
    if (!is_file($path) || !is_writable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($values as $key => $value) {
        if (!is_string($key) || !is_string($value) || $value === '') continue;
        $found = false;
        foreach ($lines as &$line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', (string)$line)) { $line = $key . '=' . $value; $found = true; }
        }
        unset($line);
        if (!$found) $lines[] = $key . '=' . $value;
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
}

function svih_olist(bool $fix): array
{
    $stored = svih_read_json(BASE_PATH . '/storage/private/tokens.json');
    $clientId = svih_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST');
    $clientSecret = svih_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'CLIENT_SECRET_OLIST');
    $refresh = svih_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN') ?: (string)($stored['OLIST_REFRESH_TOKEN'] ?? '');
    $access = svih_env('OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'TOKEN_API_OLIST') ?: (string)($stored['OLIST_ACCESS_TOKEN'] ?? '');
    $tokens = ['client_id' => svih_token_meta($clientId, $clientId !== '' ? 'environment' : 'missing'), 'client_secret' => svih_token_meta($clientSecret, $clientSecret !== '' ? 'environment' : 'missing'), 'refresh_token' => svih_token_meta($refresh, $refresh !== '' ? 'environment/private-file' : 'missing'), 'access_token' => svih_token_meta($access, $access !== '' ? 'environment/private-file' : 'missing')];
    $fixes = [];
    if ($clientId === '' || $clientSecret === '' || $refresh === '') return ['name' => 'Olist / Tiny', 'key' => 'olist_tiny', 'status' => 'failed', 'message' => 'Credenciais OAuth incompletas.', 'remediation' => 'Reautorizar OAuth no Tiny.', 'tokens' => $tokens, 'fixes' => $fixes];

    if ($fix) {
        $response = svih_http('POST', 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId, 'client_secret' => $clientSecret]);
        if ($response['ok'] && !empty($response['data']['access_token'])) {
            $access = (string)$response['data']['access_token'];
            $refresh = (string)($response['data']['refresh_token'] ?? $refresh);
            svih_write_json(BASE_PATH . '/storage/private/tokens.json', ['OLIST_ACCESS_TOKEN' => $access, 'OLIST_REFRESH_TOKEN' => $refresh, 'updated_at' => gmdate('c')]);
            svih_save_env_tokens(['OLIST_ACCESS_TOKEN' => $access, 'OLIST_REFRESH_TOKEN' => $refresh, 'TINY_ACCESS_TOKEN' => $access, 'TINY_REFRESH_TOKEN' => $refresh, 'TOKEN_API_OLIST' => $access]);
            $fixes[] = 'Olist/Tiny: token renovado automaticamente.';
        } elseif (!$response['ok']) {
            $error = (string)($response['data']['error'] ?? 'refresh_failed');
            $description = strtolower((string)($response['data']['error_description'] ?? ''));
            $manual = $error === 'invalid_client' || str_contains($description, 'invalid client');
            return ['name' => 'Olist / Tiny', 'key' => 'olist_tiny', 'status' => 'failed', 'message' => $manual ? 'OAuth rejeitou o Client ID/Secret atual.' : 'Falha ao renovar o token OAuth.', 'remediation' => $manual ? 'Reautorizar OAuth no Tiny com o aplicativo correto.' : 'Verificar credenciais e tentar novamente.', 'tokens' => $tokens, 'fixes' => $fixes, 'provider_status' => $response['status'], 'provider_error' => $error];
        }
    }
    $api = svih_http('GET', 'https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0', ['Authorization: Bearer ' . $access, 'Accept: application/json', 'User-Agent: ShopVivaliz-IntegrationMonitor/1.0']);
    $tokens['access_token'] = svih_token_meta($access, 'environment/private-file');
    return ['name' => 'Olist / Tiny', 'key' => 'olist_tiny', 'status' => $api['ok'] ? 'connected' : 'failed', 'message' => $api['ok'] ? 'API de produtos respondeu.' : 'API do Tiny não respondeu com sucesso.', 'remediation' => $api['ok'] ? null : 'Renovar token; se invalid_client, reautorizar OAuth.', 'tokens' => $tokens, 'fixes' => $fixes, 'provider_status' => $api['status']];
}

function svih_ml(bool $fix): array
{
    $path = BASE_PATH . '/storage/private/ml-tokens.json';
    $stored = svih_read_json($path);
    $access = svih_env('ML_ACCESS_TOKEN') ?: (string)($stored['access_token'] ?? '');
    $refresh = svih_env('ML_REFRESH_TOKEN') ?: (string)($stored['refresh_token'] ?? '');
    $clientId = svih_env('ML_CLIENT_ID');
    $clientSecret = svih_env('ML_CLIENT_SECRET');
    $expiresAt = isset($stored['expires_at_ms']) ? (int)($stored['expires_at_ms'] / 1000) : null;
    $tokens = ['access_token' => svih_token_meta($access, $access !== '' && getenv('ML_ACCESS_TOKEN') ? 'environment' : 'private-file', $expiresAt), 'refresh_token' => svih_token_meta($refresh, $refresh !== '' ? 'environment/private-file' : 'missing'), 'client_id' => svih_token_meta($clientId, $clientId !== '' ? 'environment' : 'missing'), 'client_secret' => svih_token_meta($clientSecret, $clientSecret !== '' ? 'environment' : 'missing')];
    $fixes = [];
    $refreshMl = static function () use ($clientId, $clientSecret, $refresh, $path, &$fixes): string {
        if ($clientId === '' || $clientSecret === '' || $refresh === '') return '';
        $response = svih_http('POST', 'https://api.mercadolibre.com/oauth/token', [], ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'client_secret' => $clientSecret, 'refresh_token' => $refresh]);
        if (!$response['ok'] || empty($response['data']['access_token'])) return '';
        $data = $response['data']; $data['created_at_ms'] = (int)(microtime(true) * 1000); $data['expires_at_ms'] = (int)(microtime(true) * 1000) + ((int)($data['expires_in'] ?? 0) * 1000);
        svih_write_json($path, $data); $fixes[] = 'Mercado Livre: token renovado automaticamente.'; return (string)$data['access_token'];
    };
    if ($access === '' && $fix) $access = $refreshMl();
    elseif ($fix && $expiresAt !== null && $expiresAt <= time() + 600) $access = $refreshMl() ?: $access;
    if ($access === '') return ['name' => 'Mercado Livre', 'key' => 'mercado_livre', 'status' => 'failed', 'message' => 'OAuth não configurado.', 'remediation' => 'Conectar OAuth do Mercado Livre.', 'tokens' => $tokens, 'fixes' => $fixes];
    $api = svih_http('GET', 'https://api.mercadolibre.com/users/me', ['Authorization: Bearer ' . $access, 'Accept: application/json']);
    if (!$api['ok'] && $api['status'] === 401 && $fix) { $access = $refreshMl(); if ($access !== '') $api = svih_http('GET', 'https://api.mercadolibre.com/users/me', ['Authorization: Bearer ' . $access, 'Accept: application/json']); }
    $tokens['access_token'] = svih_token_meta($access, 'private-file', $expiresAt);
    return ['name' => 'Mercado Livre', 'key' => 'mercado_livre', 'status' => $api['ok'] ? 'connected' : 'failed', 'message' => $api['ok'] ? 'Conta autenticada respondeu.' : 'OAuth do Mercado Livre falhou.', 'remediation' => $api['ok'] ? null : 'Renovar ou refazer OAuth.', 'tokens' => $tokens, 'fixes' => $fixes, 'provider_status' => $api['status']];
}

function svih_bearer(string $name, string $key, array $envKeys, string $url, string $remediation): array
{
    $token = svih_env(...$envKeys); $tokens = ['access_token' => svih_token_meta($token, $token !== '' ? 'environment' : 'missing')];
    if ($token === '') return ['name' => $name, 'key' => $key, 'status' => 'failed', 'message' => 'Token não configurado.', 'remediation' => $remediation, 'tokens' => $tokens, 'fixes' => []];
    $api = svih_http('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json', 'User-Agent: ShopVivaliz-IntegrationMonitor/1.0']);
    return ['name' => $name, 'key' => $key, 'status' => $api['ok'] ? 'connected' : 'failed', 'message' => $api['ok'] ? 'Endpoint autenticado respondeu.' : 'Endpoint rejeitou o token.', 'remediation' => $api['ok'] ? null : $remediation, 'tokens' => $tokens, 'fixes' => [], 'provider_status' => $api['status']];
}

function svih_config_only(string $name, string $key, array $envKeys, string $remediation): array
{
    $token = svih_env(...$envKeys);
    return ['name' => $name, 'key' => $key, 'status' => $token !== '' ? 'attention' : 'not_configured', 'message' => $token !== '' ? 'Segredo configurado; provedor sem teste seguro neste monitor.' : 'Segredo não configurado.', 'remediation' => $token !== '' ? 'Validar no painel oficial quando necessário.' : $remediation, 'tokens' => ['secret' => svih_token_meta($token, $token !== '' ? 'environment' : 'missing')], 'validation' => 'configuration_only', 'fixes' => []];
}

function svih_check_all(bool $fix = false): array
{
    $integrations = [
        svih_olist($fix),
        svih_ml($fix),
        svih_bearer('Mercado Pago', 'mercado_pago', ['MERCADOPAGO_ACCESS_TOKEN'], 'https://api.mercadopago.com/v1/payment_methods', 'Reconfigurar access token do Mercado Pago.'),
        svih_bearer('Melhor Envio', 'melhor_envio', ['MELHORENVIO_ACCESS_TOKEN', 'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN', 'MELHORENVIO_API_KEY'], 'https://melhorenvio.com.br/api/v2/me', 'Reconectar OAuth do Melhor Envio.'),
        svih_config_only('Facebook CAPI', 'facebook_capi', ['FACEBOOK_ACCESS_TOKEN'], 'Configurar FACEBOOK_ACCESS_TOKEN.'),
        svih_config_only('Google Ads', 'google_ads', ['GOOGLE_ADS_REFRESH_TOKEN'], 'Conectar OAuth do Google Ads.'),
        svih_config_only('TikTok Pixel', 'tiktok_pixel', ['TIKTOK_PIXEL_TOKEN'], 'Configurar TIKTOK_PIXEL_TOKEN.'),
        svih_config_only('SMTP / E-mail', 'smtp', ['SMTP_PASS', 'EMAIL_PASSWORD', 'MAIL_PASS'], 'Configurar credencial SMTP.'),
    ];
    $connected = count(array_filter($integrations, static fn(array $item): bool => $item['status'] === 'connected'));
    $failed = count(array_filter($integrations, static fn(array $item): bool => $item['status'] === 'failed'));
    $attention = count(array_filter($integrations, static fn(array $item): bool => in_array($item['status'], ['attention', 'not_configured'], true)));
    $now = gmdate('c'); $path = BASE_PATH . '/storage/private/integration-health.json'; $old = svih_read_json($path); $history = is_array($old['history'] ?? null) ? $old['history'] : [];
    $history[] = ['checked_at' => $now, 'connected' => $connected, 'failed' => $failed, 'attention' => $attention]; $history = array_slice($history, -48);
    $result = ['ok' => $failed === 0 && $attention === 0, 'checked_at' => $now, 'auto_fix_attempted' => $fix, 'summary' => ['total' => count($integrations), 'connected' => $connected, 'failed' => $failed, 'attention' => $attention], 'integrations' => $integrations, 'history' => $history];
    svih_write_json($path, $result); return $result;
}

function svih_read_state(): array
{
    return svih_read_json(BASE_PATH . '/storage/private/integration-health.json');
}
