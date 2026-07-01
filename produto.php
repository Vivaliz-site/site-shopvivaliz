<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

function sv_product_catalog(): array
{
    $jsonPath = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    return is_array($decoded) ? $decoded : [];
}

function sv_product_lookup(string $sku, string $id): array
{
    foreach (sv_product_catalog() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSku = trim((string)($row['sku'] ?? ''));
        $rowId = trim((string)($row['id'] ?? $row['olist_product_id'] ?? ''));
        $rowOlistId = trim((string)($row['olist_product_id'] ?? ''));
        if (($sku !== '' && strcasecmp($rowSku, $sku) === 0) || ($id !== '' && ($rowId === $id || $rowOlistId === $id))) {
            return $row;
        }
    }
    return [];
}

function sv_product_value(string $key, string $fallback = ''): string
{
    $value = $_GET[$key] ?? $fallback;
    return is_scalar($value) ? trim((string)$value) : $fallback;
}

$requestedSku = sv_product_value('sku');
$requestedId = sv_product_value('id', sv_product_value('olist_product_id'));
$resolved = sv_product_lookup($requestedSku, $requestedId);

$sku = trim((string)($resolved['sku'] ?? '')) ?: sv_product_value('sku', sv_product_value('id', 'sem-sku'));
$name = trim((string)($resolved['name'] ?? '')) ?: sv_product_value('name', 'Produto Vivaliz');
$image = trim((string)($resolved['image_url'] ?? '')) ?: sv_product_value('image', '/favicon.ico');
$price = (string)($resolved['price'] ?? sv_product_value('price', '0'));
$olistId = trim((string)($resolved['olist_product_id'] ?? '')) ?: sv_product_value('olist_product_id', '');
$priceValue = is_numeric($price) ? (float)$price : 0.0;
$priceLabel = $priceValue > 0 ? 'R$ ' . number_format($priceValue, 2, ',', '.') : 'Preco sob consulta';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173B63">
    <title><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">Vivaliz</a>
            <div class="navbar-menu">
                <a href="/catalogo">Catálogo</a>
                <a href="/carrinho.php">Carrinho</a>
                <a href="/checkout.php">Checkout</a>
            </div>
        </div>
    </nav>

    <main class="container checkout-layout">
        <section class="checkout-panel">
            <p class="eyebrow">Produto em destaque</p>
            <div class="product-detail">
                <div class="product-detail-image">
                    <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" onerror="this.src='/favicon.ico'">
                </div>
                <div class="product-detail-copy">
                    <div class="product-sku"><?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?></div>
                    <h1><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="muted">Página pública de produto com compra direta, cálculo de frete por CEP e checkout assistido.</p>
                    <div class="product-meta">
                        <span><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($olistId !== '' ? $olistId : 'sem id Olist', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            </div>
            <div class="cart-actions">
                <button class="btn btn-primary" type="button" id="buy-now">Comprar agora</button>
                <a class="btn btn-secondary" href="/carrinho.php">Ver carrinho</a>
                <a class="btn btn-secondary" href="/checkout.php">Ir para checkout</a>
            </div>
            <div class="status-line" id="product-status"></div>
        </section>

        <aside class="checkout-panel">
            <h2>Frete por CEP</h2>
            <form action="/checkout.php" method="get" class="checkout-form">
                <label>CEP<input name="cep" inputmode="numeric" pattern="[0-9]{5}-?[0-9]{3}" maxlength="9" required autocomplete="postal-code" placeholder="00000-000"></label>
                <button class="btn btn-primary" type="submit">Calcular frete</button>
            </form>
            <div class="status-line">
                <strong>Pagamento:</strong> PIX, boleto e cartão
            </div>
        </aside>
    </main>
    <script>
        (function () {
            var key = 'shopvivaliz_cart';
            var button = document.getElementById('buy-now');
            var status = document.getElementById('product-status');
            if (!button) return;

            var product = {
                sku: <?= json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                name: <?= json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                image_url: <?= json_encode($image, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                price: <?= json_encode($priceValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                olist_product_id: <?= json_encode($olistId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
            };

            button.addEventListener('click', function () {
                var items;
                try {
                    items = JSON.parse(localStorage.getItem(key) || '[]');
                    if (!Array.isArray(items)) items = [];
                } catch (error) {
                    items = [];
                }
                var existing = items.find(function (item) { return item.sku === product.sku; });
                if (existing) existing.quantity = Number(existing.quantity || 1) + 1;
                else items.push(Object.assign({}, product, { quantity: 1 }));
                localStorage.setItem(key, JSON.stringify(items));
                if (status) status.textContent = 'Produto adicionado ao carrinho.';
                window.location.href = '/carrinho.php';
            });
        })();
    </script>
</body>
</html>
