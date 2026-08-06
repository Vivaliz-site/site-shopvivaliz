<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';

final class SvShopeeClient
{
    private string $host;
    private string $partnerId;
    private string $partnerKey;
    private string $accessToken;
    private string $refreshToken;
    private string $shopId;

    public function __construct()
    {
        $this->host = rtrim(sv_market_env('SHOPEE_HOST'), '/');
        if ($this->host === '') $this->host = 'https://partner.shopeemobile.com';
        $this->partnerId = sv_market_env('SHOPEE_PARTNER_ID');
        $this->partnerKey = sv_market_env('SHOPEE_PARTNER_KEY');
        $this->accessToken = sv_market_env('SHOPEE_ACCESS_TOKEN');
        $this->refreshToken = sv_market_env('SHOPEE_REFRESH_TOKEN');
        $this->shopId = sv_market_env('SHOPEE_SHOP_ID');
        if ($this->partnerId === '' || $this->partnerKey === '' || $this->shopId === '' || ($this->accessToken === '' && $this->refreshToken === '')) {
            throw new RuntimeException('Credenciais de escrita da Shopee incompletas.');
        }
        if ($this->accessToken === '') {
            $this->refreshAccessToken();
        }
    }

    public function request(string $method, string $path, ?array $body = null, array $extraQuery = []): array
    {
        try {
            return $this->requestOnce($method, $path, $body, $extraQuery);
        } catch (SvMarketplaceException $e) {
            if ($this->refreshToken === '' || !$this->isTokenFailure($e)) {
                throw $e;
            }
            $this->refreshAccessToken();
            return $this->requestOnce($method, $path, $body, $extraQuery);
        }
    }

    private function requestOnce(string $method, string $path, ?array $body, array $extraQuery): array
    {
        $timestamp = time();
        $base = $this->partnerId . $path . $timestamp . $this->accessToken . $this->shopId;
        $query = array_merge([
            'partner_id' => $this->partnerId,
            'timestamp' => (string)$timestamp,
            'access_token' => $this->accessToken,
            'shop_id' => $this->shopId,
            'sign' => hash_hmac('sha256', $base, $this->partnerKey),
        ], $extraQuery);
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = sv_market_http_json($method, $url, ['Accept' => 'application/json'], $body);
        $json = $response['json'];
        if (($json['error'] ?? '') !== '' && ($json['error'] ?? 0) !== 0) {
            throw new SvMarketplaceException((string)($json['message'] ?? $json['error']), $response['status'], (string)($json['request_id'] ?? ''), $json);
        }
        return [
            'status' => $response['status'],
            'request_id' => (string)($json['request_id'] ?? $response['request_id']),
            'response' => is_array($json['response'] ?? null) ? $json['response'] : [],
            'raw' => $json,
        ];
    }

    public function uploadImage(string $filePath): array
    {
        try {
            return $this->uploadImageOnce($filePath);
        } catch (SvMarketplaceException $e) {
            if ($this->refreshToken === '' || !$this->isTokenFailure($e)) {
                throw $e;
            }
            $this->refreshAccessToken();
            return $this->uploadImageOnce($filePath);
        }
    }

    private function uploadImageOnce(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Imagem local indisponível para upload na Shopee.');
        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $base = $this->partnerId . $path . $timestamp . $this->accessToken . $this->shopId;
        $query = [
            'partner_id' => $this->partnerId,
            'timestamp' => (string)$timestamp,
            'access_token' => $this->accessToken,
            'shop_id' => $this->shopId,
            'sign' => hash_hmac('sha256', $base, $this->partnerKey),
        ];
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $mime = mime_content_type($filePath) ?: 'image/png';
        $response = sv_market_http_multipart($url, ['Accept' => 'application/json'], [
            'image' => new CURLFile($filePath, $mime, basename($filePath)),
        ]);
        $json = $response['json'];
        if (($json['error'] ?? '') !== '' && ($json['error'] ?? 0) !== 0) {
            throw new SvMarketplaceException((string)($json['message'] ?? $json['error']), $response['status'], $response['request_id'], $json);
        }
        $info = $json['response']['image_info'] ?? [];
        $urls = is_array($info['image_url_list'] ?? null) ? $info['image_url_list'] : [];
        return [
            'image_id' => (string)($info['image_id'] ?? ''),
            'image_url' => (string)($urls[0]['image_url'] ?? ''),
            'request_id' => (string)($json['request_id'] ?? ''),
        ];
    }

