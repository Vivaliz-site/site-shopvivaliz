<?php
/**
 * OAuth Callback Simples
 * Apenas recebe código e salva em arquivo
 * URL: https://dev.shopvivaliz.com.br/olist/oauth-callback-simple.php
 */

header('Content-Type: application/json; charset=utf-8');

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    http_response_code(400);
    echo json_encode(['erro' => $error, 'descricao' => $_GET['error_description'] ?? '']);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode(['erro' => 'Código não recebido']);
    exit;
}

// Salvar código em arquivo
$code_file = __DIR__ . '/../.tokens/olist-oauth-code.txt';
@mkdir(dirname($code_file), 0777, true);
file_put_contents($code_file, $code);

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Código recebido e salvo. Execute sync-agora.php agora!',
    'codigo_length' => strlen($code)
]);
?>
