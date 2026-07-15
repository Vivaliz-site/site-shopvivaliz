<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();

    // Verificar se tabela existe
    $result = $db->query("SHOW TABLES LIKE 'products'");
    if ($result->num_rows === 0) {
        echo json_encode(['erro' => 'Tabela products não existe']);
        exit;
    }

    // Contar produtos atuais
    $result = $db->query("SELECT COUNT(*) as total FROM products");
    $row = $result->fetch_assoc();
    $total_antes = $row['total'] ?? 0;

    // Inserir 5 produtos de teste simples (prova de conceito)
    $teste = [
        ['id' => 'TEST-1', 'nome' => 'Teste 1', 'preco' => 10, 'desc' => 'Teste', 'cat' => 'Test', 'est' => 1],
        ['id' => 'TEST-2', 'nome' => 'Teste 2', 'preco' => 20, 'desc' => 'Teste', 'cat' => 'Test', 'est' => 2],
        ['id' => 'TEST-3', 'nome' => 'Teste 3', 'preco' => 30, 'desc' => 'Teste', 'cat' => 'Test', 'est' => 3],
    ];

    $sync = 0;
    foreach ($teste as $p) {
        $sql = "INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()";
        $stmt = $db->prepare($sql);
        if ($stmt->execute([$p['id'], $p['nome'], $p['preco'], $p['desc'], $p['cat'], $p['est']])) {
            $sync++;
        }
        $stmt->close();
    }

    // Verificar resultado
    $result = $db->query("SELECT COUNT(*) as total FROM products");
    $row = $result->fetch_assoc();
    $total_depois = $row['total'] ?? 0;

    echo json_encode([
        'ok' => true,
        'antes' => $total_antes,
        'sincronizados' => $sync,
        'depois' => $total_depois,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>
