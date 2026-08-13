<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../process_item.php';
require_once __DIR__ . '/../src/ImageChannelProfile.php';

$projectRoot = dirname(__DIR__, 3);
$storefrontResolver = $projectRoot . '/includes/storefront-image-source.php';
if (is_file($storefrontResolver)) {
    require_once $storefrontResolver;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$targetChannel = strtolower(trim((string)($_GET['target_channel'] ?? 'site')));
$rawLimit = (int)($_GET['limit'] ?? 100);
$limit = $rawLimit <= 0 ? 5000 : max(1, min(5000, $rawLimit));
$profiles = ai_studio_channel_profiles();

if (!isset($profiles[$targetChannel])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Marketplace de destino invalido.']);
    exit;
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}

svcp_ensure_schema($db);

/** @return array<string,true> */
function ais_pending_table_columns(PDO $db, string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return [];
    }
    $columns = [];
    try {
        $stmt = $db->query('SHOW COLUMNS FROM `' . $table . '`');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field !== '') $columns[$field] = true;
        }
    } catch (Throwable) {
        return [];
    }
    return $columns;
}

function ais_pending_active_product_sql(array $columns, string $alias = 'p'): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $parts = [];
    foreach (['active', 'is_active', 'ativo'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "COALESCE({$prefix}.`{$column}`, 0) = 1";
        }
    }
    foreach (['situacao', 'status'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "UPPER(COALESCE({$prefix}.`{$column}`, 'A')) NOT IN ('I','INATIVO','INACTIVE','DESATIVADO','DISABLED','EXCLUIDO','DELETED')";
        }
    }
    return $parts !== [] ? implode(' AND ', $parts) : '1=1';
}

/** @param list<string> $candidates */
function ais_pending_product_expr(array $columns, array $candidates, string $output, string $alias = 'p'): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $parts = [];
    foreach ($candidates as $column) {
        $key = strtolower($column);
        if (isset($columns[$key])) {
            $parts[] = "NULLIF(TRIM(COALESCE({$prefix}.`{$column}`, '')), '')";
        }
    }
    if ($parts === []) return "'' AS `{$output}`";
    return 'COALESCE(' . implode(', ', $parts) . ", '') AS `{$output}`";
}

/** @return array{width:int,height:int} */
function ais_pending_image_dimensions(array $row, string $resolved, string $projectRoot): array
{
    $width = 0;
    $height = 0;
    foreach (['source_width', 'width', 'image_width', 'original_width'] as $field) {
        $candidate = (int)($row[$field] ?? 0);
        if ($candidate > 0) {
            $width = $candidate;
            break;
        }
    }
    foreach (['source_height', 'height', 'image_height', 'original_height'] as $field) {
        $candidate = (int)($row[$field] ?? 0);
        if ($candidate > 0) {
            $height = $candidate;
            break;
        }
    }
    if ($width > 0 && $height > 0) return ['width' => $width, 'height' => $height];

    $localCandidates = [];
    if (str_starts_with($resolved, '/')) $localCandidates[] = $projectRoot . $resolved;
    foreach (['local_url', 'site_url'] as $field) {
        $path = trim((string)($row[$field] ?? ''));
        if ($path !== '' && str_starts_with($path, '/')) $localCandidates[] = $projectRoot . $path;
    }
    foreach (array_values(array_unique($localCandidates)) as $path) {
        if (!is_file($path)) continue;
        $size = @getimagesize($path);
        if (is_array($size) && (int)($size[0] ?? 0) > 0 && (int)($size[1] ?? 0) > 0) {
            return ['width' => (int)$size[0], 'height' => (int)$size[1]];
        }
    }
    return ['width' => $width, 'height' => $height];
}

/** @return array{url:string,type:string,width:int,height:int} */
function ais_pending_source_from_gallery_row(array $row, string $projectRoot): array
{
    $resolved = '';
    if (function_exists('svsis_resolve_image_url')) {
        $resolved = trim((string)svsis_resolve_image_url($row, $projectRoot));
    }
    if ($resolved === '') {
        foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') continue;
            if (preg_match('~^https?://~i', $value) === 1 || str_starts_with($value, '/')) {
                $resolved = $value;
                break;
            }
        }
    }
    if ($resolved === '') return ['url' => '', 'type' => 'ausente', 'width' => 0, 'height' => 0];
    $dimensions = ais_pending_image_dimensions($row, $resolved, $projectRoot);
    return [
        'url' => $resolved,
        'type' => 'galeria_erp',
        'width' => $dimensions['width'],
        'height' => $dimensions['height'],
    ];
}

