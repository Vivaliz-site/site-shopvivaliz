<?php

declare(strict_types=1);

/**
 * Diagnostico read-only e protegido por admin.
 *
 * Nunca imprime valores de segredo. Retorna apenas presenca/ausencia, nomes
 * de aliases reconhecidos e existencia de arquivos privados conhecidos.
 */

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/tiny-order-push.php';
require_once __DIR__ . '/../../includes/marketplace/AmazonPublisher.php';

header('Content-Type: application/json; charset=utf-8');

function dmt_present(string $key): bool
{
    $v = getenv($key);
    return $v !== false && trim((string)$v) !== '';
}

/** @param list<string> $keys @return array<string,bool> */
function dmt_presence_map(array $keys): array
{
    $out = [];
    foreach ($keys as $key) $out[$key] = dmt_present($key);
    return $out;
}

/** @param list<string> $keys */
function dmt_any_present(array $keys): bool
{
    foreach ($keys as $key) if (dmt_present($key)) return true;
    return false;
}

/**
 * Inspeciona somente chaves conhecidas de JSON privado; nunca retorna valores.
 *
 * @param list<string> $paths
 * @param list<string> $keys
 * @return array<string,array{exists:bool,readable:bool,recognized_keys:array<string,bool>}>
 */
function dmt_private_json_presence(array $paths, array $keys): array
{
    $out = [];
    foreach ($paths as $path) {
        $entry = ['exists' => is_file($path), 'readable' => is_readable($path), 'recognized_keys' => []];
        if ($entry['exists'] && $entry['readable']) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data)) {
                foreach ($keys as $key) {
                    if (!array_key_exists($key, $data)) continue;
                    $value = $data[$key];
                    $entry['recognized_keys'][$key] = is_scalar($value) && trim((string)$value) !== '';
                }
            }
        }
        $out[$path] = $entry;
    }
    return $out;
}

function dmt_amazon_api_probe(): array
{
    try {
        $client = new SvAmazonClient();
        $response = $client->request('GET', '/sellers/v1/marketplaceParticipations');
        $payload = is_array($response['data']['payload'] ?? null)
            ? $response['data']['payload']
            : (is_array($response['data'] ?? null) ? $response['data'] : []);
        return [
            'connected' => true,
            'http_status' => (int)($response['status'] ?? 0),
            'request_id_present' => trim((string)($response['request_id'] ?? '')) !== '',
            'marketplace_participations_count' => count($payload),
            'validation' => 'GET /sellers/v1/marketplaceParticipations',
        ];
    } catch (Throwable $e) {
        return [
            'connected' => false,
            'error_type' => get_class($e),
            'http_status' => $e instanceof SvMarketplaceException ? $e->httpStatus : 0,
            'validation' => 'GET /sellers/v1/marketplaceParticipations',
        ];
    }
}

$root = dirname(__DIR__, 2);
$sharedRoot = '/home/ubuntu/shopvivaliz-deploy/shared';

$amazonClientIdAliases = [
    'AMAZON_LWA_CLIENT_ID',
    'AMAZON_SP_API_CLIENT_ID',
    'SP_API_CLIENT_ID',
    'LWA_CLIENT_ID',
];
$amazonClientSecretAliases = [
    'AMAZON_LWA_CLIENT_SECRET',
    'AMAZON_SP_API_CLIENT_SECRET',
    'SP_API_CLIENT_SECRET',
    'LWA_CLIENT_SECRET',
];
$amazonRefreshAliases = [
    'AMAZON_LWA_REFRESH_TOKEN',
    'AMAZON_SP_API_REFRESH_TOKEN',
    'SP_API_REFRESH_TOKEN',
    'LWA_REFRESH_TOKEN',
];
$amazonAccessAliases = [
    'AMAZON_LWA_ACCESS_TOKEN',
    'AMAZON_SP_API_ACCESS_TOKEN',
    'SP_API_ACCESS_TOKEN',
    'LWA_ACCESS_TOKEN',
];
$amazonSellerAliases = [
    'AMAZON_SELLER_ID',
    'AMAZON_ACCOUNT_ID',
    'AMAZON_MERCHANT_ID',
    'AMAZON_MERCHANT_TOKEN',
    'SP_API_SELLER_ID',
];
$amazonMarketplaceAliases = [
    'AMAZON_MARKETPLACE_ID',
    'AMAZON_MARKETPLACE',
    'SP_API_MARKETPLACE_ID',
];

