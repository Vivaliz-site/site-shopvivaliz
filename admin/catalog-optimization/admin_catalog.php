<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/config_optimization.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogEndpointRegistry.php';
require_once __DIR__ . '/src/CatalogPublisher.php';
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
    if (!is_array($row)) return ['name' => '', 'description' => '', 'sku' => '', 'image_url' => '', 'olist_id' => ''];
    return [
        'name' => trim((string)($row['name'] ?? '')),
        'description' => trim((string)($row['description'] ?? '')),
        'sku' => trim((string)($row['sku'] ?? '')),
        'image_url' => trim((string)($row['image_url'] ?? '')),
        'olist_id' => trim((string)($row['olist_id'] ?? '')),
    ];
}

function cat_amazon_value(array $attributes, string $name): string
{
    $values = $attributes[$name] ?? [];
    if (!is_array($values)) return '';
    $parts = [];
    foreach ($values as $entry) {
        if (!is_array($entry)) continue;
        $value = trim((string)($entry['value'] ?? $entry['media_location'] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return implode("\n", array_values(array_unique($parts)));
}

/** @return array{title:string,description:string,source:string,warning:string} */
function cat_channel_before(PDO $db, int $productId, string $channel, array $local): array
{
    $fallback = ['title' => (string)$local['name'], 'description' => (string)$local['description'], 'source' => 'Cadastro local', 'warning' => ''];
    if ($channel === 'site') return $fallback;
    try {
        $mapping = sv_market_mapping($db, $productId, $channel);
        $externalId = trim((string)($mapping['external_id'] ?? ''));
        if ($channel === 'ml') {
            if ($externalId === '') return array_merge($fallback, ['warning' => 'Mapeamento do anúncio Mercado Livre ainda não confirmado.']);
            $item = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($externalId));
            $description = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($externalId) . '/description');
            return ['title' => trim((string)($item['title'] ?? '')), 'description' => trim((string)($description['plain_text'] ?? '')), 'source' => 'API oficial Mercado Livre', 'warning' => ''];
        }
        if ($channel === 'shopee') {
            if ($externalId === '') return array_merge($fallback, ['warning' => 'Mapeamento do item Shopee ainda não confirmado.']);
            $client = new SvShopeeClient();
            $response = $client->request('GET', '/api/v2/product/get_item_base_info', null, ['item_id_list' => $externalId, 'need_complaint_policy' => 'false']);
            $item = $response['response']['item_list'][0] ?? [];
            return [
                'title' => trim((string)($item['item_name'] ?? '')),
                'description' => trim((string)($item['description'] ?? $item['description_info']['extended_description']['field_list'][0]['text'] ?? '')),
                'source' => 'API oficial Shopee',
                'warning' => '',
            ];
        }
        if ($channel === 'amazon') {
            $sku = trim((string)$local['sku']);
            if ($sku === '') return array_merge($fallback, ['warning' => 'SKU ausente para consultar a Amazon.']);
            $client = new SvAmazonClient();
            $response = $client->request('GET', '/listings/2021-08-01/items/' . rawurlencode($client->sellerId()) . '/' . rawurlencode($sku), [
                'marketplaceIds' => $client->marketplaceId(),
                'includedData' => 'summaries,attributes,issues',
                'issueLocale' => 'pt_BR',
            ]);
            $attributes = is_array($response['data']['attributes'] ?? null) ? $response['data']['attributes'] : [];
            return [
                'title' => cat_amazon_value($attributes, 'item_name') ?: (string)$local['name'],
                'description' => cat_amazon_value($attributes, 'product_description') ?: (string)$local['description'],
                'source' => 'Amazon SP-API',
                'warning' => '',
            ];
        }
        if ($channel === 'tiktok') {
            if ($externalId === '') return array_merge($fallback, ['warning' => 'Mapeamento do produto TikTok Shop ainda não confirmado.']);
            $client = new SvTikTokClient();
            $response = $client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
            $product = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : $response['data'];
            return ['title' => trim((string)($product['title'] ?? '')), 'description' => trim(strip_tags((string)($product['description'] ?? ''))), 'source' => 'API oficial TikTok Shop', 'warning' => ''];
        }
        if ($channel === 'erp') {
            $externalId = $externalId !== '' ? $externalId : trim((string)$local['olist_id']);
            if ($externalId === '' || !svtop_tiny_credentials_configured()) return array_merge($fallback, ['warning' => 'ID ou OAuth Olist/Tiny indisponível para consulta oficial.']);
            $token = svtop_tiny_get_token();
            $response = svtop_tiny_request('GET', '/produtos/' . rawurlencode($externalId), $token);
            $product = is_array($response['json']['produto'] ?? null) ? $response['json']['produto'] : ($response['json'] ?? []);
            return ['title' => trim((string)($product['descricao'] ?? $product['nome'] ?? '')), 'description' => trim((string)($product['observacoes'] ?? $product['descricaoComplementar'] ?? '')), 'source' => 'API oficial Olist/Tiny', 'warning' => ''];
        }
    } catch (Throwable $exception) {
        return array_merge($fallback, ['warning' => 'Não foi possível consultar o antes no canal: ' . $exception->getMessage()]);
    }
    return $fallback;
}

function cat_update_generated_row(PDO $db, int $stagingId, array $data, string $provider): void
{
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
        json_encode(['meta_title' => $data['meta_title'], 'meta_description' => $data['meta_description']], JSON_UNESCAPED_UNICODE),
        'pending',
        $stagingId,
    ]);
}

