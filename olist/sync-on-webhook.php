<?php
/**
 * Sincronização AO VIVO via Webhook
 * Chamado automaticamente quando ERP notifica mudanças
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$env_file = $root . '/.env';
$token = '';

// Carregar token
foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
        $token = explode('=', $line, 2)[1] ?? '';
        break;
    }
}

$token = trim($token);

if (!$token) {
    error_log("[webhook-sync] Token não encontrado");
    exit(1);
}

// ============================================================
// Buscar produtos ATIVOS via API V3
// ============================================================

$all_products = [];
$offset = 0;
$limit = 100;

while (true) {
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit={$limit}&offset={$offset}";

    // file_get_contents()/stream_context_create() era rejeitado com 401 pela
    // Cloudflare do Tiny mesmo com token e permissoes corretas (confirmado em
    // 2026-08-11: a mesma chamada via cURL funciona normalmente). Usar cURL
    // aqui evita esse bloqueio silencioso.
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
        error_log("[webhook-sync] Falha ao buscar produtos: HTTP {$httpStatus} {$curlError}");
        break;
    }

    $data = json_decode($response, true);

    if (!isset($data['itens']) || empty($data['itens'])) {
        break;
    }

    // Filtrar apenas ATIVOS (situacao == 'A'). NAO preencher estoque_disponivel
    // aqui: o campo estoque.quantidade da listagem em lote (GET /produtos) nao
    // e confiavel (fica zerado/desatualizado, especialmente em kits) -- a
    // fonte correta e GET /estoque/{id} (campo disponivel), buscada depois por
    // olist/fetch-estoque-v3.php, que só preenche quando a chave ainda não
    // existe (ver docs/TINY-ERP-API-V3.md, secao "GET /produtos/{id} vs GET
    // /estoque/{id}"). Se setarmos aqui, o enriquecimento nunca roda.
    foreach ($data['itens'] as $item) {
        if ($item['situacao'] === 'A') {
            $all_products[] = $item;
        }
    }

    if (count($data['itens']) < $limit) {
        break;
    }

    $offset += $limit;
    usleep(500000);
}

// ============================================================
// Salvar em JSON
// ============================================================

if ($all_products === []) {
    // Nao sobrescreve um cache bom anterior com uma lista vazia quando a
    // busca falhou de verdade (ex: token/rede) -- so grava se a API
    // realmente respondeu 0 produtos ativos (situacao improvavel, mas nao
    // impossivel, entao nao trata como erro fatal).
    error_log("[webhook-sync] Nenhum produto ativo retornado; cache anterior preservado");
    exit(1);
}

$output = [
    'total' => count($all_products),
    'timestamp' => date('Y-m-d H:i:s'),
    'itens' => $all_products
];

$output_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($output_file), 0755, true);

file_put_contents($output_file, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

error_log("[webhook-sync] Sincronizados " . count($all_products) . " produtos ativos");
