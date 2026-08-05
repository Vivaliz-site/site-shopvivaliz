<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config.php';

$db = ai_studio_db();
if ($db === null) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}

$flashMessage = null;
$flashError = null;

// --- Ação de aprovar/rejeitar (POST, mesma página) ---
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
        } else {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';

            $update = $db->prepare('UPDATE product_images_staging SET status = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$newStatus, $stagingId]);

            if ($newStatus === 'approved') {
                // ---------------------------------------------------------------
                // GANCHO: aqui é onde a imagem aprovada deve ser promovida para
                // a tabela oficial de produtos e passar a ser exibida na loja.
                // Não implementado automaticamente porque o schema exato da
                // tabela de imagens de produto em produção (nome da coluna,
                // se é 1 imagem principal ou uma galeria, se precisa mover o
                // arquivo físico de storage/staging/ para public/assets/...)
                // não foi confirmado neste checkout. Exemplo do que normalmente
                // entraria aqui:
                //
                //   $imagePath = str_replace(
                //       AI_STUDIO_STORAGE_URL_PREFIX,
                //       '/public/assets/products/',
                //       $row['local_path']
                //   );
                //   copy(
                //       AI_STUDIO_STORAGE_DIR . basename($row['local_path']),
                //       __DIR__ . '/../../public/assets/products/' . basename($row['local_path'])
                //   );
                //   $updateProduct = $db->prepare(
                //       'UPDATE produtos SET imagem_principal_url = ? WHERE id = ?'
                //   );
                //   $updateProduct->execute([$imagePath, (int) $row['product_id']]);
                //
                // Ajuste os nomes de coluna/tabela acima para o schema real
                // antes de descomentar, e confirme se o tipo de imagem
                // ('white' | 'hero' | 'ambient') deve ir para campos
                // diferentes (ex: imagem principal vs. galeria) em vez de
                // sempre sobrescrever a mesma coluna.
                // ---------------------------------------------------------------
                $flashMessage = "Imagem #$stagingId aprovada. Promoção para a loja não é automática — ver gancho comentado em admin_validate.php.";
            } else {
                $flashMessage = "Imagem #$stagingId rejeitada.";
            }
        }
    }
}

// --- Lista de itens pendentes ---
// Nota: não usamos JOIN direto com `produtos` aqui porque o nome exato da
// coluna de nome do produto varia entre releases (ver ai_studio_fetch_product
// em process_item.php) — um JOIN com coluna inexistente derrubaria a página
// inteira com um erro fatal de SQL. Buscamos os nomes separadamente, com
// SELECT * por produto (mesma tolerância usada no restante do módulo).
$stmt = $db->query(
    "SELECT s.id, s.product_id, s.image_type, s.provider_used, s.local_path, s.status, s.created_at
     FROM product_images_staging s
     WHERE s.status = 'pending'
     ORDER BY s.created_at DESC"
);
$pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productNamesById = [];
$uniqueProductIds = array_unique(array_map(static fn (array $r) => (int) $r['product_id'], $pendingItems));
foreach ($uniqueProductIds as $pid) {
    try {
        // `products` (não `produtos`, que não existe em produção — ver
        // process_item.php::ai_studio_fetch_product para a mesma correção).
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
    .aisv-card .actions { display: flex; gap: 8px; padding: 0 14px 14px; }
    .aisv-card .actions form { flex: 1; }
    .aisv-card .actions button { width: 100%; padding: 8px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .aisv-card .actions .approve { background: #1a7f37; color: #fff; }
    .aisv-card .actions .approve:hover { background: #146128; }
    .aisv-card .actions .reject { background: #c62828; color: #fff; }
    .aisv-card .actions .reject:hover { background: #9e1c1c; }
    .aisv-empty { padding: 40px; text-align: center; color: #666; background: #fff; border: 1px dashed #ccc; border-radius: 10px; }
</style>
</head>
<body>
<div class="aisv-wrap">
    <div style="margin-bottom:12px;"><a href="/admin/menu-completo.php" style="color:#555;text-decoration:none;font-size:14px;">← Voltar ao Admin</a></div>
    <div class="aisv-topbar">
        <h1>✅ Validar imagens geradas</h1>
        <a href="/admin/ai-image-studio/admin_dashboard.php">← Voltar ao dashboard</a>
    </div>

    <?php if ($flashError !== null): ?>
        <div class="aisv-alert error"><?= ais_v_h($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashMessage !== null): ?>
        <div class="aisv-alert info"><?= ais_v_h($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($pendingItems === []): ?>
        <div class="aisv-empty">Nenhuma imagem pendente de aprovação no momento.</div>
    <?php else: ?>
    <div class="aisv-grid">
        <?php foreach ($pendingItems as $item): ?>
            <?php
                $productName = (string) ($productNamesById[(int) $item['product_id']] ?? '');
                $productLabel = $productName !== '' ? $productName : ('Produto #' . (int) $item['product_id']);
            ?>
            <div class="aisv-card">
                <img src="<?= ais_v_h((string) $item['local_path']) ?>" alt="<?= ais_v_h($productLabel) ?> — <?= ais_v_h((string) $item['image_type']) ?>" loading="lazy">
                <div class="body">
                    <div class="title"><?= ais_v_h($productLabel) ?></div>
                    <div class="meta">Tipo: <?= ais_v_h((string) $item['image_type']) ?> · Provedor: <?= ais_v_h((string) $item['provider_used']) ?></div>
                    <div class="meta">#<?= (int) $item['id'] ?> · <?= ais_v_h((string) $item['created_at']) ?></div>
                </div>
                <div class="actions">
                    <form method="post">
                        <input type="hidden" name="staging_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="approve">Aprovar</button>
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