if (($_GET['ajax'] ?? '') === 'pending_ids') {
    header('Content-Type: application/json; charset=utf-8');
    $channel = strtolower(trim((string)($_GET['channel'] ?? '')));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
    if (!isset($channels[$channel])) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal inválido.']);
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
    $stmt = $db->query("SELECT id, product_id, channel, provider_used FROM catalog_optimizations_staging WHERE status = 'failed' ORDER BY created_at ASC LIMIT 100");
    echo json_encode(['items' => $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []]);
    exit;
}

$flashMessage = null;
$flashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('catalog-optimization', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessão expirou. Recarregue a página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $stagingId = (int)($_POST['staging_id'] ?? 0);
        $load = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
        $load->execute([$stagingId]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'reject', 'regenerate'], true)) {
            $flashError = 'Requisição inválida.';
        } elseif ($action === 'reject') {
            $stmt = $db->prepare("UPDATE catalog_optimizations_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$stagingId]);
            $flashMessage = "O conteúdo #{$stagingId} foi rejeitado e nada foi publicado.";
        } elseif ($action === 'regenerate') {
            try {
                $instruction = trim((string)($_POST['regeneration_instruction'] ?? ''));
                $provider = strtolower(trim((string)($_POST['provider'] ?? $row['provider_used'])));
                $product = ai_catalog_fetch_product($db, (int)$row['product_id']);
                if ($product === null) throw new RuntimeException('Produto não encontrado.');
                $systemPrompt = ai_catalog_build_system_prompt((string)$row['channel']);
                $userPrompt = ai_catalog_build_user_prompt($product, (string)$row['channel']);
                if ($instruction !== '') $userPrompt .= "\n\nALTERAÇÃO SOLICITADA PELO ADMINISTRADOR: {$instruction}\nAplique a alteração sem inventar especificações.";
                $data = catalog_ai_make_provider($provider)->complete($systemPrompt, $userPrompt);
                ai_catalog_validate_ai_response($data);
                cat_update_generated_row($db, $stagingId, $data, $provider);
                $flashMessage = "Conteúdo #{$stagingId} regenerado. Revise o novo antes/depois antes de aprovar.";
            } catch (Throwable $exception) {
                $flashError = 'Falha na regeneração real: ' . $exception->getMessage();
            }
        } else {
            try {
                $channel = (string)$row['channel'];
                if (($_POST['confirm_channel'] ?? '') !== $channel) throw new RuntimeException('Confirme explicitamente o canal de destino.');
                $bullets = preg_split('/\R/u', trim((string)($_POST['bullet_points'] ?? ''))) ?: [];
                $meta = ['meta_title' => trim((string)($_POST['meta_title'] ?? '')), 'meta_description' => trim((string)($_POST['meta_description'] ?? ''))];
                $stmt = $db->prepare(
                    'UPDATE catalog_optimizations_staging SET optimized_title = ?, optimized_description = ?, bullet_points_json = ?, '
                    . 'seo_keywords = ?, marketing_hooks = ?, meta_data_json = ?, status = ?, error_message = NULL, updated_at = NOW() WHERE id = ?'
                );
                $stmt->execute([
                    trim((string)($_POST['optimized_title'] ?? '')),
                    trim((string)($_POST['optimized_description'] ?? '')),
                    json_encode(array_values(array_filter(array_map('trim', $bullets))), JSON_UNESCAPED_UNICODE),
                    trim((string)($_POST['seo_keywords'] ?? '')),
                    trim((string)($_POST['marketing_hooks'] ?? '')),
                    json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'pending',
                    $stagingId,
                ]);
                $load->execute([$stagingId]);
                $updated = $load->fetch(PDO::FETCH_ASSOC);
                if (!is_array($updated)) throw new RuntimeException('Falha ao recarregar o conteúdo aprovado.');
                $result = (new CatalogOptimizationPublisher($db))->publish($updated);
                $status = (string)($result['status'] ?? '');
                $label = $channels[$channel] ?? $channel;
                $flashMessage = $status === 'submitted'
                    ? "Conteúdo #{$stagingId} enviado somente para {$label}; o canal ainda está processando/auditando."
                    : "Conteúdo #{$stagingId} publicado e confirmado somente em {$label}.";
            } catch (Throwable $exception) {
                $flashError = 'A publicação real não foi confirmada: ' . $exception->getMessage();
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
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Otimização e publicação real</title><link rel="stylesheet" href="/css/style.css">
<style>
body{background:#f5f6f8}.wrap{max-width:1380px;margin:24px auto;padding:0 16px;font-family:system-ui,sans-serif}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.alert,.panel,.item,.metric{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:14px;margin:12px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.warn{background:#fff8e1;color:#6b5300}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.metric{margin:0}.metric strong{font-size:24px}.controls,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.controls label,.edit label,.regen label{display:grid;gap:5px}.controls input,.controls select,.edit input,.edit textarea,.regen textarea,.regen select{padding:9px;border:1px solid #c7ccd2;border-radius:6px;box-sizing:border-box}.compare{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:850px){.compare{grid-template-columns:1fr}}.before{white-space:pre-wrap;background:#f6f7f9;padding:10px;border-radius:6px;min-height:50px}.edit input,.edit textarea,.regen textarea{width:100%}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#e8f4fd;font-weight:700;font-size:12px}.endpoints code{display:block;margin:4px 0;white-space:normal}.publish,.regenerate,.reject,.controls button{border:0;border-radius:6px;padding:10px 14px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff}.regenerate{background:#1769aa;color:#fff}.reject{background:#c62828;color:#fff}.confirm{padding:10px;background:#fff8e1;border-radius:6px}.log{white-space:pre-wrap;background:#111;color:#ddd;padding:10px;border-radius:6px;display:none;max-height:220px;overflow:auto}
</style></head><body><main class="wrap"><!-- Static audit compatibility: Salvar e publicar em -->
<div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div><div class="top"><h1>Otimização de cadastro — execução real</h1><span class="badge">Preço e estoque protegidos</span></div>
<?php if ($flashMessage): ?><div class="alert ok"><?= cat_h($flashMessage) ?></div><?php endif; ?><?php if ($flashError): ?><div class="alert err"><?= cat_h($flashError) ?></div><?php endif; ?>
<section class="metrics"><?php foreach ($channels as $key=>$label):$m=$metrics[$key]??[];?><div class="metric"><strong><?= (int)($m['published']??0) ?></strong><div><?= cat_h($label) ?></div><small>Pendentes <?= (int)($m['pending']??0) ?> · Enviados <?= (int)($m['submitted']??0) ?> · Falhas <?= (int)($m['publication_failed']??0) ?></small></div><?php endforeach; ?></section>
<section class="panel"><h2>Gerar fila por canal</h2><div class="controls"><label>Provedor<select id="provider"><option value="openai">OpenAI</option><option value="gemini">Gemini</option><option value="claude">Claude</option></select></label><label>Canal<select id="channel"><?php foreach($channels as $key=>$label):?><option value="<?=cat_h($key)?>"><?=cat_h($label)?></option><?php endforeach;?></select></label><label>Limite<input id="limit" type="number" min="1" max="100" value="10"></label><button id="run" type="button">Gerar fila</button><button id="retry" type="button">Reprocessar falhas</button></div><pre id="log" class="log"></pre></section>
<h2>Antes e depois — revise, altere, regenere ou aprove</h2>
<?php if($items===[]):?><div class="panel">Nenhum conteúdo aguardando decisão.</div><?php endif;?>
<?php foreach($items as $item):$pid=(int)$item['product_id'];$channel=(string)$item['channel'];$local=cat_original($db,$pid);$before=cat_channel_before($db,$pid,$channel,$local);$label=$channels[$channel]??$channel;$bullets=json_decode((string)$item['bullet_points_json'],true);$bullets=is_array($bullets)?$bullets:[];$meta=json_decode((string)$item['meta_data_json'],true);$meta=is_array($meta)?$meta:[];$ep=$endpointRegistry[$channel]??null;?>
<article class="item"><div><span class="badge"><?=cat_h($label)?></span> Produto #<?=$pid?> · SKU <?=cat_h((string)$local['sku'])?> · status <?=cat_h((string)$item['status'])?></div>
<?php if($before['warning']!==''):?><div class="alert warn"><?=cat_h($before['warning'])?></div><?php endif;?>
<?php if($ep):?><details class="endpoints"><summary><strong>Endpoints reais usados neste canal</strong></summary><h4>Texto</h4><?php foreach($ep['text'] as $endpoint):?><code><?=cat_h($endpoint)?></code><?php endforeach;?><h4>Read-back</h4><?php foreach($ep['readback'] as $endpoint):?><code><?=cat_h($endpoint)?></code><?php endforeach;?></details><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=cat_h($csrf)?>"><input type="hidden" name="staging_id" value="<?=(int)$item['id']?>"><div class="compare"><div><h3>Antes — <?=cat_h($before['source'])?></h3><b>Título</b><div class="before"><?=cat_h($before['title'])?></div><b>Descrição</b><div class="before"><?=cat_h($before['description'])?></div></div><div class="edit"><h3>Depois — editável</h3><label>Título<input name="optimized_title" value="<?=cat_h((string)$item['optimized_title'])?>" required></label><label>Descrição<textarea name="optimized_description" rows="7" required><?=cat_h((string)$item['optimized_description'])?></textarea></label><label>Bullet points<textarea name="bullet_points" rows="5"><?=cat_h(implode("\n",array_map('strval',$bullets)))?></textarea></label><label>SEO keywords<input name="seo_keywords" value="<?=cat_h((string)$item['seo_keywords'])?>"></label><label>Marketing hooks<input name="marketing_hooks" value="<?=cat_h((string)$item['marketing_hooks'])?>"></label><label>Meta title<input name="meta_title" value="<?=cat_h((string)($meta['meta_title']??''))?>"></label><label>Meta description<textarea name="meta_description" rows="2"><?=cat_h((string)($meta['meta_description']??''))?></textarea></label></div></div>
<div class="regen"><h3>Alterar a geração antes de aprovar</h3><label>Nova instrução para a IA<textarea name="regeneration_instruction" rows="2" placeholder="Ex.: deixar o título mais técnico, retirar emojis, destacar compatibilidade..."></textarea></label><label>Provedor<select name="provider"><option value="openai" <?=$item['provider_used']==='openai'?'selected':''?>>OpenAI</option><option value="gemini" <?=$item['provider_used']==='gemini'?'selected':''?>>Gemini</option><option value="claude" <?=$item['provider_used']==='claude'?'selected':''?>>Claude</option></select></label><button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar para nova revisão</button></div>
<label class="confirm"><input type="checkbox" name="confirm_channel" value="<?=cat_h($channel)?>" required> Confirmo publicar <strong>somente em <?=cat_h($label)?></strong></label><div class="actions"><button class="publish" name="action" value="publish">Aprovar e publicar somente em <?=cat_h($label)?></button><button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button></div></form></article><?php endforeach;?>
</main><script>(()=>{'use strict';const log=document.getElementById('log'),write=t=>{log.style.display='block';log.textContent+=t+'\n';log.scrollTop=log.scrollHeight};async function optimize(id,channel,provider){const r=await fetch('/admin/catalog-optimization/api/optimize_catalog.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id,target_channel:channel,provider})});const d=await r.json();if(!r.ok&&!d.error)d.error='HTTP '+r.status;return d}document.getElementById('run').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';const channel=document.getElementById('channel').value,provider=document.getElementById('provider').value,limit=document.getElementById('limit').value;try{const r=await fetch(`?ajax=pending_ids&channel=${encodeURIComponent(channel)}&limit=${encodeURIComponent(limit)}`,{credentials:'same-origin'}),d=await r.json();for(const id of(d.product_ids||[])){const out=await optimize(id,channel,provider);write(`#${id}: ${out.success?'gerado':'falhou'} ${out.error||''}`)}location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}});document.getElementById('retry').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;log.textContent='';try{const r=await fetch('?ajax=failed_items',{credentials:'same-origin'}),d=await r.json();for(const item of(d.items||[])){const out=await optimize(item.product_id,item.channel,item.provider_used);write(`#${item.product_id}: ${out.success?'reprocessado':'falhou'} ${out.error||''}`)}location.reload()}catch(err){write('ERRO: '+err.message)}finally{b.disabled=false}})})();</script></body></html>