    private function refreshAccessToken(): void
    {
        if ($this->refreshToken === '') {
            throw new RuntimeException('SHOPEE_REFRESH_TOKEN ausente; reautorize a loja.');
        }
        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $signature = hash_hmac('sha256', $this->partnerId . $path . $timestamp, $this->partnerKey);
        $url = $this->host . $path . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => (string)$timestamp,
            'sign' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);
        $payload = [
            'refresh_token' => $this->refreshToken,
            'shop_id' => (int)$this->shopId,
            'partner_id' => (int)$this->partnerId,
        ];
        $response = sv_market_http_json('POST', $url, ['Accept' => 'application/json'], $payload);
        $json = $response['json'];
        if (($json['error'] ?? '') !== '' && ($json['error'] ?? 0) !== 0) {
            throw new SvMarketplaceException('Falha ao renovar token Shopee: ' . (string)($json['message'] ?? $json['error']), $response['status'], (string)($json['request_id'] ?? ''), $json);
        }
        $data = is_array($json['response'] ?? null) ? $json['response'] : $json;
        $newAccess = trim((string)($data['access_token'] ?? ''));
        $newRefresh = trim((string)($data['refresh_token'] ?? ''));
        if ($newAccess === '') {
            throw new RuntimeException('Renovação Shopee não retornou access_token.');
        }
        $this->accessToken = $newAccess;
        if ($newRefresh !== '') {
            $this->refreshToken = $newRefresh;
        }
    }

    private function isTokenFailure(SvMarketplaceException $e): bool
    {
        if (in_array($e->httpStatus, [401, 403], true)) return true;
        $text = strtolower($e->getMessage() . ' ' . sv_market_json($e->response));
        return str_contains($text, 'access_token') || str_contains($text, 'invalid token') || str_contains($text, 'token expired');
    }
}

