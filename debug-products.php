<?php
/**
 * Debug: Por que não retorna produtos?
 */

$root = dirname(__FILE__);

echo "DEBUG PRODUTOS\n";
echo "==============\n\n";

// 1. Verificar token
echo "[*] Verificando token...\n";
$token = '';
$env_file = $root . '/.env';

foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
        $token = explode('=', $line, 2)[1] ?? '';
        break;
    }
}

$token = trim($token);

if ($token) {
    echo "[+] Token encontrado: " . substr($token, 0, 50) . "...\n";
} else {
    echo "[!] Token NÃO encontrado!\n";
    exit(1);
}

// 2. Testar API diretamente
echo "\n[*] Testando API V3...\n";

$url = "https://api.tiny.com.br/public-api/v3/produtos?limit=10&offset=0";

$context = stream_context_create([
    'https' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
        'timeout' => 30,
    ]
]);

$response = @file_get_contents($url, false, $context);

if (!$response) {
    echo "[!] Nenhuma resposta da API!\n";
    exit(1);
}

echo "[+] Resposta recebida!\n";

$data = json_decode($response, true);

echo "\nResposta JSON:\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (isset($data['itens'])) {
    echo "\n[+] Encontrou " . count($data['itens']) . " produtos\n";
    foreach (array_slice($data['itens'], 0, 3) as $item) {
        echo "    - " . $item['descricao'] . " (Preco: " . $item['precos']['preco'] . ")\n";
    }
} else {
    echo "\n[!] Chave 'itens' não encontrada!\n";
    echo "Chaves disponíveis: " . implode(', ', array_keys($data)) . "\n";
}
?>
