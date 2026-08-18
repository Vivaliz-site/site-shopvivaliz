<?php
declare(strict_types=1);

// Catalogo publico: visitantes anonimos nao precisam abrir sessao PHP.
// Retomamos a sessao somente quando ja existe cookie, preservando o estado de
// login. GET/HEAD anonimos podem usar cache curto na borda sem misturar usuarios.
$svCatalogMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$svCatalogSessionName = session_name();
$svCatalogHasSessionCookie = $svCatalogSessionName !== ''
    && isset($_COOKIE[$svCatalogSessionName])
    && trim((string)$_COOKIE[$svCatalogSessionName]) !== '';
$svCatalogPublicCache = in_array($svCatalogMethod, ['GET', 'HEAD'], true)
    && !$svCatalogHasSessionCookie;

if ($svCatalogHasSessionCookie && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!empty($_GET['busca']) && empty($_GET['q'])) {
    $canonicalQuery = trim((string)$_GET['busca']);
    if ($canonicalQuery !== '') {
        $params = ['q' => $canonicalQuery];
        if (!empty($_GET['categoria'])) {
            $params['categoria'] = trim((string)$_GET['categoria']);
        }
        if (!empty($_GET['pagina'])) {
            $params['pagina'] = max(1, (int)$_GET['pagina']);
        }
        header('Location: /catalogo?' . http_build_query($params), true, 301);
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
if ($svCatalogPublicCache) {
    header('Cache-Control: public, max-age=15, s-maxage=30, stale-while-revalidate=60');
    header('Vary: Cookie');
} else {
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}

require_once __DIR__ . '/includes/product-price-enrich.php';
require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/ml-ranking.php';

function sv_catalog_root(): string
{
    return __DIR__;
}

function sv_catalog_query(): string
{
    $value = $_GET['q'] ?? $_GET['busca'] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function sv_catalog_search_normalize(string $value): string
{
    static $accents = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
    ];

    $value = trim($value);
    $value = strtr($value, $accents);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function sv_catalog_search_aliases(string $query): array
{
    $normalized = sv_catalog_search_normalize($query);
    $aliases = [$normalized];
    $rules = [
        'RODIZIO' => ['RODIZIO', 'RODIZIOS', 'RODINHA', 'RODINHAS', 'RODAS', 'GEL', 'SILICONE'],
        'CADEADO' => ['CADEADO', 'CADEADOS', 'PAPAIZ', 'TRAVA', 'SEGURANCA'],
        'ASSENTO' => ['ASSENTO', 'TAMPA', 'VASO SANITARIO', 'SANITARIO', 'BANHEIRO', 'ASTRA'],
        'FERRAMENTA' => ['FERRAMENTA', 'FERRAMENTAS', 'ALICATE', 'CHAVE', 'CAIXA', 'CARRINHO', 'GEDORE', 'FERCAR'],
        'VASO' => ['VASO', 'VASOS', 'FLOREIRA', 'FLOREIRAS', 'CACHEPOT', 'JARDIM', 'JAPI'],
        'PET' => ['PET', 'COMEDOURO', 'RACAO', 'RACAO', 'CACHORRO', 'GATO'],
    ];

    foreach ($rules as $canonical => $terms) {
        foreach ($terms as $term) {
            if (str_contains($normalized, sv_catalog_search_normalize($term))) {
                $aliases[] = $canonical;
                foreach ($terms as $related) {
                    $aliases[] = sv_catalog_search_normalize($related);
                }
                break;
            }
        }
    }

    return array_values(array_unique(array_filter($aliases)));
}

function sv_catalog_fuzzy_contains(string $haystack, string $needle): bool
{
    if ($needle === '' || strlen($needle) < 5) {
        return false;
    }

    $tokens = preg_split('/\s+/', $haystack) ?: [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if (strlen($token) < 5 || abs(strlen($token) - strlen($needle)) > 2) {
            continue;
        }
        if (levenshtein($token, $needle) <= 2) {
            return true;
        }
    }

    return false;
}

function sv_catalog_matches_query(array $row, string $query): bool
{
    if ($query === '') {
        return true;
    }

    $haystack = implode(' ', array_filter([
        (string)($row['sku'] ?? ''),
        (string)($row['name'] ?? ''),
        (string)($row['category'] ?? ''),
        (string)($row['slug'] ?? ''),
        (string)($row['olist_product_id'] ?? ''),
        (string)($row['id'] ?? ''),
        is_array($row['tags'] ?? null) ? implode(' ', array_map('strval', $row['tags'])) : '',
    ]));

    $normalizedHaystack = sv_catalog_search_normalize($haystack);
    $queryNorm = sv_catalog_search_normalize($query);

    if (strpos($normalizedHaystack, $queryNorm) !== false) {
        return true;
    }

    $words = array_filter(explode(' ', $queryNorm));
    if ($words === []) {
        return true;
    }

    foreach ($words as $word) {
        if (strlen($word) < 2) continue;
        $wordMatched = false;
        if (strpos($normalizedHaystack, $word) !== false) {
            $wordMatched = true;
        } else {
            foreach (sv_catalog_search_aliases($word) as $alias) {
                if (strpos($normalizedHaystack, $alias) !== false) {
                    $wordMatched = true;
                    break;
                }
            }
        }
        if (!$wordMatched) {
            return false;
        }
    }

    return true;
}

function sv_catalog_load(): array
{
    static $data = null;
    if ($data !== null) return $data;
    return $data = svcr_products();
}

function sv_catalog_products(int $limit, string $query, string $category = '', int $offset = 0): array
{
    $decoded = sv_ml_rank_products(sv_catalog_load());
    if ($decoded === []) {
        return [];
    }

    $products = [];
    $skipped = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $sku  = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? 'Produto Vivaliz'));
        $cat  = trim((string)($row['category'] ?? ''));
        if (!sv_catalog_matches_query($row, $query)) continue;
        if ($category !== '' && $cat !== $category) continue;
        if ($skipped < $offset) {
            $skipped++;
            continue;
        }
        $images = is_array($row['images'] ?? null) ? $row['images'] : [];
        $products[] = [
            'sku'              => $sku !== '' ? $sku : (string)($row['id'] ?? 'sem-sku'),
            'name'             => $name !== '' ? $name : 'Produto Vivaliz',
            // Rodada 2 (2026-08-18): NAO aplicar o default do logo aqui. svp_enrich_products()
            // (chamado no return desta funcao) e svcie_enrich_images() so preenchem image_url
            // quando ele esta vazio -- com o logo ja preenchido aqui, os enriquecedores nunca
            // disparavam e o catalogo publico mostrava o logotipo no lugar da foto do produto
            // (as fotos reais ficavam em 'images', renderizadas depois do logo). O fallback pro
            // logo correto ja existe no momento do render (linha ~613, apos o enriquecimento).
            'image_url'        => trim((string)($row['image_url'] ?? '')),
            'images'           => array_slice(array_filter($images), 0, 10),
            'price'            => (float)($row['price'] ?? 0),
            'stock'            => (int)($row['stock'] ?? 0),
            'images_count'     => (int)($row['images_count'] ?? 0),
            'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
            'category'         => $cat,
            'slug'             => trim((string)($row['slug'] ?? '')),
            'tags'             => is_array($row['tags'] ?? null) ? $row['tags'] : [],
        ];
        if (count($products) >= $limit) break;
    }

    return svp_enrich_products($products);
}

