<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

function sv_catalog_root(): string
{
    return __DIR__;
}

function sv_catalog_query(): string
{
    $value = $_GET['q'] ?? $_GET['busca'] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function sv_catalog_products(int $limit, string $query): array
{
    $jsonPath = sv_catalog_root() . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return [];
    }

    $products = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? 'Produto ShopVivaliz'));
        if ($query !== '' && stripos($sku . ' ' . $name, $query) === false) {
            continue;
        }
        $products[] = [
            'sku' => $sku !== '' ? $sku : (string)($row['id'] ?? 'sem-sku'),
            'name' => $name !== '' ? $name : 'Produto ShopVivaliz',
            'image_url' => trim((string)($row['image_url'] ?? '/favicon.ico')) ?: '/favicon.ico',
            'price' => (float)($row['price'] ?? 0),
            'images_count' => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
        ];
        if (count($products) >= $limit) {
            break;
        }
    }

    return $products;
}

function sv_catalog_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_catalog_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Preço sob consulta';
}

function sv_catalog_product_url(array $product): string
{
    $params = http_build_query([
        'sku' => $product['sku'],
        'name' => $product['name'],
        'image' => $product['image_url'],
        'price' => (string)$product['price'],
        'olist_product_id' => $product['olist_product_id'],
    ]);
    return '/produto?' . $params;
}

$query = sv_catalog_query();
$products = sv_catalog_products(48, $query);
$statusText = $products
    ? count($products) . ' produto' . (count($products) === 1 ? '' : 's') . ' pronto' . (count($products) === 1 ? '' : 's') . ' para navegação.'
    : ($query !== '' ? 'Nenhum produto encontrado para a busca.' : 'Nenhum produto encontrado no catálogo.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">ShopVivaliz</a>
            <div class="navbar-menu">
                <a href="/">Home</a>
                <a href="/catalogo.php" aria-current="page">Catálogo</a>
                <a href="/admin/">Admin</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow">Catálogo ao vivo</p>
                    <h1>Produtos prontos para venda</h1>
                    <p class="muted">Itens importados do Tiny/Olist com imagens vinculadas ao catálogo do site.</p>
                </div>
                <form class="catalog-search" role="search" method="get" action="/catalogo">
                    <input id="catalog-search" name="q" type="search" placeholder="Buscar por SKU ou produto" autocomplete="off" value="<?= sv_catalog_esc($query) ?>">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </section>

        <section class="container catalog-tools">
            <form class="cep-checker" action="/checkout.php" method="get">
                <label for="catalog-cep">CEP para calcular frete</label>
                <div class="cep-checker-row">
                    <input id="catalog-cep" name="cep" type="text" inputmode="numeric" placeholder="Digite seu CEP" maxlength="9">
                    <button type="submit">Calcular frete</button>
                </div>
            </form>
            <div id="catalog-status" class="status-line"><?= sv_catalog_esc($statusText) ?></div>
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite">
            <?php foreach ($products as $product): ?>
                <?php
                $image = $product['image_url'] !== '' ? $product['image_url'] : '/favicon.ico';
                $payload = rawurlencode(json_encode([
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'image_url' => $image,
                    'price' => $product['price'],
                    'olist_product_id' => $product['olist_product_id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?>
                <article class="product-card">
                    <a class="product-image" href="<?= sv_catalog_esc($image) ?>" target="_blank" rel="noreferrer">
                        <img src="<?= sv_catalog_esc($image) ?>" alt="<?= sv_catalog_esc($product['name']) ?>" loading="lazy" onerror="this.src='/favicon.ico'">
                    </a>
                    <div class="product-info">
                        <div class="product-sku"><?= sv_catalog_esc($product['sku']) ?></div>
                        <h2><?= sv_catalog_esc($product['name']) ?></h2>
                        <div class="product-meta">
                            <span><?= sv_catalog_esc(sv_catalog_money((float)$product['price'])) ?></span>
                            <span><?= (int)$product['images_count'] ?> imagem<?= (int)$product['images_count'] === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="card-actions">
                            <a class="btn btn-secondary card-link" href="<?= sv_catalog_esc(sv_catalog_product_url($product)) ?>">Ver detalhes</a>
                            <button class="buy-button" type="button" data-product="<?= sv_catalog_esc($payload) ?>">Comprar agora</button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js"></script>
</body>
</html>
