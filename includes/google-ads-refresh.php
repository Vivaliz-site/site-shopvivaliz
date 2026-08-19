<?php
declare(strict_types=1);

/**
 * Google Ads Access Token Auto-Regenerator.
 *
 * Renova o access_token utilizando o refresh_token e atualiza o arquivo .env
 * sem imprimir valores sensiveis. O arquivo alvo e validado antes da escrita
 * para evitar symlink, permissao aberta e perda de metadados.
 */

function google_ads_refresh_error_message(?array $tokenData, string $fallback = 'unknown'): string
{
    if (is_array($tokenData)) {
        $error = trim((string)($tokenData['error'] ?? ''));
        if ($error !== '') {
            return preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $error) ?: 'oauth_error';
        }
    }
    return preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $fallback) ?: 'unknown';
}

function google_ads_refresh_env_path(): ?string
{
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return null;
    }
    if (is_link($envFile)) {
        error_log('[Google Ads] Erro: .env e symlink; renovacao recusada.');
        return null;
    }
    $realPath = realpath($envFile);
    if ($realPath === false || !is_file($realPath)) {
        return null;
    }
    $stat = stat($realPath);
    if (!is_array($stat)) {
        return null;
    }
    $mode = $stat['mode'] & 0777;
    if (($mode & 0007) !== 0) {
        error_log('[Google Ads] Erro: .env possui permissao aberta para others; renovacao recusada.');
        return null;
    }
    return $realPath;
}

function google_ads_refresh_read_env_file(string $envFile): array
{
    $content = file_get_contents($envFile);
    if (!is_string($content)) {
        return [];
    }
    $values = [];
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $values[trim($key)] = trim($value, "\"' ");
    }
    return $values;
}

function google_ads_refresh_write_env_value(string $envFile, string $key, string $value): bool
{
    if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1 || trim($value) === '' || preg_match('/\s/', $value) === 1) {
        return false;
    }

    $originalText = file_get_contents($envFile);
    if (!is_string($originalText)) {
        return false;
    }

    $lines = preg_split('/\R/', $originalText) ?: [];
    if ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }

    $updated = false;
    foreach ($lines as $index => $line) {
        $trimmed = trim((string)$line);
        if ($trimmed !== '' && !str_starts_with($trimmed, '#') && str_contains($trimmed, '=')) {
            [$lineKey] = explode('=', $trimmed, 2);
            if (trim($lineKey) === $key) {
                $lines[$index] = $key . '=' . $value;
                $updated = true;
                break;
            }
        }
    }

    if (!$updated) {
        $lines[] = $key . '=' . $value;
    }

    $candidateText = implode("\n", $lines) . "\n";
    $original = stat($envFile);
    if (!is_array($original)) {
        return false;
    }
    $mode = $original['mode'] & 0777;
    $tmpFile = $envFile . '.google.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    $handle = @fopen($tmpFile, 'wb');
    if (!is_resource($handle)) {
        return false;
    }

    try {
        if (fwrite($handle, $candidateText) === false) {
            return false;
        }
        fflush($handle);
        if (function_exists('fsync')) {
            @fsync($handle);
        }
        fclose($handle);
        $handle = null;
        if (!chmod($tmpFile, $mode)) {
            return false;
        }
        if (function_exists('chown') && function_exists('chgrp')) {
            @chown($tmpFile, (int)$original['uid']);
            @chgrp($tmpFile, (int)$original['gid']);
        }
        if (!rename($tmpFile, $envFile)) {
            return false;
        }
        $updatedStat = stat($envFile);
        if (is_array($updatedStat)
            && (($updatedStat['mode'] & 0777) !== $mode
                || (isset($original['uid'], $updatedStat['uid']) && (int)$updatedStat['uid'] !== (int)$original['uid'])
                || (isset($original['gid'], $updatedStat['gid']) && (int)$updatedStat['gid'] !== (int)$original['gid']))) {
            error_log('[Google Ads] Erro: metadados do .env mudaram apos renovacao.');
            return false;
        }
        return true;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}

function refresh_google_ads_token(): ?string
{
    $envFile = google_ads_refresh_env_path();
    if ($envFile === null) {
        return null;
    }

    $clientId = getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '';
    $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '';
    $refreshToken = getenv('GOOGLE_OAUTH_REFRESH_TOKEN') ?: getenv('GOOGLE_ADS_REFRESH_TOKEN') ?: '';

    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        $fileEnv = google_ads_refresh_read_env_file($envFile);
        $clientId = $clientId !== '' ? $clientId : (string)($fileEnv['GOOGLE_OAUTH_CLIENT_ID'] ?? '');
        $clientSecret = $clientSecret !== '' ? $clientSecret : (string)($fileEnv['GOOGLE_OAUTH_CLIENT_SECRET'] ?? '');
        $refreshToken = $refreshToken !== '' ? $refreshToken : (string)($fileEnv['GOOGLE_OAUTH_REFRESH_TOKEN'] ?? $fileEnv['GOOGLE_ADS_REFRESH_TOKEN'] ?? '');
    }

    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        error_log('[Google Ads] Erro: Credenciais ausentes no .env para renovar o token.');
        return null;
    }

    $data = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ];

    $context = stream_context_create([
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'method' => 'POST',
            'content' => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $response = file_get_contents('https://oauth2.googleapis.com/token', false, $context);
    if ($response === false) {
        error_log('[Google Ads] Erro na requisicao HTTP para renovacao do token.');
        return null;
    }

    $tokenData = json_decode($response, true);
    $accessToken = is_array($tokenData) ? trim((string)($tokenData['access_token'] ?? '')) : '';

    if ($accessToken === '') {
        error_log('[Google Ads] Erro ao renovar access token: ' . google_ads_refresh_error_message(is_array($tokenData) ? $tokenData : null));
        return null;
    }

    if (!google_ads_refresh_write_env_value($envFile, 'GOOGLE_ADS_ACCESS_TOKEN', $accessToken)) {
        error_log('[Google Ads] Erro: falha ao gravar access token renovado no .env.');
        return null;
    }

    putenv('GOOGLE_ADS_ACCESS_TOKEN=' . $accessToken);
    $_ENV['GOOGLE_ADS_ACCESS_TOKEN'] = $accessToken;
    $_SERVER['GOOGLE_ADS_ACCESS_TOKEN'] = $accessToken;

    return $accessToken;
}
