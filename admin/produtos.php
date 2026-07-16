<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

$produtos = [];
try {
    $db = Database::getInstance();
    $result = $db->query("SELECT * FROM products ORDER BY created_at DESC LIMIT 200");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
    }
} catch (Exception $e) {
    error_log('Erro ao carregar produtos: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 2rem; margin-bottom: 2rem; color: #333; }
        .btn { padding: 0.75rem 1.5rem; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #5568d3; }
        .products-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .products-table th { background: #f8f9fa; padding: 1rem; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6; }
        .products-table td { padding: 1rem; border-bottom: 1px solid #dee2e6; }
        .products-table tr:hover { background: #f8f9fa; }
        .actions { display: flex; gap: 0.5rem; }
        .btn-small { padding: 0.5rem 1rem; font-size: 0.9rem; }
        .btn-edit { background: #667eea; }
        .btn-delete { background: #dc3545; }
        .empty-state { text-align: center; padding: 3rem; color: #666; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>🛍️ ShopVivaliz Admin / Produtos</div>
                <a href="/admin/" style="color: white; text-decoration: none;">← Voltar</a>
            </div>
        </div>
    </div>

    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h1 class="page-title">Gestão de Produtos</h1>
            <a href="#novo-produto" class="btn">➕ Novo Produto</a>
        </div>

        <div id="novo-produto" style="background: white; padding: 2rem; border-radius: 8px; margin-bottom: 2rem; display: none;">
            <h2>Novo Produto</h2>
            <form style="display: grid; gap: 1rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Nome</label>
                    <input type="text" placeholder="Nome do produto" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">SKU</label>
                    <input type="text" placeholder="SKU" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Preço</label>
                    <input type="number" step="0.01" placeholder="0.00" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Estoque</label>
                    <input type="number" placeholder="0" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn">Salvar Produto</button>
                    <button type="button" class="btn" style="background: #6c757d;" onclick="document.getElementById('novo-produto').style.display='none'">Cancelar</button>
                </div>
            </form>
        </div>

        <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <table class="products-table" id="products-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nome</th>
                        <th>Preço</th>
                        <th>Estoque</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="products-body">
                    <tr><td colspan="5" class="empty-state">Carregando produtos...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (async () => {
        try {
            const r = await fetch('/api/catalog/products.php?limit=200');
            const data = await r.json();
            const tbody = document.getElementById('products-body');

            if (!data.products || data.products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Nenhum produto encontrado</td></tr>';
                return;
            }

            tbody.innerHTML = data.products.slice(0, 50).map(p => `
                <tr>
                    <td><strong>${p.sku}</strong></td>
                    <td>${p.name}</td>
                    <td>R$ ${parseFloat(p.price).toFixed(2)}</td>
                    <td>${p.stock}</td>
                    <td>
                        <div class="actions">
                            <a href="/admin/editar-produto.php?id=${p.id}" class="btn btn-small btn-edit">✏️ Editar</a>
                            <button class="btn btn-small btn-delete" onclick="if(confirm('Deletar?')) alert('Deletar: ${p.id}')">🗑️ Deletar</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        } catch(e) {
            document.getElementById('products-body').innerHTML = '<tr><td colspan="5" class="empty-state">Erro ao carregar produtos</td></tr>';
        }
    })();

    document.querySelector('.btn')?.addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById('novo-produto').style.display = 'block';
    });
    </script>
</body>
</html>
