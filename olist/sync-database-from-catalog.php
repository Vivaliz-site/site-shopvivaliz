<?php
/**
 * Sincronizar 198 produtos do catálogo para o banco de dados
 * Funciona mesmo se cache não existir (usa include do catálogo)
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Incluir catálogo para acessar $produtos
ob_start();
include __DIR__ . '/../catalogo/index.php';
ob_end_clean();

// Agora temos $produtos_pagina (atual) e $total_produtos (total)
// Mas precisamos de TODOS os produtos, não paginado

// Incluir o arquivo do catálogo novamente para pegar todos sem paginação
$_GET = [];  // Limpar GET para evitar paginação
ob_start();
include __DIR__ . '/../catalogo/index.php';
ob_end_clean();

// Depois do include, $produtos tem todos os produtos
if (empty($produtos)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Nenhum produto encontrado no catálogo']);
    exit;
}

// Conectar ao banco
try {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao conectar: ' . $e->getMessage()]);
    exit;
}

// Sincronizar produtos
$total_sync = 0;
$total_erro = 0;

foreach ($produtos as $p) {
    $id = $p['id'] ?? null;
    $nome = $p['nome'] ?? '';
    $preco = $p['preco'] ?? 0;
    $descricao = $p['descricao'] ?? '';
    $categoria = $p['categoria'] ?? '';
    $estoque = $p['estoque'] ?? 0;
    $url_imagem = $p['url_imagem'] ?? '';

    if (!$id || !$nome) {
        $total_erro++;
        continue;
    }

    // Inserir ou atualizar
    $sql = "INSERT INTO products (product_id, name, price, description, category, stock, image_url, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            price = VALUES(price),
            description = VALUES(description),
            category = VALUES(category),
            stock = VALUES(stock),
            image_url = VALUES(image_url),
            updated_at = NOW()";

    try {
        $stmt = $db->prepare($sql);
        if ($stmt && $stmt->execute([$id, $nome, $preco, $descricao, $categoria, $estoque, $url_imagem])) {
            $total_sync++;
        } else {
            $total_erro++;
        }
        if ($stmt) $stmt->close();
    } catch (Exception $e) {
        $total_erro++;
    }
}

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Sincronização concluída',
    'sincronizados' => $total_sync,
    'erros' => $total_erro,
    'total_produtos' => count($produtos),
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);
?>