function sv_catalog_count_matching(string $query, string $category = ''): int
{
    $decoded = sv_catalog_load();
    $count = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $sku  = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $cat  = trim((string)($row['category'] ?? ''));
        if (!sv_catalog_matches_query($row, $query)) continue;
        if ($category !== '' && $cat !== $category) continue;
        $count++;
    }
    return $count;
}

function sv_catalog_categories(): array
{
    $decoded = sv_catalog_load();
    if ($decoded === []) return [];
    $cats = [];
    foreach ($decoded as $row) {
        $cat = trim((string)($row['category'] ?? ''));
        if ($cat !== '') $cats[$cat] = ($cats[$cat] ?? 0) + 1;
    }
    arsort($cats);
    return $cats;
}

function sv_catalog_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_catalog_default_image(): string
{
    return '/images/logo-vivaliz-square-v2.png';
}

function sv_catalog_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Consulte o valor';
}

function sv_catalog_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $official = __DIR__ . '/config/official-site.php';
    if (is_file($official)) {
        $data = @include $official;
        $candidate = is_array($data) ? trim((string)($data['base_url'] ?? '')) : '';
        if ($candidate !== '') {
            return $base = rtrim($candidate, '/');
        }
    }

    $env = trim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: ''));
    if ($env !== '') {
        return $base = rtrim($env, '/');
    }

    return $base = 'https://shopvivaliz.com.br';
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

