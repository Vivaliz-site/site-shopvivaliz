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

header('Content-Type: application/json; charset=utf-8');

function svtw_log(string $message): void
{
    $logFile = dirname(__DIR__, 2) . '/logs/tiny-stock-webhook.log';
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
}

function svtw_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/webhook-secret.php';

// SEGURANCA: gravar saldo e uma operacao sensivel -- um POST anonimo podia
// zerar o estoque de qualquer SKU (derrubando as vendas) ou inflar o saldo
// (gerando venda sem lastro). Como a Tiny nao assina, exigimos segredo
// compartilhado na URL configurada no painel. Ver includes/webhook-secret.php
// para o rollout em duas etapas.
if (!sv_webhook_secret_gate('tiny-stock')) {
    svtw_log('Requisicao rejeitada: chave ausente ou invalida');
    svtw_json(401, ['ok' => false, 'error' => 'unauthorized']);
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

$stock = (int)round((float)$saldo);

// Limite de sanidade: saldo negativo nao existe e valores absurdos indicam
// payload corrompido ou forjado. Rejeitamos em vez de gravar.
if ($stock < 0 || $stock > 1000000) {
    svtw_log("Saldo fora do intervalo aceitavel: sku={$sku} saldo={$saldo}");
    svtw_json(200, ['ok' => true, 'ignored' => true, 'reason' => 'stock_out_of_range']);
}
$catalogPath = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';

$fp = fopen($catalogPath, 'c+');
if (!$fp) {
    svtw_log("Falha ao abrir {$catalogPath}");
    svtw_json(500, ['ok' => false, 'error' => 'catalog_unavailable']);
}

flock($fp, LOCK_EX);
$content = stream_get_contents($fp);
$catalog = json_decode($content !== false ? $content : '[]', true);
if (!is_array($catalog)) {
    $catalog = [];
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
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
flock($fp, LOCK_UN);
fclose($fp);

svtw_log(($updated ? 'Atualizado' : 'SKU nao encontrado no catalogo') . ": sku={$sku} stock={$stock}");

svtw_json(200, ['ok' => true, 'updated' => $updated, 'sku' => $sku, 'stock' => $stock]);