final class SvShopeePublisher
{
    private SvShopeeClient $client;
    public function __construct(private PDO $db) { $this->client = new SvShopeeClient(); }

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para localizar o produto na Shopee.');
        $itemId = $this->resolveItemId($productId, $sku);
        $payload = [
            'item_id' => (int)$itemId,
            'item_name' => $this->limit(trim((string)($content['title'] ?? '')), 120),
            'description' => $this->description($content),
        ];
        sv_market_assert_no_commerce_fields($payload, 'Shopee update_item');
        if ($payload['item_name'] === '' || $payload['description'] === '') throw new RuntimeException('Título ou descrição vazios para a Shopee.');
        $result = $this->client->request('POST', '/api/v2/product/update_item', $payload);
        $read = $this->getBaseInfo([(int)$itemId]);
        $item = $read[0] ?? [];
        if (trim((string)($item['item_name'] ?? '')) !== $payload['item_name']) throw new RuntimeException('A Shopee não confirmou o título atualizado no read-back.');
        $readDescription = trim((string)($item['description'] ?? $item['description_info']['extended_description']['field_list'][0]['text'] ?? ''));
        if ($readDescription === '') throw new RuntimeException('A Shopee não confirmou a descrição no read-back.');
        sv_market_save_mapping($this->db, $productId, 'shopee', (string)$itemId, $sku, ['item_status' => $item['item_status'] ?? null]);
        return [
            'status' => 'published',
            'operation' => 'POST /api/v2/product/update_item',
            'external_id' => (string)$itemId,
            'http_status' => (int)$result['status'],
            'request_id' => (string)$result['request_id'],
            'fields' => ['item_name', 'description'],
            'response' => ['title_confirmed' => true, 'description_confirmed' => true, 'item_status' => $item['item_status'] ?? null],
            'verified' => true,
        ];
    }

    public function publishImages(int $productId, array $localFiles): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para publicar imagens na Shopee.');
        $itemId = $this->resolveItemId($productId, $sku);
        $before = $this->getBaseInfo([(int)$itemId]);
        $beforeItem = $before[0] ?? [];
        $existingIds = $beforeItem['image']['image_id_list'] ?? $beforeItem['image_info']['image_id_list'] ?? [];
        $existingIds = is_array($existingIds) ? array_values(array_filter(array_map('strval', $existingIds))) : [];
        $imageIds = [];
        $requestIds = [];
        foreach (array_slice($localFiles, 0, 9) as $file) {
            $uploaded = $this->client->uploadImage((string)$file);
            if ($uploaded['image_id'] === '') throw new RuntimeException('Shopee não retornou image_id após o upload.');
            $imageIds[] = $uploaded['image_id'];
            if ($uploaded['request_id'] !== '') $requestIds[] = $uploaded['request_id'];
        }
        $imageIds = array_slice(array_values(array_unique(array_merge($imageIds, $existingIds))), 0, 9);
        if ($imageIds === []) throw new RuntimeException('Nenhuma imagem foi enviada à Shopee.');
        $payload = ['item_id' => (int)$itemId, 'image' => ['image_id_list' => $imageIds]];
        sv_market_assert_no_commerce_fields($payload, 'Shopee image update');
        $result = $this->client->request('POST', '/api/v2/product/update_item', $payload);
        $read = $this->getBaseInfo([(int)$itemId]);
        $item = $read[0] ?? [];
        $readImages = $item['image']['image_id_list'] ?? $item['image_info']['image_id_list'] ?? [];
        if (!is_array($readImages) || $readImages === []) throw new RuntimeException('A Shopee aceitou a chamada, mas não confirmou imagens no read-back.');
        return [
            'status' => 'published',
            'operation' => 'upload_image + update_item image',
            'external_id' => (string)$itemId,
            'http_status' => (int)$result['status'],
            'request_id' => (string)($result['request_id'] ?: ($requestIds[0] ?? '')),
            'fields' => ['image'],
            'response' => ['image_count' => count($readImages)],
            'verified' => true,
        ];
    }

    private function resolveItemId(int $productId, string $sku): int
    {
        $mapping = sv_market_mapping($this->db, $productId, 'shopee');
        if (is_array($mapping) && (int)$mapping['external_id'] > 0) return (int)$mapping['external_id'];
        $offset = 0;
        do {
            $list = $this->client->request('GET', '/api/v2/product/get_item_list', null, ['offset' => $offset, 'page_size' => 100, 'item_status' => 'NORMAL']);
            $items = $list['response']['item'] ?? $list['response']['item_list'] ?? [];
            $ids = [];
            foreach (is_array($items) ? $items : [] as $item) if (!empty($item['item_id'])) $ids[] = (int)$item['item_id'];
            foreach (array_chunk($ids, 50) as $chunk) {
                foreach ($this->getBaseInfo($chunk) as $detail) {
                    if (trim((string)($detail['item_sku'] ?? '')) === $sku) {
                        $itemId = (int)$detail['item_id'];
                        sv_market_save_mapping($this->db, $productId, 'shopee', (string)$itemId, $sku, ['source' => 'item_sku']);
                        return $itemId;
                    }
                    $itemId = (int)($detail['item_id'] ?? 0);
                    if ($itemId > 0 && $this->modelContainsSku($itemId, $sku)) {
                        sv_market_save_mapping($this->db, $productId, 'shopee', (string)$itemId, $sku, ['source' => 'model_sku']);
                        return $itemId;
                    }
                }
            }
            $hasNext = !empty($list['response']['has_next_page']);
            $offset = (int)($list['response']['next_offset'] ?? ($offset + count($ids)));
        } while ($hasNext);
        throw new RuntimeException("Produto Shopee não localizado para o SKU {$sku}.");
    }

    private function getBaseInfo(array $ids): array
    {
        if ($ids === []) return [];
        $result = $this->client->request('GET', '/api/v2/product/get_item_base_info', null, [
            'item_id_list' => implode(',', $ids),
            'need_complaint_policy' => 'false',
        ]);
        $items = $result['response']['item_list'] ?? [];
        return is_array($items) ? array_values($items) : [];
    }

    private function modelContainsSku(int $itemId, string $sku): bool
    {
        try {
            $result = $this->client->request('GET', '/api/v2/product/get_model_list', null, ['item_id' => $itemId]);
            $models = $result['response']['model'] ?? $result['response']['model_list'] ?? [];
            foreach (is_array($models) ? $models : [] as $model) if (trim((string)($model['model_sku'] ?? '')) === $sku) return true;
        } catch (Throwable) {
            return false;
        }
        return false;
    }

    private function description(array $content): string
    {
        $parts = [trim((string)($content['description'] ?? ''))];
        $bullets = is_array($content['bullet_points'] ?? null) ? $content['bullet_points'] : [];
        if ($bullets !== []) $parts[] = implode("\n", array_map(static fn($v): string => '• ' . trim((string)$v), $bullets));
        $hooks = is_array($content['marketing_hooks'] ?? null) ? $content['marketing_hooks'] : [];
        if ($hooks !== []) $parts[] = implode("\n", array_map(static fn($v): string => trim((string)$v), $hooks));
        return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
