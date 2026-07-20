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
        .admin-searchbar {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .admin-searchbar input {
            flex: 1 1 320px;
            padding: 0.85rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            background: #fff;
        }
        .admin-searchbar input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.12);
        }
        .admin-search-meta {
            color: #6b7280;
            font-size: 0.95rem;
            white-space: nowrap;
        }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
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
        </div>

        <div style="background: #fff8e6; border: 1px solid #f0d78c; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 2rem; color: #5c4a09;">
            ℹ️ O catálogo é sincronizado automaticamente do ERP (Tiny). Para cadastrar um produto novo, alterar preço/estoque na origem ou trocar categoria, faça isso diretamente no Tiny — o próximo sync (a cada ~10 min) replica para o site. Para ajustes pontuais no site (ex: ocultar da venda), use "Editar" abaixo.
        </div>

        <div class="admin-searchbar">
            <input type="search" id="product-search" placeholder="Buscar por SKU, nome ou categoria" autocomplete="off" aria-label="Buscar produto no admin">
            <div class="admin-search-meta" id="product-search-meta">Carregando...</div>
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
            <div id="products-pager" style="padding: 1rem; text-align: center; border-top: 1px solid #dee2e6;"></div>
        </div>
    </div>

    <script>
    (async () => {
        try {
            const r = await fetch('/api/catalog/products.php?limit=200');
            const data = await r.json();
            const tbody = document.getElementById('products-body');
            const searchInput = document.getElementById('product-search');
            const searchMeta = document.getElementById('product-search-meta');

            if (!data.products || data.products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Nenhum produto encontrado</td></tr>';
                if (searchMeta) searchMeta.textContent = '0 produtos';
                return;
            }

            const PAGE_SIZE = 20;
            let currentPage = 1;
            const allProducts = data.products;
            let filteredProducts = allProducts.slice();

            function normalizeTerm(value) {
                return String(value || '').toLowerCase().trim();
            }

            function applySearch(term) {
                const q = normalizeTerm(term);
                filteredProducts = q === ''
                    ? allProducts.slice()
                    : allProducts.filter(p => {
                        const haystack = normalizeTerm([p.sku, p.name, p.category, p.olist_product_id, p.id].join(' '));
                        return haystack.includes(q);
                    });
                currentPage = 1;
                renderPage(currentPage);
            }

            function renderPage(page) {
                const start = (page - 1) * PAGE_SIZE;
                const pageItems = filteredProducts.slice(start, start + PAGE_SIZE);
                const totalPages = Math.max(1, Math.ceil(filteredProducts.length / PAGE_SIZE));

                if (searchMeta) {
                    const q = searchInput ? searchInput.value.trim() : '';
                    searchMeta.textContent = q === ''
                        ? `${filteredProducts.length} produtos`
                        : `${filteredProducts.length} resultado(s) para "${q}"`;
                }

                if (pageItems.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Nenhum produto encontrado para esta busca</td></tr>';
                } else {
                    tbody.innerHTML = pageItems.map(p => `
                    <tr>
                        <td><strong>${p.sku}</strong></td>
                        <td>${p.name}</td>
                        <td>R$ ${parseFloat(p.price).toFixed(2)}</td>
                        <td>${p.stock}</td>
                        <td>
                            <div class="actions">
                                <a href="/admin/editar-produto.php?id=${p.olist_product_id || p.id}" class="btn btn-small btn-edit">✏️ Editar</a>
                            </div>
                        </td>
                    </tr>
                `).join('');
                }

                const pager = document.getElementById('products-pager');
                if (pager) {
                    pager.innerHTML = `
                        <button class="btn btn-small" ${page <= 1 ? 'disabled' : ''} id="pager-prev">← Anterior</button>
                        <span style="margin: 0 1rem;">Página ${page} de ${totalPages} (${filteredProducts.length} produtos)</span>
                        <button class="btn btn-small" ${page >= totalPages ? 'disabled' : ''} id="pager-next">Próxima →</button>
                    `;
                    document.getElementById('pager-prev')?.addEventListener('click', () => { currentPage--; renderPage(currentPage); });
                    document.getElementById('pager-next')?.addEventListener('click', () => { currentPage++; renderPage(currentPage); });
                }
            }

            searchInput?.addEventListener('input', function () {
                applySearch(this.value);
            });

            renderPage(currentPage);
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
