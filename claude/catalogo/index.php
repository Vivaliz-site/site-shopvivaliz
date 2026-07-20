<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://shopvivaliz.com.br');
$cache_file = __DIR__ . '/../storage/catalogo-cache.json';

// Configuração TinyERP/Olist
$tiny_api_key = getenv('TINY_ERP_API_KEY') ?: '';
$tiny_api_url = 'https://api.tiny.com.brapi/v2/produtos.json';

// Parâmetros de busca
$categoria_filtro = $_GET['categoria'] ?? '';
$busca = $_GET['busca'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$por_pagina = 20;

// Carrega 198 produtos via arquivo incluído
$produtos = [];
$cache_valido = false;

// 1. Tentar incluir arquivo de 198 produtos
$arquivo_produtos = __DIR__ . '/../olist/produtos-olist-array.php';
if (file_exists($arquivo_produtos)) {
    include $arquivo_produtos;
    if (!empty($GLOBALS['produtos_olist'])) {
        $produtos = $GLOBALS['produtos_olist'];
        $cache_valido = true;
        error_log("[Catalogo] Carregou " . count($produtos) . " produtos do arquivo");

        // SYNC 198 PRODUTOS AO BANCO - TEMPORARIAMENTE DESABILITADO PARA DEBUG
        // TODO: Implementar sincronização após resolver erro de conexão ao banco
    }
}

// Se cache expirado, buscar da API
if (!$cache_valido && $tiny_api_key) {
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tiny_api_url . '?token=' . urlencode($tiny_api_key) . '&formato=json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            $produtos = $data['produtos'] ?? [];

            // Salvar cache
            @mkdir(dirname($cache_file), 0755, true);
            file_put_contents($cache_file, json_encode([
                'timestamp' => date('c'),
                'produtos' => $produtos,
                'total' => count($produtos)
            ]));
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar Olist: " . $e->getMessage());
    }
}

// Fallback: dados de teste se nenhum produto
if (empty($produtos)) {
    error_log("[Catalogo] AVISO: Nenhum produto carregado! Cache: $cache_file, Existe: " . (file_exists($cache_file) ? 'SIM' : 'NÃO'));

    // Apenas para teste - remover quando tiver dados reais
    $produtos = [
        ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90, 'categoria' => 'Roupas', 'descricao' => '100% algodão'],
        ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90, 'categoria' => 'Roupas', 'descricao' => 'Azul clássico'],
        ['id' => 3, 'nome' => 'Tênis Esportivo', 'preco' => 199.90, 'categoria' => 'Calçados', 'descricao' => 'Para corrida'],
        ['id' => 4, 'nome' => 'Mochila Executiva', 'preco' => 189.90, 'categoria' => 'Acessórios', 'descricao' => 'Profissional'],
        ['id' => 5, 'nome' => 'Relógio Analógico', 'preco' => 99.90, 'categoria' => 'Acessórios', 'descricao' => 'Clássico'],
    ];
} else {
    error_log("[Catalogo] Produtos carregados: " . count($produtos));
}

// Aplicar filtros
$total_produtos = count($produtos);

if ($categoria_filtro) {
    $produtos = array_filter($produtos, function($p) use ($categoria_filtro) {
        return ($p['categoria'] ?? 'Geral') === $categoria_filtro;
    });
}

if ($busca) {
    $produtos = array_filter($produtos, function($p) use ($busca) {
        return stripos($p['nome'], $busca) !== false ||
               stripos($p['descricao'] ?? '', $busca) !== false;
    });
}

// Paginação
$total_filtrado = count($produtos);
$total_paginas = ceil($total_filtrado / $por_pagina);
$offset = ($pagina - 1) * $por_pagina;
$produtos_pagina = array_slice($produtos, $offset, $por_pagina);

