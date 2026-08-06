<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config_optimization.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/src/CatalogPublisher.php';

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}
svcp_ensure_schema($db);
$channels = catalog_ai_channels();

function cat_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string,mixed> */
function cat_original(PDO $db, int $productId): array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['name' => '', 'description' => '', 'sku' => '', 'image_url' => ''];
    }
    return [
        'name' => trim((string)($row['name'] ?? '')),
        'description' => trim((string)($row['description'] ?? '')),
        'sku' => trim((string)($row['sku'] ?? '')),
        'image_url' => trim((string)($row['image_url'] ?? '')),
    ];
}

if (($_GET['ajax'] ?? '') === 'pending_ids') {
    header('Content-Type: application/json; charset=utf-8');
    $channel = strtolower(trim((string)($_GET['channel'] ?? '')));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 10)));
    if (!array_key_exists($channel, $channels)) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal inválido.']);
        exit;
    }
    $stmt = $db->prepare(
        'SELECT p.id FROM products p '
        . 'LEFT JOIN catalog_optimizations_staging s ON s.product_id = p.id AND s.channel = ? '
        . 'WHERE s.id IS NULL ORDER BY p.id ASC LIMIT ' . $limit
    );
    $stmt->execute([$channel]);
    echo json_encode(['product_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'failed_items') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $db->query(
        "SELECT id, product_id, channel, provider_used, error_message FROM catalog_optimizations_staging "
        . "WHERE status = 'failed' ORDER BY created_at ASC LIMIT 200"
    );
    echo json_encode(['items' => $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []]);
    exit;
}

