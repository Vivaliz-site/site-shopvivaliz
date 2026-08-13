<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config_optimization.php';
require_once __DIR__ . '/../../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../../includes/marketplace/CatalogChannelProfile.php';
require_once __DIR__ . '/../../../includes/marketplace/CatalogChangeSet.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function cat_effective_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cat_effective_list(mixed $raw, string $separator = ','): array
{
    if (is_array($raw)) return sv_catalog_change_list($raw);
    if (!is_scalar($raw)) return [];
    $text = trim((string)$raw);
    if ($text === '') return [];
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return sv_catalog_change_list($decoded);
    return array_values(array_filter(array_map('trim', explode($separator, $text)), static fn(string $value): bool => $value !== ''));
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    cat_effective_json(['success' => false, 'error' => 'Use GET.'], 405);
}

$stagingId = (int)($_GET['staging_id'] ?? 0);
if ($stagingId <= 0) {
    cat_effective_json(['success' => false, 'error' => 'Informe um staging_id valido.'], 400);
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    cat_effective_json(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.'], 503);
}

try {
    svcp_ensure_schema($db);
    $stmt = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
    $stmt->execute([$stagingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        cat_effective_json(['success' => false, 'error' => 'Resultado de otimizacao nao encontrado.'], 404);
    }

    $productId = (int)($row['product_id'] ?? 0);
    $channel = strtolower(trim((string)($row['channel'] ?? 'site')));
    $profile = sv_catalog_channel_profile($channel);
    $fieldMap = is_array($profile['field_map'] ?? null) ? $profile['field_map'] : [];

    $productStmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) {
        cat_effective_json(['success' => false, 'error' => 'Produto relacionado nao encontrado.'], 404);
    }

    $local = [
        'name' => trim((string)($product['name'] ?? $product['nome'] ?? $product['descricao'] ?? '')),
        'description' => trim((string)($product['description'] ?? $product['descricao_completa'] ?? $product['descricaoComplementar'] ?? '')),
        'sku' => trim((string)($product['sku'] ?? '')),
        'image_url' => trim((string)($product['image_url'] ?? $product['imagem_principal_url'] ?? '')),
        'olist_id' => trim((string)($product['olist_id'] ?? '')),
    ];
    $before = sv_catalog_channel_snapshot($db, $productId, $channel, $local);
    $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    $after = [
        'optimized_title' => trim((string)($row['optimized_title'] ?? '')),
        'optimized_description' => trim((string)($row['optimized_description'] ?? '')),
        'bullet_points' => cat_effective_list($row['bullet_points_json'] ?? '[]'),
        'seo_keywords' => cat_effective_list($row['seo_keywords'] ?? '', ','),
        'marketing_hooks' => cat_effective_list($row['marketing_hooks'] ?? '', '|'),
        'meta_title' => trim((string)($meta['meta_title'] ?? '')),
        'meta_description' => trim((string)($meta['meta_description'] ?? '')),
    ];

    $changes = sv_catalog_change_set($before, $after, $fieldMap);
    cat_effective_json([
        'success' => true,
        'staging_id' => $stagingId,
        'product_id' => $productId,
        'channel' => $channel,
        'channel_label' => (string)($profile['label'] ?? $channel),
        'policy_version' => (string)($profile['policy_version'] ?? ''),
        'changes' => $changes,
        'checked_at' => gmdate(DATE_ATOM),
    ]);
} catch (Throwable $exception) {
    error_log('[catalog-effective-changes] ' . $exception->getMessage());
    cat_effective_json(['success' => false, 'error' => 'Nao foi possivel calcular as alteracoes efetivas.'], 500);
}