function sv_catalog_slugify(string $name, string $sku): string
{
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $base = strtr($lower, $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

function sv_catalog_product_href(array $product): string
{
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $slug = trim((string)($product['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_catalog_slugify($name, $sku) : '');
    return $slug !== '' ? '/produto/' . $slug : sv_catalog_product_url($product);
}

function sv_catalog_contact_url(array $product): string
{
    return '/contato?' . http_build_query([
        'sku' => $product['sku'] ?? '',
        'produto' => $product['name'] ?? '',
    ]);
}

function sv_catalog_canonical_url(string $category): string
{
    $params = [];
    if ($category !== '') {
        $params['categoria'] = $category;
    }

    $query = http_build_query($params);
    return sv_catalog_base_url() . '/catalogo' . ($query !== '' ? '?' . $query : '');
}

function sv_catalog_page_title(string $category, string $query): string
{
    if ($query !== '' && $category !== '') {
        return $query . ' em ' . $category . ' | Produtos Vivaliz';
    }
    if ($query !== '') {
        return 'Busca por ' . $query . ' | Produtos Vivaliz';
    }
    if ($category !== '') {
        return $category . ' | Produtos Vivaliz';
    }
    return 'Catálogo | Vivaliz';
}

function sv_catalog_meta_description(string $category, string $query, int $count): string
{
    $countText = $count . ' produto' . ($count === 1 ? '' : 's');
    if ($query !== '' && $category !== '') {
        return 'Resultados para "' . $query . '" em ' . $category . ' na Vivaliz. ' . $countText . ' com compra segura, suporte comercial e entrega para todo o Brasil.';
    }
    if ($query !== '') {
        return 'Resultados de busca por "' . $query . '" nos produtos Vivaliz. Explore produtos com compra segura, suporte comercial e entrega para todo o Brasil.';
    }
    if ($category !== '') {
        return 'Explore ' . $category . ' na Vivaliz. ' . $countText . ' com compra segura, atendimento comercial e entrega para todo o Brasil.';
    }
    return 'Produtos Vivaliz com compra segura, suporte comercial e entrega para todo o Brasil. Explore rodízios, ferragens, utilidades e muito mais.';
}

function sv_catalog_structured_data(array $products, string $canonicalUrl, string $pageTitle, string $metaDescription): array
{
    $items = [];
    foreach (array_slice($products, 0, 12) as $index => $product) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'url' => sv_catalog_base_url() . sv_catalog_product_href($product),
            'name' => $product['name'],
            'image' => $product['image_url'],
        ];
    }

    return [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $pageTitle,
        'description' => $metaDescription,
        'url' => $canonicalUrl,
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => 'Vivaliz',
            'url' => sv_catalog_base_url() . '/',
        ],
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => count($products),
            'itemListElement' => $items,
        ],
    ];
}

function sv_catalog_website_schema(): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Vivaliz',
        'url' => sv_catalog_base_url() . '/',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => sv_catalog_base_url() . '/catalogo?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

function sv_catalog_faq_schema(string $category, string $query): array
{
    $scope = $category !== '' ? $category : ($query !== '' ? 'produtos encontrados' : 'produtos Vivaliz');
    return [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Os produtos do catálogo têm preço e estoque atualizados?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'O catálogo usa a origem canônica de produtos da ShopVivaliz e exibe preço e disponibilidade disponíveis no momento da consulta.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'A Vivaliz entrega ' . $scope . ' para todo o Brasil?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Sim. A ShopVivaliz calcula entrega por CEP no carrinho e atende pedidos para diferentes regiões do Brasil conforme transportadoras disponíveis.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Como encontrar o produto certo no catálogo?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Use a busca por nome, SKU, categoria, descrição ou característica do produto. Também é possível filtrar por categoria e abrir a página de detalhes antes da compra.',
                ],
            ],
        ],
    ];
}

