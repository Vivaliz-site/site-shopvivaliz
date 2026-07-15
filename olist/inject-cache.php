<?php
/**
 * Inject Cache - Cria cache com os 198 produtos localmente
 * Usado para sincronizar dados entre máquinas
 */

header('Content-Type: application/json; charset=utf-8');

// Verificar se cache local existe
$local_cache = __DIR__ . '/../logs/olist-products-cache.json';

if (!file_exists($local_cache)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Cache local não encontrado']);
    exit;
}

// Ler cache local
$cache_data = json_decode(file_get_contents($local_cache), true);

if (empty($cache_data['produtos'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Cache local vazio']);
    exit;
}

// Criar cache no servidor remoto (se a requisição vier de POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Receber dados via POST
    $post_data = json_decode(file_get_contents('php://input'), true);

    if (!empty($post_data['produtos'])) {
        $cache_file = __DIR__ . '/../logs/olist-products-cache.json';
        @mkdir(dirname($cache_file), 0755, true);

        $cache = [
            'timestamp' => date('c'),
            'total' => count($post_data['produtos']),
            'com_imagem' => $post_data['com_imagem'] ?? count($post_data['produtos']),
            'produtos' => $post_data['produtos']
        ];

        file_put_contents($cache_file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        http_response_code(200);
        echo json_encode(['sucesso' => true, 'mensagem' => 'Cache injetado com sucesso', 'total' => count($post_data['produtos'])]);
        exit;
    }
}

// Se GET, retornar o comando curl para injetar
$curl_cmd = "curl -X POST https://dev.shopvivaliz.com.br/olist/inject-cache.php \\\n";
$curl_cmd .= "  -H 'Content-Type: application/json' \\\n";
$curl_cmd .= "  -d '@" . $local_cache . "'";

http_response_code(200);
echo json_encode([
    'sucesso' => false,
    'mensagem' => 'Para injetar cache, execute:',
    'comando' => $curl_cmd,
    'alternativa' => 'curl -X POST https://dev.shopvivaliz.com.br/olist/inject-cache.php -H "Content-Type: application/json" -d "' . json_encode($cache_data) . '"',
    'total_produtos_local' => count($cache_data['produtos'] ?? [])
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
