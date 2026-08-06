<?php
declare(strict_types=1);

/**
 * Marketplace publication checks for the existing Admin Connections monitor.
 *
 * These are authenticated read-only calls through the same official clients
 * used by catalog publication. They never publish products, never read/write
 * price or inventory, and never expose secret values.
 */
require_once __DIR__ . '/marketplace/ShopeePublisher.php';
require_once __DIR__ . '/marketplace/TikTokPublisher.php';
require_once __DIR__ . '/marketplace/AmazonPublisher.php';

/** @return array<string,mixed> */
function svih_marketplace_token(string $environmentKey, string $privateFile = '', string $privateKey = 'access_token'): array
{
    $value = svih_env($environmentKey);
    $source = $value !== '' ? 'environment' : 'missing';
    if ($value === '' && $privateFile !== '' && is_file($privateFile) && is_readable($privateFile)) {
        $data = json_decode((string)file_get_contents($privateFile), true);
        if (is_array($data)) {
            $value = trim((string)($data[$privateKey] ?? ''));
            if ($value !== '') $source = 'private-file';
        }
    }
    return svih_token_meta($value, $source);
}

/** @return array<string,mixed> */
function svih_shopee_publication(): array
{
    $root = dirname(__DIR__);
    $tokens = [
        'access_token' => svih_marketplace_token('SHOPEE_ACCESS_TOKEN', $root . '/storage/private/shopee-tokens.json'),
        'refresh_token' => svih_marketplace_token('SHOPEE_REFRESH_TOKEN', $root . '/storage/private/shopee-tokens.json', 'refresh_token'),
        'partner_id' => svih_token_meta(svih_env('SHOPEE_PARTNER_ID'), 'environment'),
        'partner_key' => svih_token_meta(svih_env('SHOPEE_PARTNER_KEY'), 'environment'),
        'shop_id' => svih_token_meta(svih_env('SHOPEE_SHOP_ID'), 'environment'),
    ];
    try {
        $client = new SvShopeeClient();
        $response = $client->request('GET', '/api/v2/product/get_item_list', null, [
            'offset' => 0,
            'page_size' => 1,
            'item_status' => 'NORMAL',
        ]);
        return [
            'name' => 'Shopee — publicação de catálogo',
            'key' => 'shopee_publication',
            'status' => 'connected',
            'message' => 'Open API autenticada respondeu à leitura de produtos.',
            'remediation' => null,
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => (int)($response['status'] ?? 200),
            'validation' => 'authenticated_product_read',
        ];
    } catch (Throwable $e) {
        return [
            'name' => 'Shopee — publicação de catálogo',
            'key' => 'shopee_publication',
            'status' => 'failed',
            'message' => 'A Open API não confirmou acesso ao catálogo.',
            'remediation' => str_contains(strtolower($e->getMessage()), 'credenciais')
                ? 'Preencher Partner ID, Partner Key, Shop ID e access/refresh token.'
                : 'Renovar/reautorizar a loja Shopee e validar os escopos de produto.',
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => $e instanceof SvMarketplaceException ? $e->httpStatus : 0,
            'provider_error' => get_class($e),
            'validation' => 'authenticated_product_read',
        ];
    }
}

/** @return array<string,mixed> */
function svih_tiktok_shop_publication(): array
{
    $root = dirname(__DIR__);
    $tokens = [
        'access_token' => svih_marketplace_token('TIKTOK_ACCESS_TOKEN', $root . '/storage/private/tiktok-tokens.json'),
        'refresh_token' => svih_marketplace_token('TIKTOK_REFRESH_TOKEN', $root . '/storage/private/tiktok-tokens.json', 'refresh_token'),
        'app_key' => svih_token_meta(svih_env('TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID'), 'environment'),
        'app_secret' => svih_token_meta(svih_env('TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET'), 'environment'),
        'shop_cipher' => svih_token_meta(svih_env('TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID'), 'environment'),
    ];
    try {
        $client = new SvTikTokClient();
        // Corpo não vazio força JSON object válido em PHP; o SKU sentinela
        // não corresponde a anúncio real e serve somente para provar acesso.
        $response = $client->request(
            'POST',
            '/product/202309/products/search',
            ['seller_skus' => ['__SHOPVIVALIZ_HEALTHCHECK_NO_MATCH__']],
            ['page_size' => 1]
        );
        return [
            'name' => 'TikTok Shop — publicação de catálogo',
            'key' => 'tiktok_shop_publication',
            'status' => 'connected',
            'message' => 'Products API autenticada respondeu à busca do catálogo.',
            'remediation' => null,
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => (int)($response['status'] ?? 200),
            'validation' => 'authenticated_product_read',
        ];
    } catch (Throwable $e) {
        return [
            'name' => 'TikTok Shop — publicação de catálogo',
            'key' => 'tiktok_shop_publication',
            'status' => 'failed',
            'message' => 'A Products API não confirmou acesso ao catálogo.',
            'remediation' => 'Preencher App Key, App Secret, Shop Cipher e access/refresh token da TikTok Shop.',
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => $e instanceof SvMarketplaceException ? $e->httpStatus : 0,
            'provider_error' => get_class($e),
            'validation' => 'authenticated_product_read',
        ];
    }
}

/** @return array<string,mixed> */
function svih_amazon_publication(): array
{
    $tokens = [
        'lwa_refresh_token' => svih_token_meta(svih_env('AMAZON_LWA_REFRESH_TOKEN'), 'environment'),
        'lwa_client_id' => svih_token_meta(svih_env('AMAZON_LWA_CLIENT_ID'), 'environment'),
        'lwa_client_secret' => svih_token_meta(svih_env('AMAZON_LWA_CLIENT_SECRET'), 'environment'),
        'seller_id' => svih_token_meta(svih_env('AMAZON_SELLER_ID', 'AMAZON_ACCOUNT_ID'), 'environment'),
        'marketplace_id' => svih_token_meta(svih_env('AMAZON_MARKETPLACE_ID'), 'environment'),
    ];
    try {
        $client = new SvAmazonClient();
        $response = $client->request('GET', '/sellers/v1/marketplaceParticipations');
        return [
            'name' => 'Amazon SP-API — publicação de catálogo',
            'key' => 'amazon_publication',
            'status' => 'connected',
            'message' => 'SP-API autenticada confirmou as participações do vendedor.',
            'remediation' => null,
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => (int)($response['status'] ?? 200),
            'validation' => 'authenticated_seller_read',
        ];
    } catch (Throwable $e) {
        return [
            'name' => 'Amazon SP-API — publicação de catálogo',
            'key' => 'amazon_publication',
            'status' => 'failed',
            'message' => 'A SP-API não confirmou acesso ao vendedor.',
            'remediation' => 'Preencher LWA Client ID/Secret, Refresh Token, Seller ID e Marketplace ID da Amazon.',
            'tokens' => $tokens,
            'fixes' => [],
            'provider_status' => $e instanceof SvMarketplaceException ? $e->httpStatus : 0,
            'provider_error' => get_class($e),
            'validation' => 'authenticated_seller_read',
        ];
    }
}

/** @return list<array<string,mixed>> */
function svih_marketplace_publication_checks(): array
{
    return [
        svih_shopee_publication(),
        svih_tiktok_shop_publication(),
        svih_amazon_publication(),
    ];
}
