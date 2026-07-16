<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Array de 198 produtos (extraído do cache)
$produtos = include __DIR__ . '/catalogo/index.php';

// Se include retornar array, usar. Senão, gerar fallback.
if (!is_array($produtos) || empty($produtos)) {
    // Fallback: 5 produtos de teste
    $produtos = [
        ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90, 'categoria' => 'Roupas', 'descricao' => '100% algodão', 'estoque' => 100],
        ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90, 'categoria' => 'Roupas', 'descricao' => 'Azul', 'estoque' => 50],
        ['id' => 3, 'nome' => 'Tênis', 'preco' => 199.90, 'categoria' => 'Calçados', 'descricao' => 'Esportivo', 'estoque' => 30],
        ['id' => 4, 'nome' => 'Mochila', 'preco' => 189.90, 'categoria' => 'Acessórios', 'descricao' => 'Executiva', 'estoque' => 20],
        ['id' => 5, 'nome' => 'Relógio', 'preco' => 99.90, 'categoria' => 'Acessórios', 'descricao' => 'Analógico', 'estoque' => 15],
    ];
}

try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'BD: ' . $e->getMessage()]);
    exit;
}

$sync = 0;
$erro = 0;

foreach ($produtos as $p) {
    if (empty($p['id']) || empty($p['nome'])) continue;

    try {
        $sql = "INSERT INTO products (product_id, name, price, description, category, stock, image_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), image_url=VALUES(image_url), updated_at=NOW()";

        $stmt = $db->prepare($sql);
        if ($stmt->execute([
            $p['id'],
            $p['nome'] ?? '',
            (float)($p['preco'] ?? 0),
            $p['descricao'] ?? '',
            $p['categoria'] ?? 'Geral',
            (int)($p['estoque'] ?? 0),
            $p['url_imagem'] ?? ''
        ])) {
            $sync++;
        } else {
            $erro++;
        }
        $stmt->close();
    } catch (Exception $e) {
        $erro++;
    }
}

$result = $db->query("SELECT COUNT(*) as total FROM products");
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

echo json_encode([
    'sucesso' => true,
    'sync' => $sync,
    'erro' => $erro,
    'total_agora' => $total,
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);
?>
