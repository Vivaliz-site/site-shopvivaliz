<?php
header('Content-Type: application/json; charset=utf-8');

// Incluir arquivo com 198 produtos
$arquivo = __DIR__ . '/../olist/produtos-198.php';

if (!file_exists($arquivo)) {
    http_response_code(404);
    echo json_encode(['erro' => 'Arquivo não encontrado']);
    exit;
}

include $arquivo;

if (empty($GLOBALS['produtos_olist'])) {
    http_response_code(500);
    echo json_encode(['erro' => 'Produtos não carregados']);
    exit;
}

$produtos = $GLOBALS['produtos_olist'];

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'total' => count($produtos),
    'produtos' => $produtos
], JSON_UNESCAPED_UNICODE);
?>
