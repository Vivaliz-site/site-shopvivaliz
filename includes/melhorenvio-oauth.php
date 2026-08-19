<?php
declare(strict_types=1);

/**
 * OAuth 2.0 do Melhor Envio.
 * Mantem tokens fora do repositorio e suporta producao/sandbox.
 */

function me_oauth_root(): string
{
    return dirname(__DIR__);
}

function me_oauth_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $constants = me_oauth_root() . '/config/constants.php';
        if (is_file($constants)) {
            require_once $constants;
        }
    }

    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }
    return '';
}

function me_token_path(): string
{
    $dir = me_oauth_root() . '/storage/private';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/melhorenvio-tokens.json';
}

function me_save_tokens(array $data): array
{
    $existing = me_read_tokens() ?? [];
    $expiresAt = isset($data['expires_in'])
        ? time() + max(0, (int)$data['expires_in'])
        : (int)($existing['expires_at'] ?? 0);

    $enriched = array_merge($existing, $data, [
        'saved_at' => gmdate('c'),
        'expires_at' => $expiresAt,
    ]);
    unset($enriched['_refresh_error'], $enriched['_refresh_status']);

    $json = json_encode($enriched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        file_put_contents(me_token_path(), $json . PHP_EOL, LOCK_EX);
    }
    return $enriched;
}

function me_read_tokens(): ?array
{
    $path = me_token_path();
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function me_environment(): string
{
    $configured = strtolower(me_oauth_env('MELHORENVIO_ENV', 'MELHORENVIO_ENVIRONMENT'));
    if (in_array($configured, ['sandbox', 'homolog', 'homologacao', 'test'], true)) {
        return 'sandbox';
    }
    return 'production';
}

function me_base_url(?string $environment = null): string
{
    $environment = $environment ?: me_environment();
    return $environment === 'sandbox'
        ? 'https://sandbox.melhorenvio.com.br'
        : 'https://melhorenvio.com.br';
}

function me_user_agent(): string
{
    return me_oauth_env('MELHORENVIO_USER_AGENT') ?: 'ShopVivaliz (suporte@shopvivaliz.com.br)';
}

function me_redirect_uri(): string
{
    return me_oauth_env('MELHORENVIO_REDIRECT_URI')
        ?: 'https://shopvivaliz.com.br/api/melhorenvio/webhook.php';
}

function me_authorization_url(string $state): string
{
    $clientId = me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID');
    $scope = me_oauth_env('MELHORENVIO_SCOPES')
        ?: 'cart-read cart-write orders-read shipping-calculate shipping-checkout shipping-generate shipping-preview shipping-print shipping-tracking users-read';

    return me_base_url() . '/oauth/authorize?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => me_redirect_uri(),
        'response_type' => 'code',
        'state' => $state,
        'scope' => $scope,
    ], '', '&', PHP_QUERY_RFC3986);
}

function me_validate_oauth_state(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $expected = (string)($_SESSION['melhorenvio_oauth_state'] ?? '');
    if ($expected === '') {
        // Rodada 5 (2026-08-19): antes retornava true aqui -- ou seja,
        // qualquer requisicao SEM sessao (exatamente o caso de um atacante
        // que nunca passou por connect.php) era aceita como valida. Isso
        // permitia que um terceiro com conta Melhor Envio autorizasse o
        // proprio app, capturasse o code do redirect dele, e o replayasse
        // em api/melhorenvio/webhook.php?code=... pra sobrescrever
        // storage/private/melhorenvio-tokens.json com os tokens dele --
        // efetivamente sequestrando a integracao de frete da loja. Sem
        // state esperado na sessao, a resposta correta e recusar, nao
        // aceitar. Ver R5-2 no relatorio da Rodada 5 de melhoria continua.
        return false;
    }
    $createdAt = (int)($_SESSION['melhorenvio_oauth_state_created_at'] ?? 0);
    $received = (string)($_GET['state'] ?? '');
    unset($_SESSION['melhorenvio_oauth_state'], $_SESSION['melhorenvio_oauth_state_created_at']);
    return $received !== ''
        && $createdAt >= time() - 900
        && hash_equals($expected, $received);
}

/** Troca o code recebido no callback OAuth por access_token/refresh_token. */
function me_exchange_code(string $code): array
{
    if (!me_validate_oauth_state()) {
        return ['error' => 'invalid_oauth_state', '_http_status' => 400];
    }
    $fields = [
        'grant_type' => 'authorization_code',
        'client_id' => me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID'),
        'client_secret' => me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET'),
        'redirect_uri' => me_redirect_uri(),
        'code' => $code,
    ];

    $preferred = me_environment();
    $environments = $preferred === 'sandbox'
        ? ['sandbox', 'production']
        : ['production', 'sandbox'];
    $last = ['error' => 'token_exchange_failed'];

    foreach ($environments as $environment) {
        $result = me_oauth_post(me_base_url($environment) . '/oauth/token', $fields);
        if (!empty($result['access_token'])) {
            $result['_token_host'] = $environment;
            return $result;
        }
        $last = $result;
        if (($result['error'] ?? '') !== 'invalid_client') {
            break;
        }
    }

    return $last;
}

function me_refresh_if_needed(bool $force = false): ?array
{
    $tokens = me_read_tokens();
    if ($tokens === null) {
        return null;
    }

    if (!$force && (int)($tokens['expires_at'] ?? 0) > time() + 600) {
        return $tokens;
    }

    $refresh = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refresh === '') {
        $tokens['_refresh_error'] = 'refresh_token_missing';
        return $tokens;
    }

    $fields = [
        'grant_type' => 'refresh_token',
        'client_id' => me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID'),
        'client_secret' => me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET'),
        'refresh_token' => $refresh,
    ];
    $environment = (string)($tokens['_token_host'] ?? me_environment());
    $response = me_oauth_post(me_base_url($environment) . '/oauth/token', $fields);

    if (empty($response['access_token'])) {
        $tokens['_refresh_error'] = (string)($response['error'] ?? 'refresh_failed');
        $tokens['_refresh_status'] = (int)($response['_http_status'] ?? 0);
        return $tokens;
    }

    $response['_token_host'] = $environment;
    return me_save_tokens($response);
}

function me_oauth_post(string $url, array $fields): array
{
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: ' . me_user_agent(),
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return ['error' => 'curl_init_failed', '_http_status' => 0];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [
            'error' => $curlError !== '' ? 'transport_error' : 'invalid_response',
            'error_description' => $curlError !== '' ? $curlError : 'Resposta OAuth nao reconhecida.',
            '_http_status' => $status,
        ];
    }
    $data['_http_status'] = $status;
    return $data;
}

/** Access token valido, priorizando o obtido via OAuth sobre variavel estatica. */
function me_current_access_token(): ?string
{
    $tokens = me_refresh_if_needed();
    if ($tokens !== null && !empty($tokens['access_token'])) {
        return (string)$tokens['access_token'];
    }

    $fallback = me_oauth_env(
        'MELHORENVIO_ACCESS_TOKEN',
        'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN',
        'MELHORENVIO_API_KEY'
    );
    return $fallback !== '' ? $fallback : null;
}

/** Host base da API conforme onde o token atual foi emitido. */
function me_api_base(): string
{
    $tokens = me_read_tokens();
    $environment = (string)($tokens['_token_host'] ?? me_environment());
    return me_base_url($environment);
}
