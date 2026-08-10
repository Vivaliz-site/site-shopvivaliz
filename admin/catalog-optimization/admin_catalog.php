<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/release-info.php';
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
$releaseInfo = sv_release_info_from_path(__DIR__);
$channels = catalog_ai_channels();
$endpointRegistry = sv_market_catalog_endpoint_registry();

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
 * @param array<string,bool> $qualityChecks
 * @return list<string>
 */
function cat_quality_gate_failures(array $qualityChecks): array
{
    return array_values(array_keys(array_filter($qualityChecks, static fn(bool $ok): bool => !$ok)));
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
    $failed = array_values(array_keys(array_filter($quality['checks'], static fn(bool $ok): bool => !$ok)));

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

function cat_mode_label(string $mode): string
{
    return match ($mode) {
        'direct' => 'publicado no campo do canal',
        'embedded' => 'incorporado em outro campo',
        default => 'apoio interno / nao enviado',
    };
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

if (($_GET['ajax'] ?? '') === 'pending_ids') {
    header('Content-Type: application/json; charset=utf-8');
    $channel = strtolower(trim((string)($_GET['channel'] ?? '')));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
    if (!isset($channels[$channel])) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal invalido.']);
        exit;
    }
    $stmt = $db->prepare(
        'SELECT p.* FROM products p '
        . 'LEFT JOIN catalog_optimizations_staging latest ON latest.id = ('
        . 'SELECT s2.id FROM catalog_optimizations_staging s2 WHERE s2.product_id = p.id AND s2.channel = ? ORDER BY s2.created_at DESC, s2.id DESC LIMIT 1) '
        . "WHERE latest.id IS NULL OR latest.status IN ('published','rejected','failed') ORDER BY p.id ASC LIMIT " . $limit
    );
    $stmt->execute([$channel]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productIds = [];
    foreach ($rows as $row) {
        if (is_array($row) && ai_catalog_is_active_product_row($row)) {
            $productIds[] = (int)($row['id'] ?? 0);
        }
    }
    echo json_encode(['product_ids' => array_values(array_filter($productIds))]);
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
$defaultCatalogChannel = isset($channels['ml']) ? 'ml' : (string)(array_key_first($channels) ?? '');
$selectedProvider = 'openai';
$selectedChannel = $defaultCatalogChannel;
$submittedProductIds = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('catalog-optimization', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessao expirou. Recarregue a pagina.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'generate_selected') {
            $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
            $channel = strtolower(trim((string)($_POST['channel'] ?? '')));
            $selectedIds = array_values(array_unique(cat_json_list((array)($_POST['selected_product_ids'] ?? []))));
            $submittedProductIds = $selectedIds;

            if (in_array($provider, ['openai', 'gemini', 'claude'], true)) {
                $selectedProvider = $provider;
            }
            if (isset($channels[$channel])) {
                $selectedChannel = $channel;
            }

            if ($selectedIds === []) {
                $flashError = 'Selecione ao menos um produto para gerar a otimizacao.';
            } elseif (!isset($channels[$channel])) {
                $flashError = 'Canal invalido para geracao.';
            } elseif (!in_array($provider, ['openai', 'gemini', 'claude'], true)) {
                $flashError = 'Provedor invalido para geracao.';
            } else {
                $successCount = 0;
                $errors = [];
                foreach ($selectedIds as $selectedId) {
                    $result = ai_catalog_process_item($db, $selectedId, $channel, $provider);
                    if (($result['success'] ?? false) === true) {
                        $successCount++;
                    } else {
                        $errors[] = "#{$selectedId}: " . (string)($result['error'] ?? 'falha desconhecida');
                    }
                }

                if ($successCount > 0) {
                    $flashMessage = "{$successCount} item(ns) enviado(s) para geracao em {$channels[$channel]}.";
                }
                if ($errors !== []) {
                    $flashError = 'Falhas em ' . count($errors) . ' item(ns): ' . implode(' | ', array_slice($errors, 0, 3));
                    if (count($errors) > 3) {
                        $flashError .= ' ...';
                    }
                }
            }
        } elseif ($action === 'bulk_apply') {
            $bulkAction = (string)($_POST['bulk_action'] ?? '');
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])))));
            $load = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');

            if ($selectedIds === [] || !in_array($bulkAction, ['publish', 'reject', 'delete'], true)) {
                $flashError = 'Selecione itens e uma acao em massa valida.';
            } else {
                $processed = 0;
                $errors = [];
                $publisher = $bulkAction === 'publish' ? new CatalogOptimizationPublisher($db) : null;

                foreach ($selectedIds as $selectedId) {
                    $load->execute([$selectedId]);
                    $row = $load->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) {
                        $errors[] = "#{$selectedId}: item nao encontrado.";
                        continue;
                    }

                    try {
                        if ($bulkAction === 'reject') {
                            $stmt = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                            $stmt->execute([$selectedId]);
                            $processed++;
                            continue;
                        }

                        if ($bulkAction === 'delete') {
                            $stmt = $db->prepare('DELETE FROM catalog_optimizations_staging WHERE id = ?');
                            $stmt->execute([$selectedId]);
                            $processed++;
                            continue;
                        }

                        $audit = cat_refresh_quality($db, $row, true);
                        $updated = $audit['row'];
                        if (!$publisher instanceof CatalogOptimizationPublisher) {
                            throw new RuntimeException('Publisher indisponivel para aprovacao em massa.');
                        }
                        $publisher->publish($updated);
                        $processed++;
                    } catch (Throwable $exception) {
                        $errors[] = "#{$selectedId}: " . $exception->getMessage();
                    }
                }

                $labels = [
                    'publish' => 'aprovado(s) e publicado(s)',
                    'reject' => 'rejeitado(s)',
                    'delete' => 'excluido(s) do staging',
                ];
                if ($processed > 0) {
                    $flashMessage = "{$processed} item(ns) {$labels[$bulkAction]} com sucesso.";
                }
                if ($errors !== []) {
                    $flashError = 'Falhas em ' . count($errors) . ' item(ns): ' . implode(' | ', array_slice($errors, 0, 3));
                    if (count($errors) > 3) {
                        $flashError .= ' ...';
                    }
                }
            }
        } else {
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
                    $provider = strtolower(trim((string)($_POST['provider'] ?? $row['provider_used'])));
                    $channel = (string)$row['channel'];
                    $product = ai_catalog_fetch_product($db, (int)$row['product_id']);
                    if ($product === null) {
                        throw new RuntimeException('Produto nao encontrado.');
                    }
                    $systemPrompt = ai_catalog_build_system_prompt($channel);
                    $userPrompt = ai_catalog_build_user_prompt($product, $channel);
                    if ($instruction !== '') {
                        $userPrompt .= "\n\nALTERACAO SOLICITADA PELO ADMINISTRADOR: {$instruction}\nAplique a alteracao sem inventar especificacoes.";
                    }
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
                    if ($action === 'publish' && ($_POST['confirm_channel'] ?? '') !== $channel) {
                        throw new RuntimeException('Confirme explicitamente o canal de destino.');
                    }
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
foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $metric) {
    $metrics[(string)$metric['channel']][(string)$metric['status']] = (int)$metric['total'];
}
$stmt = $db->query("SELECT * FROM catalog_optimizations_staging WHERE status IN ('pending','publication_failed') ORDER BY created_at DESC LIMIT 50");
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = sv_csrf_token('catalog-optimization');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Otimizacao de cadastro - ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
:root{color-scheme:light;--bg:#f4f7fb;--surface:#fff;--surface-soft:#f8fafc;--line:#d8e0ea;--text:#132135;--muted:#607186;--shadow:0 24px 60px rgba(15,23,42,.08);--radius:22px;--orange:#ea580c;--blue:#1769aa;--green:#1a7f37;--red:#c62828}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:linear-gradient(180deg,#eef4fb 0%,var(--bg) 34%,#edf2f7 100%);color:var(--text)}
.wrap{max-width:1400px;margin:0 auto;padding:24px 16px 40px}
.hero,.panel,.queue-item,.metric{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
.hero{padding:26px;background:linear-gradient(135deg,#111827 0%,#7c2d12 48%,#ea580c 100%);color:#fff}
.hero-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
.hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3.4rem);line-height:.96}
.hero p{margin:0;max-width:780px;color:rgba(255,255,255,.86);line-height:1.62}
.back-link,.hero-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:999px;padding:10px 14px;font-weight:800}
.back-link{background:#fff;color:#111827}
.hero-link{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);color:#fff}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.release-pill{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16);color:#fff;font-size:.82rem;font-weight:800;line-height:1.2}
.eyebrow{margin:0 0 10px;text-transform:uppercase;letter-spacing:.14em;font-size:.74rem;font-weight:800;color:rgba(255,255,255,.74)}
.alert{padding:14px 16px;border-radius:18px;margin-bottom:14px}
.alert.ok{background:#e8f5e9;color:#175b28}
.alert.err{background:#fdecea;color:#7b1717}
.alert.warn{background:#fff8e1;color:#6b5300}
.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0}
.metric{padding:18px}
.metric strong{display:block;font-size:1.8rem;margin-bottom:4px}
.metric small{display:block;color:var(--muted);line-height:1.45}
.panel{padding:22px;margin-bottom:18px}
.panel h2{margin:0 0 10px;font-size:1.28rem}
.panel p,.panel .muted{margin:0;color:var(--muted);line-height:1.58}
.draft-note{margin-top:16px;padding:14px 16px;border-radius:18px;background:#eef6ff;border-left:4px solid var(--blue);color:#19406c}
.controls,.actions,.queue-toolbar,.summary-main,.queue-summary-meta,.checks{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.controls{margin-top:18px}
.controls label,.edit label,.regen label{display:grid;gap:6px;font-weight:700}
.controls input,.controls select,.edit input,.edit textarea,.regen textarea,.regen select{padding:12px 14px;border:1px solid #c7d2df;border-radius:14px;font:inherit;font-size:15px;background:#fff;max-width:100%}
.controls .search{flex:1;min-width:min(340px,100%)}
.controls button,.publish,.save,.regenerate,.reject,.delete-btn,.queue-tool-btn{border:0;border-radius:999px;padding:12px 16px;font-weight:800;cursor:pointer;font:inherit}
.controls button,.queue-tool-btn{background:#e2e8f0;color:#0f172a}
.publish{background:var(--green);color:#fff}
.publish:disabled{background:#94a3b8;color:#f8fafc;cursor:not-allowed;opacity:.82}
.save{background:#4b5563;color:#fff}
.regenerate{background:var(--blue);color:#fff}
.reject{background:var(--red);color:#fff}
.delete-btn{background:#7f1d1d;color:#fff}
.source-title{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.badge,.field-badge,.queue-state,.queue-quality{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-weight:800;font-size:.78rem}
.badge{background:#fff;color:#111827}
.queue-state{background:#eef2ff;color:#3730a3}
.queue-state.fail{background:#fff7ed;color:#9a3412}
.queue-quality{background:#ecfdf5;color:#166534}
.queue-quality.approved{background:#dcfce7;color:#166534}
.queue-quality.rejected{background:#fee2e2;color:#991b1b}
.field-badge{font-size:.72rem;background:#eef2f7;color:#475569}
.field-badge.direct{background:#dcfce7;color:#166534}
.field-badge.embedded{background:#fef3c7;color:#854d0e}
.field-badge.internal{background:#f1f5f9;color:#475569}
.active-products-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px}
.product-card{display:flex;gap:12px;align-items:flex-start;padding:16px;border:1px solid var(--line);border-radius:20px;background:var(--surface-soft);cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
.product-card.is-selected{background:#eef6ff;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.product-card:hover{transform:translateY(-2px);border-color:#a9b8cb;box-shadow:0 16px 32px rgba(15,23,42,.08)}
.product-card input{margin-top:4px}
.product-card-title{font-weight:800;line-height:1.3}
.product-card-meta,.product-card-stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:.82rem;color:#455468}
.product-card-stats span{padding:4px 8px;border-radius:999px;background:#fff;border:1px solid #e2e8f0}
.empty-state{padding:20px;border:1px dashed #cbd5e1;border-radius:18px;background:#f8fafc;color:#475467}
.log{white-space:pre-wrap;background:#111827;color:#dbeafe;padding:14px;border-radius:18px;display:none;max-height:240px;overflow:auto;margin-top:14px}
.queue-header{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
.queue-toolbar{position:sticky;top:16px;z-index:5;margin-top:12px;background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:14px}
.queue-summary{display:flex;align-items:flex-start;gap:14px;width:100%}
.queue-summary input[type=checkbox]{margin-top:5px}
.queue-item{margin-top:14px;overflow:hidden}
.queue-item.is-selected{background:#eef6ff;border-color:#93c5fd}
.queue-item summary{list-style:none;cursor:pointer;padding:18px}
.queue-item summary::-webkit-details-marker{display:none}
.queue-item[open] summary{border-bottom:1px solid var(--line)}
.queue-main{flex:1;min-width:0}
.queue-title{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-weight:800}
.queue-summary-meta{margin-top:8px;color:var(--muted);font-size:.9rem}
.queue-body{padding:18px}
.compare{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}
.compare>*{min-width:0}
.before{white-space:pre-wrap;background:#f6f7f9;padding:12px;border-radius:16px;min-height:48px;overflow-wrap:anywhere;word-break:break-word;margin:6px 0 12px}
.before-label{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
.edit input,.edit textarea,.regen textarea{width:100%;min-width:0}
.profile{background:#fbfcfe;border:1px solid #e2e8f0;border-radius:18px;padding:14px;margin:14px 0}
.profile h4{margin:0 0 8px}
.profile ul{margin:6px 0;padding-left:20px}
.quality-note{margin-top:10px;padding:12px 14px;border-radius:16px;background:#fff8e1;color:#7a5b00;line-height:1.5}
.quality-note strong{display:block;margin-bottom:4px}
.endpoints code{display:block;margin:4px 0;white-space:normal;overflow-wrap:anywhere}
.field-map{display:grid;gap:8px}
.field-map-row{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:10px;padding:10px;background:#fff;border-radius:14px;border:1px solid #edf0f3}
.field-map-row span:last-child{overflow-wrap:anywhere}
.details-grid{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:6px 10px;margin:8px 0}
.details-grid dt{font-weight:700}
.details-grid dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
.quality{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0}
.score{font-size:1.05rem;font-weight:800}
.score.approved{color:#166534}
.score.rejected{color:#991b1b}
.check{font-size:.72rem;padding:4px 8px;border-radius:999px}
.check.okc{background:#dcfce7;color:#166534}
.check.badc{background:#fee2e2;color:#991b1b}
.confirm{display:block;padding:12px;background:#fff8e1;border-radius:16px;margin:14px 0}
.char-count{font-size:.8rem;color:#64748b;text-align:right}
@media(max-width:920px){.compare{grid-template-columns:minmax(0,1fr)}.wrap{padding:14px 10px 32px}.queue-summary{align-items:flex-start}}
</style>
</head>
<body>
<main class="wrap">
    <section class="hero">
        <div class="hero-top">
            <div>
                <a class="back-link" href="/admin/">Voltar ao admin</a>
                <p class="eyebrow" style="margin-top:16px">SEO e publicacao por canal</p>
                <h1>Otimizacao de cadastro por marketplace</h1>
                <p>
                    Gere apenas produtos ativos selecionados, revise o texto real de cada canal e aprove em lote
                    quando o quality gate estiver consistente. Os itens da fila agora ficam recolhidos por padrao
                    para deixar a pagina mais limpa.
                </p>
                <div class="hero-actions">
                    <a class="hero-link" href="/admin/connections.php">Checar credenciais</a>
                    <span class="badge">Preco e estoque protegidos</span>
                    <span class="release-pill" title="Release detectado via <?= cat_h($releaseInfo['source']) ?>">
                        Release <?= cat_h($releaseInfo['identifier']) ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <div class="draft-note">
        <strong>Auditoria por canal:</strong> o bloco “Antes” mostra sempre os sete campos editoriais, mesmo quando o
        marketplace nao possui um campo externo separado. Assim fica claro o que vem do read-back real, o que e
        incorporado em outro campo e o que existe apenas como apoio interno.
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert ok"><?= cat_h($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert err"><?= cat_h($flashError) ?></div>
    <?php endif; ?>

    <section class="metrics">
        <?php foreach ($channels as $key => $label): ?>
            <?php $m = $metrics[$key] ?? []; ?>
            <div class="metric">
                <strong><?= (int)($m['published'] ?? 0) ?></strong>
                <div><?= cat_h($label) ?></div>
                <small>
                    Pendentes <?= (int)($m['pending'] ?? 0) ?> ·
                    Enviados <?= (int)($m['submitted'] ?? 0) ?> ·
                    Falhas <?= (int)($m['publication_failed'] ?? 0) ?>
                </small>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Tela 1 - Selecao inicial</h2>
        <p>Lista minimalista com ID e titulo. Marque os itens e envie os IDs selecionados via POST para gerar a otimizacao.</p>

        <form id="generation-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= cat_h($csrf) ?>">
            <input type="hidden" name="action" value="generate_selected">

            <div class="controls">
                <label>
                    Provedor
                    <select id="provider" name="provider">
                        <option value="openai" <?= $selectedProvider === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                        <option value="gemini" <?= $selectedProvider === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                        <option value="claude" <?= $selectedProvider === 'claude' ? 'selected' : '' ?>>Claude</option>
                    </select>
                </label>

                <label>
                    Canal
                    <select id="channel" name="channel">
                        <?php foreach ($channels as $key => $label): ?>
                            <option value="<?= cat_h($key) ?>" <?= $selectedChannel === $key ? 'selected' : '' ?>><?= cat_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Categoria
                    <select id="category-filter">
                        <option value="">Todas</option>
                    </select>
                </label>

                <label>
                    Ordenar
                    <select id="sort-by">
                        <option value="relevance">Relevancia</option>
                        <option value="name">Nome</option>
                        <option value="price-asc">Menor preco</option>
                        <option value="price-desc">Maior preco</option>
                    </select>
                </label>

                <label class="search">
                    Busca
                    <input id="product-search" type="search" placeholder="SKU, nome, categoria..." autocomplete="off">
                </label>

                <label>
                    Itens por pagina
                    <input id="page-size" type="number" min="6" max="48" value="12">
                </label>

                <div class="actions">
                    <button id="refresh-list" type="button">Atualizar lista</button>
                    <button id="clear-selection" type="button">Limpar selecao</button>
                    <button id="retry" type="button">Reprocessar falhas</button>
                    <button id="run-selected" type="submit">Gerar otimizacao</button>
                </div>
            </div>

            <div class="panel" style="margin-top:14px;margin-bottom:0">
                <div class="source-title">
                    <strong>Produtos ativos disponiveis</strong>
                    <small id="product-summary">Carregando...</small>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;font-weight:800">
                    <input id="select-all-products" type="checkbox">
                    Selecionar todos desta pagina
                </label>
                <div id="active-products-list" class="active-products-grid"></div>
                <div id="selected-products-hidden" hidden></div>
                <div class="actions" style="margin-top:14px;justify-content:space-between">
                    <button id="prev-page" type="button">Pagina anterior</button>
                    <div id="page-status"></div>
                    <button id="next-page" type="button">Proxima pagina</button>
                </div>
            </div>
        </form>

        <pre id="log" class="log"></pre>
    </section>

    <section class="panel">
        <div class="queue-header">
            <div>
                <h2>Fila para aprovacao e publicacao</h2>
                <p class="muted">Os itens ficam recolhidos por padrao. Clique no resumo para expandir somente o produto que voce quer revisar.</p>
            </div>
            <div>
                <span class="badge"><strong id="bulk-selected-count">0</strong> item(ns) selecionado(s)</span>
            </div>
        </div>

        <form id="bulk-queue-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= cat_h($csrf) ?>">
            <input type="hidden" name="action" value="bulk_apply">
            <div class="queue-toolbar">
                <button class="publish" type="submit" name="bulk_action" value="publish">Aprovar selecionados</button>
                <button class="reject" type="submit" name="bulk_action" value="reject">Rejeitar selecionados</button>
                <button class="delete-btn" type="submit" name="bulk_action" value="delete">Excluir do staging</button>
                <button class="queue-tool-btn" id="select-all-queue" type="button">Selecionar todos</button>
                <button class="queue-tool-btn" id="clear-all-queue" type="button">Limpar marcacoes</button>
                <button class="queue-tool-btn" id="expand-all-queue" type="button">Expandir tudo</button>
                <button class="queue-tool-btn" id="collapse-all-queue" type="button">Recolher tudo</button>
            </div>
        </form>

        <?php if ($items === []): ?>
            <div class="empty-state" style="margin-top:16px">Nenhum conteudo aguardando decisao.</div>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <?php
            $pid = (int)$item['product_id'];
            $channel = (string)$item['channel'];
            $local = cat_original($db, $pid);
            $before = sv_catalog_channel_snapshot($db, $pid, $channel, $local);
            $profile = sv_catalog_channel_profile($channel);
            $label = $channels[$channel] ?? $channel;
            $bullets = cat_json_list($item['bullet_points_json'] ?? '[]');
            $meta = json_decode((string)($item['meta_data_json'] ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            $qualityChecks = is_array($meta['quality_checks'] ?? null) ? $meta['quality_checks'] : [];
            $qualityScore = is_numeric($meta['quality_score'] ?? null) ? (int)$meta['quality_score'] : null;
            $qualityFailures = cat_quality_gate_failures($qualityChecks);
            $qualityApproved = $qualityFailures === [];
            $publishDisabled = $qualityScore !== null && !$qualityApproved;
            $ep = $endpointRegistry[$channel] ?? null;
            $fieldMap = is_array($profile['field_map'] ?? null) ? $profile['field_map'] : [];
            $limits = is_array($profile['limits'] ?? null) ? $profile['limits'] : [];
            $statusClass = (string)($item['status'] ?? '') === 'publication_failed' ? 'queue-state fail' : 'queue-state';
            ?>
            <details class="queue-item" data-queue-item>
                <summary>
                    <div class="queue-summary">
                        <input
                            type="checkbox"
                            class="queue-select"
                            name="selected_ids[]"
                            value="<?= (int)$item['id'] ?>"
                            form="bulk-queue-form"
                        >
                        <div class="queue-main">
                            <div class="queue-title">
                                <span class="badge"><?= cat_h($label) ?></span>
                                <span>Produto #<?= $pid ?></span>
                                <span>SKU <?= cat_h((string)$local['sku']) ?></span>
                                <span class="<?= cat_h($statusClass) ?>"><?= cat_h((string)$item['status']) ?></span>
                                <?php if ($qualityScore !== null): ?>
                                    <span class="queue-quality <?= $qualityApproved ? 'approved' : 'rejected' ?>">
                                        <?= $qualityApproved ? 'Aprovado' : 'Reprovado' ?>
                                        <?= $qualityScore ?>/100
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="queue-summary-meta">
                                <span>Politica <?= cat_h((string)($profile['policy_version'] ?? '')) ?></span>
                                <span>Criado em <?= cat_h((string)($item['created_at'] ?? '')) ?></span>
                                <span>Atualizado em <?= cat_h((string)($item['updated_at'] ?? '')) ?></span>
                            </div>
                        </div>
                    </div>
                </summary>

                <div class="queue-body">
                    <?php if ($before['warning'] !== ''): ?>
                        <div class="alert warn"><?= cat_h($before['warning']) ?></div>
                    <?php endif; ?>

                    <div class="profile">
                        <h4>SEO e catalogo especificos de <?= cat_h($label) ?></h4>
                        <ul>
                            <?php foreach ((array)($profile['seo_notes'] ?? []) as $note): ?>
                                <li><?= cat_h((string)$note) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($limits !== []): ?>
                            <small>
                                Limites/alvos:
                                <?php foreach ($limits as $k => $v): ?>
                                    <strong><?= cat_h((string)$k) ?></strong>=<?= cat_h((string)$v) ?>&nbsp;
                                <?php endforeach; ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php if ($ep): ?>
                        <details class="profile endpoints">
                            <summary><strong>Endpoints reais usados neste canal</strong></summary>
                            <h4>Texto</h4>
                            <?php foreach ($ep['text'] as $endpoint): ?>
                                <code><?= cat_h($endpoint) ?></code>
                            <?php endforeach; ?>
                            <h4>Read-back</h4>
                            <?php foreach ($ep['readback'] as $endpoint): ?>
                                <code><?= cat_h($endpoint) ?></code>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>

                    <details class="profile">
                        <summary><strong>Mapa de publicacao de cada campo</strong></summary>
                        <div class="field-map">
                            <?php foreach ($fieldMap as $key => $field): ?>
                                <?php $mode = (string)($field['mode'] ?? 'internal'); ?>
                                <div class="field-map-row">
                                    <strong>
                                        <?= cat_h((string)($field['label'] ?? $key)) ?>
                                        <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    </strong>
                                    <span><?= cat_h((string)($field['target'] ?? '')) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <?php if ($qualityScore !== null): ?>
                        <div class="quality">
                            <span class="score <?= $qualityApproved ? 'approved' : 'rejected' ?>">
                                <?= $qualityApproved ? 'Aprovado' : 'Reprovado' ?> pelo quality gate: <?= $qualityScore ?>/100
                            </span>
                            <div class="checks">
                                <?php foreach ($qualityChecks as $check => $ok): ?>
                                    <span class="check <?= $ok ? 'okc' : 'badc' ?>">
                                        <?= $ok ? 'OK' : 'Falhou' ?> <?= cat_h((string)$check) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($qualityFailures !== []): ?>
                            <div class="quality-note">
                                <strong>Publicacao bloqueada pelo quality gate</strong>
                                Ajuste os checks que falharam antes de aprovar: <?= cat_h(implode(', ', $qualityFailures)) ?>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= cat_h($csrf) ?>">
                        <input type="hidden" name="staging_id" value="<?= (int)$item['id'] ?>">

                        <div class="compare">
                            <div>
                                <h3>Antes - <?= cat_h((string)$before['source']) ?></h3>
                                <?php foreach ([
                                    'title' => 'Titulo',
                                    'description' => 'Descricao',
                                    'bullet_points' => 'Bullet points / selling points',
                                    'seo_keywords' => 'SEO keywords / search terms',
                                    'marketing_hooks' => 'Marketing hooks',
                                    'meta_title' => 'Meta title',
                                    'meta_description' => 'Meta description',
                                ] as $fieldKey => $fieldLabel): ?>
                                    <?php
                                    $mapKey = match ($fieldKey) {
                                        'title' => 'optimized_title',
                                        'description' => 'optimized_description',
                                        default => $fieldKey,
                                    };
                                    $fm = $fieldMap[$mapKey] ?? [];
                                    $mode = (string)($fm['mode'] ?? 'internal');
                                    ?>
                                    <div class="before-label">
                                        <b><?= cat_h($fieldLabel) ?></b>
                                        <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    </div>
                                    <div class="before"><?= cat_h(cat_before_text($before, $fieldMap, $fieldKey)) ?></div>
                                <?php endforeach; ?>

                                <h4>Identidade, categoria e atributos do cadastro real</h4>
                                <dl class="details-grid">
                                    <?php foreach ($before['details'] as $key => $value): ?>
                                        <?php if (trim((string)$value) === '') { continue; } ?>
                                        <dt><?= cat_h((string)$key) ?></dt>
                                        <dd><?= cat_h((string)$value) ?></dd>
                                    <?php endforeach; ?>
                                </dl>
                            </div>

                            <div class="edit">
                                <h3>Depois - editavel</h3>

                                <?php $fm = $fieldMap['optimized_title'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Titulo <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <input
                                        name="optimized_title"
                                        value="<?= cat_h((string)$item['optimized_title']) ?>"
                                        required
                                        data-counter="title"
                                        <?= isset($limits['title_max']) ? ' maxlength="' . (int)$limits['title_max'] . '"' : '' ?>
                                    >
                                    <span class="char-count" data-count-for="title"></span>
                                </label>

                                <?php $fm = $fieldMap['optimized_description'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Descricao <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <textarea name="optimized_description" rows="9" required data-counter="description"><?= cat_h((string)$item['optimized_description']) ?></textarea>
                                    <span class="char-count" data-count-for="description"></span>
                                </label>

                                <?php $fm = $fieldMap['bullet_points'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Bullet points / selling points <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <textarea name="bullet_points" rows="6"><?= cat_h(implode("\n", $bullets)) ?></textarea>
                                    <small>Um ponto por linha.</small>
                                </label>

                                <?php $fm = $fieldMap['seo_keywords'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    <?= cat_h((string)($fm['label'] ?? 'SEO keywords')) ?> <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <textarea name="seo_keywords" rows="3"><?= cat_h((string)$item['seo_keywords']) ?></textarea>
                                    <small>Uma por linha ou separadas por virgula. <?= cat_h((string)($fm['target'] ?? '')) ?></small>
                                </label>

                                <?php $fm = $fieldMap['marketing_hooks'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Marketing hooks <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <textarea name="marketing_hooks" rows="3"><?= cat_h(str_replace(' | ', "\n", (string)$item['marketing_hooks'])) ?></textarea>
                                    <small>Um por linha.</small>
                                </label>

                                <?php $fm = $fieldMap['meta_title'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Meta title <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <input name="meta_title" value="<?= cat_h((string)($meta['meta_title'] ?? '')) ?>" data-counter="meta-title">
                                    <span class="char-count" data-count-for="meta-title"></span>
                                </label>

                                <?php $fm = $fieldMap['meta_description'] ?? []; $mode = (string)($fm['mode'] ?? 'internal'); ?>
                                <label>
                                    Meta description <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span>
                                    <textarea name="meta_description" rows="3" data-counter="meta-description"><?= cat_h((string)($meta['meta_description'] ?? '')) ?></textarea>
                                    <span class="char-count" data-count-for="meta-description"></span>
                                </label>
                            </div>
                        </div>

                        <div class="actions" style="margin-top:14px">
                            <button class="save" name="action" value="save">Salvar e reauditar sem publicar</button>
                        </div>

                        <div class="regen">
                            <h3>Alterar a geracao antes de aprovar</h3>
                            <label>
                                Nova instrucao para a IA
                                <textarea name="regeneration_instruction" rows="3" placeholder="Ex.: priorizar o modelo no titulo, retirar repeticao, reforcar um atributo comprovado..."></textarea>
                            </label>
                            <label>
                                Provedor
                                <select name="provider">
                                    <option value="openai" <?= $item['provider_used'] === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                                    <option value="gemini" <?= $item['provider_used'] === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                                    <option value="claude" <?= $item['provider_used'] === 'claude' ? 'selected' : '' ?>>Claude</option>
                                </select>
                            </label>
                            <button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar e reauditar</button>
                        </div>

                        <label class="confirm">
                            <input type="checkbox" name="confirm_channel" value="<?= cat_h($channel) ?>">
                            Confirmo publicar <strong>somente em <?= cat_h($label) ?></strong>. O quality gate sera executado novamente antes da chamada real.
                        </label>

                        <div class="actions">
                            <button class="publish" name="action" value="publish" <?= $publishDisabled ? 'disabled aria-disabled="true"' : '' ?>>
                                <?= $publishDisabled ? 'Publicacao bloqueada pelo quality gate' : 'Aprovar e publicar somente em ' . cat_h($label) ?>
                            </button>
                            <button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button>
                        </div>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
</main>

<script>
(() => {
    'use strict';

    const log = document.getElementById('log');
    const $ = (id) => document.getElementById(id);
    const initialSelectedIds = <?= json_encode(array_values($submittedProductIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const state = {
        products: [],
        selected: new Set((Array.isArray(initialSelectedIds) ? initialSelectedIds : []).map((id) => String(id))),
        page: 1,
        totalPages: 1,
        categories: []
    };
    const listEl = $('active-products-list');
    const summaryEl = $('product-summary');
    const pageStatusEl = $('page-status');
    const categoryFilterEl = $('category-filter');
    const sortByEl = $('sort-by');
    const queueCheckboxes = () => Array.from(document.querySelectorAll('.queue-select'));
    const queueDetails = () => Array.from(document.querySelectorAll('[data-queue-item]'));

    function write(text) {
        log.style.display = 'block';
        log.textContent += text + '\n';
        log.scrollTop = log.scrollHeight;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function updateBulkCount() {
        $('bulk-selected-count').textContent = String(queueCheckboxes().filter((input) => input.checked).length);
    }

    function bindQueueControls() {
        queueCheckboxes().forEach((input) => {
            input.addEventListener('click', (event) => event.stopPropagation());
            input.addEventListener('change', (event) => {
                event.currentTarget.closest('.queue-item')?.classList.toggle('is-selected', event.currentTarget.checked);
                updateBulkCount();
            });
        });

        $('select-all-queue')?.addEventListener('click', () => {
            queueCheckboxes().forEach((input) => {
                input.checked = true;
                input.closest('.queue-item')?.classList.add('is-selected');
            });
            updateBulkCount();
        });
        $('clear-all-queue')?.addEventListener('click', () => {
            queueCheckboxes().forEach((input) => {
                input.checked = false;
                input.closest('.queue-item')?.classList.remove('is-selected');
            });
            updateBulkCount();
        });
        $('expand-all-queue')?.addEventListener('click', () => {
            queueDetails().forEach((detail) => { detail.open = true; });
        });
        $('collapse-all-queue')?.addEventListener('click', () => {
            queueDetails().forEach((detail) => { detail.open = false; });
        });
        updateBulkCount();
    }

    async function optimize(id, channel, provider) {
        const response = await fetch('/admin/catalog-optimization/api/optimize_catalog.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: id, target_channel: channel, provider })
        });
        const payload = await response.json();
        if (!response.ok && !payload.error) {
            payload.error = 'HTTP ' + response.status;
        }
        return payload;
    }

    function renderSelectedCount() {
        const count = state.selected.size;
        summaryEl.textContent = count === 0
            ? `${state.products.length} ativo(s) carregado(s) nesta pagina`
            : `${count} selecionado(s) · ${state.products.length} ativo(s) na pagina`;
    }

    function renderCategories(categories) {
        const current = categoryFilterEl.value;
        categoryFilterEl.innerHTML = ['<option value="">Todas</option>']
            .concat(categories.map((cat) => `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`))
            .join('');
        if (categories.includes(current)) {
            categoryFilterEl.value = current;
        }
    }

    function syncSelectAllProducts() {
        const master = $('select-all-products');
        if (!master) {
            return;
        }
        const visibleIds = state.products.map((product) => String(product.id || '')).filter(Boolean);
        master.checked = visibleIds.length > 0 && visibleIds.every((id) => state.selected.has(id));
        master.indeterminate = visibleIds.some((id) => state.selected.has(id)) && !master.checked;
    }

    function syncSelectedInputs() {
        const hiddenContainer = $('selected-products-hidden');
        if (!hiddenContainer) {
            return;
        }
        hiddenContainer.innerHTML = Array.from(state.selected)
            .map((id) => `<input type="hidden" name="selected_product_ids[]" value="${escapeHtml(id)}">`)
            .join('');
    }

    function renderList() {
        if (!state.products.length) {
            listEl.innerHTML = '<div class="empty-state">Nenhum produto ativo encontrado nesta busca.</div>';
            pageStatusEl.textContent = '';
            renderSelectedCount();
            syncSelectAllProducts();
            syncSelectedInputs();
            return;
        }

        listEl.innerHTML = state.products.map((product) => {
            const id = String(product.id || '');
            const checked = state.selected.has(id) ? 'checked' : '';
            return `
                <label class="product-card ${checked ? 'is-selected' : ''}">
                    <input type="checkbox" class="product-check" value="${escapeHtml(id)}" data-product-id="${escapeHtml(id)}" ${checked}>
                    <div>
                        <div class="product-card-title">#${escapeHtml(id)} - ${escapeHtml(product.name || 'Produto sem nome')}</div>
                    </div>
                </label>`;
        }).join('');

        listEl.querySelectorAll('.product-check').forEach((input) => {
            input.addEventListener('change', (event) => {
                const id = String(event.currentTarget.dataset.productId || '');
                if (event.currentTarget.checked) {
                    state.selected.add(id);
                } else {
                    state.selected.delete(id);
                }
                event.currentTarget.closest('.product-card')?.classList.toggle('is-selected', event.currentTarget.checked);
                renderSelectedCount();
                syncSelectAllProducts();
                syncSelectedInputs();
            });
        });

        pageStatusEl.textContent = `Pagina ${state.page} de ${state.totalPages}`;
        renderSelectedCount();
        syncSelectAllProducts();
        syncSelectedInputs();
    }

    async function loadProducts(page = 1) {
        state.page = page;
        const query = $('product-search').value.trim();
        const pageSize = Math.max(6, Math.min(48, parseInt($('page-size').value || '12', 10) || 12));
        summaryEl.textContent = 'Carregando produtos ativos...';

        try {
            const url = new URL('/api/catalog/products.php', window.location.origin);
            url.searchParams.set('page', String(page));
            url.searchParams.set('limit', String(pageSize));
            url.searchParams.set('sort', sortByEl.value || 'relevance');
            if (query) {
                url.searchParams.set('q', query);
            }
            const category = categoryFilterEl.value.trim();
            if (category) {
                url.searchParams.set('category', category);
            }

            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.error || ('HTTP ' + response.status));
            }

            state.products = Array.isArray(payload.products) ? payload.products : [];
            state.totalPages = Math.max(1, Number(payload.total_pages || 1));
            state.categories = Object.keys(payload.categories || {});
            renderCategories(state.categories);
            renderList();
        } catch (error) {
            listEl.innerHTML = '<div class="empty-state">Erro ao carregar a lista de produtos ativos.</div>';
            summaryEl.textContent = 'Falha no carregamento';
            pageStatusEl.textContent = '';
            write('ERRO: ' + error.message);
        }
    }

    $('generation-form')?.addEventListener('submit', (event) => {
        if (state.selected.size === 0) {
            event.preventDefault();
            window.alert('Selecione ao menos um produto para gerar a otimizacao.');
        }
    });
    $('refresh-list').addEventListener('click', () => loadProducts(1));
    $('clear-selection').addEventListener('click', () => {
        state.selected.clear();
        renderList();
    });
    $('select-all-products')?.addEventListener('change', (event) => {
        state.products.forEach((product) => {
            const id = String(product.id || '');
            if (!id) {
                return;
            }
            if (event.currentTarget.checked) {
                state.selected.add(id);
            } else {
                state.selected.delete(id);
            }
        });
        renderList();
    });
    $('prev-page').addEventListener('click', () => {
        if (state.page > 1) {
            loadProducts(state.page - 1);
        }
    });
    $('next-page').addEventListener('click', () => {
        if (state.page < state.totalPages) {
            loadProducts(state.page + 1);
        }
    });
    $('retry').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        log.textContent = '';
        try {
            const response = await fetch('?ajax=failed_items', { credentials: 'same-origin' });
            const payload = await response.json();
            for (const item of (payload.items || [])) {
                const output = await optimize(item.product_id, item.channel, item.provider_used);
                write(`#${item.product_id}: ${output.success ? 'reprocessado' : 'falhou'} ${output.error || ''}`);
            }
            await loadProducts(state.page);
        } catch (error) {
            write('ERRO: ' + error.message);
        } finally {
            button.disabled = false;
        }
    });
    $('product-search').addEventListener('input', () => loadProducts(1));
    $('page-size').addEventListener('change', () => loadProducts(1));
    sortByEl.addEventListener('change', () => loadProducts(1));
    categoryFilterEl.addEventListener('change', () => loadProducts(1));

    document.querySelectorAll('[data-counter]').forEach((element) => {
        const target = element.closest('label')?.querySelector(`[data-count-for="${element.dataset.counter}"]`);
        if (!target) {
            return;
        }
        const update = () => {
            target.textContent = `${element.value.length} caracteres${element.maxLength > 0 ? ' / ' + element.maxLength : ''}`;
        };
        element.addEventListener('input', update);
        update();
    });

    bindQueueControls();
    loadProducts(1);
})();
</script>
</body>
</html>
