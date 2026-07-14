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

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        error_log("[webhook-sync] Falha ao buscar produtos");
        break;
    }

    $data = json_decode($response, true);

    if (!isset($data['itens']) || empty($data['itens'])) {
        break;
    }

    // Filtrar apenas ATIVOS (situacao == 'A')
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

$output = [
    'total' => count($all_products),
    'timestamp' => date('Y-m-d H:i:s'),
    'itens' => $all_products
];

$output_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($output_file), 0755, true);

file_put_contents($output_file, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

error_log("[webhook-sync] Sincronizados " . count($all_products) . " produtos ativos");
?>
