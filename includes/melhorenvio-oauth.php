<?php
declare(strict_types=1);

/**
 * OAuth 2.0 do Melhor Envio -- troca de codigo por token e refresh,
 * mesmo padrao usado em api/ml/client.php para o Mercado Livre.
 */

function me_oauth_root(): string { return dirname(__DIR__); }

function me_oauth_env(string ...$keys): string {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $constants = me_oauth_root() . '/config/constants.php';
        if (is_file($constants)) {
            require_once $constants;
        }
        $f = me_oauth_root() . '/.env';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') return $v;
    }
    return '';
}

function me_token_path(): string {
    $dir = me_oauth_root() . '/storage/private';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/melhorenvio-tokens.json';
}

function me_save_tokens(array $data): array {
    $existing = me_read_tokens() ?? [];
    $expiresAt = isset($data['expires_in'])
        ? time() + (int)$data['expires_in']
        : ($existing['expires_at'] ?? 0);

    $enriched = array_merge($existing, $data, [
        'saved_at' => date('c'),
        'expires_at' => $expiresAt,
    ]);
    file_put_contents(me_token_path(), json_encode($enriched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $enriched;
}

function me_read_tokens(): ?array {
    $path = me_token_path();
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Troca o "code" recebido no callback OAuth por access_token/refresh_token. */
function me_exchange_code(string $code): array {
    // O .env de producao tem a variavel gravada como MELHORENVIO_CLIENTE_ID
    // (em portugues), nao MELHORENVIO_CLIENT_ID -- sem esse alias o OAuth
    // nunca completava a troca de code por token (client_id sempre vazio).
    $clientId = me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID');
    $clientSecret = me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET');
    $redirectUri = me_oauth_env('MELHORENVIO_REDIRECT_URI') ?: 'https://dev.shopvivaliz.com.br/api/melhorenvio/webhook.php';

    $fields = [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'code' => $code,
    ];

    $result = me_oauth_post('https://www.melhorenvio.com.br/oauth/token', $fields);
    if (!empty($result['access_token'])) {
        $result['_token_host'] = 'production';
        return $result;
    }

    // App registrado na area de desenvolvimento (app.melhorenvio.com.br/integracoes/area-dev)
    // usa o endpoint de sandbox, nao o de producao.
    if (($result['error'] ?? '') === 'invalid_client') {
        $sandboxResult = me_oauth_post('https://sandbox.melhorenvio.com.br/oauth/token', $fields);
        if (!empty($sandboxResult['access_token'])) {
            $sandboxResult['_token_host'] = 'sandbox';
            return $sandboxResult;
        }
    }

    return $result;
}

function me_refresh_if_needed(): ?array {
    $tokens = me_read_tokens();
    if ($tokens === null) return null;

    $tenMin = 600;
    if (($tokens['expires_at'] ?? 0) > time() + $tenMin) {
        return $tokens;
    }

    $refresh = $tokens['refresh_token'] ?? '';
    if ($refresh === '') return $tokens;

    $refreshFields = [
        'grant_type' => 'refresh_token',
        'client_id' => me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID'),
        'client_secret' => me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET'),
        'refresh_token' => $refresh,
    ];
    $tokenHost = str_contains((string)($tokens['_token_host'] ?? ''), 'sandbox')
        ? 'https://sandbox.melhorenvio.com.br/oauth/token'
        : 'https://www.melhorenvio.com.br/oauth/token';
    $resp = me_oauth_post($tokenHost, $refreshFields);

    if (!isset($resp['access_token'])) {
        return $tokens; // refresh falhou, mantem o que tinha
    }

    return me_save_tokens($resp);
}

function me_oauth_post(string $url, array $fields): array {
    // Melhor Envio rejeita client_id/client_secret no corpo com
    // "invalid_client" -- exige HTTP Basic Auth (padrao OAuth2 para
    // clientes confidenciais). Envia dos dois jeitos: Basic Auth no
    // header, e sem client_id/client_secret duplicado no corpo.
    $clientId = (string)($fields['client_id'] ?? '');
    $clientSecret = (string)($fields['client_secret'] ?? '');
    unset($fields['client_id'], $fields['client_secret']);

    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    if ($clientId !== '' && $clientSecret !== '') {
        $headers[] = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['error' => 'invalid_response', 'status' => $status, 'raw' => substr($body, 0, 300)];
    }
    $data['_http_status'] = $status;
    return $data;
}

/** Access token valido, priorizando o obtido via OAuth sobre variavel de ambiente estatica. */
function me_current_access_token(): ?string {
    $tokens = me_refresh_if_needed();
    if ($tokens !== null && !empty($tokens['access_token'])) {
        return (string)$tokens['access_token'];
    }
    return null;
}

/** Host base da API (sandbox ou producao) conforme onde o token atual foi emitido. */
function me_api_base(): string {
    $tokens = me_read_tokens();
    $host = (string)($tokens['_token_host'] ?? 'production');
    return $host === 'sandbox' ? 'https://sandbox.melhorenvio.com.br' : 'https://www.melhorenvio.com.br';
}
