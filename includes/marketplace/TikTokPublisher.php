<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';

final class SvTikTokClient
{
    private string $host;
    private string $appKey;
    private string $appSecret;
    private string $accessToken;
    private string $refreshToken;
    private string $shopCipher;
    private int $accessTokenExpiresAt = 0;

    public function __construct()
    {
        $this->host = rtrim(sv_market_env('TIKTOK_SHOP_API_ENDPOINT'), '/');
        if ($this->host === '') $this->host = 'https://open-api.tiktokglobalshop.com';
        $this->appKey = sv_market_env('TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID');
        $this->appSecret = sv_market_env('TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET');
        $this->accessToken = sv_market_env('TIKTOK_ACCESS_TOKEN');
        $this->refreshToken = sv_market_env('TIKTOK_REFRESH_TOKEN');
        $this->shopCipher = sv_market_env('TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID');
        $this->loadTokenCache();
        if ($this->appKey === '' || $this->appSecret === '' || $this->shopCipher === '' || ($this->accessToken === '' && $this->refreshToken === '')) {
            throw new RuntimeException('Credenciais de escrita do TikTok Shop incompletas.');
        }
        if ($this->accessToken === '' || ($this->accessTokenExpiresAt > 0 && $this->accessTokenExpiresAt <= time() + 600)) {
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
        $bodyString = $body === null ? '' : sv_market_json($body);
        $query = $this->signedQuery($path, $extraQuery, $bodyString);
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = sv_market_http_json($method, $url, [
            'x-tts-access-token' => $this->accessToken,
            'Accept' => 'application/json',
        ], $body);
        $json = $response['json'];
        $code = (int)($json['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new SvMarketplaceException(
                'TikTok Shop ' . $code . ': ' . (string)($json['message'] ?? 'erro desconhecido'),
                $response['status'],
                (string)($json['request_id'] ?? ''),
                $json
            );
        }
        return [
            'status' => $response['status'],
            'request_id' => (string)($json['request_id'] ?? $response['request_id']),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
            'raw' => $json,
        ];
    }

    public function uploadProductImage(string $filePath): array
    {
        try {
            return $this->uploadProductImageOnce($filePath);
        } catch (SvMarketplaceException $e) {
            if ($this->refreshToken === '' || !$this->isTokenFailure($e)) {
                throw $e;
            }
            $this->refreshAccessToken();
            return $this->uploadProductImageOnce($filePath);
        }
    }

    private function uploadProductImageOnce(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Imagem local indisponível para upload no TikTok Shop.');
        $path = '/product/202309/images/upload';
        $query = ['app_key' => $this->appKey, 'timestamp' => (string)time()];
        $query['sign'] = $this->sign($path, $query, '');
        $url = $this->host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $mime = mime_content_type($filePath) ?: 'image/png';
        $response = sv_market_http_multipart($url, [
            'x-tts-access-token' => $this->accessToken,
            'Accept' => 'application/json',
        ], [
            'data' => new CURLFile($filePath, $mime, basename($filePath)),
            'use_case' => 'MAIN_IMAGE',
        ]);
        $json = $response['json'];
        $code = (int)($json['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new SvMarketplaceException(
                'TikTok upload ' . $code . ': ' . (string)($json['message'] ?? 'erro desconhecido'),
                $response['status'],
                (string)($json['request_id'] ?? ''),
                $json
            );
        }
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        return [
            'uri' => (string)($data['uri'] ?? ''),
            'url' => trim((string)($data['url'] ?? '')),
            'request_id' => (string)($json['request_id'] ?? ''),
            'status' => $response['status'],
        ];
    }

    private function signedQuery(string $path, array $extra, string $body): array
    {
        $query = array_merge([
            'app_key' => $this->appKey,
            'shop_cipher' => $this->shopCipher,
            'timestamp' => (string)time(),
        ], $extra);
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

    private function refreshAccessToken(): void
    {
        if ($this->refreshToken === '') {
            throw new RuntimeException('TIKTOK_REFRESH_TOKEN ausente; a loja precisa ser reautorizada.');
        }
        $url = 'https://auth.tiktok-shops.com/api/v2/token/refresh?' . http_build_query([
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC3986);
        $response = sv_market_http_json('GET', $url, ['Accept' => 'application/json'], null, 30);
        $json = $response['json'];
        if ((int)($json['code'] ?? -1) !== 0) {
            throw new SvMarketplaceException(
                'Falha ao renovar token TikTok Shop: ' . (string)($json['message'] ?? 'erro desconhecido'),
                $response['status'],
                (string)($json['request_id'] ?? ''),
                $json
            );
        }
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $access = trim((string)($data['access_token'] ?? ''));
        $refresh = trim((string)($data['refresh_token'] ?? ''));
        if ($access === '') {
            throw new RuntimeException('Renovação TikTok Shop não retornou access_token.');
        }
        $this->accessToken = $access;
        if ($refresh !== '') $this->refreshToken = $refresh;
        $this->accessTokenExpiresAt = (int)($data['access_token_expire_in'] ?? 0);
        $this->saveTokenCache([
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'access_token_expire_in' => $this->accessTokenExpiresAt,
            'refresh_token_expire_in' => (int)($data['refresh_token_expire_in'] ?? 0),
            'updated_at' => gmdate('c'),
        ]);
    }

    private function tokenCachePath(): string
    {
        $configured = sv_market_env('TIKTOK_TOKEN_FILE');
        if ($configured !== '') return $configured;
        return dirname(__DIR__, 2) . '/storage/private/tiktok-tokens.json';
    }

    private function loadTokenCache(): void
    {
        $path = $this->tokenCachePath();
        if (!is_file($path) || !is_readable($path)) return;
        $data = sv_market_decode_json((string)file_get_contents($path));
        $access = trim((string)($data['access_token'] ?? ''));
        $refresh = trim((string)($data['refresh_token'] ?? ''));
        if ($access !== '') $this->accessToken = $access;
        if ($refresh !== '') $this->refreshToken = $refresh;
        $this->accessTokenExpiresAt = (int)($data['access_token_expire_in'] ?? 0);
    }

    private function saveTokenCache(array $data): void
    {
        $path = $this->tokenCachePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório privado de tokens TikTok.');
        }
        $temporary = tempnam($directory, '.tiktok-token-');
        if ($temporary === false) throw new RuntimeException('Não foi possível criar arquivo temporário de token TikTok.');
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível gravar token TikTok com segurança.');
            }
            @chmod($temporary, 0640);
            if (is_file($path)) {
                $stat = stat($path);
                if (is_array($stat) && function_exists('chown') && function_exists('chgrp')) {
                    @chown($temporary, (int)$stat['uid']);
                    @chgrp($temporary, (int)$stat['gid']);
                }
            }
            if (!@rename($temporary, $path)) throw new RuntimeException('Não foi possível substituir o token TikTok atomicamente.');
            @chmod($path, 0640);
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function isTokenFailure(SvMarketplaceException $e): bool
    {
        if (in_array($e->httpStatus, [401, 403], true)) return true;
        $text = strtolower($e->getMessage() . ' ' . sv_market_json($e->response));
        return str_contains($text, 'access token') || str_contains($text, 'access_token') || str_contains($text, 'token expired');
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
        $payload = [
            'save_mode' => 'LISTING',
            'title' => $this->limit(trim((string)($content['title'] ?? '')), 300),
            'description' => $this->htmlDescription($content),
        ];
        sv_market_assert_no_commerce_fields($payload, 'TikTok partial edit');
        if ($payload['title'] === '' || $payload['description'] === '') throw new RuntimeException('Título ou descrição vazios para TikTok Shop.');
        $response = $this->client->request('POST', '/product/202509/products/' . rawurlencode($externalId) . '/partial_edit', $payload);
        $review = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId), null, ['return_under_review_version' => 'true']);
        $live = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
        $reviewData = $review['data'];
        $liveData = $live['data'];
        $reviewTitle = trim((string)($reviewData['title'] ?? $reviewData['product']['title'] ?? ''));
        $liveTitle = trim((string)($liveData['title'] ?? $liveData['product']['title'] ?? ''));
        if ($reviewTitle !== $payload['title'] && $liveTitle !== $payload['title']) {
            throw new RuntimeException('TikTok Shop aceitou a chamada, mas nenhum read-back confirmou o título enviado.');
        }
        $liveStatus = strtoupper((string)($liveData['status'] ?? $liveData['product_status'] ?? ''));
        $auditStatus = strtoupper((string)($reviewData['audit']['status'] ?? $response['data']['audit']['status'] ?? ''));
        $published = $liveTitle === $payload['title'] && in_array($liveStatus, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true);
        $publicationStatus = $published ? 'published' : 'submitted';
        sv_market_save_mapping($this->db, $productId, 'tiktok', $externalId, $sku, [
            'product_status' => $liveStatus,
            'audit_status' => $auditStatus,
        ]);
        return [
            'status' => $publicationStatus,
            'operation' => 'POST /product/202509/products/{id}/partial_edit',
            'external_id' => $externalId,
            'http_status' => (int)$response['status'],
            'request_id' => (string)$response['request_id'],
            'fields' => ['title', 'description'],
            'response' => [
                'accepted' => true,
                'under_review_confirmed' => $reviewTitle === $payload['title'],
                'live_confirmed' => $published,
                'product_status' => $liveStatus,
                'audit_status' => $auditStatus,
            ],
            'submitted' => !$published,
            'verified' => true,
        ];
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
        $expectedUris = array_slice(array_values(array_unique(array_merge($newUris, $existingUris))), 0, 9);
        $images = array_map(static fn(string $uri): array => ['uri' => $uri], $expectedUris);
        if ($images === []) throw new RuntimeException('Nenhuma imagem válida foi enviada ao TikTok Shop.');
        $payload = ['save_mode' => 'LISTING', 'main_images' => $images];
        sv_market_assert_no_commerce_fields($payload, 'TikTok image partial edit');
        $response = $this->client->request('POST', '/product/202509/products/' . rawurlencode($externalId) . '/partial_edit', $payload);
        $review = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId), null, ['return_under_review_version' => 'true']);
        $live = $this->client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
        $reviewData = $review['data'];
        $liveData = $live['data'];
        $reviewImages = $reviewData['main_images'] ?? $reviewData['product']['main_images'] ?? [];
        $liveImages = $liveData['main_images'] ?? $liveData['product']['main_images'] ?? [];
        $reviewUris = $this->imageUris($reviewImages);
        $liveUris = $this->imageUris($liveImages);
        if (array_intersect($newUris, $reviewUris) === [] && array_intersect($newUris, $liveUris) === []) {
            throw new RuntimeException('TikTok Shop aceitou a chamada, mas nenhum read-back confirmou as novas imagens.');
        }
        $liveStatus = strtoupper((string)($liveData['status'] ?? $liveData['product_status'] ?? ''));
        $auditStatus = strtoupper((string)($reviewData['audit']['status'] ?? $response['data']['audit']['status'] ?? ''));
        $published = array_intersect($newUris, $liveUris) !== [] && in_array($liveStatus, ['ACTIVATE', 'LIVE', 'PUBLISHED'], true);
        return [
            'status' => $published ? 'published' : 'submitted',
            'operation' => 'POST images/upload + partial_edit main_images',
            'external_id' => $externalId,
            'http_status' => (int)$response['status'],
            'request_id' => (string)($response['request_id'] ?: ($requestIds[0] ?? '')),
            'fields' => ['main_images'],
            'response' => [
                'accepted' => true,
                'under_review_image_count' => count($reviewUris),
                'live_image_count' => count($liveUris),
                'audit_status' => $auditStatus,
                'live_confirmed' => $published,
            ],
            'submitted' => !$published,
            'verified' => true,
        ];
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

    /** @return list<string> */
    private function imageUris(mixed $images): array
    {
        $uris = [];
        foreach (is_array($images) ? $images : [] as $entry) {
            $uri = trim((string)($entry['uri'] ?? ''));
            if ($uri !== '' && !in_array($uri, $uris, true)) $uris[] = $uri;
        }
        return $uris;
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
