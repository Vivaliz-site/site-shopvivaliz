<?php
/**
 * Sincronização Imediata: 198 Produtos do Catálogo → Banco de Dados
 * Endpoint na raiz para máxima velocidade de deploy
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Incluir catálogo para pegar os 198 produtos
ob_start();

// Limpuar GET para não paginar
$_GET = [];

include __DIR__ . '/catalogo/index.php';
ob_end_clean();

// Agora $produtos tem todos (não paginado)
if (empty($produtos)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Nenhum produto encontrado', 'debug' => 'Catálogo retornou vazio']);
    exit;
}

// Conectar ao banco
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Conexão BD falhou: ' . $e->getMessage()]);
    exit;
}

// Sincronizar
$sync = 0;
$erro = 0;
$skip = 0;

foreach ($produtos as $p) {
    $id = $p['id'] ?? null;
    $nome = $p['nome'] ?? '';
    $preco = (float)($p['preco'] ?? 0);
    $descricao = $p['descricao'] ?? '';
    $categoria = $p['categoria'] ?? 'Geral';
    $estoque = (int)($p['estoque'] ?? 0);
    $img = $p['url_imagem'] ?? '';

    if (!$id || !$nome) {
        $skip++;
        continue;
    }

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
        if ($stmt && $stmt->execute([$id, $nome, $preco, $descricao, $categoria, $estoque, $img])) {
            $sync++;
        } else {
            $erro++;
        }
        if ($stmt) $stmt->close();
    } catch (Exception $e) {
        $erro++;
    }
}

// Verificar resultado
$result = $db->query("SELECT COUNT(*) as total FROM products");
$row = $result->fetch_assoc();
$total_final = $row['total'] ?? 0;

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => "Sincronização concluída: $sync inseridos, $erro erros, $skip pulados",
    'resumo' => [
        'sincronizados' => $sync,
        'erros' => $erro,
        'pulados' => $skip,
        'total_processado' => count($produtos),
        'total_banco_agora' => $total_final,
        'esperado' => 198
    ],
    'status' => $total_final >= 198 ? 'SUCESSO' : 'PARCIAL',
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);
?>
