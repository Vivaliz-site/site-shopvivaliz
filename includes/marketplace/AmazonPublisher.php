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
        if ($this->sellerId === '' || $this->marketplaceId === '' || $this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new RuntimeException('Credenciais Amazon SP-API incompletas; configure seller, marketplace e LWA.');
        }
    }

    public function sellerId(): string { return $this->sellerId; }
    public function marketplaceId(): string { return $this->marketplaceId; }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->endpoint . $path;
        if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = sv_market_http_json($method, $url, [
            'x-amz-access-token' => $this->accessToken(),
            'x-amz-date' => gmdate('Ymd\THis\Z'),
            'user-agent' => 'ShopVivaliz-CatalogPublisher/1.0',
            'Accept' => 'application/json',
        ], $body, 60);
        return ['status' => $response['status'], 'request_id' => $response['request_id'], 'data' => $response['json']];
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $ch = curl_init('https://api.amazon.com/auth/o2/token');
        if ($ch === false) throw new RuntimeException('Falha ao iniciar autenticação LWA.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token', 'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
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
        $keywords = is_array($content['seo_keywords'] ?? null) ? $content['seo_keywords'] : [];
        if ($keywords !== []) $patches[] = $this->patch('generic_keyword', [['value' => implode(' ', array_map('strval', $keywords)), 'language_tag' => $languageTag, 'marketplace_id' => $marketplaceId]]);
        if ($patches === []) throw new RuntimeException('Nenhum atributo textual válido para a Amazon.');
        $payload = ['productType' => $productType, 'patches' => $patches];
        sv_market_assert_no_commerce_fields($payload, 'Amazon Listings Items patch');
        $response = $this->client->request('PATCH', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku),
            ['marketplaceIds' => $marketplaceId, 'issueLocale' => 'pt_BR'], $payload);
        $data = $response['data'];
        $submissionStatus = strtoupper((string)($data['status'] ?? ''));
        if ($submissionStatus !== 'ACCEPTED') throw new RuntimeException('Amazon rejeitou a atualização: ' . sv_market_safe_excerpt(sv_market_json($data['issues'] ?? [])));
        sv_market_save_mapping($this->db, $productId, 'amazon', $sku, $sku, ['product_type' => $productType]);
        return ['status' => 'submitted', 'operation' => 'PATCH /listings/2021-08-01/items/{sellerId}/{sku}',
            'external_id' => $sku, 'http_status' => (int)$response['status'], 'request_id' => (string)$response['request_id'],
            'fields' => array_map(static fn(array $patch): string => str_replace('/attributes/', '', (string)$patch['path']), $patches),
            'response' => ['submission_status' => $submissionStatus, 'issues' => $data['issues'] ?? []], 'submitted' => true, 'verified' => false];
    }

    public function publishImages(int $productId, array $imageUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para publicar imagens na Amazon.');
        $listing = $this->getListing($sku);
        $productType = $this->productType($listing);
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
        $response = $this->client->request('PATCH', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku),
            ['marketplaceIds' => $marketplaceId, 'issueLocale' => 'pt_BR'], $payload);
        $data = $response['data'];
        $submissionStatus = strtoupper((string)($data['status'] ?? ''));
        if ($submissionStatus !== 'ACCEPTED') throw new RuntimeException('Amazon rejeitou as imagens: ' . sv_market_safe_excerpt(sv_market_json($data['issues'] ?? [])));
        return ['status' => 'submitted', 'operation' => 'PATCH Listings Items image locators', 'external_id' => $sku,
            'http_status' => (int)$response['status'], 'request_id' => (string)$response['request_id'],
            'fields' => array_map(static fn(array $patch): string => str_replace('/attributes/', '', (string)$patch['path']), $patches),
            'response' => ['submission_status' => $submissionStatus, 'issues' => $data['issues'] ?? []], 'submitted' => true, 'verified' => false];
    }

    private function getListing(string $sku): array
    {
        $response = $this->client->request('GET', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku),
            ['marketplaceIds' => $this->client->marketplaceId(), 'includedData' => 'summaries,attributes,issues']);
        return $response['data'];
    }

    private function productType(array $listing): string
    {
        $summaries = is_array($listing['summaries'] ?? null) ? $listing['summaries'] : [];
        $productType = trim((string)($summaries[0]['productType'] ?? $listing['productType'] ?? ''));
        if ($productType === '') throw new RuntimeException('Amazon não retornou productType para o SKU; patch seguro não pode ser montado.');
        return $productType;
    }

    private function patch(string $attribute, array $value): array
    {
        return ['op' => 'replace', 'path' => '/attributes/' . $attribute, 'value' => $value];
    }
}
