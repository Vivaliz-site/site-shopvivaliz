<?php

declare(strict_types=1);

/**
 * Publica em lote todo o backlog de catalog_optimizations_staging com status
 * 'pending', um por um, replicando exatamente o fluxo "Aprovar e publicar
 * selecionados" do Admin -> Otimizacao de Cadastro (admin_catalog.php,
 * bulk_action=bulk_publish): revalida o quality gate com enforce=true antes
 * de publicar (bloqueia rascunhos que ainda falhem apos o fix do gate de
 * identidade) e delega a publicacao real ao CatalogOptimizationPublisher, que
 * roteia por canal (ml/shopee/amazon/tiktok/site/erp).
 *
 * So existe porque o Admin exige uma sessao HTTP autenticada por checkbox;
 * este script e o mesmo caminho de codigo executado via CLI para o backlog
 * inteiro de uma vez, sob pedido explicito do operador.
 *
 * Uso: php publish_pending_catalog_backlog.php [--limit=N] [--channel=ml]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script so pode ser executado via CLI.\n");
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../admin/catalog-optimization/config_optimization.php';
require_once __DIR__ . '/../admin/catalog-optimization/api/optimize_catalog.php';
require_once __DIR__ . '/../admin/catalog-optimization/src/CatalogGeneratedDataNormalizer.php';
require_once __DIR__ . '/../admin/catalog-optimization/src/CatalogPublisher.php';
require_once __DIR__ . '/../includes/marketplace/CatalogChannelProfile.php';

$limit = 0;
$channelFilter = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) $limit = max(0, (int)substr($arg, 8));
    if (str_starts_with($arg, '--channel=')) $channelFilter = strtolower(trim(substr($arg, 10)));
}

/** @return array<string,mixed> */
function pcb_staging_ai_data(array $row): array
{
    $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    return [
        'optimized_title' => trim((string)($row['optimized_title'] ?? '')),
        'optimized_description' => trim((string)($row['optimized_description'] ?? '')),
        'bullet_points' => array_values(array_filter((array)(json_decode((string)($row['bullet_points_json'] ?? '[]'), true) ?: []), 'is_string')),
        'seo_keywords' => array_values(array_filter(array_map('trim', explode(',', (string)($row['seo_keywords'] ?? ''))))),
        'marketing_hooks' => array_values(array_filter(array_map('trim', explode('|', (string)($row['marketing_hooks'] ?? ''))))),
        'meta_title' => trim((string)($meta['meta_title'] ?? '')),
        'meta_description' => trim((string)($meta['meta_description'] ?? '')),
    ];
}

/**
 * Espelho exato de cat_refresh_quality() em admin_catalog.php: recalcula o
 * quality gate, persiste o relatorio, e — quando $enforce — repete a mesma
 * validacao hard que bloqueia a geracao (ai_catalog_validate_ai_response),
 * jogando excecao se algum check obrigatorio ainda falhar.
 *
 * @return array{row:array<string,mixed>,quality:array{score:int,checks:array<string,bool>}}
 */
function pcb_refresh_quality(PDO $db, array $row, bool $enforce): array
{
    $channel = strtolower(trim((string)($row['channel'] ?? '')));
    $product = ai_catalog_fetch_product($db, (int)($row['product_id'] ?? 0));
    if ($product === null) throw new RuntimeException('Produto nao encontrado para auditoria de qualidade.');

    $data = pcb_staging_ai_data($row);
    $quality = ai_catalog_quality_report($data, $channel, $product);

    $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    $profile = sv_catalog_channel_profile($channel);
    $meta['meta_title'] = (string)$data['meta_title'];
    $meta['meta_description'] = (string)$data['meta_description'];
    $meta['quality_score'] = $quality['score'];
    $meta['quality_checks'] = $quality['checks'];
    $meta['policy_version'] = (string)($profile['policy_version'] ?? 'marketplace-premium-2026-08');
    $meta['manual_edit_pending_quality_recheck'] = false;

    $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) throw new RuntimeException('Falha ao persistir relatorio de qualidade.');
    $update = $db->prepare('UPDATE catalog_optimizations_staging SET meta_data_json = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$encoded, (int)$row['id']]);
    $row['meta_data_json'] = $encoded;

    if ($enforce) {
        ai_catalog_validate_ai_response($data, $channel, $product);
    }
    return ['row' => $row, 'quality' => $quality];
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "catalog_ai_db indisponivel\n");
    exit(2);
}

$sql = "SELECT * FROM catalog_optimizations_staging WHERE status = 'pending'";
$params = [];
if ($channelFilter !== '') {
    $sql .= ' AND channel = ?';
    $params[] = $channelFilter;
}
$sql .= ' ORDER BY id ASC';
if ($limit > 0) $sql .= ' LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$publisher = new CatalogOptimizationPublisher($db);
$published = 0;
$failed = [];
$byChannel = [];

foreach ($rows as $row) {
    $stagingId = (int)$row['id'];
    $channel = (string)$row['channel'];
    try {
        $audit = pcb_refresh_quality($db, $row, true);
        $result = $publisher->publish($audit['row']);
        $status = (string)($result['status'] ?? '');
        if ($status === 'submitted' || $status === 'published') {
            $published++;
            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + 1;
            echo "#{$stagingId} [{$channel}] produto {$row['product_id']}: {$status}\n";
        } else {
            $failed[] = "#{$stagingId} [{$channel}]: retorno inesperado ({$status})";
            echo "#{$stagingId} [{$channel}] produto {$row['product_id']}: FALHOU (retorno inesperado: {$status})\n";
        }
    } catch (Throwable $exception) {
        $failed[] = "#{$stagingId} [{$channel}]: " . $exception->getMessage();
        echo "#{$stagingId} [{$channel}] produto {$row['product_id']}: FALHOU ({$exception->getMessage()})\n";
    }
}

echo "\n=== RESUMO ===\n";
echo 'total processado: ' . count($rows) . "\n";
echo "publicados: {$published}\n";
foreach ($byChannel as $ch => $n) echo "  {$ch}: {$n}\n";
echo 'falhas: ' . count($failed) . "\n";
foreach ($failed as $f) echo "  {$f}\n";
