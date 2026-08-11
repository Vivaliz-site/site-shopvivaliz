<?php

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie, Accept-Encoding');
}

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/config_optimization.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogEndpointRegistry.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogChannelProfile.php';
require_once __DIR__ . '/src/CatalogPublisher.php';
require_once __DIR__ . '/src/CatalogDraftEditor.php';
require_once __DIR__ . '/api/optimize_catalog.php';

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Falha ao conectar ao banco de dados.');
}
svcp_ensure_schema($db);
$channels = catalog_ai_channels();
$endpointRegistry = sv_market_catalog_endpoint_registry();
$providerLabels = [
    'openai' => 'GPT / OpenAI',
    'gemini' => 'Gemini',
    'claude' => 'Claude',
    'openrouter' => 'OpenRouter',
    'groq' => 'Groq / Qrope',
];

function cat_provider_health_snapshot(): array
{
    $path = dirname(__DIR__, 3) . '/storage/ai-provider-health.json';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cat_provider_audit_tail(int $limit = 16): array
{
    $path = dirname(__DIR__, 3) . '/storage/ai-provider-audit.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        return [];
    }
    $events = [];
    foreach (array_slice($lines, max(0, count($lines) - $limit)) as $line) {
        $decoded = json_decode((string)$line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }
    return array_reverse($events);
}

function cat_provider_options(string $selected, array $labels): string
{
    $html = '';
    foreach ($labels as $value => $label) {
        $isSelected = $selected === $value ? ' selected' : '';
        $html .= '<option value="' . cat_h((string)$value) . '"' . $isSelected . '>' . cat_h((string)$label) . '</option>';
    }
    return $html;
}

function cat_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array{name:string,description:string,sku:string,image_url:string,olist_id:string} */
function cat_original(PDO $db, int $productId): array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return ['name' => '', 'description' => '', 'sku' => '', 'image_url' => '', 'olist_id' => ''];
    return [
        'name' => trim((string)($row['name'] ?? '')),
        'description' => trim((string)($row['description'] ?? '')),
        'sku' => trim((string)($row['sku'] ?? '')),
        'image_url' => trim((string)($row['image_url'] ?? '')),
        'olist_id' => trim((string)($row['olist_id'] ?? '')),
    ];
}

/** @return list<string> */
function cat_json_list(mixed $raw): array
{
    $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map(
        static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
        $decoded
    ), static fn(string $value): bool => $value !== ''));
}

/** @return array<string,mixed> */
function cat_staging_ai_data(array $row): array
{
    $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    return [
        'optimized_title' => trim((string)($row['optimized_title'] ?? '')),
        'optimized_description' => trim((string)($row['optimized_description'] ?? '')),
        'bullet_points' => cat_json_list($row['bullet_points_json'] ?? '[]'),
        'seo_keywords' => array_values(array_filter(array_map('trim', explode(',', (string)($row['seo_keywords'] ?? ''))))),
        'marketing_hooks' => array_values(array_filter(array_map('trim', explode('|', (string)($row['marketing_hooks'] ?? ''))))),
        'meta_title' => trim((string)($meta['meta_title'] ?? '')),
        'meta_description' => trim((string)($meta['meta_description'] ?? '')),
    ];
}

/**
 * Recalcula o quality gate depois de qualquer edicao manual. O relatorio novo
 * e persistido ANTES da tentativa de publicar, de forma que uma reprovacao
 * continue visivel no proximo carregamento do Admin.
 *
 * @return array{row:array<string,mixed>,quality:array{score:int,checks:array<string,bool>},failed:list<string>}
 */
function cat_refresh_quality(PDO $db, array $row, bool $enforce): array
{
    $channel = strtolower(trim((string)($row['channel'] ?? '')));
    $product = ai_catalog_fetch_product($db, (int)($row['product_id'] ?? 0));
    if ($product === null) throw new RuntimeException('Produto nao encontrado para auditoria de qualidade.');

    $data = cat_staging_ai_data($row);
    $quality = ai_catalog_quality_report($data, $channel, $product);
    $softChecks = array_flip(ai_catalog_soft_quality_checks());
    $failed = array_values(array_filter(
        array_keys(array_filter($quality['checks'], static fn(bool $ok): bool => !$ok)),
        static fn(string $check): bool => !isset($softChecks[$check])
    ));

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
        // Valida estrutura, claims, protecoes comerciais e todos os checks.
        ai_catalog_validate_ai_response($data, $channel, $product);
    }
    return ['row' => $row, 'quality' => $quality, 'failed' => $failed];
}

