<?php
/**
 * API REST - Produtos
 * GET api/produtos.php?limite=20&pagina=1
 */

header('Content-Type: application/json; charset=utf-8');

$limite = (int)($_GET['limite'] ?? 20);
$pagina = (int)($_GET['pagina'] ?? 1);
$categoria = $_GET['categoria'] ?? '';
$busca = $_GET['busca'] ?? '';

// Limitar
$limite = min($limite, 100);
$pagina = max($pagina, 1);

// Carregar cache
$cache_file = __DIR__ . '/../storage/cache/olist-products-cache.json';
$produtos = [];

if (file_exists($cache_file)) {
    $data = json_decode((string)file_get_contents($cache_file), true);
    if ($data && !empty($data['produtos'])) {
        $produtos = $data['produtos'];
    }
}

// Se vazio, retornar array vazio
if (empty($produtos)) {
    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total' => 0,
        'pagina' => $pagina,
        'limite' => $limite,
        'produtos' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Aplicar filtros
if ($categoria) {
    $produtos = array_filter($produtos, function($p) use ($categoria) {
        return ($p['categoria'] ?? '') === $categoria;
    });
}

if ($busca) {
    $produtos = array_filter($produtos, function($p) use ($busca) {
        return stripos($p['nome'] ?? '', $busca) !== false ||
               stripos($p['descricao'] ?? '', $busca) !== false;
    });
}

// Paginação
$total = count($produtos);
$offset = ($pagina - 1) * $limite;
$produtos_pagina = array_values(array_slice($produtos, $offset, $limite));

// Retornar
http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'total' => $total,
    'pagina' => $pagina,
    'limite' => $limite,
    'total_paginas' => ceil($total / $limite),
    'produtos' => $produtos_pagina
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