$amazonPrivatePaths = [
    $root . '/storage/private/amazon-tokens.json',
    $root . '/storage/private/amazon-spapi.json',
    $root . '/storage/private/amazon-sp-api.json',
    $root . '/storage/private/sp-api.json',
    $root . '/storage/private/lwa-tokens.json',
    $sharedRoot . '/amazon-tokens.json',
    $sharedRoot . '/amazon-spapi.json',
    $sharedRoot . '/amazon-sp-api.json',
    $sharedRoot . '/sp-api.json',
    $sharedRoot . '/lwa-tokens.json',
];
$amazonPrivateKeys = [
    'client_id', 'client_secret', 'refresh_token', 'access_token',
    'lwa_client_id', 'lwa_client_secret', 'lwa_refresh_token', 'lwa_access_token',
    'seller_id', 'merchant_id', 'merchant_token', 'account_id', 'marketplace_id',
];

$report = [
    'ml' => [
        'nome' => 'Mercado Livre',
        'client_id_present' => dmt_present('ML_CLIENT_ID'),
        'client_secret_present' => dmt_present('ML_CLIENT_SECRET'),
        'access_token_env_present' => dmt_present('ML_ACCESS_TOKEN'),
        'refresh_token_env_present' => dmt_present('ML_REFRESH_TOKEN'),
        'token_file_exists' => is_file($root . '/storage/private/ml-tokens.json') || is_file($root . '/data/tokens.json'),
        'publisher_real' => 'MercadoLivrePublisher.php',
    ],
    'shopee' => [
        'nome' => 'Shopee',
        'partner_id_present' => dmt_present('SHOPEE_PARTNER_ID'),
        'partner_key_present' => dmt_present('SHOPEE_PARTNER_KEY'),
        'shop_id_present' => dmt_present('SHOPEE_SHOP_ID'),
        'access_token_present' => dmt_present('SHOPEE_ACCESS_TOKEN'),
        'refresh_token_present' => dmt_present('SHOPEE_REFRESH_TOKEN'),
        'token_file_exists' => is_file($root . '/storage/private/shopee-tokens.json'),
        'publisher_real' => 'ShopeePublisher.php',
    ],
    'tiktok' => [
        'nome' => 'TikTok Shop',
        'app_key_aliases' => dmt_presence_map(['TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID']),
        'app_secret_aliases' => dmt_presence_map(['TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET']),
        'access_token_present' => dmt_present('TIKTOK_ACCESS_TOKEN'),
        'refresh_token_present' => dmt_present('TIKTOK_REFRESH_TOKEN'),
        'shop_aliases' => dmt_presence_map(['TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID']),
        'configured_token_file_env_present' => dmt_present('TIKTOK_TOKEN_FILE'),
        'default_token_file_exists' => is_file($root . '/storage/private/tiktok-tokens.json'),
        'shared_token_file_exists' => is_file($sharedRoot . '/tiktok-tokens.json'),
        'publisher_real' => 'TikTokPublisher.php',
    ],
    'amazon' => [
        'nome' => 'Amazon SP-API',
        'auth_mode' => 'LWA OAuth / x-amz-access-token; AWS IAM/SigV4 nao e requisito',
        'client_id_aliases' => dmt_presence_map($amazonClientIdAliases),
        'client_secret_aliases' => dmt_presence_map($amazonClientSecretAliases),
        'refresh_token_aliases' => dmt_presence_map($amazonRefreshAliases),
        'access_token_aliases' => dmt_presence_map($amazonAccessAliases),
        'seller_aliases' => dmt_presence_map($amazonSellerAliases),
        'marketplace_aliases' => dmt_presence_map($amazonMarketplaceAliases),
        'lwa_refresh_configuration_complete' => dmt_any_present($amazonClientIdAliases)
            && dmt_any_present($amazonClientSecretAliases)
            && dmt_any_present($amazonRefreshAliases),
        'private_file_candidates' => dmt_private_json_presence($amazonPrivatePaths, $amazonPrivateKeys),
        'publisher_real' => 'AmazonPublisher.php -> Sellers API + Listings Items API + Product Type Definitions',
        'read_only_api_probe' => dmt_amazon_api_probe(),
    ],
    'erp_tiny' => [
        'nome' => 'ERP / Tiny',
        'credenciais_configuradas' => svtop_tiny_credentials_configured(),
        'publisher_real' => 'TinyPublisher.php / tiny-product-push.php',
    ],
];

echo json_encode([
    'ok' => true,
    'safe_output' => 'Somente presenca/ausencia; nenhum valor de segredo e retornado.',
    'canais' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
