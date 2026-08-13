<?php
declare(strict_types=1);

/**
 * Monitoramento central das integracoes.
 * Tokens nunca sao devolvidos em claro: somente presenca, mascara e validade.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/melhorenvio-oauth.php';

function svih_env(string ...$keys): string
{
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

function svih_strip_bearer(string $token): string
{
    $token = trim($token);
    return preg_replace('/^Bearer\s+/i', '', $token) ?: $token;
}

function svih_valid_oauth_credential(string $value): bool
{
    $value = trim($value);
    return strlen($value) >= 16 && !in_array(strtolower($value), [
        'changeme', 'change-me', 'placeholder', 'client_id', 'client_secret',
    ], true);
}

function svih_mask(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('*', max(1, strlen($value) - 8)) . substr($value, -4);
}

function svih_http(string $method, string $url, array $headers = [], ?array $fields = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init_failed', 'data' => null, 'raw' => null];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($fields !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body, true) : null;
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error !== '' ? $error : null,
        'data' => is_array($data) ? $data : null,
        'raw' => is_string($body) ? substr($body, 0, 500) : null,
    ];
}

function svih_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function svih_write_json(string $path, array $data): bool
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) && file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function svih_token_meta(string $token, string $source, ?int $expiresAt = null): array
{
    return [
        'configured' => $token !== '',
        'mask' => svih_mask($token),
        'source' => $source,
        'expires_at' => $expiresAt ? gmdate('c', $expiresAt) : null,
    ];
}

function svih_env_targets(): array
{
    $projectParent = dirname(BASE_PATH);
    $deployRoot = basename($projectParent) === 'releases'
        ? dirname($projectParent)
        : $projectParent;

    return array_values(array_unique([
        BASE_PATH . '/.env',
        $deployRoot . '/shared/.env',
    ]));
}

function svih_olist_token_store_path(): string
{
    $explicit = trim((string)(getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE') ?: ''));
    if ($explicit !== '') {
        return $explicit;
    }

    $projectParent = dirname(BASE_PATH);
    $deployRoot = basename($projectParent) === 'releases'
        ? dirname($projectParent)
        : $projectParent;
    $sharedStore = $deployRoot . '/shared/private/olist-tokens.json';
    if (is_dir($deployRoot . '/shared')) {
        return $sharedStore;
    }

    return BASE_PATH . '/storage/private/olist-tokens.json';
}

function svih_olist_api(string $accessToken): array
{
    if ($accessToken === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'token_missing'];
    }
    return svih_http(
        'GET',
        'https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0',
        [
            'Authorization: Bearer ' . svih_strip_bearer($accessToken),
            'Accept: application/json',
            'User-Agent: ShopVivaliz-IntegrationMonitor/2.0',
        ]
    );
}

function svih_olist_candidates(array $stored): array
{
    $storedFamily = (string)($stored['credential_family'] ?? '');
    $storedRefresh = (string)($stored['OLIST_REFRESH_TOKEN'] ?? $stored['TINY_REFRESH_TOKEN'] ?? '');
    $storedAccess = (string)($stored['OLIST_ACCESS_TOKEN'] ?? $stored['TINY_ACCESS_TOKEN'] ?? '');

    // client_id/client_secret sao o MESMO app OAuth2 do Tiny/Olist, so
    // gravado sob nomes de variavel diferentes por convencao historica (ver
    // docs/knowledge/secrets-and-integrations-map.md: CLIENT_ID_API_OLIST ->
    // OLIST_CLIENT_ID sao apelidos do mesmo valor, nao apps diferentes).
    // BUG CORRIGIDO 2026-08-05: o commit 17a6c5ef7 (#747) passou a exigir
    // que client_id/client_secret venham da MESMA familia (olist/tiny/
    // legacy) que teria tambem o refresh_token, em vez de aceitar qualquer
    // nome valido — se as credenciais reais estivessem gravadas sob um
    // nome (ex: CLIENT_ID_API_OLIST) e o refresh_token persistido tivesse
    // credential_family diferente (ex: 'tiny'), NENHUMA familia batia com
    // as 3 pecas completas e o painel reportava "credenciais incompletas
    // ou placeholders" mesmo com tudo configurado. Fallback único abaixo
    // restaura o comportamento anterior para client_id/secret, mantendo a
    // separação por família só para refresh/access token (que sim podem
    // ser específicos por integração).
    $sharedClientId = svih_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST');
    $sharedClientSecret = svih_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'CLIENT_SECRET_OLIST');

    $definitions = [
        'olist' => [
            'client_id' => svih_env('OLIST_CLIENT_ID') ?: $sharedClientId,
            'client_secret' => svih_env('OLIST_CLIENT_SECRET') ?: $sharedClientSecret,
            'refresh_token' => svih_env('OLIST_REFRESH_TOKEN'),
            'access_token' => svih_env('OLIST_ACCESS_TOKEN'),
        ],
        'tiny' => [
            'client_id' => svih_env('TINY_CLIENT_ID') ?: $sharedClientId,
            'client_secret' => svih_env('TINY_CLIENT_SECRET') ?: $sharedClientSecret,
            'refresh_token' => svih_env('TINY_REFRESH_TOKEN'),
            'access_token' => svih_env('TINY_ACCESS_TOKEN'),
        ],
        'legacy' => [
            'client_id' => svih_env('CLIENT_ID_API_OLIST') ?: $sharedClientId,
            'client_secret' => svih_env('CLIENT_SECRET_OLIST') ?: $sharedClientSecret,
            'refresh_token' => svih_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'),
            'access_token' => svih_env('TOKEN_API_OLIST'),
        ],
    ];

    $candidates = [];
    foreach ($definitions as $family => $candidate) {
        if ($candidate['refresh_token'] === '' && ($storedFamily === '' || $storedFamily === $family)) {
            $candidate['refresh_token'] = $storedRefresh;
        }
        if ($candidate['access_token'] === '' && ($storedFamily === '' || $storedFamily === $family)) {
            $candidate['access_token'] = $storedAccess;
        }
        $candidate['family'] = $family;
        $candidate['complete'] = svih_valid_oauth_credential($candidate['client_id'])
            && svih_valid_oauth_credential($candidate['client_secret'])
            && $candidate['refresh_token'] !== '';
        $candidate['score'] = ($candidate['complete'] ? 10 : 0)
            + ($candidate['access_token'] !== '' ? 2 : 0)
            + ($candidate['refresh_token'] !== '' ? 2 : 0)
            + ($storedFamily === $family ? 3 : 0);
        $candidates[] = $candidate;
    }

    usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return $candidates;
}

function svih_olist(bool $fix): array
{
    $path = svih_olist_token_store_path();
    $stored = svih_read_json($path);
    $candidates = svih_olist_candidates($stored);
    $primary = $candidates[0] ?? [
        'family' => 'none', 'client_id' => '', 'client_secret' => '',
        'refresh_token' => '', 'access_token' => '', 'complete' => false,
    ];

    $accessCandidates = [];
    foreach ([
    (string)($stored['OLIST_ACCESS_TOKEN'] ?? ''),
    (string)($stored['TINY_ACCESS_TOKEN'] ?? ''),
    svih_env('OLIST_ACCESS_TOKEN'),
    svih_env('TINY_ACCESS_TOKEN'),
    svih_env('TOKEN_API_OLIST'),
] as $token) {
        $token = svih_strip_bearer((string)$token);
        if ($token !== '' && !in_array($token, $accessCandidates, true)) {
            $accessCandidates[] = $token;
        }
    }

    $tokens = [
        'client_id' => svih_token_meta((string)$primary['client_id'], (string)$primary['family']),
        'client_secret' => svih_token_meta((string)$primary['client_secret'], (string)$primary['family']),
        'refresh_token' => svih_token_meta((string)$primary['refresh_token'], (string)$primary['family'] . '/private-file'),
        'access_token' => svih_token_meta((string)($accessCandidates[0] ?? ''), 'environment/private-file'),
    ];
    $action = [
        'action_url' => '/olist/connect.php',
        'action_label' => 'Reautorizar Olist / Tiny',
    ];

    foreach ($accessCandidates as $access) {
        $api = svih_olist_api($access);
        if ($api['ok']) {
            $tokens['access_token'] = svih_token_meta($access, 'environment/private-file');
            return array_merge([
                'name' => 'Olist / Tiny',
                'key' => 'olist_tiny',
                'status' => 'connected',
                'message' => 'API de produtos respondeu.',
                'remediation' => null,
                'tokens' => $tokens,
                'fixes' => [],
                'provider_status' => $api['status'],
            ], $action);
        }
    }

    $fixes = [];
    $lastError = '';
    $lastStatus = 0;

    $hasCompleteCandidate = count(array_filter(
        $candidates,
        static fn(array $candidate): bool => (bool)$candidate['complete']
    )) > 0;
    if (!$hasCompleteCandidate) {
        $message = 'Credenciais OAuth incompletas ou placeholders.';
        $remediation = 'Configure Client ID/Secret validos e reautorize o aplicativo.';
    } elseif ($lastError === 'invalid_client') {
        $message = 'O provedor rejeitou todos os pares de Client ID/Secret configurados.';
        $remediation = 'Confirme o aplicativo e a URL de callback; depois reautorize.';
    } elseif (in_array($lastError, ['invalid_grant', 'invalid_token'], true)) {
        $message = 'Refresh token expirado, revogado ou vinculado a outro aplicativo.';
        $remediation = 'Reautorize o aplicativo para gerar um novo refresh token.';
    } else {
        $message = $accessCandidates === []
            ? 'Access token nao configurado ou indisponivel.'
            : 'Access token expirado ou rejeitado pela API.';
        $remediation = 'O daemon central de renovacao e o unico escritor OAuth; verifique o servico e, se persistir, reautorize.';
    }

    return array_merge([
        'name' => 'Olist / Tiny',
        'key' => 'olist_tiny',
        'status' => 'failed',
        'message' => $message,
        'remediation' => $remediation,
        'tokens' => $tokens,
        'fixes' => $fixes,
        'provider_status' => $lastStatus,
        'provider_error' => $lastError !== '' ? $lastError : null,
    ], $action);
}

function svih_ml(bool $fix): array
{
    $path = BASE_PATH . '/storage/private/ml-tokens.json';
    $stored = svih_read_json($path);
    $access = svih_strip_bearer(svih_env('ML_ACCESS_TOKEN') ?: (string)($stored['access_token'] ?? ''));
    $refresh = svih_env('ML_REFRESH_TOKEN') ?: (string)($stored['refresh_token'] ?? '');
    $clientId = svih_env('ML_CLIENT_ID');
    $clientSecret = svih_env('ML_CLIENT_SECRET');
    $expiresAt = isset($stored['expires_at_ms']) ? (int)($stored['expires_at_ms'] / 1000) : null;
    $tokens = [
        'access_token' => svih_token_meta($access, $access !== '' && getenv('ML_ACCESS_TOKEN') ? 'environment' : 'private-file', $expiresAt),
        'refresh_token' => svih_token_meta($refresh, $refresh !== '' ? 'environment/private-file' : 'missing'),
        'client_id' => svih_token_meta($clientId, $clientId !== '' ? 'environment' : 'missing'),
        'client_secret' => svih_token_meta($clientSecret, $clientSecret !== '' ? 'environment' : 'missing'),
    ];
    $fixes = [];
    $refreshMl = static function () use ($clientId, $clientSecret, $refresh, $path, &$fixes): string {
        if ($clientId === '' || $clientSecret === '' || $refresh === '') {
            return '';
        }
        $response = svih_http('POST', 'https://api.mercadolibre.com/oauth/token', [], [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
        ]);
        if (!$response['ok'] || empty($response['data']['access_token'])) {
            return '';
        }
        $data = $response['data'];
        $data['created_at_ms'] = (int)(microtime(true) * 1000);
        $data['expires_at_ms'] = (int)(microtime(true) * 1000) + ((int)($data['expires_in'] ?? 0) * 1000);
        svih_write_json($path, $data);
        $fixes[] = 'Mercado Livre: token renovado automaticamente.';
        return svih_strip_bearer((string)$data['access_token']);
    };
    if ($access === '' && $fix) {
        $access = $refreshMl();
    } elseif ($fix && $expiresAt !== null && $expiresAt <= time() + 600) {
        $access = $refreshMl() ?: $access;
    }
    if ($access === '') {
        return [
            'name' => 'Mercado Livre', 'key' => 'mercado_livre', 'status' => 'failed',
            'message' => 'OAuth nao configurado.', 'remediation' => 'Conectar OAuth do Mercado Livre.',
            'tokens' => $tokens, 'fixes' => $fixes,
        ];
    }
    $api = svih_http('GET', 'https://api.mercadolibre.com/users/me', [
        'Authorization: Bearer ' . $access,
        'Accept: application/json',
    ]);
    if (!$api['ok'] && $api['status'] === 401 && $fix) {
        $access = $refreshMl();
        if ($access !== '') {
            $api = svih_http('GET', 'https://api.mercadolibre.com/users/me', [
                'Authorization: Bearer ' . $access,
                'Accept: application/json',
            ]);
        }
    }
    $tokens['access_token'] = svih_token_meta($access, 'private-file', $expiresAt);
    return [
        'name' => 'Mercado Livre', 'key' => 'mercado_livre',
        'status' => $api['ok'] ? 'connected' : 'failed',
        'message' => $api['ok'] ? 'Conta autenticada respondeu.' : 'OAuth do Mercado Livre falhou.',
        'remediation' => $api['ok'] ? null : 'Renovar ou refazer OAuth.',
        'tokens' => $tokens, 'fixes' => $fixes, 'provider_status' => $api['status'],
    ];
}

function svih_melhor_envio(bool $fix): array
{
    $stored = me_read_tokens() ?? [];
    $oauthAccess = svih_strip_bearer((string)($stored['access_token'] ?? ''));
    $envAccess = svih_strip_bearer(svih_env(
        'MELHORENVIO_ACCESS_TOKEN',
        'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN',
        'MELHORENVIO_API_KEY'
    ));
    $access = $oauthAccess !== '' ? $oauthAccess : $envAccess;
    $expiresAt = isset($stored['expires_at']) ? (int)$stored['expires_at'] : null;
    $tokens = [
        'access_token' => svih_token_meta($access, $oauthAccess !== '' ? 'private-file' : ($envAccess !== '' ? 'environment' : 'missing'), $expiresAt),
        'refresh_token' => svih_token_meta((string)($stored['refresh_token'] ?? ''), $stored !== [] ? 'private-file' : 'missing'),
        'client_id' => svih_token_meta(me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID'), 'environment'),
        'client_secret' => svih_token_meta(me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET'), 'environment'),
    ];
    $action = [
        'action_url' => '/api/melhorenvio/connect.php',
        'action_label' => 'Reconectar Melhor Envio',
    ];

    $check = static function (string $token): array {
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'token_missing'];
        }
        return svih_http('GET', me_api_base() . '/api/v2/me', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . me_user_agent(),
        ]);
    };

    $api = $check($access);
    $fixes = [];
    $refreshError = '';
    if (!$api['ok'] && $fix) {
        $refreshed = me_refresh_if_needed(true);
        if (is_array($refreshed)) {
            $refreshError = (string)($refreshed['_refresh_error'] ?? '');
            $newAccess = svih_strip_bearer((string)($refreshed['access_token'] ?? ''));
            if ($newAccess !== '') {
                $access = $newAccess;
                $api = $check($access);
                $expiresAt = isset($refreshed['expires_at']) ? (int)$refreshed['expires_at'] : $expiresAt;
                $tokens['access_token'] = svih_token_meta($access, 'private-file', $expiresAt);
                $tokens['refresh_token'] = svih_token_meta((string)($refreshed['refresh_token'] ?? ''), 'private-file');
                if ($api['ok']) {
                    $fixes[] = 'Melhor Envio: token renovado automaticamente.';
                }
            }
        }
    }

    if ($api['ok']) {
        return array_merge([
            'name' => 'Melhor Envio',
            'key' => 'melhor_envio',
            'status' => 'connected',
            'message' => 'Conta autenticada respondeu.',
            'remediation' => null,
            'tokens' => $tokens,
            'fixes' => $fixes,
            'provider_status' => $api['status'],
        ], $action);
    }

    if ($access === '') {
        $message = 'OAuth nao conectado.';
        $remediation = 'Autorize o aplicativo do Melhor Envio.';
    } elseif (in_array($refreshError, ['invalid_grant', 'invalid_token'], true)) {
        $message = 'Refresh token expirado ou revogado.';
        $remediation = 'Reconecte a conta para emitir novos tokens.';
    } elseif ($refreshError === 'invalid_client') {
        $message = 'Client ID/Secret ou callback nao correspondem ao aplicativo.';
        $remediation = 'Confirme o ambiente e a callback; depois reconecte.';
    } else {
        $message = 'Token rejeitado pelo Melhor Envio.';
        $remediation = $fix
            ? 'A renovacao falhou; reconecte o OAuth.'
            : 'Use Atualizar agora para renovar; se persistir, reconecte.';
    }

    return array_merge([
        'name' => 'Melhor Envio',
        'key' => 'melhor_envio',
        'status' => 'failed',
        'message' => $message,
        'remediation' => $remediation,
        'tokens' => $tokens,
        'fixes' => $fixes,
        'provider_status' => (int)($api['status'] ?? 0),
        'provider_error' => $refreshError !== '' ? $refreshError : null,
    ], $action);
}

function svih_bearer(string $name, string $key, array $envKeys, string $url, string $remediation): array
{
    $token = svih_strip_bearer(svih_env(...$envKeys));
    $tokens = ['access_token' => svih_token_meta($token, $token !== '' ? 'environment' : 'missing')];
    if ($token === '') {
        return [
            'name' => $name, 'key' => $key, 'status' => 'failed',
            'message' => 'Token nao configurado.', 'remediation' => $remediation,
            'tokens' => $tokens, 'fixes' => [],
        ];
    }
    $api = svih_http('GET', $url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ShopVivaliz-IntegrationMonitor/2.0',
    ]);
    return [
        'name' => $name, 'key' => $key,
        'status' => $api['ok'] ? 'connected' : 'failed',
        'message' => $api['ok'] ? 'Endpoint autenticado respondeu.' : 'Endpoint rejeitou o token.',
        'remediation' => $api['ok'] ? null : $remediation,
        'tokens' => $tokens, 'fixes' => [], 'provider_status' => $api['status'],
    ];
}

function svih_config_only(string $name, string $key, array $envKeys, string $remediation): array
{
    $token = svih_env(...$envKeys);
    return [
        'name' => $name,
        'key' => $key,
        'status' => $token !== '' ? 'attention' : 'not_configured',
        'message' => $token !== ''
            ? 'Segredo configurado; provedor sem teste seguro neste monitor.'
            : 'Segredo nao configurado.',
        'remediation' => $token !== '' ? 'Validar no painel oficial quando necessario.' : $remediation,
        'tokens' => ['secret' => svih_token_meta($token, $token !== '' ? 'environment' : 'missing')],
        'validation' => 'configuration_only',
        'fixes' => [],
    ];
}

function svih_check_all(bool $fix = false): array
{
    $integrations = [
        svih_olist($fix),
        svih_ml($fix),
        svih_bearer(
            'Mercado Pago',
            'mercado_pago',
            ['MERCADOPAGO_ACCESS_TOKEN'],
            'https://api.mercadopago.com/v1/payment_methods',
            'Reconfigurar access token do Mercado Pago.'
        ),
        svih_melhor_envio($fix),
        svih_config_only('Facebook CAPI', 'facebook_capi', ['FACEBOOK_ACCESS_TOKEN'], 'Configurar FACEBOOK_ACCESS_TOKEN.'),
        svih_config_only('Google Ads', 'google_ads', ['GOOGLE_ADS_REFRESH_TOKEN'], 'Conectar OAuth do Google Ads.'),
        svih_config_only('TikTok Pixel', 'tiktok_pixel', ['TIKTOK_PIXEL_TOKEN'], 'Configurar TIKTOK_PIXEL_TOKEN.'),
        svih_config_only('SMTP / E-mail', 'smtp', ['SMTP_PASS', 'EMAIL_PASSWORD', 'MAIL_PASS'], 'Configurar credencial SMTP.'),
    ];

    $connected = count(array_filter($integrations, static fn(array $item): bool => $item['status'] === 'connected'));
    $failed = count(array_filter($integrations, static fn(array $item): bool => $item['status'] === 'failed'));
    $attention = count(array_filter(
        $integrations,
        static fn(array $item): bool => in_array($item['status'], ['attention', 'not_configured'], true)
    ));

    $now = gmdate('c');
    $path = BASE_PATH . '/storage/private/integration-health.json';
    $old = svih_read_json($path);
    $history = is_array($old['history'] ?? null) ? $old['history'] : [];
    $history[] = [
        'checked_at' => $now,
        'connected' => $connected,
        'failed' => $failed,
        'attention' => $attention,
    ];
    $history = array_slice($history, -48);

    $result = [
        'ok' => $failed === 0 && $attention === 0,
        'checked_at' => $now,
        'auto_fix_attempted' => $fix,
        'summary' => [
            'total' => count($integrations),
            'connected' => $connected,
            'failed' => $failed,
            'attention' => $attention,
        ],
        'integrations' => $integrations,
        'history' => $history,
    ];
    svih_write_json($path, $result);
    return $result;
}

function svih_read_state(): array
{
    return svih_read_json(BASE_PATH . '/storage/private/integration-health.json');
}
