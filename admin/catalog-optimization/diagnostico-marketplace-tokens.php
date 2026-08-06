<?php

declare(strict_types=1);

/**
 * Diagnóstico read-only (uso único, admin-guarded): confirma, canal por
 * canal, se existe de verdade credencial/token configurado para PUSH de
 * atualização de produto (não apenas leitura) — NUNCA imprime valor real,
 * só booleans de presença e, quando aplicável, se o arquivo de token local
 * existe.
 *
 * Motivo de existir: Fred pediu para conectar "Aprovar" da Otimização de
 * Cadastro às APIs reais de update de cada marketplace. Antes de escrever
 * qualquer código de integração, preciso confirmar objetivamente quais
 * canais já têm credencial de escrita configurada agora — em vez de
 * assumir a partir do painel Conexões (que, por inspeção do código-fonte de
 * includes/integration-health.php, só verifica Olist/Tiny, Mercado Livre,
 * Mercado Pago, Melhor Envio, Facebook CAPI, Google Ads, TikTok Pixel e
 * SMTP — não inclui Shopee, Amazon nem TikTok Shop).
 *
 * Uso: abrir uma vez, logado como admin:
 *   https://shopvivaliz.com.br/admin/catalog-optimization/diagnostico-marketplace-tokens.php
 */

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/tiny-order-push.php';

header('Content-Type: application/json; charset=utf-8');

function dmt_present(string $key): bool
{
    $v = getenv($key);
    return $v !== false && trim((string) $v) !== '';
}

function dmt_file_exists_bool(string $path): bool
{
    return is_file($path);
}

$root = dirname(__DIR__, 2);

$report = [
    'ml' => [
        'nome' => 'Mercado Livre',
        'client_id_present' => dmt_present('ML_CLIENT_ID'),
        'client_secret_present' => dmt_present('ML_CLIENT_SECRET'),
        'access_token_env_present' => dmt_present('ML_ACCESS_TOKEN'),
        'refresh_token_env_present' => dmt_present('ML_REFRESH_TOKEN'),
        'token_file_exists' => dmt_file_exists_bool($root . '/storage/private/ml-tokens.json')
            || dmt_file_exists_bool($root . '/data/tokens.json'),
        'item_map_file_exists' => dmt_file_exists_bool($root . '/storage/private/ml-item-map.json'),
        'cliente_real_no_repo' => 'api/ml/client.php + api/ml/products.php (PUT /items/{id} já implementado, falta description sub-resource)',
    ],
    'shopee' => [
        'nome' => 'Shopee',
        'partner_id_present' => dmt_present('SHOPEE_PARTNER_ID'),
        'partner_key_present' => dmt_present('SHOPEE_PARTNER_KEY'),
        'shop_id_present' => dmt_present('SHOPEE_SHOP_ID'),
        'access_token_present' => dmt_present('SHOPEE_ACCESS_TOKEN'),
        'refresh_token_present' => dmt_present('SHOPEE_REFRESH_TOKEN'),
        'cliente_real_no_repo' => 'utils/shopee_client.py update_product() -> POST /api/v2/product/update_item (Python; sem porta PHP ainda)',
        'gap_conhecido' => 'Nenhum mapeamento product_id -> shopee item_id persistido no banco (ML tem ml-item-map.json, Shopee não tem equivalente).',
    ],
    'tiktok' => [
        'nome' => 'TikTok Shop',
        'app_key_present' => dmt_present('TIKTOK_APP_KEY'),
        'app_secret_present' => dmt_present('TIKTOK_APP_SECRET'),
        'access_token_present' => dmt_present('TIKTOK_ACCESS_TOKEN'),
        'shop_cipher_present' => dmt_present('TIKTOK_SHOP_CIPHER'),
        'shop_id_present' => dmt_present('TIKTOK_SHOP_ID'),
        'cliente_real_no_repo' => 'scripts/utils/tiktok_client.py update_product() -> PUT /api/products (Python; sem porta PHP ainda)',
        'gap_conhecido' => 'Nenhum mapeamento product_id -> tiktok product_id persistido no banco. Painel Conexões só verifica TIKTOK_PIXEL_TOKEN (analytics), não é o mesmo escopo de Shop/produto.',
    ],
    'amazon' => [
        'nome' => 'Amazon SP-API',
        'lwa_client_id_present' => dmt_present('AMAZON_LWA_CLIENT_ID'),
        'lwa_client_secret_present' => dmt_present('AMAZON_LWA_CLIENT_SECRET'),
        'lwa_refresh_token_present' => dmt_present('AMAZON_LWA_REFRESH_TOKEN'),
        'aws_access_key_present' => dmt_present('AMAZON_AWS_ACCESS_KEY_ID'),
        'aws_secret_key_present' => dmt_present('AMAZON_AWS_SECRET_ACCESS_KEY'),
        'cliente_real_no_repo' => 'NENHUM — nem leitura nem escrita. Só nomes de secret documentados em docs/knowledge/secrets-and-integrations-map.md, sem nenhuma implementação de cliente/chamada real em nenhuma linguagem do repositório.',
    ],
    'erp_tiny' => [
        'nome' => 'ERP / Tiny',
        'credenciais_configuradas' => svtop_tiny_credentials_configured(),
        'cliente_real_no_repo' => 'includes/tiny-product-push.php svtpp_push_product_update() -> PUT /produtos/{id} já implementado e funcional.',
    ],
];

echo json_encode(['ok' => true, 'canais' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
