<?php
declare(strict_types=1);

namespace ShopVivaliz\Google;

use RuntimeException;

/**
 * Small authenticated JSON client for Google APIs.
 *
 * It obtains access tokens through OAuthTokenProvider and retries exactly once
 * after HTTP 401 with a forced refresh. It deliberately does not log request
 * headers or response bodies because they may contain sensitive data.
 */
final class GoogleApiClient
{
    private OAuthTokenProvider $tokens;

    public function __construct(OAuthTokenProvider $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @param array<int,string> $extraHeaders
     * @return array{status:int,body:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function request(
        string $method,
        string $url,
        ?array $jsonBody = null,
        array $extraHeaders = []
    ): array {
        $response = $this->requestOnce($method, $url, $jsonBody, $extraHeaders, false);
        if ($response['status'] === 401) {
            $response = $this->requestOnce($method, $url, $jsonBody, $extraHeaders, true);
        }

        return $response;
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @param array<int,string> $extraHeaders
     * @return array{status:int,body:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    private function requestOnce(
        string $method,
        string $url,
        ?array $jsonBody,
        array $extraHeaders,
        bool $forceRefresh
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL PHP extension is required for Google API calls.');
        }

        $token = $this->tokens->getAccessToken($forceRefresh);
        $headers = array_merge([
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ShopVivaliz-GoogleAPI/1.0',
        ], $extraHeaders);

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Could not encode Google API request body as JSON.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL for Google API request.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Google API transport error: ' . ($error !== '' ? $error : 'unknown cURL error') . '.');
        }

        $decoded = json_decode((string)$raw, true);
        if ($decoded !== null && !is_array($decoded)) {
            $decoded = null;
        }

        return [
            'status' => $status,
            'body' => $decoded,
            'raw' => (string)$raw,
        ];
    }
}
