<?php

declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';

final class SvAmazonClient
{
    private string $endpoint;
    private string $sellerId;
    private string $marketplaceId;
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private string $awsAccessKey;
    private string $awsSecretKey;
    private string $awsSessionToken;
    private string $region;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->endpoint = rtrim(sv_market_env('AMAZON_SP_API_ENDPOINT'), '/');
        if ($this->endpoint === '') $this->endpoint = 'https://sellingpartnerapi-na.amazon.com';
        $this->sellerId = sv_market_env('AMAZON_SELLER_ID', 'AMAZON_ACCOUNT_ID');
        $this->marketplaceId = sv_market_env('AMAZON_MARKETPLACE_ID');
        $this->clientId = sv_market_env('AMAZON_LWA_CLIENT_ID');
        $this->clientSecret = sv_market_env('AMAZON_LWA_CLIENT_SECRET');
        $this->refreshToken = sv_market_env('AMAZON_LWA_REFRESH_TOKEN');
        $this->awsAccessKey = sv_market_env('AMAZON_AWS_ACCESS_KEY_ID', 'AWS_ACCESS_KEY_ID');
        $this->awsSecretKey = sv_market_env('AMAZON_AWS_SECRET_ACCESS_KEY', 'AWS_SECRET_ACCESS_KEY');
        $this->awsSessionToken = sv_market_env('AMAZON_AWS_SESSION_TOKEN', 'AWS_SESSION_TOKEN');
        $this->region = sv_market_env('AMAZON_SP_API_REGION', 'AWS_REGION');
        if ($this->region === '') $this->region = $this->inferRegion($this->endpoint);
        if ($this->sellerId === '' || $this->marketplaceId === '' || $this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new RuntimeException('Credenciais Amazon LWA, seller ou marketplace incompletas.');
        }
        if ($this->awsAccessKey === '' || $this->awsSecretKey === '') {
            throw new RuntimeException('Credenciais AWS para assinatura SigV4 da Amazon SP-API ausentes.');
        }
    }

    public function sellerId(): string { return $this->sellerId; }
    public function marketplaceId(): string { return $this->marketplaceId; }

    /** @return array{status:int,request_id:string,data:array<string,mixed>} */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        ksort($query, SORT_STRING);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $this->endpoint . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $payload = $body === null ? '' : sv_market_json($body);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $host = (string)parse_url($this->endpoint, PHP_URL_HOST);
        $headers = [
            'content-type' => 'application/json',
            'host' => $host,
            'x-amz-access-token' => $this->accessToken(),
            'x-amz-date' => $amzDate,
        ];
        if ($this->awsSessionToken !== '') $headers['x-amz-security-token'] = $this->awsSessionToken;
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) $canonicalHeaders .= strtolower($name) . ':' . trim(preg_replace('/\s+/', ' ', $value) ?? $value) . "\n";
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalUri($path),
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);
        $scope = $date . '/' . $this->region . '/execute-api/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->signingKey($date, $this->region, 'execute-api');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->awsAccessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $requestHeaders = [
            'Authorization' => $authorization,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Host' => $host,
            'x-amz-access-token' => $headers['x-amz-access-token'],
            'x-amz-date' => $amzDate,
            'user-agent' => 'ShopVivaliz-CatalogPublisher/2.0',
        ];
        if ($this->awsSessionToken !== '') $requestHeaders['x-amz-security-token'] = $this->awsSessionToken;
        $response = sv_market_http_json($method, $url, $requestHeaders, $body, 60);
        return [
            'status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'data' => is_array($response['json']) ? $response['json'] : [],
        ];
    }

    private function canonicalUri(string $path): string
    {
        $segments = explode('/', $path);
        return implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), $segments));
    }

    private function signingKey(string $date, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->awsSecretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function inferRegion(string $endpoint): string
    {
        if (str_contains($endpoint, '-eu.')) return 'eu-west-1';
        if (str_contains($endpoint, '-fe.')) return 'us-west-2';
        return 'us-east-1';
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $handle = curl_init('https://api.amazon.com/auth/o2/token');
        if ($handle === false) throw new RuntimeException('Falha ao iniciar autenticação LWA.');
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

final class SvAmazonPublisher
{
    private SvAmazonClient $client;
    public function __construct(private PDO $db) { $this->client = new SvAmazonClient(); }

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para a Amazon.');
        $listing = $this->getListing($sku);
        $productType = $this->productType($listing);
        $this->validateProductTypeEndpoint($productType);
        $marketplaceId = $this->client->marketplaceId();
        $languageTag = sv_market_env('AMAZON_LANGUAGE_TAG') ?: 'pt_BR';
        $patches = [];
        $title = trim((string)($content['title'] ?? ''));
        $description = trim((string)($content['description'] ?? ''));
        if ($title !== '') $patches[] = $this->patch('item_name', [['value' => $title, 'language_tag' => $languageTag, 'marketplace_id' => $marketplaceId]]);
        if ($description !== '') $patches[] = $this->patch('product_description', [['value' => $description, 'language_tag' => $languageTag, 'marketplace_id' => $marketplaceId]]);
        $bullets = is_array($content['bullet_points'] ?? null) ? array_slice($content['bullet_points'], 0, 5) : [];
        if ($bullets !== []) {
            $values = [];
            foreach ($bullets as $bullet) $values[] = ['value' => trim((string)$bullet), 'language_tag' => $languageTag, 'marketplace_id' => $marketplaceId];
            $patches[] = $this->patch('bullet_point', $values);
        }
        $keywords = is_array($content['seo_keywords'] ?? null) ? array_values(array_filter(array_map('strval', $content['seo_keywords']))) : [];
        if ($keywords !== []) $patches[] = $this->patch('generic_keyword', [['value' => implode(' ', $keywords), 'language_tag' => $languageTag, 'marketplace_id' => $marketplaceId]]);
        if ($patches === []) throw new RuntimeException('Nenhum atributo textual válido para a Amazon.');
        $payload = ['productType' => $productType, 'patches' => $patches];
        sv_market_assert_no_commerce_fields($payload, 'Amazon Listings Items patch');
        $response = $this->client->request('PATCH', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku), [
            'marketplaceIds' => $marketplaceId,
            'issueLocale' => 'pt_BR',
        ], $payload);
        $data = $response['data'];
        $submissionStatus = strtoupper((string)($data['status'] ?? ''));
        if ($submissionStatus !== 'ACCEPTED') throw new RuntimeException('Amazon rejeitou a atualização: ' . sv_market_safe_excerpt(sv_market_json($data['issues'] ?? [])));
        $after = $this->getListing($sku);
        sv_market_save_mapping($this->db, $productId, 'amazon', $sku, $sku, ['product_type' => $productType]);
        return [
            'status' => 'submitted',
            'operation' => 'GET Product Type Definition + PATCH Listings Item + GET Listings Item',
            'external_id' => $sku,
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'fields' => array_map(static fn(array $patch): string => str_replace('/attributes/', '', (string)$patch['path']), $patches),
            'response' => [
                'submission_status' => $submissionStatus,
                'issues' => $data['issues'] ?? [],
                'readback_issues' => $after['issues'] ?? [],
            ],
            'submitted' => true,
            'verified' => false,
        ];
    }

    public function publishImages(int $productId, array $imageUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para publicar imagens na Amazon.');
        $listing = $this->getListing($sku);
        $productType = $this->productType($listing);
        $this->validateProductTypeEndpoint($productType);
        $marketplaceId = $this->client->marketplaceId();
        $urls = array_slice(array_values(array_unique(array_map('sv_market_absolute_url', $imageUrls))), 0, 9);
        if ($urls === []) throw new RuntimeException('Nenhuma imagem válida para a Amazon.');
        $patches = [];
        foreach ($urls as $index => $url) {
            $attribute = $index === 0 ? 'main_product_image_locator' : 'other_product_image_locator_' . $index;
            $patches[] = $this->patch($attribute, [['media_location' => $url, 'marketplace_id' => $marketplaceId]]);
        }
        $payload = ['productType' => $productType, 'patches' => $patches];
        sv_market_assert_no_commerce_fields($payload, 'Amazon image patch');
        $response = $this->client->request('PATCH', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku), [
            'marketplaceIds' => $marketplaceId,
            'issueLocale' => 'pt_BR',
        ], $payload);
        $data = $response['data'];
        if (strtoupper((string)($data['status'] ?? '')) !== 'ACCEPTED') throw new RuntimeException('Amazon rejeitou as imagens: ' . sv_market_safe_excerpt(sv_market_json($data['issues'] ?? [])));
        return [
            'status' => 'submitted',
            'operation' => 'PATCH Listings Items image locators',
            'external_id' => $sku,
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'fields' => array_map(static fn(array $patch): string => str_replace('/attributes/', '', (string)$patch['path']), $patches),
            'response' => ['submission_status' => 'ACCEPTED', 'issues' => $data['issues'] ?? []],
            'submitted' => true,
            'verified' => false,
        ];
    }

    private function getListing(string $sku): array
    {
        $response = $this->client->request('GET', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku), [
            'marketplaceIds' => $this->client->marketplaceId(),
            'includedData' => 'summaries,attributes,issues',
            'issueLocale' => 'pt_BR',
        ]);
        return $response['data'];
    }

    private function validateProductTypeEndpoint(string $productType): void
    {
        $response = $this->client->request('GET', '/definitions/2020-09-01/productTypes/' . rawurlencode($productType), [
            'marketplaceIds' => $this->client->marketplaceId(),
            'sellerId' => $this->client->sellerId(),
            'requirements' => 'LISTING_PRODUCT_ONLY',
            'locale' => 'pt_BR',
        ]);
        if (trim((string)($response['data']['productType'] ?? '')) === '') {
            throw new RuntimeException('Amazon Product Type Definitions não confirmou o productType.');
        }
    }

    private function productType(array $listing): string
    {
        $summaries = is_array($listing['summaries'] ?? null) ? $listing['summaries'] : [];
        $productType = trim((string)($summaries[0]['productType'] ?? $listing['productType'] ?? ''));
        if ($productType === '') throw new RuntimeException('Amazon não retornou productType para o SKU.');
        return $productType;
    }

    private function patch(string $attribute, array $value): array
    {
        return ['op' => 'replace', 'path' => '/attributes/' . $attribute, 'value' => $value];
    }
}
