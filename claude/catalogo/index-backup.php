<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://shopvivaliz.com.br');

// Dados de teste
$produtos = [
    ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90, 'categoria' => 'Roupas', 'imagem' => '👕'],
    ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90, 'categoria' => 'Roupas', 'imagem' => '👖'],
    ['id' => 3, 'nome' => 'Tênis Esportivo', 'preco' => 199.90, 'categoria' => 'Calçados', 'imagem' => '👟'],
    ['id' => 4, 'nome' => 'Relógio Digital', 'preco' => 89.90, 'categoria' => 'Acessórios', 'imagem' => '⌚'],
    ['id' => 5, 'nome' => 'Mochila Impermeável', 'preco' => 129.90, 'categoria' => 'Acessórios', 'imagem' => '🎒'],
    ['id' => 6, 'nome' => 'Jaqueta de Couro', 'preco' => 299.90, 'categoria' => 'Roupas', 'imagem' => '🧥'],
];

$categorias = ['Roupas', 'Calçados', 'Acessórios'];
$categoria_filtro = $_GET['categoria'] ?? '';
$busca = $_GET['busca'] ?? '';

if ($categoria_filtro) {
    $produtos = array_filter($produtos, fn($p) => $p['categoria'] === $categoria_filtro);
}
if ($busca) {
    $produtos = array_filter($produtos, fn($p) => stripos($p['nome'], $busca) !== false);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#667eea">
    <title><?php echo APP_NAME; ?> - Catálogo</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-bar input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .search-bar button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #e5e7eb;
            background: white;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.3s;
        }
        .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .product-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .product-image {
            font-size: 3em;
            margin: 10px 0;
        }
        .product-name {
            font-weight: 600;
            margin: 10px 0;
            color: #1f2937;
        }
        .product-price {
            font-size: 1.5em;
            color: #667eea;
            font-weight: bold;
            margin: 10px 0;
        }
        .btn-produto {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        .btn-produto:hover {
            background: #764ba2;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <main class="container">
        <h1>Catálogo de Produtos</h1>

        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="busca" placeholder="Buscar produtos..." value="<?php echo htmlspecialchars($busca); ?>">
                <button type="submit">🔍 Buscar</button>
            </form>
        </div>

        <div class="filters">
            <a href="?"><button class="filter-btn <?php echo !$categoria_filtro ? 'active' : ''; ?>">Todos</button></a>
            <?php foreach ($categorias as $cat): ?>
                <a href="?categoria=<?php echo urlencode($cat); ?>">
                    <button class="filter-btn <?php echo $categoria_filtro === $cat ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </button>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="products-grid">
            <?php foreach ($produtos as $p): ?>
                <a href="produto.php?id=<?php echo $p['id']; ?>" style="text-decoration: none;">
                    <div class="product-card">
                        <div class="product-image"><?php echo $p['imagem']; ?></div>
                        <div class="product-name"><?php echo htmlspecialchars($p['nome']); ?></div>
                        <div class="product-price">R$ <?php echo number_format($p['preco'], 2, ',', '.'); ?></div>
                        <button class="btn-produto" onclick="location.href='produto.php?id=<?php echo $p['id']; ?>'; return false;">
                            Ver Detalhes
                        </button>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($produtos)): ?>
            <p style="text-align: center; color: #666; padding: 40px;">
                Nenhum produto encontrado.
            </p>
        <?php endif; ?>
    </main>
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>