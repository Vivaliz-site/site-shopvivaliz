<?php
declare(strict_types=1);

namespace ShopVivaliz\Google;

use RuntimeException;

/**
 * Exchanges the permanent Google OAuth refresh token for short-lived access tokens.
 *
 * Security rules:
 * - credentials are read only from the environment;
 * - tokens are never persisted by this class;
 * - response bodies containing tokens are never included in exceptions;
 * - callers should reuse one instance so the in-process access-token cache is used.
 */
final class OAuthTokenProvider
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const EXPIRY_SKEW_SECONDS = 120;

    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken)
    {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->refreshToken = trim($refreshToken);

        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new RuntimeException(
                'Google OAuth is not configured. Required env vars: GOOGLE_OAUTH_CLIENT_ID, '
                . 'GOOGLE_OAUTH_CLIENT_SECRET, GOOGLE_OAUTH_REFRESH_TOKEN.'
            );
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''),
            (string)(getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''),
            (string)(getenv('GOOGLE_OAUTH_REFRESH_TOKEN') ?: '')
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (
            !$forceRefresh
            && $this->accessToken !== null
            && $this->expiresAt > (time() + self::EXPIRY_SKEW_SECONDS)
        ) {
            return $this->accessToken;
        }

        $payload = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->postForm(self::TOKEN_ENDPOINT, $payload);
        $status = $response['status'];
        $data = json_decode($response['body'], true);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('Google OAuth token refresh failed with HTTP ' . $status . '.');
        }

        $token = trim((string)($data['access_token'] ?? ''));
        $expiresIn = max(60, (int)($data['expires_in'] ?? 3600));
        if ($token === '') {
            $error = trim((string)($data['error'] ?? 'missing_access_token'));
            throw new RuntimeException('Google OAuth token refresh failed: ' . $error . '.');
        }

        $this->accessToken = $token;
        $this->expiresAt = time() + $expiresIn;

        return $this->accessToken;
    }

    /** @return array{status:int,body:string} */
    private function postForm(string $url, string $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL PHP extension is required for Google OAuth.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL for Google OAuth.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ShopVivaliz-GoogleOAuth/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Google OAuth transport error: ' . ($error !== '' ? $error : 'unknown cURL error') . '.');
        }

        return ['status' => $status, 'body' => (string)$body];
    }
}
