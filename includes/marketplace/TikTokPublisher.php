<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';

final class SvTikTokClient
{
    private string $host;
    private string $appKey;
    private string $appSecret;
    private string $accessToken;
    private string $shopCipher;

    public function __construct()
    {
        $this->host = rtrim(sv_market_env('TIKTOK_SHOP_API_ENDPOINT'), '/');
        if ($this->host === '') $this->host = 'https://open-api.tiktokglobalshop.com';
        $this->appKey = sv_market_env('TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID');
        $this->appSecret = sv_market_env('TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET');
        $this->accessToken = sv_market_env('TIKTOK_ACCESS_TOKEN');
        $this->shopCipher = sv_market_env('TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID');
        if ($this->appKey === '' || $this->appSecret === '' || $this->accessToken === '' || $this->shopCipher === '') {
            throw new RuntimeException('Credenciais de escrita do TikTok Shop incompletas.');
        }
    }

    public function request(string $method, string $path, ?array $body = null, array $extraQuery = []): array
    {
        $bodyString = $body === null ? '' : sv_market_json($body);
        $query = $this->signedQuery($path, $extraQuery, $bodyString);
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = sv_market_http_json($method, $url, ['x-tts-access-token' => $this->accessToken, 'Accept' => 'application/json'], $body);
        $json = $response['json'];
        $code = (int)($json['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new SvMarketplaceException('TikTok Shop ' . $code . ': ' . (string)($json['message'] ?? 'erro desconhecido'),
                $response['status'], (string)($json['request_id'] ?? ''), $json);
        }
        return ['status' => $response['status'], 'request_id' => (string)($json['request_id'] ?? $response['request_id']),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'raw' => $json];
    }

    public function uploadProductImage(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Imagem local indisponível para upload no TikTok Shop.');
        $path = '/product/202309/images/upload';
        $query = ['app_key' => $this->appKey, 'timestamp' => (string)time()];
        $query['sign'] = $this->sign($path, $query, '');
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $mime = mime_content_type($filePath) ?: 'image/png';
        $response = sv_market_http_multipart($url, ['x-tts-access-token' => $this->accessToken, 'Accept' => 'application/json'], [
            'data' => new CURLFile($filePath, $mime, basename($filePath)), 'use_case' => 'MAIN_IMAGE',
        ]);
        $json = $response['json'];
        $code = (int)($json['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new SvMarketplaceException('TikTok upload ' . $code . ': ' . (string)($json['message'] ?? 'erro desconhecido'),
                $response['status'], (string)($json['request_id'] ?? ''), $json);
        }
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        return ['uri' => (string)($data['uri'] ?? ''), 'url' => trim((string)($data['url'] ?? '')),
            'request_id' => (string)($json['request_id'] ?? ''), 'status' => $response['status']];
    }

    private function signedQuery(string $path, array $extra, string $body): array
    {
        $query = array_merge(['app_key' => $this->appKey, 'shop_cipher' => $this->shopCipher, 'timestamp' => (string)time()], $extra);
        $query['sign'] = $this->sign($path, $query, $body);
        return array_map('strval', $query);
    }

    private function sign(string $path, array $params, string $body): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params, SORT_STRING);
        $parameterString = '';
        foreach ($params as $key => $value) $parameterString .= $key . (string)$value;
        $base = $this->appSecret . $path . $parameterString . $body . $this->appSecret;
        return hash_hmac('sha256', $base, $this->appSecret);
    }
}

