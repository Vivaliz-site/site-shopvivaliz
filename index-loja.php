<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

function home_read_mapping_products(int $limit = 8): array
{
    $path = __DIR__ . '/uploads/olist_imagens_site_mapeamento.csv';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        return [];
    }

    $headers = fgetcsv($fh);
    if (!is_array($headers)) {
        fclose($fh);
        return [];
    }

    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $products = [];

    while (($values = fgetcsv($fh)) !== false) {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[(string)$header] = $values[$index] ?? '';
        }

        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['nome_produto'] ?? ''));
        $image = trim((string)($row['site_url'] ?? ''));
        $isPrimary = strtolower(trim((string)($row['is_primary'] ?? ''))) === 'true';

        if ($sku === '' || $name === '' || $image === '' || !$isPrimary) {
            continue;
        }

        $products[] = [
            'sku' => $sku,
            'name' => $name,
            'image_url' => $image,
        ];

        if (count($products) >= $limit) {
            break;
        }
    }

    fclose($fh);
    return $products;
}

function home_product_tags(string $name): array
{
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);

    $map = [
        'Rodizios' => ['rodizio', 'rodizios'],
        'Banheiro' => ['tampa', 'assento', 'banheiro'],
        'Ferragens' => ['abracadeira', 'fita perfurada', 'suporte', 'gancho'],
        'Casa' => ['floreira', 'organiz', 'casa', 'cozinha'],
        'Moveis' => ['rodizio', 'gel', 'freio'],
        'Utilidades' => ['jogo', 'kit', 'conjunto'],
    ];

    $tags = [];
    foreach ($map as $label => $terms) {
        foreach ($terms as $term) {
            if (str_contains($normalized, $term)) {
                $tags[] = $label;
                break;
            }
        }
    }

    return array_values(array_unique($tags));
}

