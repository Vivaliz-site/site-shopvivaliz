<?php

declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';
require_once __DIR__ . '/AmazonClient.php';

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
        if ($patches === []) throw new RuntimeException('Nenhum atributo textual valido para a Amazon.');
        $payload = ['productType' => $productType, 'patches' => $patches];
        sv_market_assert_no_commerce_fields($payload, 'Amazon Listings Items patch');
        $response = $this->client->request('PATCH', '/listings/2021-08-01/items/' . rawurlencode($this->client->sellerId()) . '/' . rawurlencode($sku), [
            'marketplaceIds' => $marketplaceId,
            'issueLocale' => 'pt_BR',
        ], $payload);
        $data = $response['data'];
        $submissionStatus = strtoupper((string)($data['status'] ?? ''));
        if ($submissionStatus !== 'ACCEPTED') throw new RuntimeException('Amazon rejeitou a atualizacao: ' . sv_market_safe_excerpt(sv_market_json($data['issues'] ?? [])));
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
        if ($urls === []) throw new RuntimeException('Nenhuma imagem valida para a Amazon.');
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
            throw new RuntimeException('Amazon Product Type Definitions nao confirmou o productType.');
        }
    }

    private function productType(array $listing): string
    {
        $summaries = is_array($listing['summaries'] ?? null) ? $listing['summaries'] : [];
        $productType = trim((string)($summaries[0]['productType'] ?? $listing['productType'] ?? ''));
        if ($productType === '') throw new RuntimeException('Amazon nao retornou productType para o SKU.');
        return $productType;
    }

    private function patch(string $attribute, array $value): array
    {
        return ['op' => 'replace', 'path' => '/attributes/' . $attribute, 'value' => $value];
    }
}