$query        = sv_catalog_query();
$category     = trim((string)($_GET['categoria'] ?? ''));
$initialSort  = trim((string)($_GET['ordem'] ?? $_GET['sort'] ?? 'relevance')) ?: 'relevance';
if (!in_array($initialSort, ['relevance', 'price-asc', 'price-desc', 'name'], true)) {
    $initialSort = 'relevance';
}
$perPage      = 20;
$totalCount   = sv_catalog_count_matching($query, $category);
$totalPages   = max(1, (int)ceil($totalCount / $perPage));
$currentPage  = max(1, min($totalPages, (int)($_GET['pagina'] ?? 1)));
$offset       = ($currentPage - 1) * $perPage;
$products     = svp_enrich_products(sv_catalog_products($perPage, $query, $category, $offset));
$categories   = sv_catalog_categories();
$totalStr     = $totalCount . ' produto' . ($totalCount === 1 ? '' : 's');
$statusText = $products
    ? $totalStr . ($category !== '' ? " em \"{$category}\"" : '') . '.'
    : ($query !== '' ? 'Nenhum produto encontrado para essa busca.' : 'Explore nossas categorias ou fale com a equipe para localizar o item ideal.');

function sv_catalog_page_url(int $page, string $query, string $category): string
{
    $params = [];
    if ($query !== '') $params['q'] = $query;
    if ($category !== '') $params['categoria'] = $category;
    if ($page > 1) $params['pagina'] = $page;
    $qs = http_build_query($params);
    return '/catalogo' . ($qs !== '' ? '?' . $qs : '');
}
$pageTitle = sv_catalog_page_title($category, $query);
$metaDescription = sv_catalog_meta_description($category, $query, count($products));
$canonicalUrl = sv_catalog_canonical_url($category);
$structuredData = sv_catalog_structured_data($products, $canonicalUrl, $pageTitle, $metaDescription);
$websiteSchema = sv_catalog_website_schema();
$faqSchema = sv_catalog_faq_schema($category, $query);
$searchNoindex = $query !== '';
$svNavCurrent = 'catalogo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sv_catalog_esc($metaDescription) ?>">
    <meta name="theme-color" content="#173B63">
    <?php if ($searchNoindex): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <meta property="og:title" content="<?= sv_catalog_esc($pageTitle) ?>">
    <meta property="og:description" content="<?= sv_catalog_esc($metaDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= sv_catalog_esc($canonicalUrl) ?>">
    <meta property="og:site_name" content="Vivaliz">
    <meta property="og:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square-v2.png">
    <meta property="og:image:alt" content="ShopVivaliz - Catálogo de produtos">
    <meta property="og:locale" content="pt_BR">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= sv_catalog_esc($pageTitle) ?>">
    <meta name="twitter:description" content="<?= sv_catalog_esc($metaDescription) ?>">
    <meta name="twitter:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square-v2.png">
    <link rel="icon" href="/images/favicon.svg?v=2026-07-27" type="image/svg+xml">
    <link rel="icon" href="/favicon.png?v=2026-07-27" type="image/png">
    <link rel="alternate icon" href="/favicon.ico?v=2" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.png?v=2">
    <meta name="msapplication-TileColor" content="#173B63">
    <meta name="theme-color" content="#173B63">
    <link rel="canonical" href="<?= sv_catalog_esc($canonicalUrl) ?>">
    <title><?= sv_catalog_esc($pageTitle) ?></title>
    <style>body { opacity: 1 !important; visibility: visible !important; }</style>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/catalog-conversion-v4.css?v=2026-07-26-v4">
    <link rel="stylesheet" href="/css/first-purchase-popup-v1.css?v=2026-07-30-1">
    <link rel="stylesheet" href="/css/zoom-responsive.css?v=2026-07-26-1">
    <!-- Polimento de layout: precisa vir por ultimo para vencer na cascata. -->
    <link rel="stylesheet" href="/css/layout-polish-v1.css?v=2026-07-29-1">
    <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <script type="application/ld+json"><?= json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
    <link rel="stylesheet" href="/css/catalog-category-select-v1.css?v=2026-08-17-1">
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main class="catalog-page">
        <section class="catalog-header">
            <div class="container catalog-header-inner">
                <div>
                    <p class="eyebrow"><?= $category !== '' ? sv_catalog_esc($category) : 'Todos os produtos' ?></p>
                    <h1>Produtos Vivaliz</h1>
                    <p class="muted"><?= $statusText ?></p>
                </div>
                <form class="catalog-search" role="search" method="get" action="/catalogo">
                    <input id="catalog-search" name="q" type="search" aria-label="Buscar no catálogo" autocomplete="off" value="<?= sv_catalog_esc($query) ?>">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </section>

        <section class="container catalog-tools">
            <div class="sv-catalog-toolbar">
                <div class="sv-catalog-toolbar-left">
                    <label class="sv-category-select-wrap" for="catalog-category-select">
                        <span>Categoria</span>
                        <select id="catalog-category-select" aria-label="Filtrar produtos por categoria">
                            <option value=""<?= $category === '' ? ' selected' : '' ?>>Todas as categorias</option>
                            <?php foreach ($categories as $cat => $count): ?>
                                <option value="<?= sv_catalog_esc($cat) ?>"<?= $category === $cat ? ' selected' : '' ?>><?= sv_catalog_esc($cat) ?> (<?= $count ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label class="sv-sort-wrap">
                    <span>Ordenar por</span>
                    <select aria-label="Ordenar produtos">
                        <option value="relevance"<?= $initialSort === 'relevance' ? ' selected' : '' ?>>Relevância</option>
                        <option value="price-asc"<?= $initialSort === 'price-asc' ? ' selected' : '' ?>>Menor preço</option>
                        <option value="price-desc"<?= $initialSort === 'price-desc' ? ' selected' : '' ?>>Maior preço</option>
                        <option value="name"<?= $initialSort === 'name' ? ' selected' : '' ?>>Nome A–Z</option>
                    </select>
                </label>
            </div>
            <div class="category-filters" role="navigation" aria-label="Filtrar por categoria">
                <a class="cat-filter<?= $category === '' ? ' active' : '' ?>" data-category="" href="/catalogo">Todos</a>
                <?php foreach ($categories as $cat => $count): ?>
                    <a class="cat-filter<?= $category === $cat ? ' active' : '' ?>"
                       data-category="<?= sv_catalog_esc($cat) ?>"
                       href="/catalogo?categoria=<?= rawurlencode($cat) ?><?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>">
                        <?= sv_catalog_esc($cat) ?> <span class="cat-count"><?= $count ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div id="catalog-status" class="status-line"><?= sv_catalog_esc($statusText) ?></div>
            <div class="catalog-trust-strip" aria-label="Informações de confiança do catálogo" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span>Checkout protegido</span>
                </div>
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                    <span>Entrega calculada pelo CEP</span>
                </div>
                <div class="catalog-trust-item" style="display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                    <span>7 dias para solicitar devolução</span>
                </div>
            </div>
        </section>

        <section class="container product-grid" id="product-grid" aria-live="polite">
            <?php foreach ($products as $product): ?>
                <?php
                // ShopVivaliz nao opera com pre-venda/"consulte o valor": produto
                // sem preco real valido nao aparece na vitrine. Ver docs/AGENTS.md.
                if ((float)($product['price'] ?? 0) <= 0) {
                    continue;
                }
                $image      = $product['image_url'] !== '' ? $product['image_url'] : sv_catalog_default_image();
                $productUrl = sv_catalog_product_href($product);
                $contactUrl = sv_catalog_contact_url($product);
                $stock      = (int)($product['stock'] ?? 0);
                $hasPrice   = (float)$product['price'] > 0 && $stock > 0;
                $payload = rawurlencode(json_encode([
                    'sku'              => $product['sku'],
                    'name'             => $product['name'],
                    'image_url'        => $image,
                    'price'            => $product['price'],
                    'olist_product_id' => $product['olist_product_id'],
                    'stock'            => $stock,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?>
                <article class="product-card<?= $stock <= 0 ? ' is-out-of-stock' : '' ?>">
                    <?php $cardImages = array_values(array_unique(array_filter(array_merge([$image], is_array($product['images'] ?? null) ? $product['images'] : [])))); ?>
                    <a class="product-image" href="<?= sv_catalog_esc($productUrl) ?>" data-images="<?= sv_catalog_esc(json_encode(array_slice($cardImages, 0, 10), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
                        <img src="<?= sv_catalog_esc($image) ?>" alt="<?= sv_catalog_esc($product['name']) ?>" width="400" height="400" loading="lazy" decoding="async" onerror="this.src='<?= sv_catalog_default_image() ?>'">
                        <?php if ($stock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                    </a>
                    <div class="product-info">
                        <?php if ($product['category'] !== ''): ?>
                            <div class="product-category"><?= sv_catalog_esc($product['category']) ?></div>
                        <?php endif; ?>
                        <h2><?= sv_catalog_esc($product['name']) ?></h2>
                        <div class="product-price-wrap" style="display:flex; flex-direction:column; gap:2px; margin: 6px 0;">
                            <div class="product-price" style="font-size: 1.15rem; font-weight: 800; color: #0f172a;"><?= sv_catalog_esc(sv_catalog_money((float)$product['price'])) ?></div>
                            <?php if ((float)$product['price'] > 0): ?>
                                <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                                    <span>Opções e condições de pagamento no checkout</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($product['tags'])): ?>
                            <div class="product-tags">
                                <?php foreach (array_slice($product['tags'], 0, 3) as $tag): ?>
                                    <span class="tag"><?= sv_catalog_esc($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="card-actions">
                            <a class="btn btn-secondary card-link" href="<?= sv_catalog_esc($productUrl) ?>">Ver detalhes</a>
                            <?php if ($hasPrice): ?>
                                <button class="buy-button" type="button" data-product="<?= sv_catalog_esc($payload) ?>">Comprar agora</button>
                            <?php elseif ($stock <= 0): ?>
                                <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                            <?php else: ?>
                                <a class="btn btn-primary card-link" href="<?= sv_catalog_esc($contactUrl) ?>">Falar com vendas</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($totalPages > 1): ?>
        <nav class="container catalog-pagination" aria-label="Paginação do catálogo" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:30px 0;">
            <?php if ($currentPage > 1): ?>
                <a class="btn btn-secondary" href="<?= sv_catalog_esc(sv_catalog_page_url($currentPage - 1, $query, $category)) ?>">&laquo; Anterior</a>
            <?php endif; ?>
            <span class="muted">Página <?= $currentPage ?> de <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn btn-secondary" href="<?= sv_catalog_esc(sv_catalog_page_url($currentPage + 1, $query, $category)) ?>">Próxima &raquo;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="/autodev/client.js"></script>
    <script src="/js/catalog.js?v=<?= filemtime(__DIR__ . '/js/catalog.js') ?: '1' ?>"></script>
    <script src="/js/catalog-conversion-v4.js?v=<?= filemtime(__DIR__ . '/js/catalog-conversion-v4.js') ?: '1' ?>"></script>
    <script src="/js/first-purchase-popup-v1.js?v=2026-07-30-1" defer></script>
    <script>
    (function(){
        try {
            var cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            var count = cart.reduce(function(a,i){ return a+(i.quantity||1); }, 0);
            var badge = document.getElementById('nav-cart-count');
            if (badge) badge.textContent = count > 0 ? count : '';
        } catch(e){}
    })();
    </script>
    <script src="/js/auto-image-carousel.js?v=20260811-1"></script>
</body>
</html>
