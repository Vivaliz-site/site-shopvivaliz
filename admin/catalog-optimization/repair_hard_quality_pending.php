<?php

declare(strict_types=1);

/**
 * Repara rascunhos antigos que ficaram em pending apesar de reprovados por
 * checks hard. O script nunca publica em marketplace: ele remove o rascunho
 * invalido da fila de aprovacao e gera um novo staging usando o pipeline
 * validado/fallback de provedores.
 *
 * Uso:
 *   php repair_hard_quality_pending.php --limit=500
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script so pode ser executado via CLI.\n";
    exit;
}

require_once __DIR__ . '/config_optimization.php';
require_once __DIR__ . '/api/optimize_catalog.php';

$limit = 500;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(2000, (int)substr($arg, 8)));
    }
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "catalog_hard_repair_db_available=false\n");
    exit(2);
}

/** @return list<string> */
function catalog_hard_repair_json_list(mixed $raw): array
{
    $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map(
        static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
        $decoded
    ), static fn(string $value): bool => $value !== ''));
}

/** @return list<string> */
function catalog_hard_repair_split(string $raw, string $separator): array
{
    return array_values(array_filter(array_map('trim', explode($separator, $raw)), static fn(string $value): bool => $value !== ''));
}

/** @return array<string,mixed> */
function catalog_hard_repair_data(array $row): array
{
    $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    return [
        'optimized_title' => trim((string)($row['optimized_title'] ?? '')),
        'optimized_description' => trim((string)($row['optimized_description'] ?? '')),
        'bullet_points' => catalog_hard_repair_json_list($row['bullet_points_json'] ?? '[]'),
        'seo_keywords' => catalog_hard_repair_split((string)($row['seo_keywords'] ?? ''), ','),
        'marketing_hooks' => catalog_hard_repair_split((string)($row['marketing_hooks'] ?? ''), '|'),
        'meta_title' => trim((string)($meta['meta_title'] ?? '')),
        'meta_description' => trim((string)($meta['meta_description'] ?? '')),
    ];
}

/** @return list<string> */
function catalog_hard_repair_failures(array $quality): array
{
    $soft = array_flip(ai_catalog_soft_quality_checks());
    $failed = [];
    foreach ((array)($quality['checks'] ?? []) as $check => $ok) {
        if (!$ok && !isset($soft[(string)$check])) {
            $failed[] = (string)$check;
        }
    }
    return $failed;
}

$stmt = $db->query(
    "SELECT * FROM catalog_optimizations_staging WHERE status = 'pending' ORDER BY id ASC LIMIT " . (int)$limit
);
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$scanned = 0;
$hardFound = 0;
$repaired = 0;
$failed = 0;
$skipped = 0;

foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $scanned++;
    $stagingId = (int)($row['id'] ?? 0);
    $productId = (int)($row['product_id'] ?? 0);
    $channel = strtolower(trim((string)($row['channel'] ?? '')));
    $provider = catalog_ai_normalize_provider((string)($row['provider_used'] ?? 'gemini'));

    if ($stagingId <= 0 || $productId <= 0 || !array_key_exists($channel, catalog_ai_channels())) {
        $skipped++;
        continue;
    }
    if (!in_array($provider, ai_catalog_allowed_providers(), true)) {
        $provider = 'gemini';
    }

    $product = ai_catalog_fetch_product($db, $productId);
    if ($product === null) {
        $skipped++;
        continue;
    }

    $data = catalog_hard_repair_data($row);
    $quality = ai_catalog_quality_report($data, $channel, $product);
    $hard = catalog_hard_repair_failures($quality);
    if ($hard === []) {
        continue;
    }
    $hardFound++;

    // O rascunho invalido sai imediatamente da fila de aprovacao. Mantemos a
    // linha como evidencia auditavel em vez de apaga-la.
    $mark = $db->prepare(
        "UPDATE catalog_optimizations_staging
         SET status = 'failed', error_message = ?, updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $mark->execute([
        'Substituido automaticamente: hard quality gate ' . implode(', ', $hard),
        $stagingId,
    ]);
    if ($mark->rowCount() !== 1) {
        $skipped++;
        continue;
    }

    // ai_catalog_process_item nunca cria pending antes de passar pelo mesmo
    // validador hard usado na publicacao e ja possui fallback de provedores e
    // rotacao de pools de credenciais.
    $result = ai_catalog_process_item($db, $productId, $channel, $provider);
    if (($result['success'] ?? false) === true) {
        $repaired++;
    } else {
        $failed++;
    }
}

echo 'catalog_hard_repair_scanned=' . $scanned . PHP_EOL;
echo 'catalog_hard_repair_found=' . $hardFound . PHP_EOL;
echo 'catalog_hard_repair_success=' . $repaired . PHP_EOL;
echo 'catalog_hard_repair_failed=' . $failed . PHP_EOL;
echo 'catalog_hard_repair_skipped=' . $skipped . PHP_EOL;
echo 'catalog_hard_repair_publication_attempted=false' . PHP_EOL;
echo 'secret_values_printed=false' . PHP_EOL;

exit($failed > 0 ? 1 : 0);
