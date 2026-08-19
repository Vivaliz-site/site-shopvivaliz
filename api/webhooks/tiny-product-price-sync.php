<?php
/**
 * Webhook para sincronizar preços de produtos do Tiny em tempo real
 * POST /api/webhooks/tiny-product-price-sync.php
 *
 * Atualiza preços IMEDIATAMENTE no cache quando Tiny notifica mudanças
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$log_file = __DIR__ . '/../../logs/tiny-webhook-price.log';
$timestamp = date('Y-m-d H:i:s');

function log_event($message) {
    global $log_file, $timestamp;
    @file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Rodada 6 (2026-08-19): este endpoint aceitava POST anonimo e reescrevia
// preco.preco/preco/preco_venda/price de qualquer SKU nos dois caches
// autoritativos que alimentam a vitrine -- sem nenhuma verificacao de
// emissor. O webhook irmao de estoque (api/tiny/stock-webhook.php) ja usa
// este mesmo gate pra exatamente esse risco; faltava aplicar aqui. O gate
// faz rollout em duas etapas sozinho: sem TINY_WEBHOOK_SECRET/
// OLIST_WEBHOOK_SECRET configurado no ambiente, fica permissivo e so
// registra em log (nao quebra a integracao real hoje); assim que o Fred
// definir o segredo no .env da VM E atualizar a URL de notificacao de
// preco no painel Tiny, passa a exigir a chave automaticamente, sem
// precisar de outro deploy. Ver R6-1 no relatorio da Rodada 6.
require_once __DIR__ . '/../../includes/webhook-secret.php';
if (!sv_webhook_secret_gate('tiny-product-price')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

function update_cache_price($sku, $new_price) {
    $cache_files = [
        __DIR__ . '/../../storage/products-cache-ativos.json',
        __DIR__ . '/../../api/catalog/fallback-products.json',
    ];

    $updated_count = 0;

    foreach ($cache_files as $cache_file) {
        if (!is_file($cache_file)) {
            continue;
        }

        $content = @file_get_contents($cache_file);
        if (!$content) {
            continue;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            continue;
        }

        // Procurar pelo SKU nos produtos
        $items = $data['itens'] ?? $data['items'] ?? $data['produtos'] ?? $data['products'] ?? $data;
        if (!is_array($items)) {
            continue;
        }

        $found = false;
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $item_sku = $item['sku'] ?? $item['codigo'] ?? '';
            if ($item_sku === $sku) {
                $old_price = $item['precos']['preco'] ?? $item['preco'] ?? 0;

                // Rodada 6 (2026-08-19): trava de sanidade independente do
                // gate de autenticacao acima -- limita o estrago de qualquer
                // envenenamento futuro (inclusive um Tiny mal configurado):
                // recusa preco <= 0 e recusa variacao maior que 70% do preco
                // atual, so registrando em log em vez de gravar. Ver R6-1 no
                // relatorio da Rodada 6.
                $oldPriceFloat = (float)$old_price;
                $newPriceFloat = (float)$new_price;
                if ($newPriceFloat <= 0) {
                    log_event("Preço REJEITADO (<=0) para {$sku} em {$cache_file}: R$ {$new_price}");
                    continue;
                }
                if ($oldPriceFloat > 0 && abs($newPriceFloat - $oldPriceFloat) / $oldPriceFloat > 0.70) {
                    log_event("Preço REJEITADO (variação >70%) para {$sku} em {$cache_file}: R$ {$old_price} -> R$ {$new_price}");
                    continue;
                }

                // Atualizar preço
                if (!isset($item['precos']) || !is_array($item['precos'])) {
                    $item['precos'] = [];
                }

                $item['precos']['preco'] = (float)$new_price;
                $item['preco'] = (float)$new_price;
                $item['preco_venda'] = (float)$new_price;
                $item['price'] = (float)$new_price;

                log_event("Preço atualizado em {$cache_file}: {$sku} de R$ {$old_price} para R$ {$new_price}");

                $found = true;
                $updated_count++;
                break;
            }
        }

        if ($found) {
            // Salvar arquivo atualizado
            $json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
            if (json_encode($data, $json_flags) !== false) {
                @file_put_contents($cache_file, json_encode($data, $json_flags));
                log_event("Cache salvo: {$cache_file}");
            }
        }
    }

    return $updated_count > 0;
}

// Ler payload
$input = @file_get_contents('php://input');
log_event("=== WEBHOOK RECEBIDO ===");
log_event("Tamanho: " . strlen($input) . " bytes");

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload', 'timestamp' => $timestamp]);
    log_event("ERRO: Payload vazio");
    exit;
}

$data = json_decode($input, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON', 'timestamp' => $timestamp]);
    log_event("ERRO: JSON inválido");
    exit;
}

log_event("Evento: " . json_encode($data, JSON_UNESCAPED_UNICODE));

// Extrair informações do produto
$product = $data['produto'] ?? $data['product'] ?? $data;
$sku = $product['sku'] ?? $product['codigo'] ?? '';
$price = $product['precos']['preco'] ?? $product['preco'] ?? $product['price'] ?? null;

if (!$sku) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing SKU', 'timestamp' => $timestamp]);
    log_event("ERRO: SKU não encontrado");
    exit;
}

log_event("SKU: {$sku}");

// Se tem preço, atualizar no cache
if ($price !== null) {
    log_event("Novo preço: R$ {$price}");

    if (update_cache_price($sku, $price)) {
        http_response_code(200);
        echo json_encode([
            'status' => 'updated',
            'sku' => $sku,
            'price' => (float)$price,
            'timestamp' => $timestamp,
            'message' => 'Preço atualizado com sucesso no cache'
        ]);
        log_event("✓ SUCESSO: Preço atualizado no cache");
    } else {
        http_response_code(200);
        echo json_encode([
            'status' => 'received',
            'sku' => $sku,
            'price' => (float)$price,
            'timestamp' => $timestamp,
            'message' => 'Webhook recebido, mas SKU não encontrado no cache'
        ]);
        log_event("⚠ AVISO: SKU não encontrado nos caches");
    }
} else {
    // Não tem preço no webhook, apenas reconhecer
    http_response_code(200);
    echo json_encode([
        'status' => 'received',
        'sku' => $sku,
        'timestamp' => $timestamp,
        'message' => 'Webhook recebido, sem informação de preço'
    ]);
    log_event("ℹ INFO: Webhook recebido sem preço");
}

log_event("=== FIM DO WEBHOOK ===\n");
?>