// Categorias únicas
$categorias = array_unique(array_column($produtos, 'categoria'));
sort($categorias);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1F3A70">
    <title>Vivaliz - Catalogo</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
        }
        .catalog-header {
            background: linear-gradient(135deg, #1F3A70 0%, #667eea 55%, #2ECC71 140%);
            color: white;
            padding: 20px 0;
            margin-bottom: 20px;
        }
        .catalog-header h1 {
            color: white;
            margin-bottom: 10px;
        }
        .product-count {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-bar form {
            display: flex;
            gap: 10px;
            width: 100%;
        }
        .search-bar input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 1em;
        }
        .search-bar button {
            padding: 10px 20px;
            background: #1F3A70;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
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
            font-weight: 600;
        }
        .filter-btn:hover {
            border-color: #1F3A70;
        }
        .filter-btn.active {
            background: #1F3A70;
            color: white;
            border-color: #1F3A70;
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
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .product-image {
            width: 100%;
            height: 120px;
            background: #f5f7fa;
            border-radius: 6px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3em;
        }
        .product-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .product-name {
            font-weight: 600;
            margin: 10px 0;
            color: #1f2937;
            font-size: 0.95em;
            flex-grow: 1;
        }
        .product-desc {
            font-size: 0.8em;
            color: #666;
            margin-bottom: 8px;
        }
        .product-price {
            font-size: 1.5em;
            color: #1F3A70;
            font-weight: bold;
            margin: 10px 0;
        }
        .btn-produto {
            background: #1F3A70;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            font-weight: 600;
        }
        .btn-produto:hover {
            background: #667eea;
        }
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin: 20px 0;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            text-decoration: none;
            color: #667eea;
        }
        .pagination a:hover, .pagination .active {
            background: #1F3A70;
            color: white;
        }
        .no-products {
            text-align: center;
            padding: 40px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>

    <div class="catalog-header">
        <div class="container">
            <h1>🛍️ Catálogo ShopVivaliz</h1>
            <div class="product-count">
                Total: <?php echo number_format($total_filtrado, 0, ',', '.'); ?> produtos
                <?php if ($categoria_filtro): ?>
                    - Categoria: <?php echo htmlspecialchars($categoria_filtro); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <main class="container">
        <div class="search-bar">
            <form method="GET">
                <input type="text" name="busca" placeholder="Buscar produtos..." value="<?php echo htmlspecialchars($busca); ?>">
                <button type="submit">🔍 Buscar</button>
            </form>
        </div>

        <div class="filters">
            <a href="?"><button class="filter-btn <?php echo !$categoria_filtro ? 'active' : ''; ?>">Todos (<?php echo count(array_unique(array_column($produtos, 'categoria'))); ?>)</button></a>
            <?php foreach ($categorias as $cat): ?>
                <a href="?categoria=<?php echo urlencode($cat); ?>">
                    <button class="filter-btn <?php echo $categoria_filtro === $cat ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </button>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($produtos_pagina)): ?>
            <div class="no-products">
                <p>Nenhum produto encontrado</p>
            </div>
        <?php else: ?>
            <div class="products-grid">
                <?php foreach ($produtos_pagina as $p): ?>
                    <a href="produto.php?id=<?php echo urlencode($p['id'] ?? ''); ?>" style="text-decoration: none; color: inherit;">
                        <div class="product-card">
                            <div class="product-image">
                                <?php if (!empty($p['url_imagem'])): ?>
                                    <img src="<?php echo htmlspecialchars($p['url_imagem']); ?>" alt="<?php echo htmlspecialchars($p['nome']); ?>">
                                <?php else: ?>
                                    📦
                                <?php endif; ?>
                            </div>
                            <div class="product-name"><?php echo htmlspecialchars($p['nome']); ?></div>
                            <?php if (!empty($p['descricao'])): ?>
                                <div class="product-desc"><?php echo htmlspecialchars(substr($p['descricao'], 0, 40)); ?></div>
                            <?php endif; ?>
                            <div class="product-price">R$ <?php echo number_format($p['preco'], 2, ',', '.'); ?></div>
                            <button class="btn-produto" onclick="location.href='produto.php?id=<?php echo urlencode($p['id']); ?>'; return false;">
                                Ver Detalhes
                            </button>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php if ($categoria_filtro) echo '&categoria=' . urlencode($categoria_filtro); ?><?php if ($busca) echo '&busca=' . urlencode($busca); ?>">← Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <?php if ($i === $pagina): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?pagina=<?php echo $i; ?><?php if ($categoria_filtro) echo '&categoria=' . urlencode($categoria_filtro); ?><?php if ($busca) echo '&busca=' . urlencode($busca); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php if ($categoria_filtro) echo '&categoria=' . urlencode($categoria_filtro); ?><?php if ($busca) echo '&busca=' . urlencode($busca); ?>">Próxima →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>
