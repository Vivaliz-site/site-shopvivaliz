<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/constants.php';
require_once $root . '/includes/pdo-database.php';
require_once $root . '/includes/catalog-publication-schema.php';

function sv_smoke_cleanup_arg(string $name): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return trim(substr((string)$argument, strlen($prefix)));
        }
    }
    return '';
}

/** @return list<string> */
function sv_smoke_cleanup_report_paths(string $root, string $requestedDirectory): array
{
    $directories = array_values(array_unique(array_filter([
        $requestedDirectory,
        $root . '/storage/logs',
        '/home/ubuntu/shopvivaliz-deploy/shared/logs',
    ], static fn(string $value): bool => trim($value) !== '')));

    $paths = [];
    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        foreach (['ai-routines-smoke-*.json', 'ai-image-smoke-v2-*.json'] as $pattern) {
            foreach (glob(rtrim($directory, '/') . '/' . $pattern) ?: [] as $path) {
                if (is_file($path) && is_readable($path)) {
                    $paths[$path] = $path;
                }
            }
        }
    }
    ksort($paths, SORT_STRING);
    return array_values($paths);
}

/**
 * @return array{catalog:array<int,true>,images:array<int,true>,reports:int,invalid:int}
 */
function sv_smoke_cleanup_collect_ids(array $paths): array
{
    $catalog = [];
    $images = [];
    $reports = 0;
    $invalid = 0;

    foreach ($paths as $path) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['results'] ?? null)) {
            $invalid++;
            continue;
        }
        $schema = (string)($decoded['schema'] ?? '');
        if (!in_array($schema, ['shopvivaliz.ai-smoke-matrix.v1', 'shopvivaliz.ai-image-smoke-matrix.v2'], true)) {
            $invalid++;
            continue;
        }
        $reports++;
        foreach ($decoded['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }
            // Canonical smoke report contract: result['staging_id'].
            $stagingId = (int)($result['staging_id'] ?? 0);
            $kind = strtolower(trim((string)($result['kind'] ?? '')));
            if ($stagingId <= 0 || $kind === '') {
                continue;
            }
            if ($kind === 'catalog') {
                $catalog[$stagingId] = true;
            } elseif (str_starts_with($kind, 'image')) {
                $images[$stagingId] = true;
            }
        }
    }

    return ['catalog' => $catalog, 'images' => $images, 'reports' => $reports, 'invalid' => $invalid];
}

function sv_smoke_cleanup_active_sql(PDO $db, string $alias = 'p'): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $columns = [];
    try {
        $statement = $db->query('SHOW COLUMNS FROM products');
        foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $field = strtolower(trim((string)($row['Field'] ?? '')));
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

/** @return array<string,int> */
function sv_smoke_cleanup_candidate_diagnostics(PDO $db): array
{
    $activeSql = sv_smoke_cleanup_active_sql($db, 'p');
    $active = (int)$db->query('SELECT COUNT(*) FROM products p WHERE ' . $activeSql)->fetchColumn();
    $out = ['active' => $active];
    $eligible = $db->prepare(
        'SELECT COUNT(*) FROM products p WHERE ' . $activeSql . ' AND NOT EXISTS ('
        . ' SELECT 1 FROM catalog_optimizations_staging s'
        . ' WHERE s.product_id = p.id AND s.channel = ?'
        . " AND COALESCE(s.status, '') NOT IN ('published','rejected','failed')"
        . ')'
    );
    foreach (['ml', 'shopee', 'amazon', 'tiktok', 'site', 'erp'] as $channel) {
        $eligible->execute([$channel]);
        $out[$channel] = (int)$eligible->fetchColumn();
    }
    return $out;
}

$db = sv_pdo();
if (!$db instanceof PDO) {
    fwrite(STDERR, "smoke_cleanup_database_unavailable=true\n");
    exit(3);
}
svcp_ensure_schema($db);

$requestedDirectory = sv_smoke_cleanup_arg('reports-dir');
$paths = sv_smoke_cleanup_report_paths($root, $requestedDirectory);
$ids = sv_smoke_cleanup_collect_ids($paths);
$catalogIds = array_map('intval', array_keys($ids['catalog']));
$imageIds = array_map('intval', array_keys($ids['images']));
sort($catalogIds, SORT_NUMERIC);
sort($imageIds, SORT_NUMERIC);

$catalogRejected = 0;
$imageRejected = 0;
$db->beginTransaction();
try {
    $catalogUpdate = $db->prepare(
        "UPDATE catalog_optimizations_staging SET status = 'rejected', "
        . "error_message = COALESCE(NULLIF(error_message, ''), 'Automated production smoke; never publish.'), updated_at = NOW() "
        . "WHERE id = ? AND COALESCE(status, '') NOT IN ('published','publishing','submitted','rejected')"
    );
    foreach ($catalogIds as $stagingId) {
        $catalogUpdate->execute([$stagingId]);
        $catalogRejected += $catalogUpdate->rowCount();
    }

    $imageUpdate = $db->prepare(
        "UPDATE product_images_staging SET status = 'rejected', "
        . "error_message = COALESCE(NULLIF(error_message, ''), 'Automated production smoke; never publish.'), updated_at = NOW() "
        . "WHERE id = ? AND COALESCE(status, '') NOT IN ('approved','published','publishing','submitted','rejected')"
    );
    foreach ($imageIds as $stagingId) {
        $imageUpdate->execute([$stagingId]);
        $imageRejected += $imageUpdate->rowCount();
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'smoke_cleanup_error=' . preg_replace('/\s+/u', ' ', $exception->getMessage()) . "\n");
    exit(4);
}

$diagnostics = sv_smoke_cleanup_candidate_diagnostics($db);
echo 'smoke_reports_scanned=' . (int)$ids['reports'] . PHP_EOL;
echo 'smoke_reports_invalid=' . (int)$ids['invalid'] . PHP_EOL;
echo 'smoke_catalog_ids_found=' . count($catalogIds) . PHP_EOL;
echo 'smoke_image_ids_found=' . count($imageIds) . PHP_EOL;
echo 'smoke_catalog_rows_rejected=' . $catalogRejected . PHP_EOL;
echo 'smoke_image_rows_rejected=' . $imageRejected . PHP_EOL;
echo 'catalog_active_products=' . (int)$diagnostics['active'] . PHP_EOL;
foreach (['ml', 'shopee', 'amazon', 'tiktok', 'site', 'erp'] as $channel) {
    echo 'catalog_candidates_' . $channel . '_eligible=' . (int)($diagnostics[$channel] ?? 0) . PHP_EOL;
}
echo "smoke_staging_cleanup_complete=true\n";
