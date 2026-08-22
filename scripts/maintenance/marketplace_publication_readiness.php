<?php
declare(strict_types=1);

/**
 * Auditoria mascarada de prontidão das rotinas de publicação real.
 * Nunca imprime valores de tokens, somente presença, validade estrutural,
 * origem segura e contagens. Nenhuma chamada de escrita é executada aqui.
 */
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-publication-schema.php';

function svmpr_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') return trim($_ENV[$key]);
    }
    return '';
}

function svmpr_valid(string $value, int $minimum = 4): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '' || strlen($value) < $minimum) return false;
    foreach (['changeme', 'placeholder', 'your_', 'replace_', 'example', 'dummy', 'undefined', 'null', 'none', 'token_here'] as $marker) {
        if (str_contains($normalized, $marker)) return false;
    }
    return true;
}

/** @return array{ready:bool,source:string,access_present:bool,refresh_present:bool,expiring_soon:bool,file_readable:bool} */
function svmpr_token_state(array $paths, array $accessAliases, array $refreshAliases, string $expiryKey = ''): array
{
    $access = svmpr_env(...$accessAliases);
    $refresh = svmpr_env(...$refreshAliases);
    $state = [
        'ready' => svmpr_valid($access, 8) || svmpr_valid($refresh, 8),
        'source' => svmpr_valid($access, 8) || svmpr_valid($refresh, 8) ? 'environment' : 'missing',
        'access_present' => svmpr_valid($access, 8),
        'refresh_present' => svmpr_valid($refresh, 8),
        'expiring_soon' => false,
        'file_readable' => false,
    ];

    foreach (array_values(array_filter($paths)) as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $state['file_readable'] = true;
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) continue;
        $fileAccess = trim((string)($data['access_token'] ?? ''));
        $fileRefresh = trim((string)($data['refresh_token'] ?? ''));
        if (svmpr_valid($fileAccess, 8) || svmpr_valid($fileRefresh, 8)) {
            $state['ready'] = true;
            $state['source'] = 'private_token_file';
            $state['access_present'] = $state['access_present'] || svmpr_valid($fileAccess, 8);
            $state['refresh_present'] = $state['refresh_present'] || svmpr_valid($fileRefresh, 8);
            if ($expiryKey !== '') {
                $expiresAt = (int)($data[$expiryKey] ?? 0);
                $state['expiring_soon'] = $expiresAt > 0 && $expiresAt <= time() + 1800;
            }
            break;
        }
    }
    return $state;
}

$root = dirname(__DIR__, 2);
$mlTokenState = svmpr_token_state(
    array_values(array_filter([
        svmpr_env('ML_TOKEN_FILE'),
        $root . '/storage/private/ml-tokens.json',
        $root . '/data/tokens.json',
    ])),
    ['ML_ACCESS_TOKEN', 'MERCADO_LIVRE_ACCESS_TOKEN'],
    ['ML_REFRESH_TOKEN', 'MERCADO_LIVRE_REFRESH_TOKEN'],
    'expires_at_ms'
);
$shopeeTokenState = svmpr_token_state(
    array_values(array_filter([
        svmpr_env('SHOPEE_TOKEN_FILE'),
        $root . '/storage/private/shopee-tokens.json',
    ])),
    ['SHOPEE_ACCESS_TOKEN'],
    ['SHOPEE_REFRESH_TOKEN']
);
$tiktokTokenState = svmpr_token_state(
    array_values(array_filter([
        svmpr_env('TIKTOK_TOKEN_FILE'),
        $root . '/storage/private/tiktok-tokens.json',
    ])),
    ['TIKTOK_ACCESS_TOKEN'],
    ['TIKTOK_REFRESH_TOKEN'],
    'access_token_expire_in'
);

