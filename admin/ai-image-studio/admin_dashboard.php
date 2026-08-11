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

$IMAGE_TYPE_LABELS = ['white' => 'Branco / capa', 'hero' => 'Hero comercial', 'ambient' => 'Ambientada / lifestyle'];
$channelProfiles = ai_studio_channel_profiles();
$batchResults = null;
$batchError = null;
$previewProducts = null;
$previewProvider = '';
$previewModel = '';
$previewChannel = 'site';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['preview'] ?? '') === '1') {
    $previewProvider = ai_studio_normalize_provider((string)($_GET['provider'] ?? ''));
    $previewModel = trim((string)($_GET['model'] ?? ''));
    $previewChannel = strtolower(trim((string)($_GET['target_channel'] ?? 'site')));
    $rawLimit = (int)($_GET['limit'] ?? 5);
    $limit = $rawLimit <= 0 ? 5000 : max(1, min(5000, $rawLimit));

    if (!in_array($previewProvider, ['openai', 'google', 'claude', 'openrouter', 'groq'], true)) {
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
            $stmt = $db->prepare(
                'SELECT p.id, p.name, p.image_url, p.sku '
                . 'FROM products p '
                . 'LEFT JOIN product_images_staging s ON s.product_id = p.id AND s.target_channels_json LIKE ? '
                . 'WHERE COALESCE(p.active, 0) = 1 AND s.id IS NULL ORDER BY p.id ASC LIMIT ' . (int)$limit
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

    if (!in_array($provider, ['openai', 'google', 'claude', 'openrouter', 'groq'], true)) {
        $batchError = 'Selecione um provedor valido.';
    } elseif (!isset($channelProfiles[$targetChannel])) {
        $batchError = 'Marketplace de destino invalido.';
    } elseif ($productIds === []) {
        $batchError = 'Nenhum produto selecionado.';
    } else {
        $batchResults = [];
        foreach ($productIds as $productId) {
            $types = array_values(array_map('strval', (array)($imageTypesByProduct[(string)$productId] ?? [])));
            if ($types === []) continue;
            $batchResults[] = $enqueueOnly
                ? ai_studio_enqueue_job($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel)
                : ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel);
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
*{box-sizing:border-box}body{background:#f5f6f8}.ais-wrap{max-width:1100px;margin:24px auto;padding:0 16px;font-family:system-ui,-apple-system,sans-serif}.ais-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-bottom:24px}.ais-card{background:#fff;border:1px solid #e2e4ea;border-radius:10px;padding:16px;text-align:center}.ais-card .num{font-size:28px;font-weight:700}.ais-form{background:#fff;border:1px solid #e2e4ea;border-radius:10px;padding:20px;margin-bottom:24px}.ais-form label{display:block;font-weight:600;margin:14px 0 6px}.ais-form select,.ais-form input[type=number],.ais-form input[type=text]{padding:10px;border:1px solid #ccc;border-radius:6px;width:360px;max-width:100%;font:inherit;font-size:16px}.ais-form button{margin-top:18px;background:#1a1a2e;color:#fff;border:0;padding:11px 22px;border-radius:6px;font-weight:700;cursor:pointer}.ais-form small{display:block;color:#666;margin-top:4px;max-width:760px}.ais-alert{padding:12px 16px;border-radius:8px;margin-bottom:16px}.ais-alert.error{background:#fdecea;color:#611a15}.ais-alert.info{background:#e8f4fd;color:#0c3b57}.ais-alert.note{background:#fff8e1;color:#6b5300}.ais-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px}.ais-back{margin-bottom:16px}.ais-preview-list{display:flex;flex-direction:column;gap:10px;margin:16px 0}.ais-preview-item{display:flex;align-items:center;gap:14px;background:#fafbfc;border:1px solid #e6e8ed;border-radius:8px;padding:10px 14px;min-width:0}.ais-preview-item img{width:64px;height:64px;object-fit:contain;border-radius:6px;background:#eee;flex-shrink:0}.ais-pi-info{flex:1;min-width:0}.ais-pi-name{font-weight:600;overflow-wrap:anywhere}.ais-pi-id{color:#888;font-size:12px}.ais-pi-types{display:flex;gap:12px;flex-wrap:wrap}.ais-pi-types label{display:flex;align-items:center;gap:5px;font-weight:400;margin:0;font-size:13px}.channel-profile{background:#eef6ff;border-left:4px solid #1769aa;padding:12px;margin:12px 0}.channel-profile ul{margin:7px 0;padding-left:20px}.table-wrap{overflow:auto;border-radius:10px}table.ais-table{width:100%;border-collapse:collapse;background:#fff;min-width:720px}table.ais-table th,table.ais-table td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px}.ais-badge{padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:#eef2f7}@media(max-width:760px){.ais-wrap{padding:0 10px;margin-top:12px}.ais-preview-item{align-items:flex-start;flex-wrap:wrap}.ais-pi-types{width:100%;padding-left:78px}.ais-topbar h1{font-size:26px}}
</style>
<style>
.ais-preview-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center}
.ais-preview-actions button,.ais-product-check{border:0;border-radius:6px;padding:8px 12px;font-weight:700;cursor:pointer;background:#eef2f7;color:#111}
.ais-preview-summary{display:flex;align-items:center;gap:6px;padding:8px 10px;border-radius:6px;background:#f8fafc;border:1px solid #e5e7eb;color:#334155;font-size:13px}
.ais-product-check{display:flex;align-items:center;gap:8px;flex-shrink:0}
.ais-product-check input{transform:scale(1.08)}
.ais-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0 22px}
.ais-summary-card{background:#fff;border:1px solid #e2e4ea;border-radius:10px;padding:14px}
.ais-summary-card h3{margin:0 0 8px;font-size:16px}
.ais-summary-card ul{margin:0;padding-left:18px}
.ais-summary-card li{margin:4px 0;overflow-wrap:anywhere}
.ais-error-summary{display:block;max-width:480px}
.ais-error-summary summary{cursor:pointer;font-weight:700}
.ais-error-summary pre{margin:8px 0 0;white-space:pre-wrap;word-break:break-word;background:#fff8f8;border:1px solid #f2d0d0;border-radius:8px;padding:10px}
</style>
</head>
<body>
<div class="ais-wrap">
<div class="ais-back"><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div>
<div class="ais-topbar"><h1>AI Image Studio — por marketplace</h1><a href="/admin/ai-image-studio/admin_validate.php">Ir para aprovacao →</a></div>
<div class="channel-profile"><strong>Fluxo auditado:</strong> escolha o marketplace <em>antes</em> de gerar. Depois, marque os produtos que quer processar. O prompt, a fila e a revisao passam a carregar esse destino. A imagem continua nascendo da foto real do mesmo produto; preco e estoque nao participam do fluxo.</div>
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
                    <td><span class="ais-badge"><?= ai_studio_h((string)($event['status'] ?? '')) ?></span></td>
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
<label for="limit">Mostrar ate</label><input type="number" name="limit" id="limit" value="200" min="0" max="5000" required><div><button type="submit">Buscar produtos deste canal →</button></div></form></div>
<script>function aisUpdateModelDefault(){const p=document.getElementById('provider').value,i=document.getElementById('model'),d={openai:'gpt-image-1',claude:'',google:'gemini-2.5-flash-image',openrouter:'openai/gpt-image-1',groq:''};if(Object.values(d).includes(i.value)||i.value==='openai/gpt-oss-20b')i.value=d[p]||''}aisUpdateModelDefault();</script>
<?php else: $profile=$channelProfiles[$previewChannel]??$channelProfiles['site']; ?>
<div class="ais-form"><h2>Passo 2 — Confirmar geracao para <?=ai_studio_h((string)$profile['label'])?></h2><p>Provedor: <strong><?=ai_studio_h($previewProvider)?></strong><?php if($previewModel!==''):?> · Modelo: <strong><?=ai_studio_h($previewModel)?></strong><?php endif;?> · <?=count($previewProducts)?> produto(s).</p>
<div class="channel-profile"><strong>Regra visual deste canal</strong><ul><?php foreach((array)($profile['audit_notes']??[]) as $note):?><li><?=ai_studio_h((string)$note)?></li><?php endforeach;?></ul><small>Minimo tecnico: <?= (int)($profile['minimum_side']??1000) ?>px por lado · alvo recomendado: <?= (int)($profile['recommended_side']??1000) ?>px · galeria: ate <?= (int)($profile['max_gallery']??9) ?> imagens.</small></div>
<?php if($previewProducts===[]): ?><div class="ais-alert note">Nenhum produto pendente para este marketplace. <a href="/admin/ai-image-studio/admin_dashboard.php">Buscar outro canal</a></div><?php else: ?>
<form method="post"><input type="hidden" name="run_batch" value="1"><input type="hidden" name="provider" value="<?=ai_studio_h($previewProvider)?>"><input type="hidden" name="model" value="<?=ai_studio_h($previewModel)?>"><input type="hidden" name="target_channel" value="<?=ai_studio_h($previewChannel)?>"><label style="display:flex;align-items:center;gap:8px;margin:14px 0 6px"><input type="checkbox" name="enqueue_only" value="1" checked> Enfileirar processamento e responder rápido</label><small>Recomendado para lotes maiores, porque a fila processa cada produto em segundo plano sem travar a interface.</small><div class="ais-preview-actions"><button type="button" id="ais-select-all">Selecionar tudo</button><button type="button" id="ais-clear-all">Limpar</button><div class="ais-preview-summary">Selecionados: <strong id="ais-selected-count">0</strong>/<span id="ais-total-count"><?=count($previewProducts)?></span></div></div><div class="ais-preview-list">
<?php foreach($previewProducts as $p): $pid=(int)$p['id'];$pname=trim((string)($p['name']??''))!==''?(string)$p['name']:"Produto #$pid";$pimg=trim((string)($p['image_url']??'')); ?><div class="ais-preview-item"><label class="ais-product-check"><input type="checkbox" name="selected_products[]" value="<?=$pid?>" data-product-check> Selecionar</label><?php if($pimg!==''):?><img src="<?=ai_studio_h($pimg)?>" alt="Foto atual"><?php else:?><div style="width:64px;height:64px;background:#eee;border-radius:6px"></div><?php endif;?><div class="ais-pi-info"><div class="ais-pi-name"><?=ai_studio_h($pname)?></div><div class="ais-pi-id">#<?=$pid?><?=trim((string)($p['sku']??''))!==''?' · SKU '.ai_studio_h((string)$p['sku']):''?><?=trim((string)($p['category']??''))!==''?' · '.ai_studio_h((string)$p['category']):''?><?=$pimg===''?' · sem foto real: sera bloqueado':''?></div></div><div class="ais-pi-types"><?php foreach($IMAGE_TYPE_LABELS as $key=>$text):?><label><input type="checkbox" name="image_types[<?=$pid?>][]" value="<?=ai_studio_h($key)?>" checked> <?=ai_studio_h($text)?></label><?php endforeach;?></div></div><?php endforeach; ?>
</div><button type="submit" id="ais-submit">Confirmar e gerar para <?=ai_studio_h((string)$profile['label'])?></button> &nbsp; <a href="/admin/ai-image-studio/admin_dashboard.php">Cancelar</a></form><script>(()=>{const inputs=[...document.querySelectorAll('[data-product-check]')],count=document.getElementById('ais-selected-count'),submit=document.getElementById('ais-submit');const update=()=>{const selected=inputs.filter(x=>x.checked).length;if(count)count.textContent=String(selected);if(submit)submit.disabled=selected===0};document.getElementById('ais-select-all')?.addEventListener('click',()=>{inputs.forEach(x=>x.checked=true);update()});document.getElementById('ais-clear-all')?.addEventListener('click',()=>{inputs.forEach(x=>x.checked=false);update()});inputs.forEach(x=>x.addEventListener('change',update));update()})();</script><?php endif;?></div>
<?php endif; ?>

<h2>Itens recentes</h2><?php if($recentItems===[]):?><p>Nenhum item processado ainda.</p><?php else:?><div class="table-wrap"><table class="ais-table"><thead><tr><th>ID</th><th>Produto</th><th>Destino</th><th>Tipo</th><th>Provedor</th><th>Status</th><th>Falha</th><th>Criado em</th></tr></thead><tbody><?php foreach($recentItems as $item):$target=ai_studio_target_channel($item);$error=trim((string)($item['error_message']??''));?><tr><td>#<?=(int)$item['id']?></td><td>#<?=(int)$item['product_id']?></td><td><?=ai_studio_h((string)($channelProfiles[$target]['label']??$target))?></td><td><?=ai_studio_h((string)$item['image_type'])?></td><td><?=ai_studio_h((string)$item['provider_used'])?></td><td><span class="ais-badge"><?=ai_studio_h((string)$item['status'])?></span></td><td><?php if($error!==''):?><details class="ais-error-summary"><summary><?=ai_studio_h(ai_studio_excerpt($error, 96))?></summary><pre><?=ai_studio_h($error)?></pre></details><?php else:?>—<?php endif;?></td><td><?=ai_studio_h((string)$item['created_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?>
</div>
</body>
</html>
