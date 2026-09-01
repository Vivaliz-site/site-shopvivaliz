<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';

interface SvAmazonApi
{
    /** @return array{status:int,request_id:string,data:array<string,mixed>} */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array;
    public function sellerId(): string;
    public function marketplaceId(): string;
}

final class SvAmazonClient implements SvAmazonApi
{
    private string $endpoint;
    private string $sellerId;
    private string $marketplaceId;
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->endpoint = rtrim(sv_market_env('AMAZON_SP_API_ENDPOINT', 'SP_API_ENDPOINT'), '/');
        if ($this->endpoint === '') $this->endpoint = 'https://sellingpartnerapi-na.amazon.com';
        $this->clientId = sv_market_env('AMAZON_LWA_CLIENT_ID', 'AMAZON_SP_API_CLIENT_ID', 'SP_API_CLIENT_ID', 'LWA_CLIENT_ID');
        $this->clientSecret = sv_market_env('AMAZON_LWA_CLIENT_SECRET', 'AMAZON_SP_API_CLIENT_SECRET', 'SP_API_CLIENT_SECRET', 'LWA_CLIENT_SECRET');
        $this->refreshToken = sv_market_env('AMAZON_LWA_REFRESH_TOKEN', 'AMAZON_SP_API_REFRESH_TOKEN', 'SP_API_REFRESH_TOKEN', 'LWA_REFRESH_TOKEN');
        $this->sellerId = sv_market_env('AMAZON_SELLER_ID', 'AMAZON_ACCOUNT_ID', 'AMAZON_MERCHANT_ID', 'AMAZON_MERCHANT_TOKEN', 'SP_API_SELLER_ID');
        $this->marketplaceId = sv_market_env('AMAZON_MARKETPLACE_ID', 'AMAZON_MARKETPLACE', 'SP_API_MARKETPLACE_ID');
        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new RuntimeException('Credenciais Amazon LWA incompletas.');
        }
    }

    public function sellerId(): string
    {
        if ($this->sellerId === '') throw new RuntimeException('Seller ID/Merchant ID da Amazon nao configurado.');
        return $this->sellerId;
    }

    public function marketplaceId(): string
    {
        if ($this->marketplaceId !== '') return $this->marketplaceId;
        return $this->marketplaceId = $this->discoverMarketplaceId();
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        ksort($query, SORT_STRING);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $this->endpoint . $path . ($queryString !== '' ? '?' . $queryString : '');
        $response = sv_market_http_json($method, $url, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-amz-access-token' => $this->accessToken(),
            'user-agent' => 'ShopVivaliz-AmazonRecovery/1.0',
        ], $body, 60);
        return [
            'status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'data' => is_array($response['json']) ? $response['json'] : [],
        ];
    }

    private function discoverMarketplaceId(): string
    {
        $response = $this->request('GET', '/sellers/v1/marketplaceParticipations');
        $payload = $response['data'];
        $entries = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        if (!is_array($entries)) throw new RuntimeException('Amazon Sellers API nao retornou participacoes de marketplace.');
        $fallback = '';
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $marketplace = is_array($entry['marketplace'] ?? null) ? $entry['marketplace'] : [];
            $participation = is_array($entry['participation'] ?? null) ? $entry['participation'] : [];
            $id = trim((string)($marketplace['id'] ?? $marketplace['marketplaceId'] ?? ''));
            if ($id === '') continue;
            if ($fallback === '') $fallback = $id;
            $country = strtoupper(trim((string)($marketplace['countryCode'] ?? '')));
            $participating = !array_key_exists('isParticipating', $participation) || (bool)$participation['isParticipating'];
            $suspended = (bool)($participation['hasSuspendedListings'] ?? false);
            if ($country === 'BR' && $participating && !$suspended) return $id;
        }
        if ($fallback !== '') return $fallback;
        throw new RuntimeException('Nenhum marketplace Amazon elegivel foi encontrado para a autorizacao LWA.');
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $handle = curl_init('https://api.amazon.com/auth/o2/token');
        if ($handle === false) throw new RuntimeException('Falha ao iniciar autenticacao LWA.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) throw new RuntimeException('Falha de transporte LWA: ' . $error);
        $json = sv_market_decode_json($raw);
        $token = trim((string)($json['access_token'] ?? ''));
        if ($status !== 200 || $token === '') {
            throw new SvMarketplaceException((string)($json['error_description'] ?? 'Falha ao obter token LWA.'), $status, '', $json);
        }
        return $this->accessToken = $token;
    }
}
