<?php
declare(strict_types=1);

$t0 = microtime(true);
$timings = [];
$timings['start'] = 0.0;

// Visitantes anonimos nao precisam criar uma sessao PHP. Criar PHPSESSID na
// home impedia cache compartilhado e mantinha locks de sessao sem necessidade.
// Se o navegador ja possui a sessao (usuario autenticado), ela e retomada antes
// de qualquer output para preservar o estado de login no navbar.
$svHasSessionCookie = isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] !== '';
if ($svHasSessionCookie && session_status() === PHP_SESSION_NONE) {
    session_start();
}
$timings['session_start'] = microtime(true) - $t0;

// Cache curto apenas para anonimos. Preco/estoque sao revalidados de forma
// autoritativa no carrinho/checkout; 30 s aqui reduzem carga sem comprometer
// a seguranca comercial. Usuarios com sessao continuam sem cache compartilhado.
if ($svHasSessionCookie) {
    header('Cache-Control: private, no-cache, must-revalidate');
} else {
    header('Cache-Control: public, max-age=15, s-maxage=30, stale-while-revalidate=60');
    header('Vary: Cookie', false);
}

require_once __DIR__ . '/config/bootstrap-env.php';
$timings['bootstrap_env'] = microtime(true) - $t0;

// Configuração Dinâmica de Ambiente
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br';
define('BASE_URL', $scheme . '://' . $host);
define('APP_NAME', 'ShopVivaliz');
require_once __DIR__ . '/includes/product-price-enrich.php';
$timings['price_enrich'] = microtime(true) - $t0;
require_once __DIR__ . '/includes/catalog-runtime.php';
$timings['catalog_runtime'] = microtime(true) - $t0;
require_once __DIR__ . '/includes/ml-ranking.php';
$timings['ml_ranking'] = microtime(true) - $t0;
require_once __DIR__ . '/includes/site-settings.php';
$timings['site_settings'] = microtime(true) - $t0;
require_once __DIR__ . '/includes/popup-cupons.php';
$timings['popup_cupons'] = microtime(true) - $t0;
$svFreeShipping = sv_free_shipping_config();
$timings['free_shipping'] = microtime(true) - $t0;

function sv_home_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_home_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function sv_home_default_image(): string
{
    return '/images/logo-vivaliz-square-v2.png';
}

function sv_home_pick_best_image(array $row): string
{
    $candidates = [];
    foreach ([
        $row['image_url'] ?? '',
        $row['image'] ?? '',
        $row['imagem_principal_url'] ?? '',
        $row['primary_image_url'] ?? '',
    ] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }
    foreach (['images', 'imagens', 'gallery', 'galeria'] as $field) {
        if (!is_array($row[$field] ?? null)) continue;
        foreach ($row[$field] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    $candidates = array_values(array_unique($candidates));
    if ($candidates === []) {
        return '';
    }

    $score = static function (string $url): int {
        $u = strtolower($url);
        $bad = 0;
        foreach (['thumb', 'thumbnail', 'small', 'mini', 'preview', 'placeholder', 'default'] as $needle) {
            if (str_contains($u, $needle)) {
                $bad += 10;
            }
        }
        if (preg_match('/[?&](w|width|h|height)=([0-9]+)/', $u, $m)) {
            $size = (int)$m[2];
            $bad += $size > 0 && $size < 600 ? 8 : 0;
        }
        if (preg_match('/(?:_|-)(\d{2,3})x(\d{2,3})(?:[._-]|$)/', $u, $m)) {
            $bad += ((int)$m[1] < 600 || (int)$m[2] < 600) ? 8 : 0;
        }
        if (preg_match('/(?:_|-)(\d{3,4})(?:[._-]|$)/', $u, $m)) {
            $bad += ((int)$m[1] < 700) ? 4 : 0;
        }
        if (str_contains($u, 'cloudinary') || str_contains($u, 'imgix') || str_contains($u, 'cdn')) {
            $bad -= 2;
        }
        return $bad;
    };

    usort($candidates, static fn(string $a, string $b): int => $score($a) <=> $score($b));
    return $candidates[0] ?? '';
}

function sv_home_catalog_source_rows(): array
{
    static $localCache = null;
    if ($localCache !== null) {
        return $localCache;
    }

    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_home_catalog_source_rows_v1';
    $fileCache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-home-catalog-source-v1.json';
    if (!$apcu && is_file($fileCache) && (time() - (int)@filemtime($fileCache)) <= 30) {
        $cached = json_decode((string)@file_get_contents($fileCache), true);
        if (is_array($cached) && $cached !== []) {
            $localCache = $cached;
            return $localCache;
        }
    }
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            $localCache = $stored;
            return $localCache;
        }
    }

    $runtime = svcr_products();
    $csvPath = __DIR__ . '/uploads/olist_imagens_site_mapeamento.csv';

    // O runtime de catalogo e autoritativo para preco/estoque, mas nem sempre
    // carrega as URLs de imagem. Enriquece os produtos ativos pelo SKU usando
    // o mapeamento persistente de imagens, sem substituir dados comerciais.
    if ($runtime !== [] && is_file($csvPath) && is_readable($csvPath)) {
        $imageMap = [];
        $handle = fopen($csvPath, 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            if (is_array($header)) {
                while (($line = fgetcsv($handle)) !== false) {
                    if (!is_array($line) || $line === []) continue;
                    $assoc = [];
                    foreach ($header as $i => $column) {
                        $key = trim((string)$column);
                        if ($key !== '') $assoc[$key] = $line[$i] ?? '';
                    }
                    $sku = strtoupper(trim((string)($assoc['sku'] ?? '')));
                    if ($sku === '') continue;
                    $url = trim((string)($assoc['site_url'] ?? ''));
                    if ($url === '') $url = trim((string)($assoc['original_url_olist'] ?? ''));
                    if ($url === '') continue;
                    if (!isset($imageMap[$sku])) $imageMap[$sku] = ['primary' => '', 'images' => []];
                    if (!in_array($url, $imageMap[$sku]['images'], true)) $imageMap[$sku]['images'][] = $url;
                    $isPrimary = strtolower(trim((string)($assoc['is_primary'] ?? '')));
                    if ($imageMap[$sku]['primary'] === '' && in_array($isPrimary, ['1','true','yes','sim'], true)) {
                        $imageMap[$sku]['primary'] = $url;
                    }
                }
            }
            fclose($handle);
        }

        foreach ($runtime as &$row) {
            if (!is_array($row)) continue;
            $sku = strtoupper(trim((string)($row['sku'] ?? '')));
            if ($sku === '' || !isset($imageMap[$sku])) continue;
            $mapped = $imageMap[$sku];
            $current = trim((string)($row['image_url'] ?? ''));
            $isWeak = $current === '' || preg_match('/placeholder|default|no[-_ ]?image|sem[-_ ]?imagem/i', $current);
            if ($isWeak) {
                $row['image_url'] = $mapped['primary'] !== '' ? $mapped['primary'] : ($mapped['images'][0] ?? '');
            }
            $existing = is_array($row['images'] ?? null) ? $row['images'] : [];
            $row['images'] = array_values(array_unique(array_filter(array_merge($existing, $mapped['images']))));
        }
        unset($row);
        
        $localCache = $runtime;
        if ($apcu) {
            apcu_store($apcuKey, $localCache, 300);
        } else {
            $encoded = json_encode($localCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $tmpCache = $fileCache . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmpCache, $encoded, LOCK_EX) !== false) {
                    @rename($tmpCache, $fileCache);
                } else {
                    @unlink($tmpCache);
                }
            }
        }
        return $localCache;
    }

    if ($runtime !== []) {
        $localCache = $runtime;
        if ($apcu) {
            apcu_store($apcuKey, $localCache, 300);
        } else {
            $encoded = json_encode($localCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $tmpCache = $fileCache . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmpCache, $encoded, LOCK_EX) !== false) {
                    @rename($tmpCache, $fileCache);
                } else {
                    @unlink($tmpCache);
                }
            }
        }
        return $localCache;
    }

    // Sem a fonte canonica de produtos ativos, a vitrine falha fechada. CSV e
    // snapshots historicos podem enriquecer imagem/conteudo, nunca provar que
    // um item ainda pode ser vendido.
    return [];
}

