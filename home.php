<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/product-price-enrich.php';
require_once __DIR__ . '/includes/catalog-runtime.php';

function home_normalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return strtr($value, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}

function home_products(int $limit = 8): array
{
    $products = [];
    foreach (svcr_products() as $row) {
        if (!is_array($row)) continue;
        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? $row['descricao'] ?? ''));
        if ($sku === '' || $name === '') continue;
        $image = trim((string)($row['image_url'] ?? ''));
        $images = is_array($row['images'] ?? null) ? $row['images'] : [];
        $products[] = [
            'sku' => $sku,
            'name' => $name,
            'image_url' => $image !== '' ? $image : '/images/logo-vivaliz-square.png',
            'images' => array_slice(array_filter($images), 0, 10),
            'price' => (float)($row['price'] ?? 0),
            'category' => trim((string)($row['category'] ?? '')),
        ];
        if (count($products) >= $limit) break;
    }
    return svp_enrich_products($products);
}

function home_category_image(array $products, array $terms): string
{
    foreach ($products as $product) {
        $haystack = home_normalize((string)($product['name'] ?? '') . ' ' . (string)($product['category'] ?? ''));
        foreach ($terms as $term) {
            if (str_contains($haystack, home_normalize($term))) {
                $image = trim((string)($product['image_url'] ?? ''));
                if ($image !== '') return $image;
            }
        }
    }
    foreach ($products as $product) {
        $image = trim((string)($product['image_url'] ?? ''));
        if ($image !== '') return $image;
    }
    return '/images/logo-vivaliz-square.png';
}

function home_product_tags(string $name): array
{
    $normalized = home_normalize($name);
    $map = [
        'Rodízios' => ['rodizio', 'gel', 'freio'],
        'Banheiro' => ['tampa', 'assento', 'banheiro'],
        'Ferragens' => ['abracadeira', 'suporte', 'gancho', 'fita'],
        'Casa' => ['floreira', 'organiz', 'casa', 'cozinha'],
        'Utilidades' => ['jogo', 'kit', 'conjunto'],
    ];
    $tags = [];
    foreach ($map as $label => $terms) {
        foreach ($terms as $term) {
            if (str_contains($normalized, $term)) { $tags[] = $label; break; }
        }
    }
    return array_values(array_unique($tags));
}

