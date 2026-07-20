<?php
declare(strict_types=1);

/**
 * Google Ads Access Token Auto-Regenerator
 * Renova o access_token utilizando o refresh_token e atualiza o arquivo .env.
 */

function refresh_google_ads_token(): ?string
{
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return null;
    }

    // Carregar variáveis
    $clientId = getenv('GOOGLE_OAUTH_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET');
    $refreshToken = getenv('GOOGLE_ADS_REFRESH_TOKEN');

    if (!$clientId || !$clientSecret || !$refreshToken) {
        // Tentar ler diretamente do arquivo se getenv falhar
        $content = file_get_contents($envFile);
        if (preg_match('/GOOGLE_OAUTH_CLIENT_ID\s*=\s*(.+)/', $content, $matches)) {
            $clientId = trim($matches[1], "\"' ");
        }
        if (preg_match('/GOOGLE_OAUTH_CLIENT_SECRET\s*=\s*(.+)/', $content, $matches)) {
            $clientSecret = trim($matches[1], "\"' ");
        }
        if (preg_match('/GOOGLE_ADS_REFRESH_TOKEN\s*=\s*(.+)/', $content, $matches)) {
            $refreshToken = trim($matches[1], "\"' ");
        }
    }

    if (!$clientId || !$clientSecret || !$refreshToken) {
        error_log("[Google Ads] Erro: Credenciais ausentes no .env para renovar o token.");
        return null;
    }

    // Requisição para a API do Google
    $url = 'https://oauth2.googleapis.com/token';
    $data = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        error_log("[Google Ads] Erro na requisição HTTP para renovação do token.");
        return null;
    }

    $tokenData = json_decode($response, true);
    $accessToken = $tokenData['access_token'] ?? null;

    if (!$accessToken) {
        error_log("[Google Ads] Erro ao renovar access token: " . ($tokenData['error_description'] ?? $response));
        return null;
    }

    // Atualizar no arquivo .env de forma atomica, preservando permissoes.
    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    $updated = false;
    foreach ($lines as $i => $line) {
        if (str_starts_with(trim($line), 'GOOGLE_ADS_ACCESS_TOKEN=')) {
            $lines[$i] = 'GOOGLE_ADS_ACCESS_TOKEN=' . $accessToken;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $lines[] = 'GOOGLE_ADS_ACCESS_TOKEN=' . $accessToken;
    }

    $tmpFile = $envFile . '.google.tmp.' . getmypid();
    $mode = fileperms($envFile) & 0777;
    if (file_put_contents($tmpFile, implode("\n", $lines) . "\n", LOCK_EX) !== false
        && chmod($tmpFile, $mode)
        && rename($tmpFile, $envFile)) {
        // Atualizar também na sessão e variáveis globais do runtime
        putenv('GOOGLE_ADS_ACCESS_TOKEN=' . $accessToken);
        $_ENV['GOOGLE_ADS_ACCESS_TOKEN'] = $accessToken;
        $_SERVER['GOOGLE_ADS_ACCESS_TOKEN'] = $accessToken;
        return $accessToken;
    }

    if (is_file($tmpFile)) {
        @unlink($tmpFile);
    }

    return null;
}
