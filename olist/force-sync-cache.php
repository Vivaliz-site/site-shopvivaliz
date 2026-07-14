<?php
/**
 * Force sync cache to products endpoint
 * Chamável via HTTP GET ou CLI
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Ler token do .env
$token = '';
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
        $token = trim(explode('=', $line, 2)[1] ?? '');
        break;
    }
}

if (!$token) {
    exit("TOKEN NOT FOUND\n");
}

// Buscar todos os produtos ATIVOS
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
        echo "[!] Falha ao buscar offset {$offset}\n";
        break;
    }

    $data = json_decode($response, true);

    if (!isset($data['itens']) || empty($data['itens'])) {
        break;
    }

    foreach ($data['itens'] as $item) {
        if ($item['situacao'] === 'A') {
            if (isset($item['estoque']['quantidade'])) {
                $item['estoque_disponivel'] = $item['estoque']['quantidade'];
            } else {
                $item['estoque_disponivel'] = 0;
            }
            $all_products[] = $item;
        }
    }

    echo "[+] Offset {$offset}: " . count(array_filter($data['itens'], fn($i) => $i['situacao'] === 'A')) . " ativos\n";

    if (count($data['itens']) < $limit) {
        break;
    }

    $offset += $limit;
    usleep(500000);
}

// Salvar em JSON
$output = [
    'total' => count($all_products),
    'timestamp' => date('Y-m-d H:i:s'),
    'itens' => $all_products
];

$output_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($output_file), 0755, true);

file_put_contents($output_file, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n[✓] Sincronizados " . count($all_products) . " produtos ativos\n";
echo "[✓] Cache salvo em: {$output_file}\n";

// Verificar se arquivo foi criado
if (is_file($output_file)) {
    echo "[✓] Arquivo existe no servidor!\n";
    echo "[✓] Tamanho: " . filesize($output_file) . " bytes\n";
} else {
    echo "[!] ERRO: Arquivo NÃO foi criado!\n";
}
?>
