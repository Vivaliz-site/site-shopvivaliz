<?php
declare(strict_types=1);

// Carrega .env do raiz
function ml_load_env(): void {
    $path = dirname(__DIR__, 2) . '/.env';
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}

// Retorna tokens armazenados localmente
function ml_tokens(): array {
    $paths = [
        dirname(__DIR__, 2) . '/data/ml-tokens.json',
        dirname(__DIR__, 2) . '/storage/ml-tokens.json',
        dirname(__DIR__, 2) . '/.ml-tokens.json',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            $data = json_decode(file_get_contents($p) ?: '', true);
            if (is_array($data)) return $data;
        }
    }
    return [];
}

// Salva tokens localmente
function ml_save_tokens(array $tokens): void {
    $path = dirname(__DIR__, 2) . '/data/ml-tokens.json';
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT));
}

// Retorna access token válido (renova se necessário)
function ml_access_token(): ?string {
    ml_load_env();
    $tokens = ml_tokens();

    // Token do env tem prioridade
    $envToken = getenv('ML_ACCESS_TOKEN') ?: '';
    if ($envToken) return $envToken;

    if (empty($tokens['access_token'])) return null;

    // Verifica expiração (margem de 5 min)
    $expiresAt = (int)($tokens['expires_at'] ?? 0);
    if ($expiresAt > 0 && time() > ($expiresAt - 300)) {
        $refreshed = ml_refresh($tokens);
        if ($refreshed) return $refreshed;
    }

    return $tokens['access_token'] ?? null;
}

// Renova token via refresh
function ml_refresh(array $tokens): ?string {
    $clientId     = getenv('ML_CLIENT_ID')     ?: ($tokens['client_id']     ?? '');
    $clientSecret = getenv('ML_CLIENT_SECRET') ?: ($tokens['client_secret'] ?? '');
    $refreshToken = $tokens['refresh_token']   ?? '';

    if (!$clientId || !$clientSecret || !$refreshToken) return null;

    $ch = curl_init('https://api.mercadolibre.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body ?: '', true);

    if (!empty($data['access_token'])) {
        $tokens['access_token']  = $data['access_token'];
        $tokens['refresh_token'] = $data['refresh_token'] ?? $refreshToken;
        $tokens['expires_at']    = time() + (int)($data['expires_in'] ?? 21600);
        ml_save_tokens($tokens);
        return $data['access_token'];
    }
    return null;
}

// Chamada GET autenticada à API ML
function ml_get(string $endpoint): array {
    $token = ml_access_token();
    if (!$token) return ['error' => 'sem_token', 'connected' => false];

    $ch = curl_init('https://api.mercadolibre.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body ?: '', true);
    if (!is_array($data)) $data = ['error' => 'invalid_json', 'raw' => substr((string)$body, 0, 200)];
    $data['_http_status'] = $httpCode;
    return $data;
}

function ml_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
