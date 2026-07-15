<?php
declare(strict_types=1);

/**
 * Mercado Livre OAuth 2.0 + PKCE — cliente PHP
 * Equivalente ao server.js fornecido pelo usuário.
 */

function ml_root(): string { return dirname(__DIR__, 2); }

function ml_load_runtime_secrets(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // config/constants.php carrega config/runtime-secrets.php, gerado pelo
    // deploy a partir dos GitHub Secrets (o servidor nao recebe .env via FTP).
    $constants = ml_root() . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }
}

function ml_env(string ...$keys): string {
    static $loaded = false;
    ml_load_runtime_secrets();
    if (!$loaded) {
        $loaded = true;
        $f = ml_root() . '/.env';
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

function ml_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ml_create_pkce(): array {
    $verifier  = ml_base64url(random_bytes(64));
    $challenge = ml_base64url(hash('sha256', $verifier, true));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

function ml_token_path(): string {
    $dir = ml_root() . '/storage/private';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/ml-tokens.json';
}

function ml_save_tokens(array $data): array {
    $existing = ml_read_tokens(false) ?? [];
    $expires_at_ms = isset($data['expires_in'])
        ? (int)(microtime(true) * 1000) + ((int)$data['expires_in'] * 1000)
        : ($existing['expires_at_ms'] ?? 0);

    $enriched = array_merge($existing, $data, [
        'created_at_ms' => (int)(microtime(true) * 1000),
        'expires_at_ms' => $expires_at_ms,
    ]);
    file_put_contents(ml_token_path(), json_encode($enriched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $enriched;
}

function ml_read_tokens(bool $throw = true): ?array {
    $path = ml_token_path();
    if (!is_file($path)) {
        if ($throw) throw new RuntimeException('Nenhum token ML salvo. Acesse /api/ml/login primeiro.');
        return null;
    }
    return json_decode((string)file_get_contents($path), true);
}

function ml_refresh_if_needed(): array {
    $tokens = ml_read_tokens();
    $ten_min = 10 * 60 * 1000;
    $now_ms  = (int)(microtime(true) * 1000);

    if (($tokens['expires_at_ms'] ?? 0) > $now_ms + $ten_min) {
        return $tokens;
    }

    $refresh = $tokens['refresh_token'] ?? '';
    if ($refresh === '') throw new RuntimeException('refresh_token ausente. Refaça o OAuth.');

    $resp = ml_http_post('https://api.mercadolibre.com/oauth/token', [
        'grant_type'    => 'refresh_token',
        'client_id'     => ml_env('ML_CLIENT_ID'),
        'client_secret' => ml_env('ML_CLIENT_SECRET'),
        'refresh_token' => $refresh,
    ]);
    return ml_save_tokens($resp);
}

function ml_http_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("ML POST $url HTTP $status: " . substr($body, 0, 300));
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException("ML resposta inválida: $body");
    return $data;
}

function ml_http_get(string $url): array {
    $tokens = ml_refresh_if_needed();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$tokens['access_token']}"],
    ]);
    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("ML GET $url HTTP $status: " . substr($body, 0, 300));
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException("ML resposta inválida: $body");
    return $data;
}

/**
 * POST/PUT autenticado com corpo JSON, usado pela API de Items do ML
 * (a ml_http_post existente e so para o form-urlencoded do OAuth).
 */
function ml_http_json(string $method, string $url, ?array $payload = null): array {
    $tokens = ml_refresh_if_needed();
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$tokens['access_token']}",
            'Content-Type: application/json',
        ],
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body   = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("ML $method $url HTTP $status: " . substr($body, 0, 500));
    }
    return is_array($data) ? $data : [];
}
