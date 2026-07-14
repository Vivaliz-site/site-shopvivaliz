<?php
/**
 * Sincronização via API V2 (token FIXO que não expira)
 * Fallback quando OAuth V3 expirar
 */

declare(strict_types=1);

$root = dirname(__FILE__);  // Diretório atual (site-shopvivaliz/)

// Ler token de integrador (V2 - FIXO)
$token = '';
$env_file = $root . '/../.env';  // Sobe um nível se estiver em subdir, ou procura localmente
if (!is_file($env_file)) {
    $env_file = getcwd() . '/.env';
}

foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_INTEGRADOR_TOKEN=')) {
        $token = trim(explode('=', $line, 2)[1] ?? '');
        break;
    }
}

if (!$token) {
    error_log("[sync-v2] Token de integrador não encontrado");
    exit(1);
}

echo "[*] Sincronizando via API V2 (token fixo)...\n";

// API V2: /api/v2/produtos.json (não expira, não precisa refresh)
$all_products = [];
$page = 1;
$max_pages = 100;

while ($page <= $max_pages) {
    $url = "https://api.tiny.com.br/api/v2/produtos.json?token={$token}&pagina={$page}&limite=100";

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        echo "[!] Falha na página {$page}\n";
        break;
    }

    $data = json_decode($response, true);

    if ($data['status'] !== 'OK' || empty($data['produtos'])) {
        echo "[+] Total de produtos: " . count($all_products) . "\n";
        break;
    }

    foreach ($data['produtos'] as $item) {
        // V2 API structure - filtrar apenas ativos
        if ($item['situacao'] === 'A') {
            // Normalizar para V3 structure
            $normalized = [
                'id' => $item['id'],
                'sku' => $item['codigo'],
                'descricao' => $item['nome'],
                'situacao' => $item['situacao'],
                'precos' => [
                    'preco' => (float)str_replace(',', '.', $item['preco'] ?? 0)
                ],
                'estoque' => [
                    'quantidade' => (int)($item['estoque'] ?? 0)
                ],
                'estoque_disponivel' => (int)($item['estoque'] ?? 0)
            ];
            $all_products[] = $normalized;
        }
    }

    echo "[+] Página {$page}: " . count(array_filter($data['produtos'], fn($i) => $i['situacao'] === 'A')) . " ativos\n";

    if (count($data['produtos']) < 100) {
        break;
    }

    $page++;
    usleep(300000);
}

// Salvar cache
$output = [
    'total' => count($all_products),
    'timestamp' => date('Y-m-d H:i:s'),
    'itens' => $all_products
];

$output_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($output_file), 0755, true);

file_put_contents($output_file, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "[✓] {$output['total']} produtos salvos em {$output_file}\n";
?>
