<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/src/OmnichannelImagePublisher.php';

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}
svcp_ensure_schema($db);

$channelLabels = [
    'site' => 'ShopVivaliz',
    'ml' => 'Mercado Livre',
    'shopee' => 'Shopee',
    'amazon' => 'Amazon',
    'tiktok' => 'TikTok Shop',
    'erp' => 'Olist / Tiny ERP',
];

function ais_v_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$flashMessage = null;
$flashError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $stagingId = (int)($_POST['staging_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM product_images_staging WHERE id = ? LIMIT 1');
    $stmt->execute([$stagingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'reject'], true)) {
        $flashError = 'Requisição inválida.';
    } elseif ($action === 'reject') {
        $update = $db->prepare("UPDATE product_images_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $update->execute([$stagingId]);
        $flashMessage = "Imagem #{$stagingId} rejeitada.";
    } else {
        $selected = $_POST['channels'] ?? [];
        $selected = is_array($selected) ? array_values(array_filter(array_map('strval', $selected))) : [];
        try {
            $result = (new AiStudioOmnichannelImagePublisher($db))->publish($row, $selected);
            $status = (string)$result['status'];
            $successNames = [];
            $failedNames = [];
            foreach ($result['results'] as $channel => $channelResult) {
                $label = $channelLabels[$channel] ?? $channel;
                if (($channelResult['status'] ?? '') === 'publication_failed') {
                    $failedNames[] = $label;
                } else {
                    $successNames[] = $label;
                }
            }
            $flashMessage = "Imagem #{$stagingId}: execução real concluída com status {$status}. "
                . ($successNames !== [] ? 'Atualizada/enviada: ' . implode(', ', $successNames) . '. ' : '')
                . ($failedNames !== [] ? 'Falhou: ' . implode(', ', $failedNames) . '.' : '');
        } catch (Throwable $e) {
            error_log('[ai-image-studio] Publicação omnicanal falhou #' . $stagingId . ': ' . $e->getMessage());
            $flashError = 'A imagem não foi marcada como publicada porque a execução real falhou: ' . $e->getMessage();
        }
    }
}

$stmt = $db->query(
    "SELECT s.*, p.name product_name, p.sku product_sku FROM product_images_staging s "
    . 'LEFT JOIN products p ON p.id = s.product_id '
    . "WHERE s.status IN ('pending','publication_failed','partial_published','submitted') "
    . 'ORDER BY s.created_at DESC LIMIT 100'
);
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Validar e publicar imagens</title><link rel="stylesheet" href="/css/style.css">
<style>
body{background:#f5f6f8}.wrap{max-width:1280px;margin:24px auto;padding:0 16px;font-family:system-ui,sans-serif}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.alert{padding:12px 16px;border-radius:8px;margin:14px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}.card{background:#fff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden}.card>img{width:100%;aspect-ratio:1/1;object-fit:contain;background:#f4f5f7}.body{padding:14px}.meta{font-size:13px;color:#606770;margin:4px 0}.channels{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.channel{display:flex;gap:8px;align-items:center;padding:8px;background:#f5f8fb;border-radius:6px}.note{font-size:12px;background:#eef6ff;border-left:4px solid #1769aa;padding:9px;margin:10px 0}.actions{display:flex;gap:9px}.actions button{border:0;border-radius:6px;padding:10px 12px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff;flex:2}.reject{background:#c62828;color:#fff;flex:1}.status{display:inline-block;padding:4px 8px;border-radius:999px;background:#eee;font-size:12px;font-weight:700}.summary{white-space:pre-wrap;font-size:12px;background:#fafafa;border:1px solid #eee;padding:8px;border-radius:6px;max-height:130px;overflow:auto}
</style></head><body><main class="wrap">
<div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div><div class="top"><h1>Validar e publicar imagens omnicanal</h1><a href="/admin/ai-image-studio/admin_dashboard.php">Dashboard de geração</a></div>
<div class="note">A geração usa a foto real do produto. Ao aprovar, cada canal selecionado recebe uma chamada real de API. O sistema preserva as galerias existentes, não altera preço nem estoque e registra resposta, request ID e read-back.</div>
<?php if ($flashMessage): ?><div class="alert ok"><?= ais_v_h($flashMessage) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert err"><?= ais_v_h($flashError) ?></div><?php endif; ?>
<?php if ($items === []): ?><div class="alert ok">Nenhuma imagem aguardando publicação.</div><?php endif; ?>
<section class="grid">
<?php foreach ($items as $item): $saved=json_decode((string)($item['target_channels_json']??'[]'),true);$saved=is_array($saved)?$saved:['site']; ?>
<article class="card"><img src="<?= ais_v_h((string)$item['local_path']) ?>" alt="Imagem gerada do produto" loading="lazy"><div class="body">
<h2><?= ais_v_h((string)($item['product_name'] ?: 'Produto #'.$item['product_id'])) ?></h2>
<div class="meta">SKU <?= ais_v_h((string)($item['product_sku']??'')) ?> · Tipo <?= ais_v_h((string)$item['image_type']) ?> · Provedor <?= ais_v_h((string)$item['provider_used']) ?></div>
<div class="meta"><span class="status"><?= ais_v_h((string)$item['status']) ?></span> · staging #<?= (int)$item['id'] ?></div>
<?php if ((string)($item['error_message']??'')!==''): ?><div class="alert err"><?= ais_v_h((string)$item['error_message']) ?></div><?php endif; ?>
<?php if ((string)($item['publication_summary_json']??'')!==''): ?><details><summary>Última execução</summary><div class="summary"><?= ais_v_h((string)$item['publication_summary_json']) ?></div></details><?php endif; ?>
<form method="post"><input type="hidden" name="staging_id" value="<?= (int)$item['id'] ?>"><fieldset><legend><strong>Canais que serão atualizados</strong></legend><div class="channels">
<?php foreach ($channelLabels as $key=>$label): ?><label class="channel"><input type="checkbox" name="channels[]" value="<?= ais_v_h($key) ?>" <?= in_array($key,$saved,true)?'checked':'' ?>> <?= ais_v_h($label) ?></label><?php endforeach; ?>
</div></fieldset><div class="actions"><button class="publish" name="action" value="publish">Aprovar e publicar nos canais selecionados</button><button class="reject" name="action" value="reject">Rejeitar</button></div></form>
</div></article><?php endforeach; ?>
</section></main></body></html>