function cat_update_generated_row(PDO $db, int $stagingId, array $data, string $provider, string $channel, array $product): void
{
    $quality = ai_catalog_quality_report($data, $channel, $product);
    $profile = sv_catalog_channel_profile($channel);
    $stmt = $db->prepare(
        'UPDATE catalog_optimizations_staging SET provider_used = ?, optimized_title = ?, optimized_description = ?, '
        . 'bullet_points_json = ?, seo_keywords = ?, marketing_hooks = ?, meta_data_json = ?, status = ?, '
        . 'error_message = NULL, publication_summary_json = NULL, published_at = NULL, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([
        $provider,
        trim((string)$data['optimized_title']),
        trim((string)$data['optimized_description']),
        json_encode(array_values($data['bullet_points']), JSON_UNESCAPED_UNICODE),
        implode(', ', array_map('strval', $data['seo_keywords'])),
        implode(' | ', array_map('strval', $data['marketing_hooks'])),
        json_encode([
            'meta_title' => $data['meta_title'],
            'meta_description' => $data['meta_description'],
            'quality_score' => $quality['score'],
            'quality_checks' => $quality['checks'],
            'policy_version' => (string)($profile['policy_version'] ?? 'marketplace-premium-2026-08'),
            'manual_edit_pending_quality_recheck' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'pending',
        $stagingId,
    ]);
}

function cat_discard_staging_row(PDO $db, int $stagingId): void
{
    $stmt = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', error_message = COALESCE(NULLIF(error_message, ''), 'Descartado em lote pelo administrador.'), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$stagingId]);
}

function cat_mode_label(string $mode): string
{
    return match ($mode) {
        'direct' => 'publicado no campo do canal',
        'embedded' => 'incorporado em outro campo',
        default => 'apoio interno / nao enviado',
    };
}

function cat_limit_text(array $limits, string $key, string $label): string
{
    if (!array_key_exists($key, $limits)) return '';
    return $label . ': ' . (string)$limits[$key];
}

/** @param array<string,bool> $qualityChecks */
function cat_quality_gate_failures(array $qualityChecks): array
{
    $soft = array_flip(ai_catalog_soft_quality_checks());
    $hard = [];
    $softFailed = [];
    foreach ($qualityChecks as $check => $ok) {
        if ($ok) {
            continue;
        }
        if (isset($soft[$check])) {
            $softFailed[] = (string)$check;
        } else {
            $hard[] = (string)$check;
        }
    }
    return ['hard' => $hard, 'soft' => $softFailed];
}

/** @param array<string,mixed> $fieldMap */
function cat_before_text(array $before, array $fieldMap, string $key): string
{
    $value = $before[$key] ?? '';
    if (is_array($value)) $text = implode("\n", array_map('strval', $value));
    else $text = trim((string)$value);
    if ($text !== '') return $text;
    $field = is_array($fieldMap[$key] ?? null) ? $fieldMap[$key] : [];
    $mode = (string)($field['mode'] ?? 'internal');
    $target = trim((string)($field['target'] ?? ''));
    if ($mode === 'internal') return '— Nao existe valor externo separado neste canal. Este campo e apenas apoio interno.';
    if ($mode === 'embedded') return '— Nao existe valor separado no read-back; este conteudo e incorporado em: ' . ($target !== '' ? $target : 'outro campo do canal') . '.';
    return '— Campo vazio ou nao retornado pelo read-back atual do canal.';
}

function cat_active_product_sql(PDO $db, string $alias = 'p'): string
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

if (($_GET['ajax'] ?? '') === 'pending_ids') {
    header('Content-Type: application/json; charset=utf-8');
    $channel = strtolower(trim((string)($_GET['channel'] ?? '')));
    $rawLimit = (int)($_GET['limit'] ?? 10);
    $limit = $rawLimit <= 0 ? 5000 : max(1, min(5000, $rawLimit));
    if (!isset($channels[$channel])) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal invalido.']);
        exit;
    }
    $stmt = $db->prepare(
        'SELECT p.id FROM products p '
        . 'LEFT JOIN catalog_optimizations_staging latest '
        . 'ON latest.product_id = p.id AND latest.channel = ? '
        . 'WHERE ' . cat_active_product_sql($db, 'p') . ' '
        . "AND (latest.id IS NULL OR latest.status IN ('published','rejected','failed')) "
        . 'ORDER BY p.id ASC LIMIT ' . $limit
    );
    $stmt->execute([$channel]);
    echo json_encode(['product_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'pending_candidates') {
    header('Content-Type: application/json; charset=utf-8');
    $channel = strtolower(trim((string)($_GET['channel'] ?? '')));
    $rawLimit = (int)($_GET['limit'] ?? 0);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    if (!isset($channels[$channel])) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal invalido.']);
        exit;
    }
    $eligibilitySql = 'FROM products p '
        . 'LEFT JOIN catalog_optimizations_staging latest '
        . 'ON latest.product_id = p.id AND latest.channel = ? '
        . 'WHERE ' . cat_active_product_sql($db, 'p') . ' '
        . "AND (latest.id IS NULL OR latest.status IN ('published','rejected','failed')) ";
    $totalStmt = $db->prepare('SELECT COUNT(*) ' . $eligibilitySql);
    $totalStmt->execute([$channel]);
    $total = (int)$totalStmt->fetchColumn();
    $limit = $rawLimit <= 0 ? max(1, $total) : max(1, min(500, $rawLimit));
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.sku, p.image_url, p.description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 1 END AS has_image, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 1 END AS has_description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 1 END AS has_sku, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 30 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 10 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 5 END AS priority_score '
        . $eligibilitySql
        . 'ORDER BY priority_score DESC, p.id ASC LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute([$channel]);
    echo json_encode([
        'items' => $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'failed_items') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $db->query("SELECT id, product_id, channel, provider_used FROM catalog_optimizations_staging WHERE status = 'failed' ORDER BY created_at ASC LIMIT 100");
    echo json_encode(['items' => $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []]);
    exit;
}

$flashMessage = null;
$flashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('catalog-optimization', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessao expirou. Recarregue a pagina.';
    } else {
        $bulkAction = (string)($_POST['bulk_action'] ?? '');
        $selectedIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => (int)$value,
            (array)($_POST['selected_ids'] ?? [])
        ), static fn(int $value): bool => $value > 0)));

        if ($bulkAction !== '') {
            try {
                if ($selectedIds === []) throw new RuntimeException('Selecione ao menos um item para o lote.');
                if (!in_array($bulkAction, ['bulk_publish', 'bulk_reject', 'bulk_discard_available'], true)) throw new RuntimeException('Acao em lote invalida.');
                if ($bulkAction === 'bulk_publish' && ($_POST['bulk_confirm'] ?? '') !== '1') {
                    throw new RuntimeException('Confirme a publicacao em lote.');
                }

                $load = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
                $reject = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $publisher = $bulkAction === 'bulk_publish' ? new CatalogOptimizationPublisher($db) : null;
                $published = 0;
                $rejected = 0;
                $discarded = 0;
                $failed = [];

                foreach ($selectedIds as $stagingId) {
                    $load->execute([$stagingId]);
                    $row = $load->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) {
                        $failed[] = "#{$stagingId}: nao encontrado";
                        continue;
                    }
                    if ($bulkAction === 'bulk_discard_available') {
                        cat_discard_staging_row($db, $stagingId);
                        $discarded++;
                        continue;
                    }
                    if ($bulkAction === 'bulk_reject') {
                        $reject->execute([$stagingId]);
                        $rejected++;
                        continue;
                    }
                    try {
                        $product = ai_catalog_fetch_product($db, (int)$row['product_id']);
                        if ($product === null) throw new RuntimeException('Produto nao encontrado.');
                        $audit = cat_refresh_quality($db, $row, true);
                        $result = $publisher->publish($audit['row']);
                        $status = (string)($result['status'] ?? '');
                        if ($status === 'submitted' || $status === 'published') {
                            $published++;
                        } else {
                            $failed[] = "#{$stagingId}: retorno inesperado";
                        }
                    } catch (Throwable $exception) {
                        $failed[] = "#{$stagingId}: " . $exception->getMessage();
                    }
                }

                if ($bulkAction === 'bulk_reject') {
                    $flashMessage = "Lote concluido: {$rejected} item(ns) rejeitado(s).";
                } elseif ($bulkAction === 'bulk_discard_available') {
                    $extra = $failed === [] ? '' : ' Falhas: ' . implode(' | ', $failed) . '.';
                    $flashMessage = "Lote concluido: {$discarded} item(ns) descartado(s)." . $extra;
                } else {
                    $extra = $failed === [] ? '' : ' Falhas: ' . implode(' | ', $failed) . '.';
                    $flashMessage = "Lote concluido: {$published} item(ns) aprovados/publicados." . $extra;
                }
            } catch (Throwable $exception) {
                $flashError = 'Falha no lote: ' . $exception->getMessage();
            }
        } else {
            $action = (string)($_POST['action'] ?? '');
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $load = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
            $load->execute([$stagingId]);
            $row = $load->fetch(PDO::FETCH_ASSOC);

            if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'save', 'reject', 'regenerate'], true)) {
                $flashError = 'Requisicao invalida.';
            } elseif ($action === 'reject') {
                $stmt = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$stagingId]);
                $flashMessage = "O conteudo #{$stagingId} foi rejeitado e nada foi publicado.";
            } elseif ($action === 'regenerate') {
                try {
                    $instruction = trim((string)($_POST['regeneration_instruction'] ?? ''));
                    $provider = catalog_ai_normalize_provider((string)($_POST['provider'] ?? $row['provider_used']));
                    $channel = (string)$row['channel'];
                    $product = ai_catalog_fetch_product($db, (int)$row['product_id']);
                    if ($product === null) throw new RuntimeException('Produto nao encontrado.');
                    $systemPrompt = ai_catalog_build_system_prompt($channel);
                    $userPrompt = ai_catalog_build_user_prompt($product, $channel);
                    if ($instruction !== '') $userPrompt .= "\n\nALTERACAO SOLICITADA PELO ADMINISTRADOR: {$instruction}\nAplique a alteracao sem inventar especificacoes.";
                    $data = catalog_ai_make_provider($provider)->complete($systemPrompt, $userPrompt);
                    ai_catalog_validate_ai_response($data, $channel, $product);
                    cat_update_generated_row($db, $stagingId, $data, $provider, $channel, $product);
                    $flashMessage = "Conteudo #{$stagingId} regenerado e revalidado pela politica de {$channels[$channel]}.";
                } catch (Throwable $exception) {
                    $flashError = 'Falha na regeneracao real: ' . $exception->getMessage();
                }
            } else {
                try {
                    $channel = (string)$row['channel'];
                    if ($action === 'publish' && ($_POST['confirm_channel'] ?? '') !== $channel) throw new RuntimeException('Confirme explicitamente o canal de destino.');
                    $updated = catalog_draft_save($db, $stagingId, $_POST);
                    $audit = cat_refresh_quality($db, $updated, $action === 'publish');
                    $updated = $audit['row'];

                    if ($action === 'save') {
                        $flashMessage = $audit['failed'] === []
                            ? "Alteracoes do conteudo #{$stagingId} salvas. Quality gate: {$audit['quality']['score']}/100. Nada foi publicado."
                            : "Rascunho #{$stagingId} salvo sem publicar. Quality gate: {$audit['quality']['score']}/100; corrija: " . implode(', ', $audit['failed']) . '.';
                    } else {
                        $result = (new CatalogOptimizationPublisher($db))->publish($updated);
                        $status = (string)($result['status'] ?? '');
                        $label = $channels[$channel] ?? $channel;
                        $flashMessage = $status === 'submitted'
                            ? "Conteudo #{$stagingId} enviado somente para {$label}; o canal ainda esta processando/auditando."
                            : "Conteudo #{$stagingId} publicado e confirmado somente em {$label}.";
                    }
                } catch (Throwable $exception) {
                    $flashError = $action === 'save'
                        ? 'As alteracoes nao foram salvas: ' . $exception->getMessage()
                        : 'A publicacao real foi bloqueada ou nao confirmada: ' . $exception->getMessage();
                }
            }
        }
    }
}