function sv_home_latest_sales_rows(): array
{
    $directory = __DIR__ . '/storage/reports';
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob($directory . '/tiny-sales-ranking-*.json') ?: [];
    if ($files === []) {
        return [];
    }

    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $decoded = json_decode((string)file_get_contents($files[0]), true);
    $rows = is_array($decoded) && is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];

    return array_values(array_filter($rows, 'is_array'));
}

function sv_home_sales_rank_map(): array
{
    $map = [];
    foreach (sv_home_latest_sales_rows() as $index => $row) {
        $score = (float)($row['quantity'] ?? 0) * 100000
            + (float)($row['orders'] ?? 0) * 1000
            + (float)($row['revenue'] ?? 0);
        if ($score <= 0) {
            continue;
        }
        foreach (['sku', 'product_id', 'olist_product_id', 'id'] as $field) {
            $key = strtoupper(trim((string)($row[$field] ?? '')));
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = ['score' => $score, 'position' => $index + 1];
            }
        }
    }

    return $map;
}

function sv_home_money(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Consulte o valor';
}

function sv_home_slugify(string $name, string $sku): string
{
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $base = strtr(sv_home_lower($name), $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

function sv_home_product_url(array $product): string
{
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $slug = trim((string)($product['slug'] ?? '')) ?: ($sku !== '' && $name !== '' ? sv_home_slugify($name, $sku) : '');
    if ($slug !== '') {
        return '/produto/' . $slug;
    }

    return '/produto?' . http_build_query([
        'sku' => $sku,
        'name' => $name,
        'image' => (string)($product['image_url'] ?? ''),
        'price' => (string)($product['price'] ?? 0),
        'olist_product_id' => (string)($product['olist_product_id'] ?? ''),
    ]);
}

function sv_home_contact_url(array $product): string
{
    return '/contato?' . http_build_query([
        'sku' => (string)($product['sku'] ?? ''),
        'produto' => (string)($product['name'] ?? ''),
    ]);
}

function sv_home_featured_products(int $limit = 8): array
{
    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_home_featured_products_v2_' . $limit;
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            return $stored;
        }
    }

    $products = [];
    $salesRank = sv_home_sales_rank_map();
    foreach (sv_home_catalog_source_rows() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $image = sv_home_pick_best_image($row);
        if ($image === '') {
            continue;
        }

        $images = is_array($row['images'] ?? null) ? $row['images'] : [];
        $sku = trim((string)($row['sku'] ?? (string)($row['id'] ?? '')));
        $olistProductId = (string)($row['olist_product_id'] ?? $row['id'] ?? '');
        $rank = $salesRank[strtoupper($sku)] ?? $salesRank[strtoupper($olistProductId)] ?? null;

        $products[] = [
            'sku' => $sku,
            'name' => trim((string)($row['name'] ?? 'Produto Vivaliz')),
            'image_url' => $image,
            'images' => array_slice(array_filter($images), 0, 10),
            'price' => (float)($row['price'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'olist_product_id' => $olistProductId,
            'category' => trim((string)($row['category'] ?? '')),
            'description' => trim((string)($row['description'] ?? '')),
            'brand' => trim((string)($row['brand'] ?? '')),
            'slug' => trim((string)($row['slug'] ?? '')),
            'sales_score' => (float)($rank['score'] ?? 0),
            'sales_position' => (int)($rank['position'] ?? 999999),
            'ml_score' => sv_ml_score($row),
        ];
    }

    $products = svp_enrich_products($products);
    usort($products, static function (array $a, array $b): int {
        $aPurchasable = ((float)($a['price'] ?? 0) > 0 && (int)($a['stock'] ?? 0) > 0) ? 1 : 0;
        $bPurchasable = ((float)($b['price'] ?? 0) > 0 && (int)($b['stock'] ?? 0) > 0) ? 1 : 0;
        if ($aPurchasable !== $bPurchasable) {
            return $bPurchasable <=> $aPurchasable;
        }

        $aMl = $a['ml_score'] ?? null;
        $bMl = $b['ml_score'] ?? null;
        if ($aMl !== null || $bMl !== null) {
            $mlCompare = (float)($bMl ?? -1) <=> (float)($aMl ?? -1);
            if ($mlCompare !== 0) return $mlCompare;
        }

        $scoreCompare = (float)($b['sales_score'] ?? 0) <=> (float)($a['sales_score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $stockCompare = (int)($b['stock'] ?? 0) <=> (int)($a['stock'] ?? 0);
        if ($stockCompare !== 0) {
            return $stockCompare;
        }

        return (float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0);
    });

    $sliced = array_slice($products, 0, $limit);
    if ($apcu) {
        apcu_store($apcuKey, $sliced, 300);
    }
    return $sliced;
}

function sv_home_catalog_count(): int
{
    // sv_home_catalog_source_rows() monta a lista completa enriquecida
    // (imagens, ranking, ml_score) só pra fins de exibição do catálogo --
    // caro demais pra rodar só pra contar. O total de produtos ativos muda
    // pouco (sync do Tiny roda a cada poucas horas), então cacheia o
    // COUNT isoladamente com TTL bem mais longo que o cache dos rows.
    static $localCache = null;
    if ($localCache !== null) {
        return $localCache;
    }

    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_home_catalog_count_v1';
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_int($stored)) {
            $localCache = $stored;
            return $localCache;
        }
    }

    // svcr_products() já é o dado bruto autoritativo (sem o custo extra de
    // enriquecimento de imagem/ranking que sv_home_catalog_source_rows faz).
    $count = count(svcr_products());
    $localCache = $count;
    if ($apcu) {
        apcu_store($apcuKey, $count, 1800);
    }
    return $count;
}

function sv_home_banners(): array
{
    return [
        [
            'alt' => 'Banner Vivaliz com 3% OFF automatico em carrinho com 2 ou mais produtos diferentes',
            'image' => '/public/assets/home-banners/banner-primeira-compra.webp',
            'tag' => 'OFERTA EXCLUSIVA',
            'title' => 'Tudo o que você precisa.',
            'subtitle' => 'Leve 2 ou mais produtos diferentes e ganhe 3% OFF automatico no carrinho.',
            'primary' => ['label' => 'Ver produtos', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Falar com vendas', 'href' => '/contato'],
        ],
        [
            'alt' => 'Banner Vivaliz para casa, jardim e organização',
            'image' => '/public/assets/home-banners/banner-casa-estilo.webp',
            'tag' => 'COLEÇÃO 2026',
            'title' => 'Renove o seu espaço.',
            'subtitle' => 'Ferramentas de alta precisão e organização inteligente para uma casa impecável.',
            'primary' => ['label' => 'Ver Coleção', 'href' => '/catalogo'],
            'secondary' => ['label' => 'Abrir contato', 'href' => '/contato'],
        ],
    ];
}

function sv_home_category_icon(string $category): string
{
    // Mapeia categorias para classes CSS ou ícones SVG
    $map = [
        'ferrament' => '/public/assets/category-images/cat-ferramentas.jpg',
        'rodízio' => '/public/assets/category-images/cat-rodizios.jpg',
        'rodizio' => '/public/assets/category-images/cat-rodizios.jpg',
        'jardim' => '/public/assets/category-images/cat-jardim.jpg',
        'floreira' => '/public/assets/category-images/cat-jardim.jpg',
        'banheiro' => '/public/assets/category-images/cat-organizacao.jpg',
        'cozinha' => '/public/assets/category-images/cat-organizacao.jpg',
        'automotiv' => '/public/assets/category-images/cat-ferramentas.jpg',
        'elétric' => '/public/assets/category-images/cat-ferramentas.jpg',
        'eletric' => '/public/assets/category-images/cat-ferramentas.jpg',
        'cadeado' => '/public/assets/category-images/cat-ferragens.jpg',
        'segurança' => '/public/assets/category-images/cat-ferragens.jpg',
        'seguranca' => '/public/assets/category-images/cat-ferragens.jpg',
        'armário' => '/public/assets/category-images/cat-organizacao.jpg',
        'armario' => '/public/assets/category-images/cat-organizacao.jpg',
        'organiza' => '/public/assets/category-images/cat-organizacao.jpg',
        'fixação' => '/public/assets/category-images/cat-ferragens.jpg',
        'fixacao' => '/public/assets/category-images/cat-ferragens.jpg',
        'ferragem' => '/public/assets/category-images/cat-ferragens.jpg',
        'caixa' => '/public/assets/category-images/cat-organizacao.jpg',
        'limpeza' => '/public/assets/category-images/cat-organizacao.jpg',
        'utilidade' => '/public/assets/category-images/cat-organizacao.jpg',
        'pintura' => '/public/assets/category-images/cat-ferramentas.jpg',
        'construção' => '/public/assets/category-images/cat-ferragens.jpg',
        'construcao' => '/public/assets/category-images/cat-ferragens.jpg',
        'pet' => '/public/assets/category-images/cat-jardim.jpg',
        'vaso' => '/public/assets/category-images/cat-jardim.jpg',
        'roda' => '/public/assets/category-images/cat-rodizios.jpg',
    ];
    foreach ($map as $needle => $img_url) {
        if (stripos($category, $needle) !== false) {
            return $img_url;
        }
    }
    return '/public/assets/category-images/cat-organizacao.jpg';
}

function sv_home_top_categories(int $limit = 8): array
{
    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_home_top_categories_v2_' . $limit;
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            return $stored;
        }
    }

    $counts = [];
    $categoryImages = [];
    foreach (sv_home_catalog_source_rows() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $category = trim((string)($row['category'] ?? ''));
        if ($category === '') {
            continue;
        }
        $counts[$category] = ($counts[$category] ?? 0) + 1;
        if (!isset($categoryImages[$category])) {
            $categoryImages[$category] = [];
        }
        $image = sv_home_pick_best_image($row);
        if ($image !== '' && !in_array($image, $categoryImages[$category], true)) {
            $categoryImages[$category][] = $image;
        }
        if (is_array($row['images'] ?? null)) {
            foreach ($row['images'] as $additional) {
                $additional = trim((string)$additional);
                if ($additional !== '' && !in_array($additional, $categoryImages[$category], true)) {
                    $categoryImages[$category][] = $additional;
                    if (count($categoryImages[$category]) >= 8) break;
                }
            }
        }
    }

    $result = [];
    if (!empty($counts)) {
        arsort($counts);
        foreach ($counts as $category => $count) {
            $localIcon = sv_home_category_icon($category);
            $preferLocal = false;
            foreach (['ferrament', 'rodízio', 'rodizio', 'rodas', 'jardim', 'organiza', 'ferragem', 'fixação', 'fixacao'] as $curatedNeedle) {
                if (stripos($category, $curatedNeedle) !== false) {
                    $preferLocal = true;
                    break;
                }
            }
            $catImgList = $categoryImages[$category] ?? [];
            $productImage = trim((string)($catImgList[0] ?? ''));
            $icon = ($preferLocal && str_starts_with($localIcon, '/public/assets/category-images/'))
                ? $localIcon
                : ($productImage !== '' ? $productImage : $localIcon);

            $carouselImages = array_values(array_unique(array_filter(array_merge([$icon], $catImgList))));

            $result[] = [
                'name' => $category,
                'count' => $count,
                'icon' => $icon,
                'images' => array_slice($carouselImages, 0, 8),
                'href' => '/catalogo?categoria=' . rawurlencode($category),
            ];
            if (count($result) >= $limit) break;
        }
    }

    $sliced = array_slice($result, 0, $limit);
    if ($apcu) {
        apcu_store($apcuKey, $sliced, 300);
    }
    return $sliced;
}

$layoutLoaderFile = __DIR__ . '/includes/layout-loader.php';
if (is_file($layoutLoaderFile)) {
    require_once $layoutLoaderFile;
}
$homeItemsPerPage = function_exists('sv_get_products_config')
    ? (int)(sv_get_products_config()['itemsPerPage'] ?? 8)
    : 8;
$featuredProducts = sv_home_featured_products($homeItemsPerPage > 0 ? $homeItemsPerPage : 8);
$timings['featured_products_load'] = microtime(true) - $t0;
$featuredProductsCount = count($featuredProducts);
$catalogCount = sv_home_catalog_count();
$timings['catalog_count_load'] = microtime(true) - $t0;
$heroBanners = sv_home_banners();
$timings['hero_banners_load'] = microtime(true) - $t0;
$homeCategories = sv_home_top_categories(10);
$timings['home_categories_load'] = microtime(true) - $t0;
$svNavCurrent = '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Rodízios, ferragens, ferramentas e utilidades para reparar e organizar a casa. Consulte preço, estoque e frete por CEP na ShopVivaliz.">
    <meta name="theme-color" content="#173B63">
    <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">
    <meta property="og:title" content="Vivaliz | Rodízios, Ferragens e Utilidades Domésticas">
    <meta property="og:description" content="Rodízios, ferragens, ferramentas e utilidades com preço e estoque atualizados e frete calculado por CEP.">
    <meta property="og:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square-v2.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://shopvivaliz.com.br/">
    <meta property="og:site_name" content="ShopVivaliz">
    <meta property="og:image:alt" content="ShopVivaliz - Loja online">
    <meta property="og:locale" content="pt_BR">
    <!-- As fotos de produto da vitrine sao servidas principalmente pelos
         anexos do ERP Tiny; antecipa somente a origem efetivamente usada. -->
    <link rel="preconnect" href="https://s3.amazonaws.com" crossorigin>
    <link rel="dns-prefetch" href="https://s3.amazonaws.com">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Vivaliz | Rodízios, Ferragens e Utilidades Domésticas">
    <meta name="twitter:description" content="Rodízios, ferragens, ferramentas e utilidades com preço e estoque atualizados e frete calculado por CEP.">
    <meta name="twitter:image" content="https://shopvivaliz.com.br/images/logo-vivaliz-square-v2.png">
    <link rel="canonical" href="https://shopvivaliz.com.br/">
    <link rel="icon" href="/images/favicon.svg?v=2026-07-27" type="image/svg+xml">
    <link rel="icon" href="/favicon.png?v=2026-07-27" type="image/png">
    <link rel="alternate icon" href="/favicon.ico?v=2026-07-27" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.png?v=2026-07-27">
    <meta name="msapplication-TileColor" content="#173B63">
    <meta name="theme-color" content="#173B63">

    <title>Vivaliz | Rodízios, Ferragens e Utilidades Domésticas</title>

    <?php
        // Bundle consolidado (2026-08-05): substitui 9 <link>/arquivos CSS
        // separados (core, premium, inline-to-classes, webp-optimization,
        // zoom-responsive, layout-polish-v1, home-mobile-compact,
        // home-mobile-final, visual-polish-v4) por UMA única resposta HTTP,
        // na mesma ordem de antes. Ver includes/asset-bundle-manifest.php e
        // css/home-bundle.php.
        // Não inclui visual-polish-v5/v6/hotfix/audit/accessibility/cls —
        // esses continuam individuais em includes/load-custom-css.php
        // porque carregam DEPOIS do CSS customizado do admin de propósito.
        require_once __DIR__ . '/includes/asset-bundle-manifest.php';
        $svHomeBundleVersion = sv_home_css_bundle_version(__DIR__);
    ?>
    <link rel="stylesheet" href="/css/home-bundle.php?v=<?= htmlspecialchars($svHomeBundleVersion, ENT_QUOTES, 'UTF-8') ?>">
    <style>
      .hero-cta .btn,
      .hero-cta .btn:visited {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 48px;
        font-weight: 800;
        text-decoration: none;
      }
      .hero-cta .btn-hero-primary,
      .hero-cta .btn-hero-primary:visited {
        background: #ffffff !important;
        color: #0b4f88 !important;
        border: 1px solid rgba(11,79,136,.16) !important;
      }
      .hero-cta .btn-hero-secondary,
      .hero-cta .btn-hero-secondary:visited {
        background: rgba(255,255,255,.14) !important;
        color: #ffffff !important;
        border: 1px solid rgba(255,255,255,.34) !important;
      }
      .hero-cta .btn-hero-primary:hover,
      .hero-cta .btn-hero-secondary:hover {
        filter: brightness(1.04);
      }
      .section-heading .btn.btn-secondary,
      .section-heading .btn.btn-secondary:visited {
        background: #edf4fb !important;
        color: #173b63 !important;
        border: 1px solid rgba(23,59,99,.14) !important;
      }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebSite",
          "name": "Vivaliz",
          "url": "https://shopvivaliz.com.br",
          "potentialAction": {
            "@type": "SearchAction",
            "target": {
              "@type": "EntryPoint",
              "urlTemplate": "https://shopvivaliz.com.br/catalogo?busca={search_term_string}"
            },
            "query-input": "required name=search_term_string"
          }
        },
        {
          "@type": "Store",
          "name": "Vivaliz",
          "description": "Rodízios e rodas para móveis, caixas de ferramentas, ferragens, vasos decorativos e utilidades domésticas com entrega para todo o Brasil.",
          "url": "https://shopvivaliz.com.br",
          "image": "https://shopvivaliz.com.br/images/logo-vivaliz-square-v2.png",
          "telephone": "+55-37-99937-4112",
          "priceRange": "$$",
          "address": {
            "@type": "PostalAddress",
            "streetAddress": "RUA CAMPINA VERDE, 841",
            "addressLocality": "Divinópolis",
            "addressRegion": "MG",
            "postalCode": "35501-236",
            "addressCountry": "BR"
          }
        },
        {
          "@type": "ItemList",
          "name": "Produtos em Destaque",
          "itemListElement": [
            <?php 
              $seoItems = [];
              $position = 1;
              foreach (array_slice($featuredProducts ?? [], 0, 10) as $p) {
                  $img = trim((string)($p['image_url'] ?? ''));
                  $url = 'https://shopvivaliz.com.br' . sv_home_product_url($p);
                  $pSku = htmlspecialchars((string)($p['sku'] ?? 'sem-sku'), ENT_QUOTES);
                  $pDesc = htmlspecialchars(preg_replace('/\s+/', ' ', trim(strip_tags((string)($p['description'] ?? '')))), ENT_QUOTES);
                  if ($pDesc === '') {
                      $pDesc = 'Consulte informações, preço, estoque e frete deste produto na ShopVivaliz.';
                  }
                  $seoItems[] = '{
                    "@type": "ListItem",
                    "position": ' . $position++ . ',
                    "item": {
                      "@type": "Product",
                      "name": "' . htmlspecialchars((string)$p['name'], ENT_QUOTES) . '",
                      "url": "' . $url . '",
                      "image": "' . $img . '",
                      "sku": "' . $pSku . '",
                      "mpn": "' . $pSku . '",
                      "description": "' . $pDesc . '",
                      "offers": {
                        "@type": "Offer",
                        "url": "' . $url . '",
                        "priceCurrency": "BRL",
                        "price": "' . (float)($p['price'] ?? 0) . '",
                        "priceValidUntil": "' . date('Y-12-31') . '",
                        "availability": "https://schema.org/' . (($p['stock'] ?? 0) > 0 ? 'InStock' : 'OutOfStock') . '"
                      }
                    }
                  }';
              }
              echo implode(",\n            ", $seoItems);
            ?>
          ]
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {
              "@type": "ListItem",
              "position": 1,
              "name": "Home",
              "item": "https://shopvivaliz.com.br"
            },
            {
              "@type": "ListItem",
              "position": 2,
              "name": "Produtos",
              "item": "https://shopvivaliz.com.br/catalogo"
            }
          ]
        },
        {
          "@type": "FAQPage",
          "mainEntity": [
            {
              "@type": "Question",
              "name": "Qual é o prazo de entrega?",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "O prazo de entrega varia conforme a região e a transportadora disponível para o seu CEP. O prazo exato e o valor do frete são calculados no carrinho antes da finalização da compra."
              }
            },
            {
              "@type": "Question",
              "name": "Como funciona a política de devolução?",
              "acceptedAnswer": {
                "@type": "Answer",
              "text": "Para compras online, o consumidor pode solicitar o direito de arrependimento em até 7 dias corridos após o recebimento. O atendimento orienta o procedimento conforme a Política de Trocas e Devoluções."
              }
            },
            {
              "@type": "Question",
              "name": "Quais são as formas de pagamento aceitas?",
              "acceptedAnswer": {
                "@type": "Answer",
              "text": "As formas de pagamento e o eventual parcelamento disponíveis são exibidos no checkout antes da confirmação do pedido."
              }
            },
            {
              "@type": "Question",
              "name": "Posso rastrear meu pedido?",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "Sim! Assim que o pedido for despachado, você receberá por e-mail o código de rastreamento para acompanhar sua entrega."
              }
            }
          ]
        }
      ]
    }
    </script>

    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
    <?php require_once __DIR__ . '/includes/head-analytics.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main id="main-content">

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <?php if ($svFreeShipping['enabled'] && $svFreeShipping['threshold'] > 0): ?>
                <div class="hero-free-shipping-badge">
                    🚚 Frete Grátis acima de R$ <?= number_format($svFreeShipping['threshold'], 0, ',', '.') ?>
                </div>
                <?php endif; ?>
                <p class="eyebrow hero-kicker">
                    Loja online de ferragens e utilidades
                </p>
                <h1>Encontre a peça certa para <span class="gradient-word">reparar, organizar e transformar</span></h1>
                <p>Rodízios, ferragens, ferramentas e utilidades com preço e estoque atualizados. Consulte o frete pelo CEP e tire dúvidas com a equipe antes de comprar.</p>

                <!-- Premium E-Commerce Search Bar -->
                <div class="hero-search-container">
                    <form action="/catalogo" method="GET" class="hero-search-form">
                        <label for="hero-search-input" class="sr-only">Buscar produtos</label>
                        <span class="hero-search-icon">🔍</span>
                        <input id="hero-search-input" type="text" name="q" placeholder="O que você está procurando hoje? Ex: rodízios, ferramentas..." required>
                        <button type="submit">Buscar</button>
                    </form>
                </div>

                <div class="cta-buttons hero-cta hero-cta-mt-24">
                    <a href="/catalogo" class="btn btn-hero-primary">
                        Ver produtos
                    </a>
                    <a href="/contato" class="btn btn-hero-secondary">
                        Falar com a equipe
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Bar -->
    <div class="trust-bar" role="list" aria-label="Diferenciais da ShopVivaliz">
        <div class="trust-bar-inner">
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon color-accent-green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Conexão protegida</strong>
                    <span>Site em HTTPS e pagamento processado por parceiros</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon color-accent-green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Frete antes de comprar</strong>
                    <span>Valor e prazo calculados pelo seu CEP</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon color-accent-green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Pagamento transparente</strong>
                    <span>Opções disponíveis aparecem no checkout</span>
                </div>
            </div>
            <div class="trust-bar-item" role="listitem">
                <span class="trust-bar-icon color-accent-green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>
                </span>
                <div class="trust-bar-text">
                    <strong>Direito de arrependimento</strong>
                    <span>Solicitação em até 7 dias após o recebimento</span>
                </div>
            </div>
        </div>
    </div>

    <section class="hero-carousel-section">
        <div class="container">
            <div class="hero-carousel" id="hero-carousel" aria-label="Banners em destaque">
                <div class="hero-carousel-track">
                    <?php foreach ($heroBanners as $index => $banner): ?>
                        <article class="hero-slide hero-image-slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide="<?= $index ?>" style="position:relative;">
                            <img src="<?= sv_home_esc($banner['image']) ?>" alt="<?= sv_home_esc($banner['alt']) ?>" class="hero-banner-image" width="1200" height="480" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" <?= $index === 0 ? 'fetchpriority="high"' : 'fetchpriority="low"' ?> decoding="async" style="width:100%;height:100%;object-fit:cover;">
                            <div class="hero-overlay banner-overlay">
                                <?php if (!empty($banner['tag'])): ?>
                                    <span class="banner-tag color-accent-green"><?= sv_home_esc($banner['tag']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($banner['title'])): ?>
                                    <h2 class="banner-title"><?= sv_home_esc($banner['title']) ?></h2>
                                <?php endif; ?>
                                <?php if (!empty($banner['subtitle'])): ?>
                                    <p class="banner-subtitle color-text-muted"><?= sv_home_esc($banner['subtitle']) ?></p>
                                <?php endif; ?>
                                <div class="banner-cta-container">
                                    <a href="<?= sv_home_esc($banner['primary']['href']) ?>" class="btn btn-primary btn-cta-green"><?= sv_home_esc($banner['primary']['label']) ?></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="hero-carousel-controls" aria-label="Controles do banner">
                    <button type="button" class="hero-carousel-arrow" data-dir="-1" aria-label="Banner anterior">‹</button>
                    <div class="hero-carousel-dots">
                        <?php foreach ($heroBanners as $index => $banner): ?>
                            <button type="button" class="hero-carousel-dot<?= $index === 0 ? ' is-active' : '' ?>" data-dot="<?= $index ?>" aria-label="Ir para banner <?= $index + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="hero-carousel-arrow" data-dir="1" aria-label="Próximo banner">›</button>
                </div>
            </div>
        </div>
    </section>

    <section class="home-categories home-products">
        <div class="container">
            <div class="section-heading">
                <div>
                    <h2>Categorias em destaque</h2>
                    <p class="muted">Navegue por linhas reais do catálogo com acesso rápido.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver catálogo</a>
            </div>

            <?php if ($homeCategories): ?>
                <div class="home-scroller" data-scroller>
                    <button type="button" class="home-scroller-arrow" data-dir="-1" aria-label="Categorias anteriores">‹</button>
                    <div class="home-scroller-track categories-track">
                        <?php foreach ($homeCategories as $category): ?>
                            <?php $catImagesJson = json_encode($category['images'] ?? [$category['icon']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                            <a class="category-slide" href="<?= sv_home_esc($category['href']) ?>">
                                <div class="category-slide-image-wrapper" data-images="<?= sv_home_esc($catImagesJson) ?>">
                                    <img src="<?= sv_home_esc($category['icon']) ?>" alt="<?= sv_home_esc($category['name']) ?>" class="category-slide-img" width="240" height="240" loading="lazy" fetchpriority="low" decoding="async">
                                </div>
                                <strong><?= sv_home_esc($category['name']) ?></strong>
                                <span class="category-slide-count"><?= (int)$category['count'] ?> itens</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="home-scroller-arrow" data-dir="1" aria-label="Próximas categorias">›</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Produtos em destaque -->
    <section class="home-products">
        <div class="container">
            <div class="section-heading">
                <div>
                    <h2>Produtos em destaque</h2>
                    <p class="muted">Seleção com imagens reais e acesso rápido às linhas mais procuradas.</p>
                </div>
                <a href="/catalogo" class="btn btn-secondary">Ver todos</a>
            </div>
            <?php if ($featuredProducts): ?>
                <div class="home-scroller" data-scroller>
                    <button type="button" class="home-scroller-arrow" data-dir="-1" aria-label="Produtos anteriores">‹</button>
                    <div class="home-scroller-track products-track" id="product-grid">
                        <?php foreach ($featuredProducts as $product): ?>
                            <?php
                            // ShopVivaliz nao opera com pre-venda/"consulte o valor": produto
                            // sem preco real valido nao aparece na home. Ver docs/AGENTS.md.
                            if ((float)($product['price'] ?? 0) <= 0) {
                                continue;
                            }
                            $image      = $product['image_url'] !== '' ? $product['image_url'] : sv_home_default_image();
                            $pSlug      = $product['slug'] ?? '';
                            $productUrl = $pSlug !== '' ? '/produto/' . $pSlug : sv_home_product_url($product);
                            $contactUrl = sv_home_contact_url($product);
                            $stock      = (int)($product['stock'] ?? 0);
                            $hasPrice   = (float)($product['price'] ?? 0) > 0 && $stock > 0;
                            $payload    = rawurlencode(json_encode([
                                'sku'              => $product['sku'],
                                'name'             => $product['name'],
                                'image_url'        => $image,
                                'price'            => $product['price'],
                                'olist_product_id' => $product['olist_product_id'],
                                'stock'            => $stock,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                            ?>
                            <article class="product-card<?= $stock <= 0 ? ' is-out-of-stock' : '' ?>" data-sku="<?= sv_home_esc($product['sku']) ?>">
                                <?php $cardImages = array_values(array_unique(array_filter(array_merge([$image], is_array($product['images'] ?? null) ? $product['images'] : [])))); ?>
                                <a class="product-image" href="<?= sv_home_esc($productUrl) ?>" data-images="<?= sv_home_esc(json_encode(array_slice($cardImages, 0, 10), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
                                    <img src="<?= sv_home_esc($image) ?>" alt="<?= sv_home_esc($product['name']) ?>" width="400" height="400" loading="lazy" fetchpriority="low" decoding="async" onerror="this.src='<?= sv_home_default_image() ?>'">
                                    <?php if ($stock <= 0): ?><span class="out-of-stock-badge">Esgotado</span><?php endif; ?>
                                </a>
                                <div class="product-info">
                                    <?php if (!empty($product['category'])): ?>
                                        <div class="product-category"><?= sv_home_esc($product['category']) ?></div>
                                    <?php endif; ?>
                                    <h2><?= sv_home_esc($product['name']) ?></h2>
                                    
                                    <div class="product-price"><?= sv_home_esc(sv_home_money((float)$product['price'])) ?></div>
                                    <div class="card-actions">
                                        <a class="btn btn-secondary card-link" href="<?= sv_home_esc($productUrl) ?>">Ver detalhes</a>
                                        <?php if ($hasPrice): ?>
                                            <button class="buy-button btn btn-primary card-link" type="button" data-product="<?= sv_home_esc($payload) ?>" title="Comprar">Comprar</button>
                                        <?php elseif ($stock <= 0): ?>
                                            <button class="btn btn-disabled card-link" type="button" disabled>Esgotado</button>
                                        <?php else: ?>
                                            <a class="btn btn-primary card-link" href="<?= sv_home_esc($contactUrl) ?>">Falar com vendas</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="home-scroller-arrow" data-dir="1" aria-label="Próximos produtos">›</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Testimonials Section: depoimentos fixos removidos em 2026-08-15 --
         eram texto inventado (nomes, cidades e avaliacoes fabricados), o
         mesmo problema ja corrigido em produto.php via
         svpts_honest_reviews_section(). Reusa a mesma secao honesta aqui. -->
    <section class="container sv-reviews-section home-section-shell home-section-soft" aria-labelledby="sv-home-reviews-title" style="margin-top:40px;padding:24px;background:#fff;border:1px solid rgba(11,79,136,.1);border-radius:20px;">
        <h2 id="sv-home-reviews-title" style="font-size:20px;font-weight:800;color:#07345d;margin:0 0 8px;">Avaliações de clientes</h2>
        <p style="margin:0 0 16px;color:#475569;line-height:1.6;">Avaliações reais aparecem aqui assim que enviadas por clientes e moderadas pela equipe.</p>
        <a href="/avaliacoes.php" class="btn btn-secondary" style="display:inline-flex;text-decoration:none;">Ver e enviar avaliações</a>
    </section>

    <!-- Premium Newsletter Section -->
    <section class="home-newsletter">
        <div class="container">
            <div class="newsletter-card">
                <div class="newsletter-info">
                    <h2>Fique por dentro das novidades 📩</h2>
                    <p>Receba ofertas exclusivas, cupons de desconto e dicas de organização diretamente no seu e-mail.</p>
                </div>
                <form class="newsletter-form">
                    <label for="newsletter-email-input" class="sr-only">Seu endereço de e-mail</label>
                    <input id="newsletter-email-input" type="email" placeholder="Digite seu melhor e-mail" required aria-label="Seu endereço de e-mail">
                    <button type="submit" class="btn btn-primary">Inscrever-se</button>
                </form>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="home-faq home-section-shell">
        <div class="container home-faq-container">
            <div class="section-heading section-heading-centered sv-reveal home-section-intro">
                <div>
                    <h2>Perguntas frequentes</h2>
                    <p class="muted">Tire suas dúvidas rápidas sobre envio, pagamentos e garantia.</p>
                    <!-- FAQ Search Bar -->
                    <div class="newsletter-max-width">
                        <label for="faq-search-input" class="sr-only">Pesquisar perguntas frequentes</label>
                        <input type="text" id="faq-search-input" placeholder="Pesquisar dúvidas comuns..."
                               class="faq-search-input-custom input-custom"
                               aria-label="Pesquisar perguntas frequentes">
                    </div>
                </div>
            </div>
            <div class="faq-list">
                <details class="faq-item sv-reveal sv-reveal-delay-1">
                    <summary>
                        Qual é o prazo de entrega?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">O prazo de entrega varia conforme sua região e a transportadora disponível para o seu CEP. Para ver o prazo exato e o valor do frete, adicione o produto ao carrinho e informe seu CEP — a cotação é calculada em tempo real antes da finalização da compra.</p>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-2">
                    <summary>
                        Como funciona a política de devolução?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">Para compras online, você pode solicitar o direito de arrependimento em até 7 dias corridos após o recebimento. Entre em contato com o número do pedido; a equipe orientará cada etapa conforme a <a href="/politica-devolucoes">Política de Trocas e Devoluções</a>.</p>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-3">
                    <summary>
                        Quais são as formas de pagamento aceitas?
                        <span class="faq-icon">+</span>
                    </summary>
                    <div class="faq-body">
                        <p>As formas de pagamento e o eventual parcelamento disponíveis aparecem no checkout antes da confirmação. Assim, você vê as condições válidas para o pedido sem depender de uma promessa fixa.</p>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e9f0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            <img src="/images/mercado-pago-logo.svg" alt="Mercado Pago - Formas de Pagamento" style="max-width: 100%; height: auto; width: 180px; border-radius: 10px;">
                            <img src="/images/infinitepay-logo.svg" alt="InfinitePay - Formas de Pagamento" style="max-width: 100%; height: auto; width: 180px; border-radius: 10px;">
                        </div>
                    </div>
                </details>
                <details class="faq-item sv-reveal sv-reveal-delay-4">
                    <summary>
                        Posso rastrear meu pedido?
                        <span class="faq-icon">+</span>
                    </summary>
                    <p class="faq-body">Consulte “Minha Conta &gt; Meus Pedidos”. Quando a transportadora disponibilizar rastreamento, o código ou link também poderá ser enviado pelos canais cadastrados.</p>
                </details>
            </div>
            
            <!-- FAQ Accordion Search Filter Script -->
            <script>
            (function() {
                var searchInput = document.getElementById('faq-search-input');
                var items = document.querySelectorAll('.faq-item');
                if (!searchInput || !items.length) return;
                
                searchInput.addEventListener('input', function() {
                    var query = searchInput.value.toLowerCase().trim();
                    items.forEach(function(item) {
                        var question = item.querySelector('summary').textContent.toLowerCase();
                        var answer = item.querySelector('.faq-body').textContent.toLowerCase();
                        if (question.indexOf(query) !== -1 || answer.indexOf(query) !== -1) {
                            item.style.display = 'block';
                            if (query.length > 2) {
                                item.setAttribute('open', '');
                            } else {
                                item.removeAttribute('open');
                            }
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            })();
            </script>
        </div>
    </section>

    </main>

    <!-- Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <!-- Scroll Reveal -->
    <script>
    (function () {
        var elements = document.querySelectorAll('.sv-reveal');
        if (!elements.length) return;
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        elements.forEach(function (el) { observer.observe(el); });
    })();
    </script>
    <script src="/js/first-purchase-popup-v1.js?v=2026-07-30-1" defer></script>
    <script>
    (function () {
        var root = document.getElementById('hero-carousel');
        if (!root) return;
        var slides = Array.prototype.slice.call(root.querySelectorAll('.hero-slide'));
        var dots = Array.prototype.slice.call(root.querySelectorAll('.hero-carousel-dot'));
        var arrows = Array.prototype.slice.call(root.querySelectorAll('.hero-carousel-arrow'));
        var current = 0;
        var timer = null;

        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, slideIndex) {
                slide.classList.toggle('is-active', slideIndex === current);
            });
            dots.forEach(function (dot, dotIndex) {
                dot.classList.toggle('is-active', dotIndex === current);
            });
        }

        function restart() {
            clearInterval(timer);
            timer = setInterval(function () {
                show(current + 1);
            }, 5000);
        }

        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                show(index);
                restart();
            });
        });

        arrows.forEach(function (arrow) {
            arrow.addEventListener('click', function () {
                show(current + Number(arrow.getAttribute('data-dir') || '1'));
                restart();
            });
        });

        show(0);
        restart();
    })();

    (function () {
        var scrollers = document.querySelectorAll('[data-scroller]');
        scrollers.forEach(function (wrap) {
            var track = wrap.querySelector('.home-scroller-track');
            if (!track) return;
            wrap.querySelectorAll('.home-scroller-arrow').forEach(function (arrow) {
                arrow.addEventListener('click', function () {
                    var dir = Number(arrow.getAttribute('data-dir') || '1');
                    track.scrollBy({ left: dir * Math.max(260, track.clientWidth * 0.82), behavior: 'smooth' });
                });
            });
        });
    })();

    // Premium Micro-interactions: Apple-style Glow
    (function() {
        // Spotlight Effect
        var cards = document.querySelectorAll('.product-card');
        cards.forEach(function(card) {
            card.addEventListener('mousemove', function(e) {
                var rect = card.getBoundingClientRect();
                var x = e.clientX - rect.left;
                var y = e.clientY - rect.top;
                card.style.setProperty('--mouse-x', x + 'px');
                card.style.setProperty('--mouse-y', y + 'px');
            });
        });
    })();

    (function () {
        function decodePayload(rawValue) {
            var raw = String(rawValue || '{}');
            var decoder = (typeof window.decodeURIComponent === 'function')
                ? window.decodeURIComponent.bind(window)
                : function (value) { return value; };
            return JSON.parse(decoder(raw));
        }

        function readCart() {
            try {
                var items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
                return Array.isArray(items) ? items : [];
            } catch (error) {
                return [];
            }
        }

        function writeCart(items) {
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
            window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: items } }));
        }

        document.querySelectorAll('.buy-button[data-product]').forEach(function (button) {
            if (button.dataset.homeBound === '1') return;
            button.dataset.homeBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                try {
                    var product = decodePayload(button.getAttribute('data-product'));
                    var items = readCart();
                    var existing = items.find(function (item) { return item.sku === product.sku; });
                    if (existing) existing.quantity = Number(existing.quantity || 1) + 1;
                    else items.push(Object.assign({}, product, { quantity: 1 }));
                    writeCart(items);
                } catch (error) {
                    return;
                }
                window.location.href = '/carrinho';
            }, true);
        });
    })();
    </script>
    <!-- Shim temporario: limpa variantes persistidas sem executar experimento. -->
    <script src="/js/shopvivaliz-ab-testing.js?v=1.0.0" defer></script>
    <script src="/js/auto-image-carousel.js?v=20260811-1" defer></script>

    <!-- Popup de Cupons Promocionais -->
    <?php echo sv_popup_cupons_html(); ?>
</body>
</html>
<?php
$timings['total'] = microtime(true) - $t0;
echo "\n<!-- PHP Exec Timings:\n";
foreach ($timings as $key => $elapsed) {
    printf("  %s: %.4fs\n", $key, $elapsed);
}
echo "-->\n";
?>
