<?php
/**
 * Sincronizar produtos direto da Tiny/Olist ERP via file_get_contents
 * (sem dependência de curl)
 *
 * Execução: php olist/sync-direct-tiny.php
 */

declare(strict_types=1);

// Carregar .env
$env_file = dirname(__DIR__) . '/.env';
if (is_file($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim(trim($val), "\"'");
        if ($key && !getenv($key)) {
            putenv("$key=$val");
        }
    }
}

$token = getenv('OLIST_ACCESS_TOKEN') ?: getenv('TINY_ACCESS_TOKEN') ?: null;

if (!$token) {
    echo json_encode([
        'ok' => false,
        'error' => 'Token não configurado em .env',
        'fetched' => 0,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

echo "[SYNC] Token: " . substr($token, 0, 20) . "...\n\n";

$products = [];
$total_fetched = 0;
$errors = [];

// Buscar produtos do ERP paginado
for ($page = 1; $page <= 50; $page++) {
    echo "[PAGE] $page... ";

    $url = "https://api.tiny.com.br/api/v2/produtos?pagina=$page&limite=100";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        echo "ERRO (conexão)\n";
        $errors[] = "Falha ao conectar página $page";
        break;
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        echo "ERRO (JSON inválido)\n";
        $errors[] = "JSON inválido na página $page";
        break;
    }

    $items = $data['data']['itens'] ?? [];

    if (!is_array($items) || empty($items)) {
        echo "Fim\n";
        break;
    }

    echo count($items) . " produtos\n";
    $total_fetched += count($items);
    $products = array_merge($products, $items);

    // Delay para não sobrecarregar API
    usleep(500000);
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "RESULTADO DO SYNC\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "Total fetched: $total_fetched\n";
echo "Erros: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nErros:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

// Salvar em JSON
$output_file = dirname(__DIR__) . '/api/catalog/fallback-products.json';
$normalized = [];

foreach ($products as $item) {
    $normalized[] = [
        'id' => (string)($item['id'] ?? ''),
        'sku' => trim((string)($item['codigo'] ?? '')),
        'olist_product_id' => (string)($item['id'] ?? ''),
        'name' => trim((string)($item['nome'] ?? 'Produto')),
        'description' => trim((string)($item['descricao_complementar'] ?? '')),
        'price' => (float)($item['preco'] ?? 0),
        'stock' => (int)($item['estoque'] ?? 0),
        'image_url' => trim((string)($item['imagem_principal_url'] ?? '')),
        'category' => 'ERP-Tiny',
    ];
}

file_put_contents($output_file, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

echo "\n✓ Salvos em: $output_file\n";
echo "✓ Total: " . count($normalized) . " produtos\n\n";

// Retornar JSON
echo json_encode([
    'ok' => count($errors) === 0,
    'fetched' => $total_fetched,
    'saved' => count($normalized),
    'errors' => $errors,
    'timestamp' => date('Y-m-d H:i:s UTC'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
