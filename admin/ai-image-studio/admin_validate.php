<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/ImagePublisher.php';

$db = ai_studio_db();
if ($db === null) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}

$flashMessage = null;
$flashError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $stagingId = (int) ($_POST['staging_id'] ?? 0);

    if ($stagingId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $flashError = 'Requisição inválida.';
    } else {
        $stmt = $db->prepare('SELECT * FROM product_images_staging WHERE id = ? LIMIT 1');
        $stmt->execute([$stagingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $flashError = "Item #$stagingId não encontrado.";
        } elseif ($action === 'reject') {
            $update = $db->prepare('UPDATE product_images_staging SET status = ?, updated_at = NOW() WHERE id = ?');
            $update->execute(['rejected', $stagingId]);
            $flashMessage = "Imagem #$stagingId rejeitada.";
        } else {
            try {
                $publisher = new AiStudioImagePublisher($db);
                $result = $publisher->publishToSite($row);
                $update = $db->prepare('UPDATE product_images_staging SET status = ?, error_message = NULL, updated_at = NOW() WHERE id = ?');
                $update->execute(['published', $stagingId]);
                $flashMessage = "Imagem #$stagingId aprovada e publicada de verdade no ShopVivaliz. " . (string)($result['note'] ?? '');
            } catch (Throwable $e) {
                error_log('[ai-image-studio] Falha ao publicar imagem #' . $stagingId . ': ' . $e->getMessage());
                $update = $db->prepare('UPDATE product_images_staging SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?');
                $update->execute(['publication_failed', mb_substr($e->getMessage(), 0, 1000), $stagingId]);
                $flashError = "A imagem #$stagingId não foi aprovada porque a publicação real falhou: " . $e->getMessage();
            }
        }
    }
}

$stmt = $db->query(
    "SELECT s.id, s.product_id, s.image_type, s.provider_used, s.local_path, s.status, s.created_at
     FROM product_images_staging s
     WHERE s.status IN ('pending', 'publication_failed')
     ORDER BY s.created_at DESC"
);
$pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productNamesById = [];
$uniqueProductIds = array_unique(array_map(static fn (array $r) => (int) $r['product_id'], $pendingItems));
foreach ($uniqueProductIds as $pid) {
    try {
        $pStmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $pStmt->execute([$pid]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($pRow)) {
            $productNamesById[$pid] = trim((string) ($pRow['name'] ?? $pRow['nome'] ?? $pRow['descricao'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('[ai-image-studio] Falha ao buscar nome do produto #' . $pid . ': ' . $e->getMessage());
    }
}

function ais_v_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Validar imagens — AI Image Studio</title>
<link rel="stylesheet" href="/css/style.css">
<style>
    .aisv-wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px; font-family: system-ui, -apple-system, sans-serif; }
    .aisv-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .aisv-topbar a { color: #1a1a2e; text-decoration: none; font-weight: 600; }
    .aisv-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
    .aisv-alert.error { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; }
    .aisv-alert.info { background: #e8f4fd; color: #0c3b57; border: 1px solid #b6e0fe; }
    .aisv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; }
    .aisv-card { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; }
    .aisv-card img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: #f0f1f4; }
    .aisv-card .body { padding: 12px 14px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
    .aisv-card .title { font-weight: 700; font-size: 14px; }
    .aisv-card .meta { font-size: 12px; color: #666; }
    .aisv-card .channel { font-size: 12px; font-weight: 700; color: #0c3b57; background: #e8f4fd; padding: 6px 8px; border-radius: 6px; margin-top: 6px; }
    .aisv-card .actions { display: flex; gap: 8px; padding: 0 14px 14px; }
    .aisv-card .actions form { flex: 1; }
    .aisv-card .actions button { width: 100%; padding: 8px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .aisv-card .actions .approve { background: #1a7f37; color: #fff; }
    .aisv-card .actions .reject { background: #c62828; color: #fff; }
    .aisv-empty { padding: 40px; text-align: center; color: #666; background: #fff; border: 1px dashed #ccc; border-radius: 10px; }
</style>
</head>
<body>
<div class="aisv-wrap">
    <div style="margin-bottom:12px;"><a href="/admin/menu-completo.php" style="color:#555;text-decoration:none;font-size:14px;">← Voltar ao Admin</a></div>
    <div class="aisv-topbar">
        <h1>✅ Validar e publicar imagens</h1>
        <a href="/admin/ai-image-studio/admin_dashboard.php">← Voltar ao dashboard</a>
    </div>

    <?php if ($flashError !== null): ?><div class="aisv-alert error"><?= ais_v_h($flashError) ?></div><?php endif; ?>
    <?php if ($flashMessage !== null): ?><div class="aisv-alert info"><?= ais_v_h($flashMessage) ?></div><?php endif; ?>

    <?php if ($pendingItems === []): ?>
        <div class="aisv-empty">Nenhuma imagem pendente de publicação.</div>
    <?php else: ?>
    <div class="aisv-grid">
        <?php foreach ($pendingItems as $item): ?>
            <?php
                $productName = (string) ($productNamesById[(int) $item['product_id']] ?? '');
                $productLabel = $productName !== '' ? $productName : ('Produto #' . (int) $item['product_id']);
            ?>
            <div class="aisv-card">
                <img src="<?= ais_v_h((string) $item['local_path']) ?>" alt="<?= ais_v_h($productLabel) ?>" loading="lazy">
                <div class="body">
                    <div class="title"><?= ais_v_h($productLabel) ?></div>
                    <div class="meta">Tipo: <?= ais_v_h((string) $item['image_type']) ?> · Provedor: <?= ais_v_h((string) $item['provider_used']) ?></div>
                    <div class="channel">Destino atual: ShopVivaliz — substituirá a imagem principal do produto</div>
                    <div class="meta">Status: <?= ais_v_h((string) $item['status']) ?> · #<?= (int) $item['id'] ?></div>
                </div>
                <div class="actions">
                    <form method="post">
                        <input type="hidden" name="staging_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="approve">Aprovar e publicar no ShopVivaliz</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="staging_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="reject">Rejeitar</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
