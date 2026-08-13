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

function ais_pending_active_product_sql(PDO $db, string $alias = 'p'): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $columns = [];
    try {
        $stmt = $db->query('SHOW COLUMNS FROM products');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    } catch (Throwable) {
        $columns = ['active' => true];
    }

    $parts = [];
    foreach (['active', 'is_active', 'ativo'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "COALESCE({$prefix}.{$column}, 0) = 1";
        }
    }
    foreach (['situacao', 'status'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "UPPER(COALESCE({$prefix}.{$column}, 'A')) NOT IN ('I','INATIVO','INACTIVE','DESATIVADO','DISABLED','EXCLUIDO','DELETED')";
        }
    }
    return $parts !== [] ? implode(' AND ', $parts) : '1=1';
}

/** @return array{url:string,type:string} */
function ais_pending_source_image(PDO $db, array $product, string $projectRoot): array
{
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') {
        try {
            $stmt = $db->prepare('SELECT * FROM olist_product_images WHERE sku = ? LIMIT 20');
            $stmt->execute([$sku]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            usort($rows, static function (array $a, array $b): int {
                return [-(int)($a['is_primary'] ?? 0), (int)($a['position'] ?? 9999), (int)($a['id'] ?? 999999)]
                    <=> [-(int)($b['is_primary'] ?? 0), (int)($b['position'] ?? 9999), (int)($b['id'] ?? 999999)];
            });
            foreach ($rows as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if (in_array($status, ['error', 'deleted', 'inactive'], true)) {
                    continue;
                }
                $resolved = '';
                if (function_exists('svsis_resolve_image_url')) {
                    $resolved = trim((string)svsis_resolve_image_url($row, $projectRoot));
                }
                if ($resolved === '') {
                    foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value === '') {
                            continue;
                        }
                        if (preg_match('~^https?://~i', $value) === 1 || str_starts_with($value, '/')) {
                            $resolved = $value;
                            break;
                        }
                    }
                }
                if ($resolved !== '') {
                    return ['url' => $resolved, 'type' => 'galeria_erp'];
                }
            }
        } catch (Throwable $e) {
            error_log('[ai-image-studio] readiness da galeria indisponivel SKU ' . $sku . ': ' . $e->getMessage());
        }
    }

    $legacy = trim((string)($product['image_ref'] ?? ''));
    if ($legacy !== '') {
        return ['url' => $legacy, 'type' => 'produto_principal'];
    }
    return ['url' => '', 'type' => 'ausente'];
}

/** @return array{score:int,state:string,missing:list<string>} */
function ais_pending_readiness(array $product, bool $hasImage): array
{
    $score = $hasImage ? 45 : 0;
    $missing = [];

    $name = trim((string)($product['name'] ?? ''));
    if ($name !== '') $score += 15; else $missing[] = 'nome';

    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') $score += 10; else $missing[] = 'SKU';

    $category = trim((string)($product['category'] ?? ''));
    if ($category !== '') $score += 10; else $missing[] = 'categoria';

    $identity = trim((string)($product['brand'] ?? '')) . trim((string)($product['model'] ?? ''));
    if ($identity !== '') $score += 10; else $missing[] = 'marca/modelo';

    $appearance = trim((string)($product['color'] ?? ''))
        . trim((string)($product['material'] ?? ''))
        . trim((string)($product['size'] ?? ''));
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
    return ['score' => $score, 'state' => $state, 'missing' => array_values(array_unique($missing))];
}

try {
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.image_url, p.sku '
        . 'FROM products p '
        . 'WHERE ' . ais_pending_active_product_sql($db, 'p') . ' '
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

    $items = [];
    $summary = ['ready' => 0, 'limited_context' => 0, 'blocked' => 0];
    foreach ($rows as $row) {
        $productId = (int)($row['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $context = ai_studio_fetch_product($db, $productId) ?? [];
        if ($context === []) {
            continue;
        }
        $source = ais_pending_source_image($db, $context, $projectRoot);
        $readiness = ais_pending_readiness($context, $source['url'] !== '');
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
            'name' => trim((string)($context['name'] ?? $row['name'] ?? '')),
            'sku' => trim((string)($context['sku'] ?? $row['sku'] ?? '')),
            'category' => trim((string)($context['category'] ?? '')),
            'brand' => trim((string)($context['brand'] ?? '')),
            'model' => trim((string)($context['model'] ?? '')),
            'color' => trim((string)($context['color'] ?? '')),
            'material' => trim((string)($context['material'] ?? '')),
            'size' => trim((string)($context['size'] ?? '')),
            'source_image_url' => $source['url'],
            'source_type' => $source['type'],
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] pending_candidates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel carregar os produtos para geracao.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