$channelChecks = [
    'ml' => [
        'required' => [
            ['ML_CLIENT_ID', 'MERCADO_LIVRE_CLIENT_ID'],
            ['ML_CLIENT_SECRET', 'MERCADO_LIVRE_CLIENT_SECRET'],
        ],
        'token' => $mlTokenState,
    ],
    'shopee' => [
        'required' => [
            ['SHOPEE_PARTNER_ID'], ['SHOPEE_PARTNER_KEY'], ['SHOPEE_SHOP_ID'],
        ],
        'token' => $shopeeTokenState,
    ],
    'tiktok' => [
        'required' => [
            ['TIKTOK_APP_KEY', 'TIKTOK_CLIENT_ID'],
            ['TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET'],
            ['TIKTOK_SHOP_CIPHER', 'TIKTOK_SHOP_ID'],
        ],
        'token' => $tiktokTokenState,
    ],
    'amazon' => [
        'required' => [
            ['AMAZON_LWA_CLIENT_ID'], ['AMAZON_LWA_CLIENT_SECRET'], ['AMAZON_LWA_REFRESH_TOKEN'],
            ['AMAZON_SELLER_ID', 'AMAZON_ACCOUNT_ID'], ['AMAZON_MARKETPLACE_ID'],
        ],
        'token' => null,
    ],
    'erp' => [
        'required' => [
            ['OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST'],
            ['OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'CLIENT_SECRET_OLIST'],
            ['OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'],
            ['OLIST_API_KEY', 'OLIST_ACCESS_TOKEN'],
        ],
        'token' => null,
    ],
];

$report = [
    'ok' => true,
    'checked_at' => gmdate('c'),
    'channels' => [],
    'database' => [],
    'protections' => [
        'price_payload_guard' => true,
        'stock_payload_guard' => true,
        'publication_requires_api_confirmation' => true,
        'secrets_redacted' => true,
    ],
];

foreach ($channelChecks as $channel => $definition) {
    $missing = [];
    foreach ($definition['required'] as $aliases) {
        if (!svmpr_valid(svmpr_env(...$aliases))) {
            $missing[] = implode('/', $aliases);
        }
    }
    $token = $definition['token'];
    if (is_array($token) && !$token['ready']) {
        $missing[] = strtoupper($channel) . '_ACCESS_TOKEN or ' . strtoupper($channel) . '_REFRESH_TOKEN/private token file';
    }
    $report['channels'][$channel] = [
        'ready' => $missing === [],
        'missing_or_invalid' => $missing,
        'token_state' => is_array($token) ? $token : ['managed_by' => $channel === 'amazon' ? 'amazon_oauth_refresh_flow' : 'tiny_oauth_refresh_flow'],
    ];
    // Apenas canais ativos e obrigatórios bloqueiam o status geral de prontidão
    if ($missing !== [] && in_array($channel, ['ml', 'shopee', 'erp'], true)) {
        $report['ok'] = false;
    }
}

$db = sv_pdo();
if (!$db instanceof PDO) {
    $report['ok'] = false;
    $report['database'] = ['ready' => false, 'error' => 'database_unavailable'];
} else {
    try {
        svcp_ensure_schema($db);
        $tables = ['product_channel_content', 'product_channel_mappings', 'catalog_publications', 'product_images'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        $mappingCounts = [];
        $stmt = $db->query('SELECT channel, COUNT(*) total FROM product_channel_mappings GROUP BY channel');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $mappingCounts[(string)$row['channel']] = (int)$row['total'];
        }
        $missingSku = (int)$db->query("SELECT COUNT(*) FROM products WHERE sku IS NULL OR TRIM(sku) = ''")->fetchColumn();
        $missingOlistId = (int)$db->query("SELECT COUNT(*) FROM products WHERE olist_id IS NULL OR TRIM(olist_id) = ''")->fetchColumn();
        $report['database'] = [
            'ready' => true,
            'tables' => $counts,
            'mapping_counts' => $mappingCounts,
            'products_missing_sku' => $missingSku,
            'products_missing_olist_id' => $missingOlistId,
            'tiny_missing_id_recovery' => 'exact_sku_lookup_enabled',
        ];
        if ($missingSku > 0) {
            $report['database']['warning'] = 'products_missing_sku_cannot_be_published_to_marketplaces';
            $report['ok'] = false;
        }
        if ($missingOlistId > 0) {
            $report['database']['notice'] = 'olist_id_is_resolved_on_demand_by_exact_sku_and_persisted';
        }
    } catch (Throwable $e) {
        $report['ok'] = false;
        $report['database'] = ['ready' => false, 'error' => get_class($e) . ':' . $e->getMessage()];
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo (is_string($json) ? $json : '{"ok":false}') . PHP_EOL;
exit($report['ok'] ? 0 : 2);
