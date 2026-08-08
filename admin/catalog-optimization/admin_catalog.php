<?php

declare(strict_types=1);

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
    if (!is_array($row)) {
        return ['name' => '', 'description' => '', 'sku' => '', 'image_url' => '', 'olist_id' => ''];
    }

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
 * Recalcula o quality gate depois de qualquer edicao manual ou regeneracao.
 * Quando $enforce=true a publicacao e bloqueada se qualquer regra do canal
 * falhar. Em "salvar sem publicar" o rascunho e mantido, mas a interface
 * passa a mostrar exatamente quais checks ainda precisam ser corrigidos.
 *
 * @return array{row:array<string,mixed>,quality:array{score:int,checks:array<string,bool>},failed:list<string>}
 */
function cat_refresh_quality(PDO $db, array $row, bool $enforce): array
{
    $channel = strtolower(trim((string)($row['channel'] ?? '')));
    $productId = (int)($row['product_id'] ?? 0);
    $product = ai_catalog_fetch_product($db, $productId);
    if ($product === null) {
        throw new RuntimeException('Produto nao encontrado para auditoria de qualidade.');
    }

    $data = cat_staging_ai_data($row);
    $quality = ai_catalog_quality_report($data, $channel, $product);
    $failed = array_values(array_keys(array_filter(
        $quality['checks'],
        static fn(bool $ok): bool => !$ok
    )));

    if ($enforce) {
        // Alem do relatorio, reaplica as validacoes estruturais e de claims.
        ai_catalog_validate_ai_response($data, $channel, $product);
    }

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
    if (!is_string($encoded)) {
        throw new RuntimeException('Falha ao persistir relatorio de qualidade.');
    }
    $update = $db->prepare('UPDATE catalog_optimizations_staging SET meta_data_json = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$encoded, (int)$row['id']]);
    $row['meta_data_json'] = $encoded;

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
        'SELECT p.id FROM products p '
        . 'LEFT JOIN catalog_optimizations_staging latest ON latest.id = ('
        . 'SELECT s2.id FROM catalog_optimizations_staging s2 WHERE s2.product_id = p.id AND s2.channel = ? ORDER BY s2.created_at DESC, s2.id DESC LIMIT 1) '
        . "WHERE latest.id IS NULL OR latest.status IN ('published','rejected','failed') ORDER BY p.id ASC LIMIT " . $limit
    );
    $stmt->execute([$channel]);
    echo json_encode(['product_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'failed_items') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $db->query(
        "SELECT id, product_id, channel, provider_used FROM catalog_optimizations_staging WHERE status = 'failed' ORDER BY created_at ASC LIMIT 100"
    );
    echo json_encode(['items' => $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []]);
    exit;
}

$flashMessage = null;
$flashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('catalog-optimization', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessao expirou. Recarregue a pagina.';
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
                $provider = strtolower(trim((string)($_POST['provider'] ?? $row['provider_used'])));
                $channel = (string)$row['channel'];
                $product = ai_catalog_fetch_product($db, (int)$row['product_id']);
                if ($product === null) throw new RuntimeException('Produto nao encontrado.');

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
                    if ($audit['failed'] === []) {
                        $flashMessage = "Alteracoes do conteudo #{$stagingId} salvas. Quality gate: {$audit['quality']['score']}/100. Nada foi publicado.";
                    } else {
                        $flashMessage = "Rascunho #{$stagingId} salvo sem publicar. Quality gate: {$audit['quality']['score']}/100; corrija: " . implode(', ', $audit['failed']) . '.';
                    }
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

$metrics = [];
$stmt = $db->query('SELECT channel, status, COUNT(*) total FROM catalog_optimizations_staging GROUP BY channel, status');
foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $metric) {
    $metrics[(string)$metric['channel']][(string)$metric['status']] = (int)$metric['total'];
}
$stmt = $db->query(
    "SELECT * FROM catalog_optimizations_staging WHERE status IN ('pending','publication_failed') ORDER BY created_at DESC LIMIT 50"
);
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = sv_csrf_token('catalog-optimization');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Otimizacao e publicacao real</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}body{background:#f5f6f8}.wrap{max-width:1380px;margin:24px auto;padding:0 16px;font-family:system-ui,-apple-system,sans-serif;color:#111827}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.alert,.panel,.item,.metric{background:#fff;border:1px solid #dfe3e8;border-radius:12px;padding:14px;margin:12px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.warn{background:#fff8e1;color:#6b5300}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.metric{margin:0}.metric strong{font-size:24px}.controls,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.controls label,.edit label,.regen label{display:grid;gap:5px}.controls input,.controls select,.edit input,.edit textarea,.regen textarea,.regen select{padding:10px;border:1px solid #c7ccd2;border-radius:7px;box-sizing:border-box;font:inherit;font-size:16px;max-width:100%}.compare{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}.compare>*{min-width:0}@media(max-width:850px){.compare{grid-template-columns:minmax(0,1fr)}.wrap{padding:0 10px;margin-top:12px}.item{padding:12px}.top h1{font-size:26px}}.before{white-space:pre-wrap;background:#f6f7f9;padding:10px;border-radius:7px;min-height:50px;overflow-wrap:anywhere;word-break:break-word}.before-list{margin:7px 0 12px;padding-left:20px}.edit input,.edit textarea,.regen textarea{width:100%;min-width:0}.badge,.field-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#e8f4fd;font-weight:700;font-size:12px}.field-badge{font-size:11px;margin-left:6px;background:#eef2f7}.field-badge.direct{background:#dcfce7;color:#166534}.field-badge.embedded{background:#fef3c7;color:#854d0e}.field-badge.internal{background:#f1f5f9;color:#475569}.endpoints code{display:block;margin:4px 0;white-space:normal;overflow-wrap:anywhere}.publish,.save,.regenerate,.reject,.controls button{border:0;border-radius:7px;padding:10px 14px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff}.save{background:#5f6368;color:#fff}.regenerate{background:#1769aa;color:#fff}.reject{background:#c62828;color:#fff}.confirm{display:block;padding:10px;background:#fff8e1;border-radius:7px;margin:12px 0}.draft-note{font-size:13px;background:#eef6ff;border-left:4px solid #1769aa;padding:10px;margin:10px 0}.log{white-space:pre-wrap;background:#111;color:#ddd;padding:10px;border-radius:7px;display:none;max-height:220px;overflow:auto}.profile{background:#fbfcfe;border:1px solid #e2e8f0;border-radius:9px;padding:12px;margin:12px 0}.profile h4{margin:0 0 8px}.profile ul{margin:6px 0;padding-left:20px}.field-map{display:grid;gap:6px}.field-map-row{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:8px;padding:7px;background:#fff;border-radius:7px;border:1px solid #edf0f3}.field-map-row span:last-child{overflow-wrap:anywhere}.details-grid{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:6px 10px;margin:8px 0}.details-grid dt{font-weight:700}.details-grid dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.quality{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}.score{font-size:18px;font-weight:800}.checks{display:flex;gap:6px;flex-wrap:wrap}.check{font-size:11px;padding:3px 6px;border-radius:999px}.check.okc{background:#dcfce7;color:#166534}.check.badc{background:#fee2e2;color:#991b1b}.char-count{font-size:12px;color:#64748b;text-align:right}.source-title{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>
<main class="wrap">
<div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div>
<div class="top"><h1>Otimizacao de cadastro — por marketplace</h1><span class="badge">Preco e estoque protegidos</span></div>
<div class="draft-note"><strong>Auditoria por canal:</strong> o painel agora diferencia o que e publicado diretamente, o que e incorporado em outro campo e o que serve apenas como apoio interno. Antes de publicar, o quality gate do marketplace e executado novamente, inclusive depois de edicoes manuais.</div>
<?php if ($flashMessage): ?><div class="alert ok"><?= cat_h($flashMessage) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert err"><?= cat_h($flashError) ?></div><?php endif; ?>
<section class="metrics">
<?php foreach ($channels as $key => $label): $m = $metrics[$key] ?? []; ?>
<div class="metric"><strong><?= (int)($m['published'] ?? 0) ?></strong><div><?= cat_h($label) ?></div><small>Pendentes <?= (int)($m['pending'] ?? 0) ?> · Enviados <?= (int)($m['submitted'] ?? 0) ?> · Falhas <?= (int)($m['publication_failed'] ?? 0) ?></small></div>
<?php endforeach; ?>
</section>
<section class="panel"><h2>Gerar fila por canal</h2><div class="controls"><label>Provedor<select id="provider"><option value="openai">OpenAI</option><option value="gemini">Gemini</option><option value="claude">Claude</option></select></label><label>Canal<select id="channel"><?php foreach ($channels as $key => $label): ?><option value="<?= cat_h($key) ?>"><?= cat_h($label) ?></option><?php endforeach; ?></select></label><label>Limite<input id="limit" type="number" min="1" max="100" value="10"></label><button id="run" type="button">Gerar fila</button><button id="retry" type="button">Reprocessar falhas</button></div><pre id="log" class="log"></pre></section>
<h2>Antes e depois — dados reais, campos reais e aprovacao</h2>
<?php if ($items === []): ?><div class="panel">Nenhum conteudo aguardando decisao.</div><?php endif; ?>
<?php foreach ($items as $item):
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
    $ep = $endpointRegistry[$channel] ?? null;
    $fieldMap = is_array($profile['field_map'] ?? null) ? $profile['field_map'] : [];
    $limits = is_array($profile['limits'] ?? null) ? $profile['limits'] : [];
?>
<article class="item">
<div class="source-title"><div><span class="badge"><?= cat_h($label) ?></span> Produto #<?= $pid ?> · SKU <?= cat_h((string)$local['sku']) ?> · status <?= cat_h((string)$item['status']) ?></div><small>Politica <?= cat_h((string)($profile['policy_version'] ?? '')) ?></small></div>
<?php if ($before['warning'] !== ''): ?><div class="alert warn"><?= cat_h($before['warning']) ?></div><?php endif; ?>
<div class="profile"><h4>SEO e catalogo especificos de <?= cat_h($label) ?></h4><ul><?php foreach ((array)($profile['seo_notes'] ?? []) as $note): ?><li><?= cat_h((string)$note) ?></li><?php endforeach; ?></ul><?php if ($limits !== []): ?><small>Limites/alvos: <?php foreach ($limits as $k => $v): ?><strong><?= cat_h((string)$k) ?></strong>=<?= cat_h((string)$v) ?> &nbsp;<?php endforeach; ?></small><?php endif; ?></div>
<?php if ($ep): ?><details class="endpoints"><summary><strong>Endpoints reais usados neste canal</strong></summary><h4>Texto</h4><?php foreach ($ep['text'] as $endpoint): ?><code><?= cat_h($endpoint) ?></code><?php endforeach; ?><h4>Read-back</h4><?php foreach ($ep['readback'] as $endpoint): ?><code><?= cat_h($endpoint) ?></code><?php endforeach; ?></details><?php endif; ?>
<details class="profile" open><summary><strong>Mapa de publicacao de cada campo</strong></summary><div class="field-map"><?php foreach ($fieldMap as $key => $field): $mode=(string)($field['mode']??'internal'); ?><div class="field-map-row"><strong><?= cat_h((string)($field['label'] ?? $key)) ?> <span class="field-badge <?= cat_h($mode) ?>"><?= cat_h(cat_mode_label($mode)) ?></span></strong><span><?= cat_h((string)($field['target'] ?? '')) ?></span></div><?php endforeach; ?></div></details>
<?php if ($qualityScore !== null): ?><div class="quality"><span class="score">Quality gate <?= $qualityScore ?>/100</span><div class="checks"><?php foreach ($qualityChecks as $check => $ok): ?><span class="check <?= $ok ? 'okc' : 'badc' ?>"><?= $ok ? '✓' : '✕' ?> <?= cat_h((string)$check) ?></span><?php endforeach; ?></div></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= cat_h($csrf) ?>">
<input type="hidden" name="staging_id" value="<?= (int)$item['id'] ?>">
<div class="compare">
<div><h3>Antes — <?= cat_h((string)$before['source']) ?></h3><b>Titulo</b><div class="before"><?= cat_h((string)$before['title']) ?></div><b>Descricao</b><div class="before"><?= cat_h((string)$before['description']) ?></div><?php if ($before['bullet_points'] !== []): ?><b>Bullet points / selling points</b><ul class="before-list"><?php foreach ($before['bullet_points'] as $value): ?><li><?= cat_h((string)$value) ?></li><?php endforeach; ?></ul><?php endif; ?><?php if ($before['seo_keywords'] !== []): ?><b>SEO / search terms do canal</b><div class="before"><?= cat_h(implode(', ', $before['seo_keywords'])) ?></div><?php endif; ?><?php if ((string)$before['meta_title'] !== ''): ?><b>Meta/SEO title</b><div class="before"><?= cat_h((string)$before['meta_title']) ?></div><?php endif; ?><?php if ((string)$before['meta_description'] !== ''): ?><b>Meta/SEO description</b><div class="before"><?= cat_h((string)$before['meta_description']) ?></div><?php endif; ?><h4>Identidade, categoria e atributos do cadastro real</h4><dl class="details-grid"><?php foreach ($before['details'] as $key => $value): if (trim((string)$value)==='') continue; ?><dt><?= cat_h((string)$key) ?></dt><dd><?= cat_h((string)$value) ?></dd><?php endforeach; ?></dl></div>
<div class="edit"><h3>Depois — editavel</h3>
<?php $fm=$fieldMap['optimized_title']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Titulo <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><input name="optimized_title" value="<?= cat_h((string)$item['optimized_title']) ?>" required data-counter="title"<?= isset($limits['title_max']) ? ' maxlength="'.(int)$limits['title_max'].'"' : '' ?>><span class="char-count" data-count-for="title"></span></label>
<?php $fm=$fieldMap['optimized_description']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Descricao <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="optimized_description" rows="9" required data-counter="description"><?= cat_h((string)$item['optimized_description']) ?></textarea><span class="char-count" data-count-for="description"></span></label>
<?php $fm=$fieldMap['bullet_points']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Bullet points / selling points <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="bullet_points" rows="6"><?= cat_h(implode("\n", $bullets)) ?></textarea></label>
<?php $fm=$fieldMap['seo_keywords']??[];$mode=(string)($fm['mode']??'internal'); ?><label><?= cat_h((string)($fm['label'] ?? 'SEO keywords')) ?> <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="seo_keywords" rows="3"><?= cat_h((string)$item['seo_keywords']) ?></textarea><small><?= cat_h((string)($fm['target'] ?? '')) ?></small></label>
<?php $fm=$fieldMap['marketing_hooks']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Marketing hooks <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="marketing_hooks" rows="3"><?= cat_h((string)$item['marketing_hooks']) ?></textarea></label>
<?php $fm=$fieldMap['meta_title']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Meta title <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><input name="meta_title" value="<?= cat_h((string)($meta['meta_title'] ?? '')) ?>" data-counter="meta-title"><span class="char-count" data-count-for="meta-title"></span></label>
<?php $fm=$fieldMap['meta_description']??[];$mode=(string)($fm['mode']??'internal'); ?><label>Meta description <span class="field-badge <?=cat_h($mode)?>"><?=cat_h(cat_mode_label($mode))?></span><textarea name="meta_description" rows="3" data-counter="meta-description"><?= cat_h((string)($meta['meta_description'] ?? '')) ?></textarea><span class="char-count" data-count-for="meta-description"></span></label>
</div>
</div>
<div class="actions"><button class="save" name="action" value="save">Salvar e reauditar sem publicar</button></div>
<div class="regen"><h3>Alterar a geracao antes de aprovar</h3><label>Nova instrucao para a IA<textarea name="regeneration_instruction" rows="3" placeholder="Ex.: priorizar o modelo no titulo, retirar repeticao, reforcar um atributo comprovado..."></textarea></label><label>Provedor<select name="provider"><option value="openai" <?= $item['provider_used'] === 'openai' ? 'selected' : '' ?>>OpenAI</option><option value="gemini" <?= $item['provider_used'] === 'gemini' ? 'selected' : '' ?>>Gemini</option><option value="claude" <?= $item['provider_used'] === 'claude' ? 'selected' : '' ?>>Claude</option></select></label><button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar e reauditar</button></div>
<label class="confirm"><input type="checkbox" name="confirm_channel" value="<?= cat_h($channel) ?>"> Confirmo publicar <strong>somente em <?= cat_h($label) ?></strong>. O quality gate sera executado novamente antes da chamada real.</label>
<div class="actions"><button class="publish" name="action" value="publish">Aprovar e publicar somente em <?= cat_h($label) ?></button><button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button></div>
</form>
</article>
<?php endforeach; ?>
</main>
<script>
(()=>{'use strict';
const log=document.getElementById('log'),write=t=>{log.style.display='block';log.textContent+=t+'\n';log.scrollTop=log.scrollHeight};
async function optimize(id,channel,provider){const r=await fetch('/admin/catalog-optimization/api/optimize_catalog.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id,target_channel:channel,provider})});const d=await r.json();if(!r.ok&&!d.error)d.error='HTTP '+r.status;return d}
document.getElementById('run').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';const channel=document.getElementById('channel').value,provider=document.getElementById('provider').value,limit=document.getElementById('limit').value;try{const r=await fetch(`?ajax=pending_ids&channel=${encodeURIComponent(channel)}&limit=${encodeURIComponent(limit)}`,{credentials:'same-origin'}),d=await r.json();for(const id of(d.product_ids||[])){const out=await optimize(id,channel,provider);write(`#${id}: ${out.success?'gerado':'falhou'} ${out.error||''}`)}location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});
document.getElementById('retry').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';try{const r=await fetch('?ajax=failed_items',{credentials:'same-origin'}),d=await r.json();for(const item of(d.items||[])){const out=await optimize(item.product_id,item.channel,item.provider_used);write(`#${item.product_id}: ${out.success?'reprocessado':'falhou'} ${out.error||''}`)}location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});
document.querySelectorAll('[data-counter]').forEach(el=>{const target=el.closest('label')?.querySelector(`[data-count-for="${el.dataset.counter}"]`);if(!target)return;const update=()=>{target.textContent=`${el.value.length} caracteres${el.maxLength>0?' / '+el.maxLength:''}`};el.addEventListener('input',update);update()});
})();
</script>
</body>
</html>