$featuredProducts = home_products(8);
$categoryLinks = [
    ['title'=>'Rodízios','query'=>'rodizio','note'=>'Modelos com gel, freio e alta carga','terms'=>['rodizio','gel','freio']],
    ['title'=>'Banheiro','query'=>'tampa','note'=>'Assentos e linhas de acabamento','terms'=>['tampa','assento','banheiro']],
    ['title'=>'Ferragens','query'=>'abracadeira','note'=>'Fixação, suporte e componentes','terms'=>['abracadeira','suporte','gancho','fita']],
    ['title'=>'Casa e organização','query'=>'organiz','note'=>'Itens práticos para o dia a dia','terms'=>['organiz','casa','cozinha','floreira']],
];
foreach ($categoryLinks as $index => $category) {
    $categoryLinks[$index]['image'] = home_category_image($featuredProducts, $category['terms']);
}
$metrics = ['itens'=>'Seleção organizada','imagens'=>'Imagens reais','operacao'=>'Compra assistida'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Vivaliz com catálogo ativo, produtos reais e operação preparada para venda online e marketplace.">
<meta name="theme-color" content="#173B63">
<title>Vivaliz | Loja online</title>
<link rel="stylesheet" href="/css/responsive.css">
<link rel="icon" type="image/png" href="/images/logo-vivaliz-square.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--navy:#173B63;--green:#2DBB57;--ink:#102033;--muted:#5E7188;--line:#DBE5EF}
html,body{font-family:'Manrope','Segoe UI',sans-serif;background:#F5F8FB;color:var(--ink)}
.hero-shell,.section-shell{padding:28px 0}.hero-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.8fr);gap:20px}
.hero-card{border-radius:30px;padding:42px;background:linear-gradient(135deg,#143A62,#20486F 55%,#2DBB57 140%);color:#fff;min-height:420px;display:flex;flex-direction:column;justify-content:space-between}
.hero-copy h1{font-size:clamp(34px,5vw,64px);line-height:1;margin:18px 0}.hero-copy p{line-height:1.7;color:rgba(255,255,255,.88)}
.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.hero-btn,.hero-btn-alt,.product-link{padding:13px 17px;border-radius:14px;text-decoration:none;font-weight:800}
.hero-btn{background:#fff;color:var(--navy)}.hero-btn-alt{color:#fff;border:1px solid rgba(255,255,255,.25)}
.hero-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:24px}.metric-box{padding:15px;border-radius:16px;background:rgba(255,255,255,.1)}
.hero-side{display:grid;gap:16px}.mini-banner,.category-card,.product-card{background:#fff;border:1px solid var(--line);border-radius:24px;overflow:hidden;box-shadow:0 16px 38px rgba(15,23,42,.06)}
.mini-banner{padding:24px}.categories-grid,.products-grid,.footer-grid{display:grid;gap:16px}.categories-grid,.products-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
.category-card{text-decoration:none;color:inherit}.category-image{height:170px;background:#EEF4F8;overflow:hidden}.category-image img,.product-image img{width:100%;height:100%;object-fit:cover}.category-body{padding:18px}.category-body p{color:var(--muted);line-height:1.6}
.section-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:18px}.section-head a{color:var(--navy);font-weight:800;text-decoration:none}
.product-image{height:240px;background:#F7FBFD}.product-body{padding:18px}.product-tag{display:inline-block;margin:0 6px 8px 0;padding:6px 10px;border-radius:999px;background:#EEF5FB;color:var(--navy);font-size:11px;font-weight:800}.product-title{font-weight:700;min-height:48px}.product-meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:14px}.product-link{background:var(--green);color:#fff}
.empty-products{padding:30px;text-align:center;background:#fff;border:1px dashed var(--line);border-radius:22px}footer{margin-top:40px;background:#0F2843;color:#fff;padding:34px 0}.footer-grid{grid-template-columns:1.2fr 1fr 1fr}.footer-grid a,.footer-grid p{color:rgba(255,255,255,.8);text-decoration:none}
@media(max-width:1100px){.hero-grid,.categories-grid,.products-grid,.footer-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:820px){.hero-grid,.categories-grid,.products-grid,.footer-grid,.hero-metrics{grid-template-columns:1fr}.hero-card{padding:28px;min-height:auto}.section-head{align-items:start;flex-direction:column}}
</style>
</head>
<body>
<?php $svNavCurrent=''; include __DIR__ . '/includes/navbar.php'; ?>
<main>
<section class="hero-shell"><div class="container"><div class="hero-grid">
<article class="hero-card"><div><strong>Loja oficial Vivaliz</strong><div class="hero-copy"><h1>Produtos para casa, ferragens e utilidades.</h1><p>Explore uma vitrine com imagens reais, categorias objetivas e acesso rápido aos produtos mais buscados.</p></div><div class="hero-actions"><a class="hero-btn" href="/catalogo">Ver catálogo</a><a class="hero-btn-alt" href="/contato">Falar com a Vivaliz</a></div></div><div class="hero-metrics"><?php foreach($metrics as $label=>$text): ?><div class="metric-box"><strong><?=htmlspecialchars($text,ENT_QUOTES,'UTF-8')?></strong><div><?=htmlspecialchars(ucfirst($label),ENT_QUOTES,'UTF-8')?></div></div><?php endforeach; ?></div></article>
<aside class="hero-side"><article class="mini-banner"><strong>Categorias em destaque</strong><h2>Rodízios, ferragens e utilidades para casa.</h2><p>Imagens reais do catálogo e acesso direto aos produtos.</p><a href="/catalogo?q=rodizio">Explorar rodízios</a></article><article class="mini-banner"><strong>Compra assistida</strong><h2>Atendimento rápido antes e depois da compra.</h2><p>Conte com a equipe para compatibilidade, prazo e quantidade.</p><a href="/contato">Falar com a equipe</a></article></aside>
</div></div></section>
<section class="section-shell"><div class="container"><div class="section-head"><div><h2>Categorias em foco</h2><p>Atalhos com imagens reais dos produtos da loja.</p></div><a href="/catalogo">Abrir todo o catálogo</a></div><div class="categories-grid"><?php foreach($categoryLinks as $category): ?><a class="category-card" href="/catalogo?q=<?=urlencode($category['query'])?>"><div class="category-image"><img src="<?=htmlspecialchars($category['image'],ENT_QUOTES,'UTF-8')?>" alt="<?=htmlspecialchars($category['title'],ENT_QUOTES,'UTF-8')?>" loading="lazy" onerror="this.src='/images/logo-vivaliz-square.png'"></div><div class="category-body"><h3><?=htmlspecialchars($category['title'],ENT_QUOTES,'UTF-8')?></h3><p><?=htmlspecialchars($category['note'],ENT_QUOTES,'UTF-8')?></p></div></a><?php endforeach; ?></div></div></section>
<section class="section-shell"><div class="container"><div class="section-head"><div><h2>Produtos em destaque</h2><p>Seleção carregada diretamente da origem canônica do catálogo.</p></div><a href="/catalogo">Ver todos</a></div><?php if($featuredProducts): ?><div class="products-grid"><?php foreach($featuredProducts as $product): $tags=home_product_tags($product['name']); ?><article class="product-card"><div class="product-image"><img src="<?=htmlspecialchars($product['image_url'],ENT_QUOTES,'UTF-8')?>" alt="<?=htmlspecialchars($product['name'],ENT_QUOTES,'UTF-8')?>" loading="lazy" onerror="this.src='/images/logo-vivaliz-square.png'"></div><div class="product-body"><?php foreach(array_slice($tags?:['Catálogo'],0,2) as $tag): ?><span class="product-tag"><?=htmlspecialchars($tag,ENT_QUOTES,'UTF-8')?></span><?php endforeach; ?><div class="product-title"><?=htmlspecialchars($product['name'],ENT_QUOTES,'UTF-8')?></div><div class="product-meta"><span><?=htmlspecialchars($product['sku'],ENT_QUOTES,'UTF-8')?></span><a class="product-link" href="/catalogo?q=<?=urlencode($product['sku'])?>">Ver item</a></div></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-products">Nenhum produto disponível no catálogo neste momento.</div><?php endif; ?></div></section>
</main>
<footer><div class="container footer-grid"><div><img src="/images/logo-vivaliz.png" alt="Vivaliz" style="height:42px;width:auto;margin-bottom:12px" onerror="this.src='/images/logo.svg'"><p>Vitrine digital da Vivaliz.</p></div><div><h3>Navegação</h3><p><a href="/catalogo">Catálogo</a></p><p><a href="/sobre">Sobre</a></p><p><a href="/contato">Contato</a></p></div><div><h3>Operação</h3><p><a href="/carrinho">Carrinho</a></p><p><a href="/faq">Perguntas frequentes</a></p><p><a href="/politica-privacidade">Privacidade</a></p></div></div></footer>
<script src="/includes/auto-image-carousel.js"></script>
</body>
</html>
