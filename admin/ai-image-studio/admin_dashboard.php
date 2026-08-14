<?php

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie, Accept-Encoding');
}

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process_item.php';
require_once __DIR__ . '/src/ImageChannelProfile.php';

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Falha ao conectar ao banco de dados.');
}
svcp_ensure_schema($db);

function ai_studio_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ai_studio_excerpt(string $value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value, 'UTF-8') <= $max) {
        return $value;
    }
    return mb_substr($value, 0, max(1, $max - 1), 'UTF-8') . '…';
}

function ai_studio_target_channel(array $item): string
{
    $targets = json_decode((string)($item['target_channels_json'] ?? '[]'), true);
    if (!is_array($targets) || !isset($targets[0])) {
        return 'legado';
    }
    return strtolower(trim((string)$targets[0]));
}

function ai_studio_active_product_sql(PDO $db, string $alias = 'p'): string
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

$IMAGE_TYPE_LABELS = ['cover' => 'Capa do canal', 'white' => 'Branco tecnico', 'hero' => 'Hero comercial', 'ambient' => 'Ambientada / lifestyle'];
$channelProfiles = ai_studio_channel_profiles();
$batchResults = null;
$batchError = null;
$previewProducts = null;
$previewProvider = '';
$previewModel = '';
$previewChannel = 'site';
$previewTotal = 0;
$previewPage = 1;
$previewPageSize = 100;
$previewPageCount = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['preview'] ?? '') === '1') {
    $previewProvider = ai_studio_normalize_provider((string)($_GET['provider'] ?? ''));
    $previewModel = trim((string)($_GET['model'] ?? ''));
    $previewChannel = strtolower(trim((string)($_GET['target_channel'] ?? 'site')));
    $rawPageSize = (int)($_GET['page_size'] ?? ($_GET['limit'] ?? 100));
    $previewPageSize = max(25, min(500, $rawPageSize));
    $previewPage = max(1, (int)($_GET['page'] ?? 1));

    if (!in_array($previewProvider, ['openai', 'google', 'claude', 'openrouter', 'groq', 'huggingface'], true)) {
        $batchError = 'Selecione um provedor valido.';
    } elseif (!isset($channelProfiles[$previewChannel])) {
        $batchError = 'Selecione um marketplace valido.';
    } else {
        try {
            // A fila agora e independente por marketplace. Um produto que ja
            // recebeu imagens para o site continua elegivel para Amazon,
            // Mercado Livre, Shopee ou TikTok. Categoria e enriquecida pelo
            // resolver tolerante de ai_studio_fetch_product(), pois a coluna
            // products.category nao existe em todos os schemas de producao.
            $eligibilitySql = 'FROM products p '
                . 'WHERE ' . ai_studio_active_product_sql($db, 'p') . ' '
                . 'AND NOT EXISTS ('
                . ' SELECT 1 FROM product_images_staging s '
                . ' WHERE s.product_id = p.id '
                . ' AND s.target_channels_json LIKE ? '
                . " AND COALESCE(s.status, '') NOT IN ('failed','rejected')"
                . ') ';
            $totalStmt = $db->prepare('SELECT COUNT(*) ' . $eligibilitySql);
            $totalStmt->execute(['%"' . $previewChannel . '"%']);
            $previewTotal = (int)$totalStmt->fetchColumn();
            $previewPageCount = max(1, (int)ceil($previewTotal / $previewPageSize));
            $previewPage = min($previewPage, $previewPageCount);
            $offset = ($previewPage - 1) * $previewPageSize;
            $stmt = $db->prepare(
                'SELECT p.id, p.name, p.image_url, p.sku '
                . $eligibilitySql
                . 'ORDER BY p.id ASC LIMIT ' . $previewPageSize . ' OFFSET ' . $offset
            );
            $stmt->execute(['%"' . $previewChannel . '"%']);
            $previewProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($previewProducts as &$previewProduct) {
                $resolved = ai_studio_fetch_product($db, (int)($previewProduct['id'] ?? 0));
                $previewProduct['category'] = is_array($resolved) ? (string)($resolved['category'] ?? '') : '';
            }
            unset($previewProduct);
        } catch (Throwable $e) {
            error_log('[ai-image-studio] Falha ao buscar produtos para preview: ' . $e->getMessage());
            $batchError = 'Falha ao buscar produtos pendentes: ' . $e->getMessage();
            $previewProducts = null;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['run_batch'])) {
    $provider = ai_studio_normalize_provider((string)($_POST['provider'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $targetChannel = strtolower(trim((string)($_POST['target_channel'] ?? 'site')));
    $enqueueOnly = ($_POST['enqueue_only'] ?? '') === '1';
    $selectedProducts = (array)($_POST['selected_products'] ?? ($_POST['product_ids'] ?? []));
    $productIds = array_values(array_unique(array_filter(array_map('intval', $selectedProducts), static fn(int $value): bool => $value > 0)));
    $imageTypesByProduct = (array)($_POST['image_types'] ?? []);

    if (!in_array($provider, ['openai', 'google', 'claude', 'openrouter', 'groq', 'huggingface'], true)) {
        $batchError = 'Selecione um provedor valido.';
    } elseif (!isset($channelProfiles[$targetChannel])) {
        $batchError = 'Marketplace de destino invalido.';
    } elseif ($productIds === []) {
        $batchError = 'Nenhum produto selecionado.';
    } else {
        $batchResults = [];
        $processedCount = 0;
        foreach ($productIds as $productId) {
            $types = array_values(array_map('strval', (array)($imageTypesByProduct[(string)$productId] ?? [])));
            if ($types === []) continue;
            if (!$enqueueOnly && $processedCount > 0) {
                // Espaca chamadas sincronas ao mesmo provedor para nao estourar
                // limites de taxa por minuto (ex: tier gratuito do Gemini).
                usleep(600000);
            }
            $batchResults[] = $enqueueOnly
                ? ai_studio_enqueue_job($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel)
                : ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel);
            $processedCount++;
        }
        if ($batchResults === []) {
            $batchError = 'Nenhum produto tinha ao menos um tipo de imagem marcado.';
            $batchResults = null;
        }
    }
}

$statusCounts = [];
try {
    $stmt = $db->query('SELECT status, COUNT(*) AS total FROM product_images_staging GROUP BY status');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $statusCounts[(string)$row['status']] = (int)$row['total'];
    }
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler estatisticas: ' . $e->getMessage());
}

$failureBuckets = [
    'providers' => [],
    'channels' => [],
    'causes' => [],
    'products' => [],
];
function ai_studio_provider_health_snapshot(): array
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
    if (!is_array($decoded)) {
        return [];
    }
    $snapshot = [];
    foreach ($decoded as $provider => $expiry) {
        $provider = ai_studio_h((string)$provider);
        $snapshot[$provider] = is_numeric($expiry) ? (float)$expiry : 0.0;
    }
    return $snapshot;
}

function ai_studio_provider_audit_tail(int $limit = 25): array
{
    $path = dirname(__DIR__, 3) . '/storage/ai-provider-audit.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        return [];
    }
    $tail = array_slice($lines, max(0, count($lines) - $limit));
    $events = [];
    foreach ($tail as $line) {
        $decoded = json_decode((string)$line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $events[] = $decoded;
    }
    return array_reverse($events);
}
$providerHealth = ai_studio_provider_health_snapshot();
$providerAuditTail = ai_studio_provider_audit_tail(24);
try {
    $stmt = $db->query(
        'SELECT id, product_id, image_type, provider_used, status, target_channels_json, error_message, created_at '
        . "FROM product_images_staging WHERE status = 'failed' ORDER BY created_at DESC"
    );
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $item) {
        $provider = trim((string)($item['provider_used'] ?? ''));
        $provider = $provider !== '' ? $provider : 'desconhecido';
        $failureBuckets['providers'][$provider] = ($failureBuckets['providers'][$provider] ?? 0) + 1;

        $target = ai_studio_target_channel($item);
        $channelLabel = (string)($channelProfiles[$target]['label'] ?? $target);
        $failureBuckets['channels'][$channelLabel] = ($failureBuckets['channels'][$channelLabel] ?? 0) + 1;

        $cause = ai_studio_excerpt((string)($item['error_message'] ?? ''), 110);
        $cause = $cause !== '' ? $cause : 'Sem mensagem registrada';
        $failureBuckets['causes'][$cause] = ($failureBuckets['causes'][$cause] ?? 0) + 1;

        $productLabel = '#' . (int)($item['product_id'] ?? 0);
        $failureBuckets['products'][$productLabel] = ($failureBuckets['products'][$productLabel] ?? 0) + 1;
    }
    foreach ($failureBuckets as $key => $values) {
        arsort($failureBuckets[$key]);
    }
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao resumir falhas: ' . $e->getMessage());
}