final class SvTikTokPublisher
{
    private SvTikTokClient $client;
    public function __construct(private PDO $db) { $this->client = new SvTikTokClient(); }

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para localizar o produto no TikTok Shop.');
        $externalId = $this->resolveProductId($productId, $sku);
        $payload = ['save_mode' => 'LISTING', 'title' => $this->limit(trim((string)($content['title'] ?? '')), 300),
            'description' => $this->htmlDescription($content)];
        sv_market_assert_no_commerce_fields($payload, 'TikTok partial edit');
        if ($payload['title'] === '' || $payload['description'] === '') throw new RuntimeException('Título ou descrição vazios para TikTok Shop.');
        $response = $this->client->request('POST', '/product/202509/products/' . rawurlencode($externalId) . '/partial_edit', $payload);
        $read = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
        $data = $read['data'];
        $readTitle = trim((string)($data['title'] ?? $data['product']['title'] ?? ''));
        $status = strtoupper((string)($data['status'] ?? $data['product_status'] ?? ''));
        $confirmed = $readTitle === $payload['title'];
        $publicationStatus = $confirmed && in_array($status, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true) ? 'published' : 'submitted';
        sv_market_save_mapping($this->db, $productId, 'tiktok', $externalId, $sku, ['product_status' => $status]);
        return ['status' => $publicationStatus, 'operation' => 'POST /product/202509/products/{id}/partial_edit',
            'external_id' => $externalId, 'http_status' => (int)$response['status'], 'request_id' => (string)$response['request_id'],
            'fields' => ['title', 'description'], 'response' => ['accepted' => true, 'readback_title_confirmed' => $confirmed,
                'product_status' => $status, 'audit_required' => true], 'submitted' => true, 'verified' => $confirmed];
    }

    public function publishImages(int $productId, array $localFiles): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para publicar imagens no TikTok Shop.');
        $externalId = $this->resolveProductId($productId, $sku);
        $before = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
        $beforeData = $before['data'];
        $existingImages = $beforeData['main_images'] ?? $beforeData['product']['main_images'] ?? [];
        $existingUris = [];
        foreach (is_array($existingImages) ? $existingImages : [] as $entry) {
            $uri = trim((string)($entry['uri'] ?? ''));
            if ($uri !== '') $existingUris[] = $uri;
        }
        $newUris = [];
        $requestIds = [];
        foreach (array_slice($localFiles, 0, 9) as $file) {
            $uploaded = $this->client->uploadProductImage((string)$file);
            if ($uploaded['uri'] === '') throw new RuntimeException('TikTok Shop não retornou URI da imagem enviada.');
            $newUris[] = $uploaded['uri'];
            if ($uploaded['request_id'] !== '') $requestIds[] = $uploaded['request_id'];
        }
        $images = array_map(static fn(string $uri): array => ['uri' => $uri],
            array_slice(array_values(array_unique(array_merge($newUris, $existingUris))), 0, 9));
        if ($images === []) throw new RuntimeException('Nenhuma imagem válida foi enviada ao TikTok Shop.');
        $payload = ['save_mode' => 'LISTING', 'main_images' => $images];
        sv_market_assert_no_commerce_fields($payload, 'TikTok image partial edit');
        $response = $this->client->request('POST', '/product/202509/products/' . rawurlencode($externalId) . '/partial_edit', $payload);
        $read = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
        $data = $read['data'];
        $readImages = $data['main_images'] ?? $data['product']['main_images'] ?? [];
        $confirmed = is_array($readImages) && $readImages !== [];
        $status = strtoupper((string)($data['status'] ?? $data['product_status'] ?? ''));
        $publicationStatus = $confirmed && in_array($status, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true) ? 'published' : 'submitted';
        return ['status' => $publicationStatus, 'operation' => 'POST images/upload + partial_edit main_images',
            'external_id' => $externalId, 'http_status' => (int)$response['status'],
            'request_id' => (string)($response['request_id'] ?: ($requestIds[0] ?? '')), 'fields' => ['main_images'],
            'response' => ['accepted' => true, 'image_count' => is_array($readImages) ? count($readImages) : 0, 'audit_required' => true],
            'submitted' => true, 'verified' => $confirmed];
    }

    private function resolveProductId(int $productId, string $sku): string
    {
        $mapping = sv_market_mapping($this->db, $productId, 'tiktok');
        if (is_array($mapping) && trim((string)$mapping['external_id']) !== '') return trim((string)$mapping['external_id']);
        $pageToken = '';
        do {
            $query = ['page_size' => 100];
            if ($pageToken !== '') $query['page_token'] = $pageToken;
            $search = $this->client->request('POST', '/product/202309/products/search', ['seller_skus' => [$sku]], $query);
            $products = $search['data']['products'] ?? [];
            foreach (is_array($products) ? $products : [] as $product) {
                $externalId = trim((string)($product['id'] ?? $product['product_id'] ?? ''));
                if ($externalId === '') continue;
                foreach (is_array($product['skus'] ?? null) ? $product['skus'] : [] as $entry) {
                    if (trim((string)($entry['seller_sku'] ?? '')) === $sku) {
                        sv_market_save_mapping($this->db, $productId, 'tiktok', $externalId, $sku, ['source' => 'search_products']);
                        return $externalId;
                    }
                }
                if (count($products) === 1) {
                    sv_market_save_mapping($this->db, $productId, 'tiktok', $externalId, $sku, ['source' => 'seller_skus_filter']);
                    return $externalId;
                }
            }
            $pageToken = trim((string)($search['data']['next_page_token'] ?? ''));
        } while ($pageToken !== '');
        throw new RuntimeException("Produto TikTok Shop não localizado para o SKU {$sku}.");
    }

    private function htmlDescription(array $content): string
    {
        $html = '<p>' . nl2br(htmlspecialchars(trim((string)($content['description'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        $bullets = is_array($content['bullet_points'] ?? null) ? $content['bullet_points'] : [];
        if ($bullets !== []) {
            $html .= '<ul>';
            foreach ($bullets as $bullet) $html .= '<li>' . htmlspecialchars(trim((string)$bullet), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            $html .= '</ul>';
        }
        $hooks = is_array($content['marketing_hooks'] ?? null) ? $content['marketing_hooks'] : [];
        foreach ($hooks as $hook) $html .= '<p><strong>' . htmlspecialchars(trim((string)$hook), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        return $this->limit($html, 10000);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
