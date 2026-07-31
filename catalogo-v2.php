<?php
/**
 * Catálogo v2 - Versão Melhorada com 188 Produtos
 * Usa cache fallback quando BD não está disponível
 */

session_start();

require_once __DIR__ . '/includes/products-cache.php';

$page = (int)($_GET['page'] ?? 1);
$page = max(1, $page);
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Filtros
$filtros = [
    'categoria' => $_GET['categoria'] ?? null,
    'priceMin' => $_GET['priceMin'] ?? null,
    'priceMax' => $_GET['priceMax'] ?? null,
    'search' => $_GET['search'] ?? null,
];

// Obter produtos
$todos_produtos = obter_produtos(999);
$produtos_filtrados = filtrar_produtos($todos_produtos, $filtros);
$total_produtos = count($produtos_filtrados);
$total_pages = ceil($total_produtos / $perPage);
$produtos = array_slice($produtos_filtrados, $offset, $perPage);

$categorias = obter_categorias();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo - ShopVivaliz | <?= $total_produtos ?> Produtos</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: white;
            padding: 20px;
            border-bottom: 1px solid #eee;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .content {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            margin-bottom: 50px;
        }
        .sidebar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        .sidebar h3 {
            font-size: 16px;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .filter-group {
            margin-bottom: 25px;
        }
        .filter-group label {
            display: block;
            margin: 8px 0;
            font-size: 13px;
            cursor: pointer;
        }
        .filter-group input[type="checkbox"],
        .filter-group input[type="number"] {
            margin-right: 8px;
        }
        .filter-group input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 5px;
        }
        .products {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .product-image {
            width: 100%;
            height: 200px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-info {
            padding: 15px;
        }
        .product-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            color: #333;
            min-height: 30px;
        }
        .product-price {
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 8px;
        }
        .product-stock {
            font-size: 12px;
            color: #999;
            margin-bottom: 10px;
        }
        .btn-comprar {
            width: 100%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn-comprar:hover {
            background: #218838;
        }
        .pagination {
            text-align: center;
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid #eee;
        }
        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 4px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #667eea;
        }
        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination a:hover {
            background: #f0f0f0;
        }
        .stats {
            text-align: center;
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .search-bar {
            margin-bottom: 30px;
        }
        .search-bar input {
            width: 100%;
            max-width: 400px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        @media (max-width: 768px) {
            .content {
                grid-template-columns: 1fr;
            }
            .sidebar {
                position: static;
            }
            .products {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🛍️ Catálogo de Produtos</h1>
        <p><?= $total_produtos ?> produtos disponíveis | Página <?= $page ?> de <?= $total_pages ?></p>
    </div>

    <div class="container">
        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px;">
                <input type="text" name="search" placeholder="Buscar produtos..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                <button type="submit" style="padding: 12px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer;">Buscar</button>
            </form>
        </div>

        <div class="content">
            <!-- Sidebar Filtros -->
            <aside class="sidebar">
                <h3>Filtros</h3>

                <div class="filter-group">
                    <h4 style="font-size: 13px; margin-bottom: 10px;">Categoria</h4>
                    <?php foreach ($categorias as $cat): ?>
                    <label>
                        <input type="checkbox" name="categoria" value="<?= htmlspecialchars($cat) ?>"
                            <?= ($filtros['categoria'] === $cat) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="filter-group">
                    <h4 style="font-size: 13px; margin-bottom: 10px;">Preço</h4>
                    <input type="number" name="priceMin" placeholder="Mín" min="0" step="0.01" value="<?= htmlspecialchars($filtros['priceMin'] ?? '') ?>">
                    <input type="number" name="priceMax" placeholder="Máx" min="0" step="0.01" value="<?= htmlspecialchars($filtros['priceMax'] ?? '') ?>">
                    <button type="submit" class="btn-comprar" style="margin-top: 10px;">Filtrar</button>
                </div>
            </aside>

            <!-- Produtos -->
            <main>
                <?php if (empty($produtos)): ?>
                <div style="text-align: center; padding: 50px 20px;">
                    <p style="font-size: 18px; color: #666;">Nenhum produto encontrado com os filtros selecionados.</p>
                </div>
                <?php else: ?>
                <div class="products">
                    <?php foreach ($produtos as $produto): ?>
                    <div class="product-card" onclick="abrirProduto(<?= $produto['id'] ?>)">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($produto['image_url']) ?>" alt="<?= htmlspecialchars($produto['name']) ?>">
                        </div>
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($produto['name']) ?></div>
                            <div class="product-price">R$ <?= number_format($produto['price'], 2, ',', '.') ?></div>
                            <div class="product-stock">
                                <?= $produto['stock'] > 0 ? 'Em estoque (' . $produto['stock'] . ')' : 'Fora de estoque' ?>
                            </div>
                            <button class="btn-comprar" onclick="event.stopPropagation(); adicionarCarrinho(<?= $produto['id'] ?>)">
                                Adicionar
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Paginação -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Primeira</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?= $i ?></span>
                    <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Próxima ›</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">Última »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script>
        function abrirProduto(id) {
            window.location.href = '/produto.php?id=' + id;
        }

        function adicionarCarrinho(id) {
            alert('Produto ' + id + ' adicionado ao carrinho!');
            // TODO: Implementar carrinho de verdade
        }
    </script>
    <script src="/includes/auto-image-carousel.js"></script>
</body>
</html>