/** @param list<string> $skus @return array<string,array{url:string,type:string,width:int,height:int}> */
function ais_pending_gallery_sources(PDO $db, array $skus, string $projectRoot): array
{
    $skus = array_values(array_unique(array_filter(array_map('trim', $skus), static fn(string $sku): bool => $sku !== '')));
    if ($skus === []) return [];

    $columns = ais_pending_table_columns($db, 'olist_product_images');
    if (!isset($columns['sku'])) return [];
    $sources = [];
    foreach (array_chunk($skus, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $where = "sku IN ({$placeholders})";
        if (isset($columns['status'])) {
            $where .= " AND (status IS NULL OR status = '' OR status NOT IN ('error','deleted','inactive'))";
        }
        $order = ['sku ASC'];
        if (isset($columns['is_primary'])) $order[] = 'is_primary DESC';
        if (isset($columns['position'])) $order[] = 'position ASC';
        if (isset($columns['id'])) $order[] = 'id ASC';
        $stmt = $db->prepare('SELECT * FROM olist_product_images WHERE ' . $where . ' ORDER BY ' . implode(', ', $order));
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku === '' || isset($sources[$sku])) continue;
            $source = ais_pending_source_from_gallery_row($row, $projectRoot);
            if ($source['url'] !== '') $sources[$sku] = $source;
        }
    }
    return $sources;
}

/** @return array{url:string,type:string,width:int,height:int} */
function ais_pending_source_image(array $product, array $gallerySources, string $projectRoot): array
{
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '' && isset($gallerySources[$sku])) return $gallerySources[$sku];

    $legacy = trim((string)($product['image_ref'] ?? ''));
    if ($legacy !== '') {
        $dimensions = ais_pending_image_dimensions([], $legacy, $projectRoot);
        return [
            'url' => $legacy,
            'type' => 'produto_principal',
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
        ];
    }
    return ['url' => '', 'type' => 'ausente', 'width' => 0, 'height' => 0];
}

/** @return array{score:int,state:string,missing:list<string>,resolution_state:string,recommended_side:int} */
function ais_pending_readiness(array $product, array $source, string $targetChannel): array
{
    $hasImage = trim((string)($source['url'] ?? '')) !== '';
    $score = $hasImage ? 35 : 0;
    $missing = [];
    $profile = ai_studio_channel_public_profile($targetChannel);
    $recommendedSide = max(600, (int)($profile['recommended_side'] ?? 1000));
    $width = max(0, (int)($source['width'] ?? 0));
    $height = max(0, (int)($source['height'] ?? 0));
    $resolutionState = 'unknown';

    if ($hasImage && $width > 0 && $height > 0) {
        $score += 5;
        if (min($width, $height) >= $recommendedSide) {
            $score += 5;
            $resolutionState = 'recommended';
        } else {
            $resolutionState = 'below_target';
            $missing[] = 'resolução da foto abaixo do alvo';
        }
    } elseif ($hasImage) {
        $missing[] = 'resolução da foto não verificada';
    }

    $name = trim((string)($product['name'] ?? ''));
    if ($name !== '') $score += 15; else $missing[] = 'nome';
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') $score += 10; else $missing[] = 'SKU';
    $category = trim((string)($product['category'] ?? ''));
    if ($category !== '') $score += 10; else $missing[] = 'categoria';
    $identity = trim((string)($product['brand'] ?? '')) . trim((string)($product['model'] ?? ''));
    if ($identity !== '') $score += 10; else $missing[] = 'marca/modelo';
    $appearance = trim((string)($product['color'] ?? '')) . trim((string)($product['material'] ?? '')) . trim((string)($product['size'] ?? ''));
    if ($appearance !== '') $score += 10; else $missing[] = 'cor/material/tamanho';

    $score = min(100, $score);
    if (!$hasImage) {
        $state = 'blocked';
        array_unshift($missing, 'foto real');
    } elseif ($score >= 70) {
        $state = 'ready';
    } else {
        $state = 'limited_context';
    }
    return [
        'score' => $score,
        'state' => $state,
        'missing' => array_values(array_unique($missing)),
        'resolution_state' => $resolutionState,
        'recommended_side' => $recommendedSide,
    ];
}

