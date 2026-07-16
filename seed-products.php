<?php
/**
 * Seed - Adicionar produtos de teste ao catálogo
 * Execute uma vez: php seed-products.php
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Produtos de exemplo
    $products = [
        [
            'name' => 'Camiseta Branca Premium',
            'description' => 'Camiseta 100% algodão, confortável e durável',
            'price' => 49.90,
            'cost' => 15.00,
            'stock' => 150,
            'category' => 'Camisetas',
            'sku' => 'TSHIRT-WHITE-001',
            'image_url' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?w=400',
        ],
        [
            'name' => 'Calça Jeans Azul',
            'description' => 'Calça jeans clássica, modelo skinny fit',
            'price' => 129.90,
            'cost' => 40.00,
            'stock' => 80,
            'category' => 'Calças',
            'sku' => 'JEANS-BLUE-001',
            'image_url' => 'https://images.unsplash.com/photo-1542272604-787c62d465d1?w=400',
        ],
        [
            'name' => 'Tênis Esportivo',
            'description' => 'Tênis confortável para atividades físicas',
            'price' => 199.90,
            'cost' => 70.00,
            'stock' => 60,
            'category' => 'Calçados',
            'sku' => 'SNEAKER-001',
            'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400',
        ],
        [
            'name' => 'Jaqueta de Inverno',
            'description' => 'Jaqueta quente e impermeável',
            'price' => 299.90,
            'cost' => 120.00,
            'stock' => 45,
            'category' => 'Jaquetas',
            'sku' => 'JACKET-WINTER-001',
            'image_url' => 'https://images.unsplash.com/photo-1539533057440-7cf90b2bbb28?w=400',
        ],
        [
            'name' => 'Boné Ajustável',
            'description' => 'Boné esportivo com ajuste traseiro',
            'price' => 59.90,
            'cost' => 15.00,
            'stock' => 200,
            'category' => 'Acessórios',
            'sku' => 'CAP-001',
            'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=400',
        ],
        [
            'name' => 'Mochila Casual',
            'description' => 'Mochila resistente para dia a dia',
            'price' => 159.90,
            'cost' => 50.00,
            'stock' => 100,
            'category' => 'Acessórios',
            'sku' => 'BACKPACK-001',
            'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=400',
        ],
        [
            'name' => 'Shorts Praia',
            'description' => 'Shorts confortável para praia e piscina',
            'price' => 89.90,
            'cost' => 25.00,
            'stock' => 120,
            'category' => 'Bermudas',
            'sku' => 'SHORTS-BEACH-001',
            'image_url' => 'https://images.unsplash.com/photo-1506629082632-1a6c62f4b95c?w=400',
        ],
        [
            'name' => 'Suéter Gola Alta',
            'description' => 'Suéter morno e acolhedor',
            'price' => 139.90,
            'cost' => 45.00,
            'stock' => 70,
            'category' => 'Suéteres',
            'sku' => 'SWEATER-001',
            'image_url' => 'https://images.unsplash.com/photo-1529720317453-c1a59ee5dd11?w=400',
        ],
    ];

    $count = 0;
    $stmt = $db->prepare('
        INSERT INTO products (external_id, name, description, price, cost, stock, category, sku, image_url, source, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar statement: ' . $db->error);
    }

    foreach ($products as $product) {
        $external_id = uniqid('SEED-');
        $source = 'manual-seed';

        $stmt->bind_param(
            'sssddisss',
            $external_id,
            $product['name'],
            $product['description'],
            $product['price'],
            $product['cost'],
            $product['stock'],
            $product['category'],
            $product['sku'],
            $product['image_url'],
            $source
        );

        if ($stmt->execute()) {
            $count++;
            echo "✅ Produto adicionado: {$product['name']}\n";
        } else {
            echo "❌ Erro ao adicionar: {$product['name']} - {$stmt->error}\n";
        }
    }

    $stmt->close();

    echo "\n=================================\n";
    echo "✅ Total de produtos adicionados: $count\n";
    echo "=================================\n";
    echo "\nOs produtos aparecem em: /catalogo.php\n";

} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