$recentItems = [];
try {
    $stmt = $db->query(
        'SELECT id, product_id, image_type, provider_used, status, target_channels_json, error_message, created_at '
        . 'FROM product_images_staging ORDER BY created_at DESC LIMIT 20'
    );
    $recentItems = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler itens recentes: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>AI Image Studio — ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}body{background:#f4f6f8;color:#111827}.ais-wrap{max-width:1240px;margin:20px auto 34px;padding:0 16px;font-family:system-ui,-apple-system,sans-serif}.ais-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px}.ais-topbar h1{margin:0;font-size:26px;line-height:1.15}.ais-back{margin-bottom:14px}.ais-back a,.ais-topbar a{color:#1769aa;font-weight:700;text-decoration:none}.ais-ops-note,.channel-profile{background:#fff;border:1px solid #dbe3ea;border-left:5px solid #1769aa;border-radius:8px;padding:12px 14px;margin:12px 0;color:#243242}.channel-profile ul{margin:7px 0;padding-left:20px}.ais-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0 20px}.ais-card{background:#fff;border:1px solid #dfe5eb;border-radius:8px;padding:12px 14px;min-height:86px;display:grid;align-content:center;gap:3px}.ais-card .num{font-size:27px;line-height:1;font-weight:800}.ais-card small,.ais-card div:last-child{color:#526071;font-size:13px}.ais-form,.ais-section{background:#fff;border:1px solid #dfe5eb;border-radius:8px;padding:16px;margin-bottom:18px}.ais-form h2,.ais-section h2{font-size:19px;margin:0 0 12px}.ais-form label{display:block;font-weight:700;margin:12px 0 6px}.ais-form select,.ais-form input[type=number],.ais-form input[type=text]{padding:10px 11px;border:1px solid #bfc8d1;border-radius:7px;width:360px;max-width:100%;font:inherit;font-size:15px;background:#fff}.ais-form button{margin-top:14px;background:#1769aa;color:#fff;border:0;padding:10px 16px;border-radius:7px;font-weight:800;cursor:pointer}.ais-form button:disabled{opacity:.5;cursor:not-allowed}.ais-form small{display:block;color:#5c6675;margin-top:4px;max-width:820px}.ais-alert{padding:12px 14px;border-radius:8px;margin-bottom:14px;border:1px solid transparent}.ais-alert.error{background:#fff1f1;color:#7b1717;border-color:#f3c7c7}.ais-alert.info{background:#eef7ff;color:#0c3b57;border-color:#cfe7fb}.ais-alert.note{background:#fff8e1;color:#6b5300;border-color:#f4dda0}.ais-preview-list{display:grid;gap:9px;margin:14px 0;max-height:560px;overflow:auto;padding:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}.ais-preview-item{display:grid;grid-template-columns:auto 72px minmax(0,1fr) minmax(250px,.62fr);align-items:center;gap:12px;background:#fff;border:1px solid #dde4eb;border-radius:8px;padding:10px 12px;min-width:0}.ais-preview-item img,.ais-image-placeholder{width:72px;height:72px;object-fit:contain;border-radius:7px;background:#eef1f4;flex-shrink:0}.ais-pi-info{min-width:0}.ais-pi-name{font-weight:800;overflow-wrap:anywhere}.ais-pi-id{color:#64748b;font-size:12px;margin-top:3px}.ais-pi-types{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.ais-pi-types label{display:flex;align-items:center;gap:5px;font-weight:700;margin:0;font-size:12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:5px 9px}.table-wrap{overflow:auto;border:1px solid #dfe5eb;border-radius:8px;background:#fff}table.ais-table{width:100%;border-collapse:collapse;background:#fff;min-width:780px}table.ais-table th,table.ais-table td{padding:10px 12px;border-bottom:1px solid #eef1f4;text-align:left;font-size:13px;vertical-align:top}table.ais-table th{position:sticky;top:0;background:#f8fafc;z-index:1;color:#334155;font-size:12px;text-transform:uppercase}.ais-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:800;background:#eef2f7;color:#334155;white-space:nowrap}.ais-badge.pending,.ais-badge.submitted{background:#e0f2fe;color:#075985}.ais-badge.published{background:#dcfce7;color:#166534}.ais-badge.failed,.ais-badge.publication_failed{background:#fee2e2;color:#991b1b}.ais-badge.rejected{background:#f1f5f9;color:#475569}@media(max-width:860px){.ais-wrap{padding:0 10px;margin-top:12px}.ais-preview-item{grid-template-columns:auto 64px minmax(0,1fr)}.ais-pi-types{grid-column:1/-1;justify-content:flex-start}.ais-topbar h1{font-size:23px}}
</style>
<style>
.ais-preview-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center}.ais-selector-list{margin:14px 0;border:1px solid #dfe5eb;border-radius:8px;background:#f8fafc}.ais-selector-list summary{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:11px 13px;cursor:pointer;color:#1e3a5f}.ais-selector-list summary span{font-size:13px;font-weight:700;color:#526071}.ais-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0}.ais-pagination a{color:#1769aa;font-weight:800;text-decoration:none}
.ais-preview-actions button,.ais-product-check{border:1px solid #cfd8e3;border-radius:7px;padding:8px 12px;font-weight:800;cursor:pointer;background:#eef2f7;color:#111827}
.ais-preview-summary{display:flex;align-items:center;gap:6px;padding:8px 10px;border-radius:7px;background:#f8fafc;border:1px solid #dfe5eb;color:#334155;font-size:13px}
.ais-product-check{display:flex;align-items:center;gap:8px;flex-shrink:0}
.ais-product-check input{transform:scale(1.08)}
.ais-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;margin:12px 0 20px}
.ais-summary-card{background:#fff;border:1px solid #dfe5eb;border-radius:8px;padding:14px}
.ais-summary-card h3{margin:0 0 8px;font-size:16px}
.ais-summary-card ul{margin:0;padding-left:18px}
.ais-summary-card li{margin:4px 0;overflow-wrap:anywhere}
.ais-error-summary{display:block;max-width:480px}
.ais-error-summary summary{cursor:pointer;font-weight:700}
.ais-error-summary pre{margin:8px 0 0;white-space:pre-wrap;word-break:break-word;background:#fff8f8;border:1px solid #f2d0d0;border-radius:8px;padding:10px}
.ais-panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.ais-panel-title h2,.ais-panel-title h3{margin:0}
.ais-strategy{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;margin:10px 0}
.ais-strategy div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:9px 10px;font-size:13px}
.ais-strategy strong{display:block;color:#0f172a;margin-bottom:3px}
</style>
</head>
<body>
<div class="ais-wrap">
<div class="ais-back"><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div>
<div class="ais-topbar"><h1>AI Image Studio — por marketplace</h1><a href="/admin/ai-image-studio/admin_validate.php">Ir para aprovacao →</a></div>
<div class="channel-profile"><strong>Fluxo auditado:</strong> escolha o marketplace <em>antes</em> de gerar. Depois, marque os produtos que quer processar. O prompt, a fila e a revisao passam a carregar esse destino. A imagem continua nascendo da foto real do mesmo produto; preco e estoque nao participam do fluxo.</div>
<div class="ais-form" style="margin-bottom:18px"><div class="ais-panel-title"><h2 style="font-size:16px;margin:0">Saude dos provedores</h2><button type="button" id="ais-health-btn" style="margin:0;background:#eef2f7;color:#111827">Testar conexao (sem gastar credito)</button></div><div id="ais-health-result" class="ais-strategy"></div></div>
<div class="ais-cards">
<?php foreach (['pending'=>'Pendentes','published'=>'Publicadas','submitted'=>'Enviadas/auditoria','rejected'=>'Rejeitadas','failed'=>'Falhas','publication_failed'=>'Falha publicacao'] as $status=>$label): ?><div class="ais-card"><div class="num"><?= (int)($statusCounts[$status] ?? 0) ?></div><div><?= ai_studio_h($label) ?></div></div><?php endforeach; ?>
</div>
<?php if ($batchError !== null): ?><div class="ais-alert error"><?= ai_studio_h($batchError) ?></div><?php endif; ?>
<?php if ($batchResults !== null): $queued=0;$ok=0;$err=0;foreach($batchResults as $r){if(($r['status']??'')==='queued'){$queued++;continue;}foreach((array)($r['results']??[]) as $x){($x['status']??'')==='pending'?$ok++:$err++;}} ?><div class="ais-alert info"><?php if($queued>0): ?>Lote enfileirado: <?=$queued?> produto(s) enviados para a fila em segundo plano. Atualize a tela para acompanhar os itens recentes.<?php else: ?>Lote processado: <?=count($batchResults)?> produto(s), <?=$ok?> imagem(ns) em revisao e <?=$err?> erro(s). O destino foi persistido em cada imagem.<?php endif; ?></div><?php endif; ?>

<?php if ($failureBuckets['providers'] !== []): ?>
<div class="ais-summary-grid">
<section class="ais-summary-card"><h3>Falhas por provedor</h3><ul><?php foreach(array_slice($failureBuckets['providers'], 0, 5, true) as $label => $total): ?><li><?=ai_studio_h((string)$label)?>: <strong><?=(int)$total?></strong></li><?php endforeach; ?></ul></section>
<section class="ais-summary-card"><h3>Falhas por canal</h3><ul><?php foreach(array_slice($failureBuckets['channels'], 0, 5, true) as $label => $total): ?><li><?=ai_studio_h((string)$label)?>: <strong><?=(int)$total?></strong></li><?php endforeach; ?></ul></section>
<section class="ais-summary-card"><h3>Falhas por produto</h3><ul><?php foreach(array_slice($failureBuckets['products'], 0, 5, true) as $label => $total): ?><li><?=ai_studio_h((string)$label)?>: <strong><?=(int)$total?></strong></li><?php endforeach; ?></ul></section>
<section class="ais-summary-card"><h3>Causas mais frequentes</h3><ul><?php foreach(array_slice($failureBuckets['causes'], 0, 5, true) as $label => $total): ?><li><?=ai_studio_h((string)$label)?>: <strong><?=(int)$total?></strong></li><?php endforeach; ?></ul></section>
</div>
<?php endif; ?>

<?php if ($providerHealth !== []): ?>
<div class="ais-summary-card" style="margin:16px 0 24px">
    <h3>Estado do pool de IA</h3>
    <ul>
        <?php foreach ($providerHealth as $provider => $expiry): ?>
            <li>
                <?= ai_studio_h($provider) ?>:
                <strong><?= $expiry > 0 ? 'cooldown até ' . ai_studio_h(date('Y-m-d H:i:s', (int)$expiry)) : 'livre' ?></strong>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($providerAuditTail !== []): ?>
<div class="ais-summary-card" style="margin:16px 0 24px">
    <h3>Últimos eventos por provedor</h3>
    <div class="table-wrap">
        <table class="ais-table">
            <thead><tr><th>Hora</th><th>Provedor</th><th>Tipo</th><th>Status</th><th>Mensagem</th><th>SKU</th></tr></thead>
            <tbody>
            <?php foreach ($providerAuditTail as $event): ?>
                <tr>
                    <td><?= ai_studio_h(date('Y-m-d H:i:s', (int)($event['ts'] ?? 0))) ?></td>
                    <td><?= ai_studio_h((string)($event['provider'] ?? '')) ?></td>
                    <td><?= ai_studio_h((string)($event['variant'] ?? ($event['image_type'] ?? ''))) ?></td>
                    <td><span class="ais-badge <?= ai_studio_h((string)($event['status'] ?? '')) ?>"><?= ai_studio_h((string)($event['status'] ?? '')) ?></span></td>
                    <td><?= ai_studio_h(ai_studio_excerpt((string)($event['message'] ?? ''), 110)) ?></td>
                    <td><?= ai_studio_h((string)($event['sku'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($previewProducts === null): ?>
<div class="ais-form"><h2>Passo 1 — Marketplace e selecao</h2><form method="get"><input type="hidden" name="preview" value="1">
<label for="target_channel">Marketplace de destino</label><select name="target_channel" id="target_channel" required><?php foreach($channelProfiles as $key=>$profile): ?><option value="<?=ai_studio_h($key)?>"><?=ai_studio_h((string)$profile['label'])?></option><?php endforeach; ?></select><small>O destino controla o prompt visual e cria uma fila independente por canal.</small>
<label for="provider">Motor de IA</label><select name="provider" id="provider" onchange="aisUpdateModelDefault()" required><option value="openai">GPT / OpenAI — edicao da foto real</option><option value="google">Gemini — edicao da foto real</option><option value="claude">Claude otimiza prompt + editor de imagem disponivel</option><option value="openrouter">OpenRouter — API de imagem com referencia</option><option value="groq">Groq otimiza prompt + editor de imagem disponivel</option></select>
<label for="model">Modelo de imagem</label><input type="text" name="model" id="model" value="gpt-image-1"><small>Use um modelo que aceite imagem de referencia. A validacao local rejeita arquivo invalido ou abaixo do minimo tecnico. As chaves do provider selecionado sao rotacionadas automaticamente se houver mais de uma disponivel.</small>
<label for="page_size">Itens por pagina</label><select name="page_size" id="page_size"><?php foreach([25,50,100,200,500] as $size): ?><option value="<?=$size?>" <?=$size===100?'selected':''?>><?=$size?></option><?php endforeach;?></select><small>A listagem cobre todos os produtos ativos elegiveis. Use as paginas para navegar sem limitar a base por um teto fixo.</small><div><button type="submit">Buscar produtos deste canal →</button></div></form></div>
<script>function aisUpdateModelDefault(){const p=document.getElementById('provider').value,i=document.getElementById('model'),d={openai:'gpt-image-1',claude:'',google:'gemini-2.5-flash-image',openrouter:'openai/gpt-image-1',groq:''};if(Object.values(d).includes(i.value)||i.value==='openai/gpt-oss-20b')i.value=d[p]||''}aisUpdateModelDefault();</script>
<?php else: $profile=$channelProfiles[$previewChannel]??$channelProfiles['site']; ?>
<div class="ais-form"><h2>Passo 2 — Confirmar geracao para <?=ai_studio_h((string)$profile['label'])?></h2><p>Provedor: <strong><?=ai_studio_h($previewProvider)?></strong><?php if($previewModel!==''):?> · Modelo: <strong><?=ai_studio_h($previewModel)?></strong><?php endif;?> · <?=count($previewProducts)?> exibido(s) de <?=$previewTotal?> ativo(s) elegivel(is).</p>
<div class="channel-profile"><strong>Regra visual deste canal</strong><ul><?php foreach((array)($profile['audit_notes']??[]) as $note):?><li><?=ai_studio_h((string)$note)?></li><?php endforeach;?></ul><?php $strategy=is_array($profile['visual_strategy']??null)?$profile['visual_strategy']:[];if($strategy!==[]):?><div class="ais-strategy"><?php foreach($IMAGE_TYPE_LABELS as $key=>$text):?><div><strong><?=ai_studio_h($text)?></strong><?=ai_studio_h((string)($strategy[$key]??''))?></div><?php endforeach;?></div><?php endif;?><small>Minimo tecnico: <?= (int)($profile['minimum_side']??1000) ?>px por lado · alvo recomendado: <?= (int)($profile['recommended_side']??1000) ?>px · galeria: ate <?= (int)($profile['max_gallery']??9) ?> imagens.</small></div>
<?php if($previewProducts===[]): ?><div class="ais-alert note">Nenhum produto pendente para este marketplace. <a href="/admin/ai-image-studio/admin_dashboard.php">Buscar outro canal</a></div><?php else: ?>
<form method="post"><input type="hidden" name="run_batch" value="1"><input type="hidden" name="provider" value="<?=ai_studio_h($previewProvider)?>"><input type="hidden" name="model" value="<?=ai_studio_h($previewModel)?>"><input type="hidden" name="target_channel" value="<?=ai_studio_h($previewChannel)?>"><label style="display:flex;align-items:center;gap:8px;margin:14px 0 6px"><input type="checkbox" name="enqueue_only" value="1" checked> Enfileirar processamento e responder rápido</label><small>Recomendado para lotes maiores, porque a fila processa cada produto em segundo plano sem travar a interface.</small><div class="ais-preview-actions"><button type="button" id="ais-select-all">Selecionar pagina</button><button type="button" id="ais-clear-all">Limpar selecao</button><div class="ais-preview-summary">Selecionados nesta pagina: <strong id="ais-selected-count">0</strong>/<span id="ais-total-count"><?=count($previewProducts)?></span></div></div><details class="ais-selector-list" open><summary><strong>Produtos ativos elegiveis</strong><span>Pagina <?=$previewPage?> de <?=$previewPageCount?> · <?=$previewTotal?> no total</span></summary><div class="ais-preview-list">
<?php foreach($previewProducts as $p): $pid=(int)$p['id'];$pname=trim((string)($p['name']??''))!==''?(string)$p['name']:"Produto #$pid";$pimg=trim((string)($p['image_url']??'')); ?><div class="ais-preview-item"><label class="ais-product-check"><input type="checkbox" name="selected_products[]" value="<?=$pid?>" data-product-check> Selecionar</label><?php if($pimg!==''):?><img src="<?=ai_studio_h($pimg)?>" alt="Foto atual"><?php else:?><div class="ais-image-placeholder"></div><?php endif;?><div class="ais-pi-info"><div class="ais-pi-name"><?=ai_studio_h($pname)?></div><div class="ais-pi-id">#<?=$pid?><?=trim((string)($p['sku']??''))!==''?' · SKU '.ai_studio_h((string)$p['sku']):''?><?=trim((string)($p['category']??''))!==''?' · '.ai_studio_h((string)$p['category']):''?><?=$pimg===''?' · sem foto real: sera bloqueado':''?></div></div><div class="ais-pi-types"><?php foreach($IMAGE_TYPE_LABELS as $key=>$text):?><label><input type="checkbox" name="image_types[<?=$pid?>][]" value="<?=ai_studio_h($key)?>" checked> <?=ai_studio_h($text)?></label><?php endforeach;?></div></div><?php endforeach; ?>
</div></details><?php $paginationBase=['preview'=>1,'provider'=>$previewProvider,'model'=>$previewModel,'target_channel'=>$previewChannel,'page_size'=>$previewPageSize];?><div class="ais-pagination"><?php if($previewPage>1):?><a href="?<?=ai_studio_h(http_build_query($paginationBase+['page'=>$previewPage-1]))?>">← Pagina anterior</a><?php endif;?><span>Pagina <?=$previewPage?> de <?=$previewPageCount?></span><?php if($previewPage<$previewPageCount):?><a href="?<?=ai_studio_h(http_build_query($paginationBase+['page'=>$previewPage+1]))?>">Proxima pagina →</a><?php endif;?></div><button type="submit" id="ais-submit">Confirmar e gerar para <?=ai_studio_h((string)$profile['label'])?></button> &nbsp; <a href="/admin/ai-image-studio/admin_dashboard.php">Cancelar</a></form><script>(()=>{const inputs=[...document.querySelectorAll('[data-product-check]')],count=document.getElementById('ais-selected-count'),submit=document.getElementById('ais-submit');const update=()=>{const selected=inputs.filter(x=>x.checked).length;if(count)count.textContent=String(selected);if(submit)submit.disabled=selected===0};document.getElementById('ais-select-all')?.addEventListener('click',()=>{inputs.forEach(x=>x.checked=true);update()});document.getElementById('ais-clear-all')?.addEventListener('click',()=>{inputs.forEach(x=>x.checked=false);update()});inputs.forEach(x=>x.addEventListener('change',update));update()})();</script><?php endif;?></div>
<?php endif; ?>

<section class="ais-section"><div class="ais-panel-title"><h2>Itens recentes</h2><span class="ais-badge"><?=count($recentItems)?> linhas</span></div><?php if($recentItems===[]):?><p>Nenhum item processado ainda.</p><?php else:?><div class="table-wrap"><table class="ais-table"><thead><tr><th>ID</th><th>Produto</th><th>Destino</th><th>Tipo</th><th>Provedor</th><th>Status</th><th>Falha</th><th>Criado em</th></tr></thead><tbody><?php foreach($recentItems as $item):$target=ai_studio_target_channel($item);$error=trim((string)($item['error_message']??''));$status=(string)$item['status'];?><tr><td>#<?=(int)$item['id']?></td><td>#<?=(int)$item['product_id']?></td><td><?=ai_studio_h((string)($channelProfiles[$target]['label']??$target))?></td><td><?=ai_studio_h((string)$item['image_type'])?></td><td><?=ai_studio_h((string)$item['provider_used'])?><?php if(str_contains((string)$item['provider_used'],'local_reference')):?> <span class="ais-badge failed" title="Nenhum provedor de IA gerou esta imagem; e a foto original sem alteracao">sem IA</span><?php endif;?></td><td><span class="ais-badge <?=ai_studio_h($status)?>"><?=ai_studio_h($status)?></span></td><td><?php if($error!==''):?><details class="ais-error-summary"><summary><?=ai_studio_h(ai_studio_excerpt($error, 96))?></summary><pre><?=ai_studio_h($error)?></pre></details><?php else:?>—<?php endif;?></td><td><?=ai_studio_h((string)$item['created_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?></section>
<script>(()=>{'use strict';const btn=document.getElementById('ais-health-btn');const out=document.getElementById('ais-health-result');if(!btn||!out)return;const labels={openai:'OpenAI',google:'Gemini',claude:'Claude',openrouter:'OpenRouter',groq:'Groq'};btn.addEventListener('click',async()=>{btn.disabled=true;btn.textContent='Testando...';out.innerHTML='';try{const r=await fetch('/admin/ai-image-studio/api/provider_health_check.php',{credentials:'same-origin'});const d=await r.json();if(!d.success)throw new Error(d.error||'Falha ao testar provedores.');out.innerHTML=Object.entries(d.providers).map(([key,info])=>`<div style="border-left:4px solid ${info.ok?'#1a7f37':'#c62828'}"><strong>${info.ok?'✓':'✕'} ${labels[key]||key}</strong><br>${info.detail}</div>`).join('')}catch(err){out.innerHTML=`<div style="border-left:4px solid #c62828">Erro: ${err.message}</div>`}finally{btn.disabled=false;btn.textContent='Testar conexao (sem gastar credito)'}})})();</script>
</div>
</body>
</html>
