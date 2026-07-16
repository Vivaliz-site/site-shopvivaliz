<?php
/**
 * Sincronizar 198 produtos para o banco de dados local
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Carregar cache
$cache_file = __DIR__ . '/../logs/olist-products-cache.json';

if (!file_exists($cache_file)) {
    http_response_code(404);
    echo json_encode(['erro' => 'Cache não encontrado']);
    exit;
}

$cache_data = json_decode(file_get_contents($cache_file), true);
$produtos = $cache_data['produtos'] ?? [];

if (count($produtos) === 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Nenhum produto no cache']);
    exit;
}

// Conectar ao banco
try {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao conectar ao banco: ' . $e->getMessage()]);
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
    $url_imagem = $p['url_imagem'] ?? $p['primary_image_url'] ?? '';

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
        if ($stmt) {
            if ($stmt->execute([$id, $nome, $preco, $descricao, $categoria, $estoque, $url_imagem])) {
                $total_sync++;
            } else {
                $total_erro++;
            }
            $stmt->close();
        } else {
            $total_erro++;
        }
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
    'total' => count($produtos),
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
