<?php
/**
 * ShopVivaliz - Homepage Principal
 * Catálogo de Produtos com Integração Olist
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Carregar configurações
require_once __DIR__ . '/constants.php';

// Carregar produtos
$produtos = [];
$arquivo_produtos = __DIR__ . '/../olist/produtos-olist-array.php';
if (file_exists($arquivo_produtos)) {
    include $arquivo_produtos;
    if (!empty($GLOBALS['produtos_olist'])) {
        $produtos = array_slice($GLOBALS['produtos_olist'], 0, 12);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopVivaliz - Loja online com 198 produtos de qualidade">
    <title>ShopVivaliz - Sua Loja Online de Confiança</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8f9fa; margin: 0; padding: 0; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 0; text-align: center; }
        .hero h1 { font-size: 3em; margin: 0 0 20px 0; }
        .hero p { font-size: 1.2em; margin: 0 0 30px 0; }
        .btn { display: inline-block; padding: 12px 30px; background: white; color: #667eea; border-radius: 5px; text-decoration: none; font-weight: 600; }
        .btn:hover { background: #f0f0f0; }
        .products { padding: 60px 0; }
        .products h2 { text-align: center; font-size: 2em; margin-bottom: 40px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 30px; }
        .product-card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15); }
        .product-image { height: 200px; background: #e0e0e0; display: flex; align-items: center; justify-content: center; font-size: 3em; }
        .product-info { padding: 20px; }
        .product-name { font-size: 1.1em; font-weight: 600; margin-bottom: 10px; }
        .product-price { font-size: 1.5em; color: #667eea; font-weight: 700; margin-bottom: 15px; }
        .btn-comprar { width: 100%; padding: 10px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .btn-comprar:hover { background: #764ba2; }
        .dashboard-link { position: fixed; bottom: 20px; right: 20px; padding: 15px 20px; background: #333; color: white; border-radius: 50px; text-decoration: none; font-size: 0.9em; }
        .dashboard-link:hover { background: #555; }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container">
            <h1>🛍️ ShopVivaliz</h1>
            <p>Sua Loja Online de Confiança</p>
            <p style="font-size: 1em; margin: 20px 0;">198 Produtos • Entrega Rápida • Qualidade Garantida</p>
            <a href="/claude/catalogo/" class="btn">Ver Catálogo Completo →</a>
        </div>
    </div>

    <div class="container products">
        <h2>Produtos em Destaque</h2>
        <div class="grid">
            <?php foreach ($produtos as $p): ?>
            <div class="product-card">
                <div class="product-image">
                    <?php if (!empty($p['url_imagem'])): ?>
                        <img src="<?= htmlspecialchars($p['url_imagem']) ?>" style="width:100%; height:100%; object-fit:cover;" alt="<?= htmlspecialchars($p['nome']) ?>">
                    <?php else: ?>
                        📦
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <div class="product-name"><?= htmlspecialchars(substr($p['nome'], 0, 40)) ?></div>
                    <div class="product-price">R$ <?= number_format($p['preco'], 2, ',', '.') ?></div>
                    <a href="/claude/carrinho/?add=<?= (int)($p['id'] ?? 0) ?>" class="btn-comprar">🛒 Adicionar ao carrinho</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <a href="/claude/dashboard/" class="dashboard-link">📊 Status do Sistema</a>
</body>
</html>
