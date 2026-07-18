<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';

$catalogPath = dirname(__DIR__) . '/api/catalog/fallback-products.json';
$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = '';
$success = '';

function ep_load_catalog(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function ep_find_index(array $catalog, string $id): ?int {
    foreach ($catalog as $i => $p) {
        $pid = (string)($p['olist_product_id'] ?? $p['id'] ?? '');
        if ($pid === $id) return $i;
    }
    return null;
}

$catalog = ep_load_catalog($catalogPath);
$index = $id !== '' ? ep_find_index($catalog, $id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('admin-editar-produto', $_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif ($index === null) {
        $error = 'Produto não encontrado no catálogo.';
    } else {
        $catalog[$index]['name'] = trim((string)($_POST['name'] ?? $catalog[$index]['name'] ?? ''));
        $catalog[$index]['price'] = (float)($_POST['price'] ?? 0);
        $catalog[$index]['stock'] = (int)($_POST['stock'] ?? 0);
        $catalog[$index]['category'] = trim((string)($_POST['category'] ?? $catalog[$index]['category'] ?? ''));
        $catalog[$index]['status'] = isset($_POST['exibir_para_venda']) ? 'active' : 'inactive';

        $catalog[$index]['slug'] = trim((string)($_POST['slug'] ?? $catalog[$index]['slug'] ?? ''));
        $catalog[$index]['gtin'] = trim((string)($_POST['gtin'] ?? $catalog[$index]['gtin'] ?? ''));
        $catalog[$index]['ncm'] = trim((string)($_POST['ncm'] ?? $catalog[$index]['ncm'] ?? ''));
        $catalog[$index]['brand'] = trim((string)($_POST['brand'] ?? $catalog[$index]['brand'] ?? ''));
        $catalog[$index]['unit'] = trim((string)($_POST['unit'] ?? $catalog[$index]['unit'] ?? ''));
        $catalog[$index]['notes'] = trim((string)($_POST['notes'] ?? $catalog[$index]['notes'] ?? ''));
        $catalog[$index]['seo_title'] = trim((string)($_POST['seo_title'] ?? $catalog[$index]['seo_title'] ?? ''));
        $catalog[$index]['seo_description'] = trim((string)($_POST['seo_description'] ?? $catalog[$index]['seo_description'] ?? ''));
        $keywordsRaw = trim((string)($_POST['keywords'] ?? ''));
        $catalog[$index]['keywords'] = $keywordsRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $keywordsRaw))))
            : ($catalog[$index]['keywords'] ?? []);

        $dimensions = is_array($catalog[$index]['dimensions'] ?? null) ? $catalog[$index]['dimensions'] : [];
        $dimensions['width'] = (float)($_POST['dim_width'] ?? $dimensions['width'] ?? 0);
        $dimensions['height'] = (float)($_POST['dim_height'] ?? $dimensions['height'] ?? 0);
        $dimensions['length'] = (float)($_POST['dim_length'] ?? $dimensions['length'] ?? 0);
        $dimensions['net_weight'] = (float)($_POST['dim_net_weight'] ?? $dimensions['net_weight'] ?? 0);
        $dimensions['gross_weight'] = (float)($_POST['dim_gross_weight'] ?? $dimensions['gross_weight'] ?? 0);
        $catalog[$index]['dimensions'] = $dimensions;

        $prices = is_array($catalog[$index]['prices'] ?? null) ? $catalog[$index]['prices'] : [];
        $prices['cost_price'] = (float)($_POST['cost_price'] ?? $prices['cost_price'] ?? 0);
        $prices['promotional_price'] = (float)($_POST['promotional_price'] ?? $prices['promotional_price'] ?? 0);
        $catalog[$index]['prices'] = $prices;

        $written = file_put_contents($catalogPath, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        if ($written === false) {
            $error = 'Falha ao salvar (permissão de escrita no catálogo).';
        } else {
            $success = 'Produto atualizado com sucesso.';
        }
    }
}

$produto = $index !== null ? $catalog[$index] : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Produto - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 720px; margin: 0 auto; padding: 2rem; }
        .card { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        input[type=text], input[type=number] { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 1rem; }
        .checkbox-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; }
        .btn { padding: 0.75rem 1.5rem; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .alert-error { background: #fdecea; color: #a33; }
        .alert-success { background: #e8f5ee; color: #157347; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>🛍️ ShopVivaliz Admin / Editar Produto</div>
                <a href="/admin/produtos.php" style="color: white; text-decoration: none;">← Voltar</a>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="card">
            <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if ($produto === null): ?>
                <p>Produto não encontrado. <a href="/admin/produtos.php">Voltar para a lista</a>.</p>
            <?php else: ?>
                <h1 style="margin-bottom: 1.5rem;">Editar: <?= htmlspecialchars((string)($produto['sku'] ?? '')) ?></h1>
                <form method="post">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                    <?= sv_csrf_input('admin-editar-produto') ?>

                    <label for="name">Nome</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars((string)($produto['name'] ?? '')) ?>" required>

                    <label for="category">Categoria</label>
                    <input type="text" id="category" name="category" value="<?= htmlspecialchars((string)($produto['category'] ?? '')) ?>">

                    <label for="price">Preço (R$)</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?= htmlspecialchars((string)($produto['price'] ?? 0)) ?>" required>

                    <label for="stock">Estoque</label>
                    <input type="number" id="stock" name="stock" min="0" value="<?= htmlspecialchars((string)($produto['stock'] ?? 0)) ?>" required>

                    <div class="checkbox-row">
                        <input type="checkbox" id="exibir_para_venda" name="exibir_para_venda" <?= ($produto['status'] ?? 'active') === 'active' ? 'checked' : '' ?>>
                        <label for="exibir_para_venda" style="margin: 0;">Exibir para venda</label>
                    </div>

                    <button type="submit" class="btn">Salvar alterações</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