$flashMessage = null;
$flashError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $stagingId = (int)($_POST['staging_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
    $stmt->execute([$stagingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'reject'], true)) {
        $flashError = 'Requisição inválida.';
    } elseif ($action === 'reject') {
        $update = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $update->execute([$stagingId]);
        $flashMessage = "Item #{$stagingId} rejeitado.";
    } else {
        $title = trim((string)($_POST['optimized_title'] ?? $row['optimized_title']));
        $description = trim((string)($_POST['optimized_description'] ?? $row['optimized_description']));
        $bulletLines = preg_split('/\R/u', trim((string)($_POST['bullet_points'] ?? ''))) ?: [];
        $bullets = array_values(array_filter(array_map('trim', $bulletLines), static fn(string $v): bool => $v !== ''));
        $meta = [
            'meta_title' => trim((string)($_POST['meta_title'] ?? '')),
            'meta_description' => trim((string)($_POST['meta_description'] ?? '')),
        ];
        if ($title === '' || $description === '') {
            $flashError = 'Título e descrição são obrigatórios para publicação real.';
        } else {
            $update = $db->prepare(
                'UPDATE catalog_optimizations_staging SET optimized_title = ?, optimized_description = ?, '
                . 'bullet_points_json = ?, seo_keywords = ?, marketing_hooks = ?, meta_data_json = ?, '
                . "status = 'pending', error_message = NULL, updated_at = NOW() WHERE id = ?"
            );
            $update->execute([
                $title,
                $description,
                json_encode($bullets, JSON_UNESCAPED_UNICODE),
                trim((string)($_POST['seo_keywords'] ?? $row['seo_keywords'])),
                trim((string)($_POST['marketing_hooks'] ?? $row['marketing_hooks'])),
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $stagingId,
            ]);
            $stmt->execute([$stagingId]);
            $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);
            try {
                if (!is_array($updatedRow)) {
                    throw new RuntimeException('Falha ao recarregar o item editado.');
                }
                $result = (new CatalogOptimizationPublisher($db))->publish($updatedRow);
                $status = (string)($result['status'] ?? '');
                $label = $channels[(string)$updatedRow['channel']] ?? (string)$updatedRow['channel'];
                $flashMessage = $status === 'submitted'
                    ? "Item #{$stagingId} enviado de verdade para {$label} e aguardando processamento/auditoria do canal."
                    : "Item #{$stagingId} publicado e confirmado de verdade em {$label}.";
            } catch (Throwable $e) {
                error_log('[catalog-optimization] Publicação real falhou #' . $stagingId . ': ' . $e->getMessage());
                $flashError = "A aprovação não foi concluída porque o canal não confirmou a atualização: " . $e->getMessage();
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
    "SELECT * FROM catalog_optimizations_staging WHERE status IN ('pending','publication_failed') "
    . 'ORDER BY created_at DESC LIMIT 50'
);
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$originals = [];
foreach ($items as $item) {
    $productId = (int)$item['product_id'];
    $originals[$productId] ??= cat_original($db, $productId);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Otimização e publicação de cadastro</title>
<link rel="stylesheet" href="/css/style.css">
<style>
body{background:#f5f6f8}.wrap{max-width:1320px;margin:24px auto;padding:0 16px;font-family:system-ui,sans-serif}.top{display:flex;justify-content:space-between;align-items:center;gap:16px}.alert{padding:12px 16px;border-radius:8px;margin:14px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:20px 0}.metric,.panel,.item{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:16px}.metric strong{font-size:24px}.panel{margin:20px 0}.controls{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.controls label{display:grid;gap:5px}.controls select,.controls input,textarea,.edit input{padding:9px;border:1px solid #c7ccd2;border-radius:6px;box-sizing:border-box}.items{display:grid;gap:18px}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#e8f4fd;color:#0c3b57;font-weight:700;font-size:12px}.dest{margin:10px 0;padding:10px;border-left:4px solid #1769aa;background:#f0f7ff}.compare{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:800px){.compare{grid-template-columns:1fr}}.original{white-space:pre-wrap;background:#f6f7f9;padding:10px;border-radius:6px;min-height:44px}.edit label{display:grid;gap:5px;margin:9px 0}.edit input,.edit textarea{width:100%}.actions{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}.actions button,.controls button{border:0;border-radius:6px;padding:10px 16px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff}.reject{background:#c62828;color:#fff}.log{white-space:pre-wrap;background:#111;color:#ddd;padding:10px;border-radius:6px;display:none;margin-top:10px;max-height:220px;overflow:auto}
</style>
</head>
<body><main class="wrap">
<div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div>
<div class="top"><h1>Otimização e publicação real</h1><span class="badge">Preço e estoque protegidos</span></div>
<?php if ($flashMessage): ?><div class="alert ok"><?= cat_h($flashMessage) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert err"><?= cat_h($flashError) ?></div><?php endif; ?>

<section class="metrics">
<?php foreach ($channels as $key => $label): $count = $metrics[$key] ?? []; ?>
<div class="metric"><strong><?= (int)($count['published'] ?? 0) ?></strong><div><?= cat_h($label) ?> publicados</div><small>Pendentes <?= (int)($count['pending'] ?? 0) ?> · Enviados <?= (int)($count['submitted'] ?? 0) ?> · Falhas <?= (int)($count['publication_failed'] ?? 0) ?></small></div>
<?php endforeach; ?>
</section>

<section class="panel">
<h2>Gerar otimizações em lote</h2>
<div class="controls">
<label>Provedor<select id="provider"><option value="openai">OpenAI</option><option value="gemini">Google Gemini</option><option value="claude">Anthropic Claude</option></select></label>
<label>Canal<select id="channel"><?php foreach ($channels as $key => $label): ?><option value="<?= cat_h($key) ?>"><?= cat_h($label) ?></option><?php endforeach; ?></select></label>
<label>Limite<input id="limit" type="number" min="1" max="200" value="10"></label>
<button id="run" type="button">Gerar fila</button><button id="retry" type="button">Reprocessar falhas da IA</button>
</div><div id="log" class="log"></div>
</section>

<h2>Pendentes de publicação ou com falha</h2>
<section class="items">
<?php if ($items === []): ?><div class="panel">Nenhum item aguardando ação.</div><?php endif; ?>
<?php foreach ($items as $item):
$productId=(int)$item['product_id'];$original=$originals[$productId];$channel=(string)$item['channel'];$label=$channels[$channel]??$channel;
$bullets=json_decode((string)$item['bullet_points_json'],true);$bullets=is_array($bullets)?$bullets:[];$meta=json_decode((string)$item['meta_data_json'],true);$meta=is_array($meta)?$meta:[];
?>
<article class="item">
<div><span class="badge"><?= cat_h($label) ?></span> Produto #<?= $productId ?> · SKU <?= cat_h((string)$original['sku']) ?> · Status <?= cat_h((string)$item['status']) ?></div>
<div class="dest"><strong>Destino:</strong> ao confirmar, o sistema chamará a API oficial de <?= cat_h($label) ?> e só marcará sucesso após confirmação ou aceite real. Campos de preço e estoque não entram no payload.</div>
<?php if ((string)$item['error_message'] !== ''): ?><div class="alert err"><?= cat_h((string)$item['error_message']) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="staging_id" value="<?= (int)$item['id'] ?>">
<div class="compare"><div><h3>Antes</h3><b>Título</b><div class="original"><?= cat_h((string)$original['name']) ?></div><b>Descrição</b><div class="original"><?= nl2br(cat_h((string)$original['description'])) ?></div></div>
<div class="edit"><h3>Depois — editável</h3>
<label>Título<input name="optimized_title" value="<?= cat_h((string)$item['optimized_title']) ?>" required></label>
<label>Descrição<textarea name="optimized_description" rows="6" required><?= cat_h((string)$item['optimized_description']) ?></textarea></label>
<label>Bullet points<textarea name="bullet_points" rows="4"><?= cat_h(implode("\n",array_map('strval',$bullets))) ?></textarea></label>
<label>SEO keywords<input name="seo_keywords" value="<?= cat_h((string)$item['seo_keywords']) ?>"></label>
<label>Marketing hooks<input name="marketing_hooks" value="<?= cat_h((string)$item['marketing_hooks']) ?>"></label>
<label>Meta title<input name="meta_title" value="<?= cat_h((string)($meta['meta_title']??'')) ?>"></label>
<label>Meta description<input name="meta_description" value="<?= cat_h((string)($meta['meta_description']??'')) ?>"></label>
</div></div><div class="actions"><button class="publish" name="action" value="publish">Salvar e publicar em <?= cat_h($label) ?></button><button class="reject" name="action" value="reject">Rejeitar</button></div></form>
</article>
<?php endforeach; ?>
</section>
</main>
<script>
(()=>{'use strict';const log=document.getElementById('log');const write=t=>{log.style.display='block';log.textContent+=t+'\n';log.scrollTop=log.scrollHeight};
async function optimize(id,channel,provider){const r=await fetch('/admin/catalog-optimization/api/optimize_catalog.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id,target_channel:channel,provider})});return r.json()}
document.getElementById('run').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';const channel=document.getElementById('channel').value,provider=document.getElementById('provider').value,limit=document.getElementById('limit').value;try{const r=await fetch(`?ajax=pending_ids&channel=${encodeURIComponent(channel)}&limit=${encodeURIComponent(limit)}`,{credentials:'same-origin'});const d=await r.json();for(const id of(d.product_ids||[])){const out=await optimize(id,channel,provider);write(`#${id}: ${out.success?'gerado':'falhou'} ${out.error||''}`)}setTimeout(()=>location.reload(),1000)}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});
document.getElementById('retry').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';try{const r=await fetch('?ajax=failed_items',{credentials:'same-origin'});const d=await r.json();for(const item of(d.items||[])){const out=await optimize(item.product_id,item.channel,item.provider_used);write(`#${item.product_id}: ${out.success?'reprocessado':'falhou'} ${out.error||''}`)}setTimeout(()=>location.reload(),1000)}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}})})();
</script></body></html>