$featuredProducts = home_read_mapping_products(8);
$metrics = [
    'itens' => '198 produtos mapeados',
    'imagens' => 'Galerias vinculadas',
    'operacao' => 'Catalogo em consolidacao',
];
$categoryLinks = [
    ['title' => 'Rodizios', 'query' => 'rodizio', 'note' => 'Modelos com gel, freio e alta carga'],
    ['title' => 'Banheiro', 'query' => 'tampa', 'note' => 'Assentos e linhas de acabamento'],
    ['title' => 'Ferragens', 'query' => 'abracadeira', 'note' => 'Fixacao, suporte e componentes'],
    ['title' => 'Casa e organizacao', 'query' => 'organiz', 'note' => 'Itens para uso pratico no dia a dia'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vivaliz com catalogo ativo, produtos reais e operacao preparada para venda online e marketplace.">
    <meta name="theme-color" content="#173B63">
    <title>Vivaliz | Loja online</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="icon" type="image/png" href="/images/logo-vivaliz-square.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #173B63;
            --green: #2DBB57;
            --green-soft: #DFF5E7;
            --ink: #102033;
            --muted: #5E7188;
            --line: #DBE5EF;
        }
        html, body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(45,187,87,0.08), transparent 28%),
                linear-gradient(180deg, #F8FBFD 0%, #F3F7FB 100%);
            color: var(--ink);
        }
        .navbar {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(219,229,239,0.85);
        }
        .navbar .container {
            min-height: 82px;
        }
        .hero-shell {
            padding: 28px 0 28px;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.8fr);
            gap: 20px;
        }
        .hero-card {
            border-radius: 30px;
            padding: 42px;
            background:
                radial-gradient(circle at top right, rgba(45,187,87,0.22), transparent 24%),
                linear-gradient(135deg, #143A62 0%, #20486F 44%, #2DBB57 125%);
            color: white;
            min-height: 420px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 28px 60px rgba(23,59,99,0.20);
        }
        .hero-kicker {
            display: inline-flex;
            width: fit-content;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.16);
            font-size: 12px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .hero-copy h1 {
            font-size: clamp(34px, 5vw, 64px);
            line-height: 0.98;
            margin: 18px 0;
            max-width: 9.5ch;
        }
        .hero-copy p {
            max-width: 58ch;
            font-size: 17px;
            line-height: 1.75;
            color: rgba(255,255,255,0.88);
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }
        .hero-btn,
        .hero-btn-alt,
        .product-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            padding: 14px 18px;
            text-decoration: none;
            font-weight: 800;
        }
        .hero-btn {
            background: white;
            color: var(--navy);
        }
        .hero-btn-alt {
            background: rgba(255,255,255,0.10);
            color: white;
            border: 1px solid rgba(255,255,255,0.16);
        }
        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 28px;
        }
        .metric-box {
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 18px;
            padding: 16px;
        }
        .metric-box strong {
            display: block;
            font-size: 16px;
            margin-bottom: 6px;
        }
        .metric-box span {
            color: rgba(255,255,255,0.80);
            font-size: 13px;
        }
        .hero-side {
            display: grid;
            gap: 16px;
        }
        .mini-banner,
        .category-card,
        .product-card {
            background: white;
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.06);
        }
        .mini-banner {
            padding: 24px;
        }
        .mini-banner strong {
            color: var(--navy);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .mini-banner h2 {
            margin: 12px 0 10px;
            font-size: 24px;
        }
        .mini-banner p,
        .section-head p,
        .category-card p {
            color: var(--muted);
            line-height: 1.7;
        }
        .mini-banner a,
        .section-head a {
            color: var(--navy);
            font-weight: 800;
            text-decoration: none;
        }
        .value-strip {
            margin: 28px 0 34px;
            padding: 24px 28px;
            border-radius: 26px;
            background: linear-gradient(135deg, #0F2843 0%, #173B63 45%, #204C77 100%);
            color: white;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .value-strip strong {
            display: block;
            font-size: 19px;
            margin-bottom: 8px;
        }
        .value-strip p {
            color: rgba(255,255,255,0.80);
            line-height: 1.7;
            font-size: 14px;
        }
        .section-shell {
            padding: 22px 0 8px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 16px;
            margin-bottom: 18px;
        }
        .section-head h2 {
            font-size: 30px;
            margin-bottom: 6px;
        }
        .categories-grid,
        .products-grid,
        .footer-grid {
            display: grid;
            gap: 16px;
        }
        .categories-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .category-card {
            padding: 24px;
            text-decoration: none;
            color: inherit;
        }
        .category-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--green-soft);
            color: var(--green);
            font-weight: 800;
            margin-bottom: 16px;
        }
        .products-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .product-image {
            background: #F7FBFD;
            height: 240px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 24px 24px 0 0;
        }
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-body {
            padding: 18px;
        }
        .product-tag-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .product-tag {
            padding: 6px 10px;
            border-radius: 999px;
            background: #EEF5FB;
            color: var(--navy);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .product-title {
            font-size: 16px;
            line-height: 1.45;
            min-height: 46px;
            margin-bottom: 14px;
            font-weight: 700;
        }
        .product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .product-meta span {
            font-size: 12px;
            color: var(--muted);
        }
        .product-link {
            min-width: 108px;
            background: var(--green);
            color: white;
            border-radius: 14px;
            padding: 11px 14px;
        }
        .empty-products {
            background: white;
            border: 1px dashed var(--line);
            border-radius: 22px;
            padding: 30px;
            color: var(--muted);
            text-align: center;
        }
        footer {
            margin-top: 46px;
            background: #0F2843;
            color: white;
            padding: 34px 0;
        }
        .footer-grid {
            grid-template-columns: 1.2fr repeat(2, minmax(0, 1fr));
        }
        .footer-grid p,
        .footer-grid a {
            color: rgba(255,255,255,0.78);
            text-decoration: none;
            line-height: 1.7;
            font-size: 14px;
        }
        .footer-grid h3 {
            margin-bottom: 10px;
            font-size: 15px;
        }
        @media (max-width: 1100px) {
            .hero-grid,
            .categories-grid,
            .products-grid,
            .footer-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 820px) {
            .hero-grid,
            .categories-grid,
            .products-grid,
            .value-strip,
            .footer-grid,
            .hero-metrics {
                grid-template-columns: 1fr;
            }
            .hero-card {
                padding: 28px;
                min-height: auto;
            }
            .section-head {
                flex-direction: column;
                align-items: start;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>
<main>
    <section class="hero-shell">
        <div class="container">
            <div class="hero-grid">
                <article class="hero-card">
                    <div>
                        <span class="hero-kicker">Catalogo ativo Vivaliz</span>
                        <div class="hero-copy">
                            <h1>Produtos reais, marca certa e vitrine com identidade.</h1>
                            <p>Agora a Vivaliz apresenta um catálogo com foco em produto técnico, navegação clara e um site pronto para venda online.</p>
                        </div>
                        <div class="hero-actions">
                            <a class="hero-btn" href="/catalogo">Ver catalogo</a>
                            <a class="hero-btn-alt" href="/contato">Falar com a Vivaliz</a>
                        </div>
                    </div>
                    <div class="hero-metrics">
                        <?php foreach ($metrics as $label => $text): ?>
                            <div class="metric-box">
                                <strong><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo htmlspecialchars(ucfirst($label), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <aside class="hero-side">
                    <article class="mini-banner">
                        <strong>Linhas com mais demanda</strong>
                        <h2>Rodizios, ferragens e itens de casa com imagem vinculada.</h2>
                        <p>Os melhores blocos para tracao inicial do site estao sendo puxados da mesma base que mapeia imagens reais para a operacao.</p>
                        <a href="/catalogo?q=rodizio">Explorar rodizios</a>
                    </article>
                    <article class="mini-banner">
                        <strong>Operacao comercial</strong>
                        <h2>Menos tema fake. Mais pagina pronta para venda.</h2>
                        <p>O foco é consolidar a vitrine e a jornada de compra para deixar o site pronto para pedidos e posicionamento comercial.</p>
                        <a href="/sobre">Ver posicionamento da marca</a>
                    </article>
                </aside>
            </div>
            <div class="value-strip">
                <div>
                    <strong>Logo oficial aplicado</strong>
                    <p>A home agora usa o arquivo de identidade da Vivaliz em vez de um simbolo improvisado.</p>
                </div>
                <div>
                    <strong>Categorias com contexto</strong>
                    <p>As entradas da vitrine deixam de simular moda e passam a refletir linhas reais mais proximas do catalogo atual.</p>
                </div>
                <div>
                    <strong>Destaques vindos do mapeamento</strong>
                    <p>Os cards principais usam nomes e imagens reais da base local de upload, evitando conteúdo genérico e test data.</p>
                </div>
            </div>
        </div>
    </section>
    <section class="section-shell">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>Categorias em foco</h2>
                    <p>Atalhos orientados para as linhas que fazem sentido no catalogo atual, usando busca direta no acervo de produtos.</p>
                </div>
                <a href="/catalogo">Abrir todo o catalogo</a>
            </div>
            <div class="categories-grid">
                <?php foreach ($categoryLinks as $index => $category): ?>
                    <a class="category-card" href="/catalogo?q=<?php echo urlencode($category['query']); ?>">
                        <span class="category-pill"><?php echo (string)($index + 1); ?></span>
                        <h3><?php echo htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo htmlspecialchars($category['note'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section-shell">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>Produtos em destaque</h2>
                    <p>Selecao inicial com imagem principal vinculada no site e nomes reais vindos do mapeamento local de produtos.</p>
                </div>
                <a href="/catalogo">Ver todos</a>
            </div>
            <?php if ($featuredProducts): ?>
                <div class="products-grid">
                    <?php foreach ($featuredProducts as $product): ?>
                        <?php $tags = home_product_tags($product['name']); ?>
                        <article class="product-card">
                            <div class="product-image">
                                <img src="<?php echo htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" onerror="this.src='/images/logo-vivaliz-square.png'">
                            </div>
                            <div class="product-body">
                                <div class="product-tag-row">
                                    <?php foreach (array_slice($tags ?: ['Catalogo'], 0, 2) as $tag): ?>
                                        <span class="product-tag"><?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="product-title"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="product-meta">
                                    <span><?php echo htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <a class="product-link" href="/catalogo?q=<?php echo urlencode($product['sku']); ?>">Ver item</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-products">O mapeamento local ainda nao retornou produtos em destaque.</div>
            <?php endif; ?>
        </div>
    </section>
</main>
<footer>
    <div class="container footer-grid">
        <div>
            <img src="/images/logo-vivaliz.png" alt="Vivaliz" style="height:42px;width:auto;margin-bottom:12px;" onerror="this.src='/images/logo.svg'">
            <p>Vitrine digital da Vivaliz em consolidacao, com foco em navegacao, catalogo e consistencia visual para venda online.</p>
        </div>
        <div>
            <h3>Navegacao</h3>
            <p><a href="/catalogo">Catalogo</a></p>
            <p><a href="/sobre">Sobre</a></p>
            <p><a href="/contato">Contato</a></p>
        </div>
        <div>
            <h3>Operacao</h3>
            <p><a href="/carrinho">Carrinho</a></p>
            <p><a href="/faq">Perguntas frequentes</a></p>
            <p><a href="/politica-privacidade">Privacidade</a></p>
        </div>
    </div>
</footer>
</body>
</html>
