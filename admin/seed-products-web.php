<?php
/**
 * Seed - Adicionar produtos via web
 * Acesse: /admin/seed-products-web.php
 * Cuidado: Qualquer um pode acessar em desenvolvimento
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

// Admin guard (opcional - remove em dev se quiser)
// require_once __DIR__ . '/../includes/admin-guard.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seed - Adicionar Produtos</title>
    <style>
        body { font-family: system-ui; max-width: 1200px; margin: 50px auto; padding: 20px; }
        .container { background: #f5f5f5; padding: 30px; border-radius: 8px; }
        h1 { color: #333; }
        button { background: #4CAF50; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #45a049; }
        .loading { display: none; color: #007bff; }
        .success { color: #28a745; padding: 10px; background: #e8f5e9; border-radius: 4px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #ffebee; border-radius: 4px; margin: 10px 0; }
        .product-item { background: white; padding: 10px; margin: 5px 0; border-left: 4px solid #4CAF50; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌾 Seed - Adicionar Produtos ao Catálogo</h1>

        <button onclick="seedProducts()">➕ Adicionar 8 Produtos de Teste</button>

        <div class="loading" id="loading">
            ⏳ Adicionando produtos... Aguarde...
        </div>

        <div id="result"></div>
    </div>

    <script>
        async function seedProducts() {
            const btn = event.target;
            const resultDiv = document.getElementById('result');
            const loading = document.getElementById('loading');

            btn.disabled = true;
            loading.style.display = 'block';
            resultDiv.innerHTML = '';

            try {
                const response = await fetch('<?= $_SERVER['REQUEST_URI'] ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'seed' })
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ <strong>${data.count} produtos adicionados com sucesso!</strong><br>
                            Acesse: <a href="/catalogo.php" target="_blank">/catalogo.php</a>
                        </div>
                    `;

                    if (data.products) {
                        data.products.forEach(p => {
                            resultDiv.innerHTML += `<div class="product-item">✓ ${p}</div>`;
                        });
                    }
                } else {
                    resultDiv.innerHTML = `<div class="error">❌ Erro: ${data.error}</div>`;
                }
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro na requisição: ${err.message}</div>`;
            } finally {
                btn.disabled = false;
                loading.style.display = 'none';
            }
        }
    </script>
</body>
</html>

<?php
// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) || (isset($_POST) && json_decode(file_get_contents('php://input'), true))) {
    header('Content-Type: application/json');

    try {
        $db = Database::getInstance()->getConnection();

        $products = [
            ['name' => 'Camiseta Branca Premium', 'desc' => 'Camiseta 100% algodão, confortável', 'price' => 49.90, 'cost' => 15.00, 'stock' => 150, 'cat' => 'Camisetas', 'sku' => 'TSHIRT-WHITE-001'],
            ['name' => 'Calça Jeans Azul', 'desc' => 'Calça jeans clássica, modelo skinny fit', 'price' => 129.90, 'cost' => 40.00, 'stock' => 80, 'cat' => 'Calças', 'sku' => 'JEANS-BLUE-001'],
            ['name' => 'Tênis Esportivo', 'desc' => 'Tênis confortável para atividades físicas', 'price' => 199.90, 'cost' => 70.00, 'stock' => 60, 'cat' => 'Calçados', 'sku' => 'SNEAKER-001'],
            ['name' => 'Jaqueta de Inverno', 'desc' => 'Jaqueta quente e impermeável', 'price' => 299.90, 'cost' => 120.00, 'stock' => 45, 'cat' => 'Jaquetas', 'sku' => 'JACKET-WINTER-001'],
            ['name' => 'Boné Ajustável', 'desc' => 'Boné esportivo com ajuste traseiro', 'price' => 59.90, 'cost' => 15.00, 'stock' => 200, 'cat' => 'Acessórios', 'sku' => 'CAP-001'],
            ['name' => 'Mochila Casual', 'desc' => 'Mochila resistente para dia a dia', 'price' => 159.90, 'cost' => 50.00, 'stock' => 100, 'cat' => 'Acessórios', 'sku' => 'BACKPACK-001'],
            ['name' => 'Shorts Praia', 'desc' => 'Shorts confortável para praia', 'price' => 89.90, 'cost' => 25.00, 'stock' => 120, 'cat' => 'Bermudas', 'sku' => 'SHORTS-BEACH-001'],
            ['name' => 'Suéter Gola Alta', 'desc' => 'Suéter morno e acolhedor', 'price' => 139.90, 'cost' => 45.00, 'stock' => 70, 'cat' => 'Suéteres', 'sku' => 'SWEATER-001'],
        ];

        $stmt = $db->prepare('INSERT INTO products (external_id, name, description, price, cost, stock, category, sku, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');

        if (!$stmt) {
            throw new Exception('Erro ao preparar: ' . $db->error);
        }

        $count = 0;
        $added = [];

        foreach ($products as $p) {
            $ext = uniqid('SEED-');
            $source = 'seed-web';

            $stmt->bind_param('sssddisss', $ext, $p['name'], $p['desc'], $p['price'], $p['cost'], $p['stock'], $p['cat'], $p['sku'], $source);

            if ($stmt->execute()) {
                $count++;
                $added[] = $p['name'];
            }
        }

        $stmt->close();

        echo json_encode(['success' => true, 'count' => $count, 'products' => $added]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
