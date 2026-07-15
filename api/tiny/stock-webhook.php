<?php
declare(strict_types=1);

/**
 * Webhook de atualizacao de estoque da Tiny/Olist (API 2.0).
 * Configurar em: painel Tiny -> Configuracoes -> E-commerce ->
 * Integracoes -> [sua integracao] -> Webhook -> URL de notificacoes
 * do estoque, apontando para esta URL.
 *
 * A Tiny nao assina/autentica essas requisicoes (confirmado na doc
 * oficial), entao validamos apenas a forma do payload. Retorna 200
 * sempre que processar com sucesso -- a Tiny reenvia ate 15x com
 * backoff se nao receber 200.
 *
 * https://tiny.com.br/api-docs/api2-webhooks-atualizacao-estoque
 */

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svtw_log(string $message): void
{
    $logFile = dirname(__DIR__, 2) . '/logs/tiny-stock-webhook.log';
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0755, true);
    }
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function svtw_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);

if (!is_array($body) || ($body['tipo'] ?? '') !== 'estoque') {
    svtw_log('Payload ignorado (tipo != estoque): ' . substr($raw, 0, 300));
    svtw_json(200, ['ok' => true, 'ignored' => true]);
}

$dados = is_array($body['dados'] ?? null) ? $body['dados'] : [];
$sku = trim((string)($dados['skuMapeamento'] ?? $dados['sku'] ?? ''));
$saldo = $dados['saldo'] ?? null;

if ($sku === '' || $saldo === null) {
    svtw_log('Payload sem sku/saldo: ' . substr($raw, 0, 300));
    svtw_json(200, ['ok' => true, 'ignored' => true, 'reason' => 'missing_sku_or_saldo']);
}

if (!is_numeric((string)$saldo)) {
    svtw_log('Payload com saldo invalido: ' . substr($raw, 0, 300));
    svtw_json(200, ['ok' => true, 'ignored' => true, 'reason' => 'invalid_saldo']);
}

$stock = (int)round((float)$saldo);
$catalogPath = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';

$fp = fopen($catalogPath, 'c+');
if (!$fp) {
    svtw_log("Falha ao abrir {$catalogPath}");
    svtw_json(500, ['ok' => false, 'error' => 'catalog_unavailable']);
}

try {
    if (!flock($fp, LOCK_EX)) {
        svtw_log("Falha ao bloquear {$catalogPath}");
        svtw_json(500, ['ok' => false, 'error' => 'catalog_lock_failed']);
    }

    rewind($fp);
    $content = stream_get_contents($fp);
    $catalog = json_decode($content !== false ? $content : '[]', true);
    if (!is_array($catalog)) {
        svtw_log("Catalogo invalido em {$catalogPath}");
        svtw_json(500, ['ok' => false, 'error' => 'catalog_invalid']);
    }

    $updated = false;
    foreach ($catalog as &$product) {
        if (!is_array($product)) {
            continue;
        }
        if (strcasecmp((string)($product['sku'] ?? ''), $sku) === 0) {
            $product['stock'] = $stock;
            $updated = true;
            break;
        }
    }
    unset($product);

    if ($updated) {
        $payload = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false) {
            svtw_log("Falha ao serializar catalogo para sku={$sku}");
            svtw_json(500, ['ok' => false, 'error' => 'catalog_encode_failed']);
        }
        ftruncate($fp, 0);
        rewind($fp);
        if (fwrite($fp, $payload . PHP_EOL) === false) {
            svtw_log("Falha ao gravar catalogo para sku={$sku}");
            svtw_json(500, ['ok' => false, 'error' => 'catalog_write_failed']);
        }
        fflush($fp);
    }
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

svtw_log(($updated ? 'Atualizado' : 'SKU nao encontrado no catalogo') . ": sku={$sku} stock={$stock}");

svtw_json(200, ['ok' => true, 'updated' => $updated, 'sku' => $sku, 'stock' => $stock]);