$metrics = [];
$stmt = $db->query('SELECT channel, status, COUNT(*) total FROM catalog_optimizations_staging GROUP BY channel, status');
foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $metric) $metrics[(string)$metric['channel']][(string)$metric['status']] = (int)$metric['total'];
$stmt = $db->query("SELECT * FROM catalog_optimizations_staging WHERE status IN ('pending','publication_failed') ORDER BY created_at DESC LIMIT 50");
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = sv_csrf_token('catalog-optimization');
$providerHealth = cat_provider_health_snapshot();
$providerAuditTail = cat_provider_audit_tail();
$channelUiProfiles = [];
foreach ($channels as $key => $label) {
    $profile = sv_catalog_channel_profile((string)$key);
    $limits = is_array($profile['limits'] ?? null) ? $profile['limits'] : [];
    $fieldMap = is_array($profile['field_map'] ?? null) ? $profile['field_map'] : [];
    $directFields = [];
    $embeddedFields = [];
    foreach ($fieldMap as $field) {
        $mode = (string)($field['mode'] ?? 'internal');
        $name = (string)($field['label'] ?? '');
        if ($name === '') continue;
        if ($mode === 'direct') $directFields[] = $name;
        if ($mode === 'embedded') $embeddedFields[] = $name;
    }
    $channelUiProfiles[(string)$key] = [
        'label' => (string)$label,
        'policy' => (string)($profile['policy_version'] ?? ''),
        'limits' => $limits,
        'notes' => array_values(array_map('strval', (array)($profile['seo_notes'] ?? []))),
            'direct_fields' => array_values(array_unique($directFields)),
            'embedded_fields' => array_values(array_unique($embeddedFields)),
            'internal_fields' => array_values(array_unique(array_map(
                static fn(array $field): string => (string)($field['label'] ?? ''),
                array_filter($fieldMap, static fn(array $field): bool => (string)($field['mode'] ?? 'internal') === 'internal')
            ))),
    ];
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Otimizacao e publicacao real</title><link rel="stylesheet" href="/css/style.css"><style>
.candidate-selector{margin:12px 0;border:1px solid #dfe5eb;border-radius:8px;background:#f8fafc}.candidate-selector summary{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 12px;cursor:pointer;color:#1e3a5f}.candidate-selector summary span{font-size:12px;font-weight:800;color:#526071}.candidate-selector .candidate-list{margin:0;border:0;border-top:1px solid #dfe5eb;border-radius:0;max-height:560px}.candidate-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:10px 0}.candidate-pagination button{border:0;border-radius:7px;padding:8px 12px;font-weight:800;cursor:pointer;background:#e8f4fd;color:#075985}.candidate-pagination button:disabled{opacity:.45;cursor:not-allowed}
*{box-sizing:border-box}body{background:#f4f6f8}.wrap{max-width:1420px;margin:20px auto 34px;padding:0 16px;font-family:system-ui,-apple-system,sans-serif;color:#111827}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:8px}.top h1{margin:0;font-size:26px;line-height:1.15}.alert,.panel,.item,.metric{background:#fff;border:1px solid #dfe5eb;border-radius:8px;padding:14px;margin:12px 0}.ok{background:#ecfdf3;color:#175b28;border-color:#c9efd4}.err{background:#fff1f1;color:#7b1717;border-color:#f3c7c7}.warn{background:#fff8e1;color:#6b5300;border-color:#f4dda0}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:10px;margin:14px 0 18px}.metric{margin:0;min-height:90px;display:grid;align-content:center;gap:3px}.metric strong{font-size:26px;line-height:1;font-weight:800}.metric div{font-weight:800}.metric small{color:#526071}.controls,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.controls{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px}.controls label,.edit label,.regen label{display:grid;gap:5px;font-weight:700}.controls input,.controls select,.edit input,.edit textarea,.regen textarea,.regen select{padding:10px 11px;border:1px solid #bfc8d1;border-radius:7px;box-sizing:border-box;font:inherit;font-size:15px;max-width:100%;background:#fff}.compare{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:16px}.compare>*{min-width:0}@media(max-width:900px){.compare{grid-template-columns:minmax(0,1fr)}.wrap{padding:0 10px;margin-top:12px}.item{padding:12px}.top h1{font-size:23px}.generation-grid{grid-template-columns:1fr}.candidate-shell{grid-template-columns:1fr}}.before{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:7px;min-height:44px;overflow-wrap:anywhere;word-break:break-word;margin:4px 0 11px}.edit input,.edit textarea,.regen textarea{width:100%;min-width:0}.badge,.field-badge,.status-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:#e8f4fd;font-weight:800;font-size:12px;color:#075985}.field-badge{font-size:11px;margin-left:6px;background:#eef2f7;color:#475569}.field-badge.direct{background:#dcfce7;color:#166534}.field-badge.embedded{background:#fef3c7;color:#854d0e}.field-badge.internal{background:#f1f5f9;color:#475569}.status-badge.pending,.status-badge.publication_failed{background:#fee2e2;color:#991b1b}.status-badge.published,.status-badge.submitted{background:#dcfce7;color:#166534}.endpoints code{display:block;margin:4px 0;white-space:normal;overflow-wrap:anywhere}.publish,.save,.regenerate,.reject,.controls button{border:0;border-radius:7px;padding:10px 14px;font-weight:800;cursor:pointer}.publish{background:#1a7f37;color:#fff}.save{background:#5f6368;color:#fff}.regenerate{background:#1769aa;color:#fff}.reject{background:#c62828;color:#fff}.confirm{display:block;padding:10px;background:#fff8e1;border:1px solid #f4dda0;border-radius:7px;margin:12px 0}.draft-note{font-size:13px;background:#fff;border:1px solid #dbe3ea;border-left:5px solid #1769aa;border-radius:8px;padding:12px 14px;margin:12px 0;color:#243242}.log{white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;display:none;max-height:360px;min-height:140px;overflow:auto}.profile{background:#fbfcfe;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:12px 0}.profile h4{margin:0 0 8px}.profile ul{margin:6px 0;padding-left:20px}.field-map{display:grid;gap:6px}.field-map-row{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:8px;padding:7px;background:#fff;border-radius:7px;border:1px solid #edf0f3}.field-map-row span:last-child{overflow-wrap:anywhere}.details-grid{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:6px 10px;margin:8px 0}.details-grid dt{font-weight:700}.details-grid dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.quality{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}.score{font-size:18px;font-weight:800}.checks{display:flex;gap:6px;flex-wrap:wrap}.check{font-size:11px;padding:3px 6px;border-radius:999px}.check.okc{background:#dcfce7;color:#166534}.check.badc{background:#fee2e2;color:#991b1b}.char-count{font-size:12px;color:#64748b;text-align:right}.source-title{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}.before-label{display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:7px}.bulk-panel{display:grid;gap:10px}.bulk-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.bulk-toolbar label{display:grid;gap:5px;font-weight:700}.bulk-toolbar input,.bulk-toolbar select{padding:10px;border:1px solid #bfc8d1;border-radius:7px;font:inherit;font-size:15px}.bulk-toolbar button{border:0;border-radius:7px;padding:10px 14px;font-weight:800;cursor:pointer}.bulk-publish{background:#1a7f37;color:#fff}.bulk-reject{background:#c62828;color:#fff}.bulk-clear{background:#5f6368;color:#fff}.item-select{display:inline-flex;align-items:center;gap:8px;font-weight:800;border:1px solid #cfd8e3;border-radius:7px;background:#eef2f7;padding:8px 10px;margin-top:8px}.item-select input{transform:scale(1.08)}.channel-pill{display:inline-flex;align-items:center;gap:6px;background:#e0f2fe;color:#075985;border-radius:999px;padding:4px 10px;font-weight:800;font-size:12px}.generation-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(360px,.75fr);gap:12px;margin-top:12px}.candidate-list{display:grid;gap:8px;max-height:520px;overflow:auto;padding:6px;border:1px solid #dfe5eb;border-radius:8px;background:#f8fafc}.candidate-item{display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid #dde4eb;border-radius:8px;background:#fff;cursor:pointer}.candidate-item:hover{border-color:#9dc4e6}.candidate-item input{margin-top:4px;transform:scale(1.08)}.candidate-meta{display:grid;gap:2px;font-size:13px;color:#475569}.candidate-name{font-weight:800;color:#111827}.candidate-actions{display:flex;gap:8px;flex-wrap:wrap}.candidate-actions button{border:0;border-radius:7px;padding:8px 12px;font-weight:800;cursor:pointer}.candidate-load{background:#1769aa;color:#fff}.candidate-run{background:#1a7f37;color:#fff}.panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.panel-title h2{margin:0}.candidate-summary-box{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.candidate-summary-box span,.channel-policy span{background:#eef2f7;border:1px solid #dfe5eb;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;color:#334155}.channel-policy{display:grid;gap:8px;margin:10px 0;padding:11px 12px;background:#fbfcfe;border:1px solid #dfe5eb;border-radius:8px}.channel-policy h3{font-size:15px;margin:0}.channel-policy .chips{display:flex;gap:8px;flex-wrap:wrap}.channel-policy ul{margin:0;padding-left:19px;color:#334155}
.quality.bad .score{color:#991b1b}.quality.good .score{color:#166534}.quality-status{font-size:12px;font-weight:800;padding:4px 8px;border-radius:999px;background:#eef2f7}.quality-status.bad{background:#fee2e2;color:#991b1b}.quality-status.good{background:#dcfce7;color:#166534}.gate-note{margin:8px 0 0}.publish:disabled{opacity:.55;cursor:not-allowed}
</style></head><body><main class="wrap">
<div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div><div class="top"><h1>Otimizacao de cadastro — por marketplace</h1><span class="badge">Preco e estoque protegidos</span></div>
<div class="draft-note"><strong>Auditoria por canal:</strong> o lado “Antes” sempre mostra os sete campos editoriais, mesmo quando o marketplace nao possui um campo externo equivalente. Assim fica explicito o que existe no read-back real, o que e incorporado e o que e apenas apoio interno.</div>
<?php if($providerHealth!==[]): ?><div class="alert warn"><strong>Estado dos provedores:</strong> <?php $parts=[];foreach($providerHealth as $name=>$expiry){$parts[] = cat_h((string)$name) . ' → ' . ((int)$expiry > time() ? 'cooldown até ' . cat_h(date('Y-m-d H:i:s', (int)$expiry)) : 'livre');} echo implode(' · ', $parts); ?></div><?php endif; ?>
<?php if($providerAuditTail!==[]): ?><details class="panel"><summary><strong>Últimos eventos de IA do catálogo</strong></summary><div class="grid" style="margin-top:12px"><?php foreach($providerAuditTail as $event): ?><div class="item"><div class="meta"><strong><?=cat_h((string)($event['provider'] ?? ''))?></strong> · <?=cat_h((string)($event['status'] ?? ''))?> · <?=cat_h(date('Y-m-d H:i:s', (int)($event['ts'] ?? 0)))?></div><div><?=cat_h((string)($event['message'] ?? ''))?></div><div class="meta">Produto <?=cat_h((string)($event['product_id'] ?? ''))?> · Canal <?=cat_h((string)($event['channel'] ?? ''))?></div></div><?php endforeach; ?></div></details><?php endif; ?>
<?php if($flashMessage):?><div class="alert ok"><?=cat_h($flashMessage)?></div><?php endif;?><?php if($flashError):?><div class="alert err"><?=cat_h($flashError)?></div><?php endif;?>
<section class="metrics"><?php foreach($channels as $key=>$label):$m=$metrics[$key]??[];?><div class="metric"><strong><?=(int)($m['published']??0)?></strong><div><?=cat_h($label)?></div><small>Pendentes <?=(int)($m['pending']??0)?> · Enviados <?=(int)($m['submitted']??0)?> · Falhas <?=(int)($m['publication_failed']??0)?></small></div><?php endforeach;?></section>
<section class="panel"><div class="panel-title"><h2>Saude dos provedores</h2><button type="button" id="cat-health-btn" class="regenerate" style="margin:0">Testar conexao (sem gastar credito)</button></div><div id="cat-health-result" class="strategy"></div></section>
<section class="panel"><div class="panel-title"><h2>Gerar fila por canal</h2><span class="badge">selecao manual</span></div><div class="controls"><label>Provedor<select id="provider"><?=cat_provider_options('openai', $providerLabels)?></select></label><label>Canal<select id="channel"><?php foreach($channels as $key=>$label):?><option value="<?=cat_h($key)?>"><?=cat_h($label)?></option><?php endforeach;?></select></label><label>Itens (0 = todos)<input id="load-limit" type="number" min="0" max="500" value="0"></label><div class="candidate-actions"><button id="load-candidates" class="candidate-load" type="button">Carregar itens</button><button id="select-candidates" type="button">Selecionar todos os exibidos</button><button id="clear-candidates" type="button">Limpar</button><button id="run" class="candidate-run" type="button">Gerar selecionados</button><button id="retry" type="button">Reprocessar falhas</button></div></div><div id="channel-policy" class="channel-policy"><h3>Campos do canal</h3><div class="chips"><span>Selecione um canal</span></div></div><div class="generation-grid"><div><small>Escolha individualmente entre todos os itens ativos elegiveis. O valor 0 carrega toda a listagem; use um numero somente se quiser reduzir a exibicao.</small><div id="candidate-summary" class="candidate-summary-box"><span>Carregue os itens do canal</span></div><details id="candidate-selector" class="candidate-selector" open><summary><strong>Itens ativos elegiveis</strong><span>Abra ou recolha a listagem</span></summary><div id="candidate-list" class="candidate-list"><div class="candidate-meta">Carregue os itens do canal para selecionar.</div></div></details></div><div><pre id="log" class="log"></pre></div></div></section>
<section class="panel bulk-panel"><h2>Acoes em lote</h2><form id="bulk-action-form" method="post"><input type="hidden" name="csrf_token" value="<?=cat_h($csrf)?>"><div class="bulk-toolbar"><label>Acao<select name="bulk_action" required><option value="bulk_publish">Aprovar e publicar selecionados</option><option value="bulk_reject">Rejeitar selecionados</option><option value="bulk_discard_available">Descartar disponiveis selecionados</option></select></label><label><input type="checkbox" name="bulk_confirm" value="1"> Confirmo que cada item sera tratado apenas no seu canal alvo.</label><button class="bulk-publish" type="submit">Executar em lote</button><button class="bulk-clear" type="button" id="bulk-select-all">Selecionar tudo</button><button class="bulk-clear" type="button" id="bulk-clear">Limpar selecao</button></div></form><small>Selecione varios itens nos cartões abaixo. A publicacao em lote respeita o marketplace de cada linha e continua bloqueada se a validacao de qualidade falhar.</small></section>
<h2>Antes e depois — dados reais, campos reais e aprovacao</h2><?php if($items===[]):?><div class="panel">Nenhum conteudo aguardando decisao.</div><?php endif;?>
<?php foreach($items as $item):
$pid=(int)$item['product_id'];$channel=(string)$item['channel'];$local=cat_original($db,$pid);$before=sv_catalog_channel_snapshot($db,$pid,$channel,$local);$profile=sv_catalog_channel_profile($channel);$label=$channels[$channel]??$channel;$bullets=cat_json_list($item['bullet_points_json']??'[]');$meta=json_decode((string)($item['meta_data_json']??'{}'),true);$meta=is_array($meta)?$meta:[];$qualityChecks=is_array($meta['quality_checks']??null)?$meta['quality_checks']:[];$qualityScore=is_numeric($meta['quality_score']??null)?(int)$meta['quality_score']:null;$qualityFailures=cat_quality_gate_failures($qualityChecks);$hardFailedChecks=$qualityFailures['hard'];$softFailedChecks=$qualityFailures['soft'];$gateApproved=$hardFailedChecks===[];$gateLabel=$gateApproved?($softFailedChecks===[]?'aprovado':'aprovado com alertas'):'reprovado pelo quality gate';$ep=$endpointRegistry[$channel]??null;$fieldMap=is_array($profile['field_map']??null)?$profile['field_map']:[];$limits=is_array($profile['limits']??null)?$profile['limits']:[];
?>
<article class="item"><div class="source-title"><div><span class="badge"><?=cat_h($label)?></span> Produto #<?=$pid?> · SKU <?=cat_h((string)$local['sku'])?> <span class="status-badge <?=cat_h((string)$item['status'])?>"><?=cat_h((string)$item['status'])?></span></div><small>Politica <?=cat_h((string)($profile['policy_version']??''))?></small></div><label class="item-select"><input type="checkbox" name="selected_ids[]" value="<?=(int)$item['id']?>" form="bulk-action-form"> Selecionar para lote</label>
<?php if($before['warning']!==''):?><div class="alert warn"><?=cat_h($before['warning'])?></div><?php endif;?>
<div class="profile"><h4>SEO e catalogo especificos de <?=cat_h($label)?></h4><div class="channel-pill">Canal alvo: <?=cat_h($label)?></div><ul><?php foreach((array)($profile['seo_notes']??[]) as $note):?><li><?=cat_h((string)$note)?></li><?php endforeach;?></ul><?php if($limits!==[]):?><small>Limites/alvos: <?php $pairs=[];foreach(['title_max'=>'teto do titulo','title_recommended_min'=>'alvo minimo','title_recommended_max'=>'alvo maximo','meta_title_target'=>'meta title','meta_description_target'=>'meta description','description_recommended_min'=>'descricao minima','bullet_max'=>'bullet max'] as $limitKey=>$labelText){$text=cat_limit_text($limits,$limitKey,$labelText);if($text!=='')$pairs[]=$text;}echo cat_h(implode(' · ',$pairs));?></small><?php endif;?></div>
<?php if($ep):?><details class="endpoints"><summary><strong>Endpoints reais usados neste canal</strong></summary><h4>Texto</h4><?php foreach($ep['text'] as $endpoint):?><code><?=cat_h($endpoint)?></code><?php endforeach;?><h4>Read-back</h4><?php foreach($ep['readback'] as $endpoint):?><code><?=cat_h($endpoint)?></code><?php endforeach;?></details><?php endif;?>
<details class="profile" open><summary><strong>Mapa de publicacao de cada campo</strong></summary><div class="field-map"><?php foreach($fieldMap as $key=>$field):$mode=(string)($field['mode']??'internal');?><div class="field-map-row"><strong><?=cat_h((string)($field['label']??$key))?> <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span></strong><span><?=cat_h((string)($field['target']??''))?></span></div><?php endforeach;?></div></details>
<?php if($qualityScore!==null):?><div class="quality <?= $gateApproved ? 'good' : 'bad' ?>"><span class="score">Quality gate <?=$qualityScore?>/100</span><span class="quality-status <?= $gateApproved ? 'good' : 'bad' ?>"><?=cat_h($gateLabel)?></span><div class="checks"><?php foreach($qualityChecks as $check=>$ok):?><span class="check <?=$ok?'okc':'badc'?>"><?=$ok?'✓':'✕'?> <?=cat_h((string)$check)?></span><?php endforeach;?></div><?php if($hardFailedChecks!==[]):?><div class="alert err gate-note">Reprovado pelo quality gate: <?=cat_h(implode(', ', $hardFailedChecks))?>.</div><?php elseif($softFailedChecks!==[]):?><div class="alert warn gate-note">Ajustes recomendados antes de publicar: <?=cat_h(implode(', ', $softFailedChecks))?>.</div><?php endif;?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=cat_h($csrf)?>"><input type="hidden" name="staging_id" value="<?=(int)$item['id']?>"><div class="compare">
<div><h3>Antes — <?=cat_h((string)$before['source'])?></h3>
<?php foreach([
    'title'=>'Titulo','description'=>'Descricao','bullet_points'=>'Bullet points / selling points','seo_keywords'=>'SEO keywords / search terms','marketing_hooks'=>'Marketing hooks','meta_title'=>'Meta title','meta_description'=>'Meta description'
] as $fieldKey=>$fieldLabel):$mapKey=match($fieldKey){'title'=>'optimized_title','description'=>'optimized_description',default=>$fieldKey};$fm=$fieldMap[$mapKey]??[];$mode=(string)($fm['mode']??'internal');?><div class="before-label"><b><?=cat_h($fieldLabel)?></b><span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span></div><div class="before"><?=cat_h(cat_before_text($before,$fieldMap,$fieldKey))?></div><?php endforeach;?>
<h4>Identidade, categoria e atributos do cadastro real</h4><dl class="details-grid"><?php foreach($before['details'] as $key=>$value):if(trim((string)$value)==='')continue;?><dt><?=cat_h((string)$key)?></dt><dd><?=cat_h((string)$value)?></dd><?php endforeach;?></dl></div>
<div class="edit"><h3>Depois — editavel</h3>
<?php $fm=$fieldMap['optimized_title']??[];$mode=(string)($fm['mode']??'internal');?><label>Titulo <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><input name="optimized_title" value="<?=cat_h((string)$item['optimized_title'])?>" required data-counter="title"<?=isset($limits['title_max'])?' maxlength="'.(int)$limits['title_max'].'"':''?>><span class="char-count" data-count-for="title"></span><?php if(isset($limits['title_max'])||isset($limits['title_recommended_min'])||isset($limits['title_recommended_max'])):?><small>Canal: <?php echo cat_h(implode(' · ', array_values(array_filter([
    isset($limits['title_max']) ? 'teto ' . (int)$limits['title_max'] : '',
    isset($limits['title_recommended_min']) ? 'alvo minimo ' . (int)$limits['title_recommended_min'] : '',
    isset($limits['title_recommended_max']) ? 'alvo maximo ' . (int)$limits['title_recommended_max'] : '',
])))); ?></small><?php endif;?></label>
<?php $fm=$fieldMap['optimized_description']??[];$mode=(string)($fm['mode']??'internal');?><label>Descricao <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="optimized_description" rows="9" required data-counter="description"><?=cat_h((string)$item['optimized_description'])?></textarea><span class="char-count" data-count-for="description"></span></label>
<?php $fm=$fieldMap['bullet_points']??[];$mode=(string)($fm['mode']??'internal');?><label>Bullet points / selling points <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="bullet_points" rows="6"><?=cat_h(implode("\n",$bullets))?></textarea><small>Um ponto por linha.</small></label>
<?php $fm=$fieldMap['seo_keywords']??[];$mode=(string)($fm['mode']??'internal');?><label><?=cat_h((string)($fm['label']??'SEO keywords'))?> <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="seo_keywords" rows="3"><?=cat_h((string)$item['seo_keywords'])?></textarea><small>Uma por linha ou separadas por virgula. <?=cat_h((string)($fm['target']??''))?></small></label>
<?php $fm=$fieldMap['marketing_hooks']??[];$mode=(string)($fm['mode']??'internal');?><label>Marketing hooks <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="marketing_hooks" rows="3"><?=cat_h(str_replace(' | ',"\n",(string)$item['marketing_hooks']))?></textarea><small>Um por linha.</small></label>
<?php $fm=$fieldMap['meta_title']??[];$mode=(string)($fm['mode']??'internal');?><label>Meta title <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><input name="meta_title" value="<?=cat_h((string)($meta['meta_title']??''))?>" data-counter="meta-title"><span class="char-count" data-count-for="meta-title"></span></label>
<?php $fm=$fieldMap['meta_description']??[];$mode=(string)($fm['mode']??'internal');?><label>Meta description <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="meta_description" rows="3" data-counter="meta-description"><?=cat_h((string)($meta['meta_description']??''))?></textarea><span class="char-count" data-count-for="meta-description"></span></label>
</div></div><div class="actions"><button class="save" name="action" value="save">Salvar e reauditar sem publicar</button></div>
<div class="regen"><h3>Alterar a geracao antes de aprovar</h3><label>Nova instrucao para a IA<textarea name="regeneration_instruction" rows="3" placeholder="Ex.: priorizar o modelo no titulo, retirar repeticao, reforcar um atributo comprovado..."></textarea></label><label>Provedor<select name="provider"><?=cat_provider_options((string)($item['provider_used'] ?? 'openai'), $providerLabels)?></select></label><button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar e reauditar</button></div>
<label class="confirm"><input type="checkbox" name="confirm_channel" value="<?=cat_h($channel)?>"> Confirmo publicar <strong>somente em <?=cat_h($label)?></strong>. O quality gate sera executado novamente antes da chamada real.</label><div class="actions"><button class="publish" name="action" value="publish" <?=$gateApproved ? '' : 'disabled title="Publicacao bloqueada enquanto houver falhas hard no quality gate."'?>>Aprovar e publicar somente em <?=cat_h($label)?></button><button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button></div></form></article><?php endforeach;?></main>
<script>(()=>{'use strict';const channelProfiles=<?=json_encode($channelUiProfiles,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const log=document.getElementById('log');const write=t=>{log.style.display='block';log.textContent+=t+'\n';log.scrollTop=log.scrollHeight};const channelEl=document.getElementById('channel');const providerEl=document.getElementById('provider');const loadLimitEl=document.getElementById('load-limit');const candidateList=document.getElementById('candidate-list');const candidateSummary=document.getElementById('candidate-summary');const channelPolicy=document.getElementById('channel-policy');const loadBtn=document.getElementById('load-candidates');const runBtn=document.getElementById('run');const selectBtn=document.getElementById('select-candidates');const clearBtn=document.getElementById('clear-candidates');function listText(values){return values&&values.length?values.join(', '):'nenhum'}function renderChannelPolicy(){if(!channelPolicy)return;const profile=channelProfiles[channelEl.value]||{};const notes=(profile.notes||[]).slice(0,2).map(n=>`<li>${String(n).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</li>`).join('');channelPolicy.innerHTML=`<h3>Campos do canal: ${profile.label||channelEl.value}</h3><div class=\"chips\"><span>Diretos: ${listText(profile.direct_fields)}</span><span>Incorporados: ${listText(profile.embedded_fields)}</span><span>Internos: ${listText(profile.internal_fields)}</span></div>${notes?`<ul>${notes}</ul>`:''}`;}const catalogDelay=ms=>new Promise(r=>setTimeout(r,ms));async function optimize(id,channel,provider){const r=await fetch('/admin/catalog-optimization/api/optimize_catalog_resilient.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({product_id:id,target_channel:channel,provider})});const d=await r.json();if(!r.ok&&!d.error)d.error='HTTP '+r.status;return d}function candidateCheckboxes(){return [...candidateList.querySelectorAll('input[data-candidate-id]')]}function updateCandidateState(){const selected=candidateCheckboxes().filter(cb=>cb.checked).length;if(runBtn)runBtn.textContent=selected>0?`Gerar selecionados (${selected})`:'Gerar selecionados'}function renderSummary(totals){if(!candidateSummary)return;candidateSummary.innerHTML=`<span>Total ${totals.total}</span><span>Prontos ${totals.ready}</span><span>Incompletos ${totals.incomplete}</span>`}function renderCandidates(items){if(!items.length){candidateList.innerHTML='<div class=\"candidate-meta\">Nenhum item disponivel para este canal.</div>';if(candidateSummary)candidateSummary.innerHTML='<span>Nenhum candidato disponivel neste canal</span>';updateCandidateState();return}const totals=items.reduce((acc,item)=>{acc.total+=1;acc.ready+=(item.has_image&&item.has_description&&item.has_sku)?1:0;acc.incomplete+=(!item.has_image||!item.has_description||!item.has_sku)?1:0;return acc;},{total:0,ready:0,incomplete:0});renderSummary(totals);candidateList.innerHTML=items.map(item=>{const flags=[item.has_image?'com foto':'sem foto',item.has_description?'com descricao':'sem descricao',item.has_sku?'com SKU':'sem SKU'];return `<label class=\"candidate-item\"><input type=\"checkbox\" data-candidate-id=\"${item.id}\" value=\"${item.id}\"><span class=\"candidate-meta\"><span class=\"candidate-name\">#${item.id} · ${item.name||'(sem nome)'}</span><span>SKU: ${item.sku||'(sem SKU)'} · Categoria: ${item.category||'(sem categoria)'}</span><span>Prioridade: ${item.priority_score ?? 0} · ${flags.join(' · ')}</span></span></label>`}).join('');updateCandidateState()}async function loadCandidates(){loadBtn.disabled=true;candidateList.innerHTML='<div class=\"candidate-meta\">Carregando itens...</div>';if(candidateSummary)candidateSummary.innerHTML='<span>Carregando fila do canal</span>';try{const channel=channelEl.value,limit=loadLimitEl.value;const r=await fetch(`?ajax=pending_candidates&channel=${encodeURIComponent(channel)}&limit=${encodeURIComponent(limit)}`,{credentials:'same-origin'});const d=await r.json();renderCandidates(d.items||[])}catch(err){candidateList.innerHTML='<div class=\"candidate-meta\">Falha ao carregar itens.</div>';if(candidateSummary)candidateSummary.innerHTML='<span>Falha ao carregar a fila</span>';write('ERRO: '+err.message)}finally{loadBtn.disabled=false}}loadBtn.addEventListener('click',loadCandidates);channelEl.addEventListener('change',()=>{renderChannelPolicy();loadCandidates()});candidateList.addEventListener('change',e=>{if(e.target instanceof HTMLInputElement&&e.target.matches('input[data-candidate-id]')) updateCandidateState()});selectBtn.addEventListener('click',()=>{candidateCheckboxes().forEach(cb=>{cb.checked=true});updateCandidateState()});clearBtn.addEventListener('click',()=>{candidateCheckboxes().forEach(cb=>{cb.checked=false});updateCandidateState()});runBtn.addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';const channel=channelEl.value,provider=providerEl.value,selected=candidateCheckboxes().filter(cb=>cb.checked).map(cb=>cb.value);if(!selected.length){write('Selecione ao menos um item para gerar.');b.disabled=false;return}let ok=0,failed=0;try{for(let i=0;i<selected.length;i++){const id=selected[i];if(i>0)await catalogDelay(600);const out=await optimize(id,channel,provider);if(out.success){ok++;write(`#${id}: rascunho #${out.staging_id||'?'} · IA ${out.provider_used||provider} · qualidade ${out.quality_score ?? '?'}${out.needs_review?' · revisar alertas':''}`)}else{failed++;write(`#${id}: falhou ${out.error||''}`)}}write(`Concluido: ${ok} rascunho(s), ${failed} falha(s). Nada foi publicado automaticamente.`);await loadCandidates();location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});document.getElementById('retry').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';let ok=0,failed=0;try{const r=await fetch('?ajax=failed_items',{credentials:'same-origin'}),d=await r.json();const items=d.items||[];for(let i=0;i<items.length;i++){const item=items[i];if(i>0)await catalogDelay(600);const out=await optimize(item.product_id,item.channel,item.provider_used);if(out.success){ok++;write(`#${item.product_id}: reprocessado em rascunho #${out.staging_id||'?'}`)}else{failed++;write(`#${item.product_id}: falhou ${out.error||''}`)}}write(`Reprocessamento concluido: ${ok} rascunho(s), ${failed} falha(s).`);location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});const bulkCheckboxes=[...document.querySelectorAll('input[name=\"selected_ids[]\"][form=\"bulk-action-form\"]')];document.getElementById('bulk-select-all')?.addEventListener('click',()=>{bulkCheckboxes.forEach(cb=>{cb.checked=true})});document.getElementById('bulk-clear')?.addEventListener('click',()=>{bulkCheckboxes.forEach(cb=>{cb.checked=false})});document.querySelectorAll('[data-counter]').forEach(el=>{const target=el.closest('label')?.querySelector(`[data-count-for=\"${el.dataset.counter}\"]`);if(!target)return;const update=()=>{target.textContent=`${el.value.length} caracteres${el.maxLength>0?' / '+el.maxLength:''}`};el.addEventListener('input',update);update()});renderChannelPolicy();loadCandidates();(()=>{const hbtn=document.getElementById('cat-health-btn');const hout=document.getElementById('cat-health-result');if(!hbtn||!hout)return;const labels={openai:'OpenAI',gemini:'Gemini',claude:'Claude',openrouter:'OpenRouter',groq:'Groq'};hbtn.addEventListener('click',async()=>{hbtn.disabled=true;hbtn.textContent='Testando...';hout.innerHTML='';try{const r=await fetch('/admin/catalog-optimization/api/provider_health_check.php',{credentials:'same-origin'});const d=await r.json();if(!d.success)throw new Error(d.error||'Falha ao testar provedores.');hout.innerHTML=Object.entries(d.providers).map(([key,info])=>`<div style="border-left:4px solid ${info.ok?'#1a7f37':'#c62828'}"><strong>${info.ok?'✓':'✕'} ${labels[key]||key}</strong><br>${info.detail}</div>`).join('')}catch(err){hout.innerHTML=`<div style="border-left:4px solid #c62828">Erro: ${err.message}</div>`}finally{hbtn.disabled=false;hbtn.textContent='Testar conexao (sem gastar credito)'}})})()})();</script></body></html>