try {
    $productColumns = ais_pending_table_columns($db, 'products');
    if (!isset($productColumns['id'])) throw new RuntimeException('Tabela products sem id.');
    $select = [
        'p.id',
        ais_pending_product_expr($productColumns, ['name', 'nome', 'descricao'], 'name'),
        ais_pending_product_expr($productColumns, ['image_url', 'imagem_principal_url', 'primary_image_url', 'imagem'], 'image_ref'),
        ais_pending_product_expr($productColumns, ['sku'], 'sku'),
        ais_pending_product_expr($productColumns, ['olist_id'], 'olist_id'),
        ais_pending_product_expr($productColumns, ['category', 'categoria', 'category_name', 'nome_categoria'], 'category'),
        ais_pending_product_expr($productColumns, ['brand', 'marca', 'manufacturer', 'fabricante'], 'brand'),
        ais_pending_product_expr($productColumns, ['model', 'modelo', 'part_number', 'mpn'], 'model'),
        ais_pending_product_expr($productColumns, ['color', 'cor'], 'color'),
        ais_pending_product_expr($productColumns, ['size', 'tamanho'], 'size'),
        ais_pending_product_expr($productColumns, ['material'], 'material'),
    ];
    $stmt = $db->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM products p '
        . 'WHERE ' . ais_pending_active_product_sql($productColumns, 'p') . ' '
        . 'AND NOT EXISTS ('
        . ' SELECT 1 FROM product_images_staging s '
        . ' WHERE s.product_id = p.id '
        . ' AND s.target_channels_json LIKE ? '
        . " AND COALESCE(s.status, '') NOT IN ('failed','rejected')"
        . ') '
        . 'ORDER BY p.id ASC LIMIT ' . (int)$limit
    );
    $stmt->execute(['%"' . $targetChannel . '"%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $skus = array_values(array_filter(array_map(static fn(array $row): string => trim((string)($row['sku'] ?? '')), $rows)));
    $gallerySources = ais_pending_gallery_sources($db, $skus, $projectRoot);

    $items = [];
    $summary = ['ready' => 0, 'limited_context' => 0, 'blocked' => 0];
    foreach ($rows as $context) {
        $productId = (int)($context['id'] ?? 0);
        if ($productId <= 0 || trim((string)($context['name'] ?? '')) === '') continue;
        $source = ais_pending_source_image($context, $gallerySources, $projectRoot);
        $readiness = ais_pending_readiness($context, $source, $targetChannel);
        $summary[$readiness['state']] = ($summary[$readiness['state']] ?? 0) + 1;

        $identity = array_values(array_filter([
            trim((string)($context['brand'] ?? '')),
            trim((string)($context['model'] ?? '')),
            trim((string)($context['color'] ?? '')),
            trim((string)($context['material'] ?? '')),
            trim((string)($context['size'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));

        $items[] = [
            'id' => $productId,
            'name' => trim((string)($context['name'] ?? '')),
            'sku' => trim((string)($context['sku'] ?? '')),
            'category' => trim((string)($context['category'] ?? '')),
            'brand' => trim((string)($context['brand'] ?? '')),
            'model' => trim((string)($context['model'] ?? '')),
            'color' => trim((string)($context['color'] ?? '')),
            'material' => trim((string)($context['material'] ?? '')),
            'size' => trim((string)($context['size'] ?? '')),
            'source_image_url' => $source['url'],
            'source_type' => $source['type'],
            'source_width' => (int)$source['width'],
            'source_height' => (int)$source['height'],
            'source_resolution_state' => $readiness['resolution_state'],
            'recommended_side' => $readiness['recommended_side'],
            'has_image' => $source['url'] !== '',
            'readiness_score' => $readiness['score'],
            'readiness_state' => $readiness['state'],
            'missing_context' => $readiness['missing'],
            'identity_summary' => implode(' · ', $identity),
            'essential_types' => ai_studio_channel_recommended_types($targetChannel, true),
            'recommended_types' => ai_studio_channel_recommended_types($targetChannel, false),
        ];
    }

    echo json_encode([
        'success' => true,
        'target_channel' => $targetChannel,
        'profile' => ai_studio_channel_public_profile($targetChannel),
        'items' => $items,
        'count' => count($items),
        'summary' => $summary,
        'query_strategy' => 'batched_products_and_gallery',
        'gallery_batches' => $skus === [] ? 0 : (int)ceil(count(array_unique($skus)) / 200),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] pending_candidates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel carregar os produtos para geracao.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
