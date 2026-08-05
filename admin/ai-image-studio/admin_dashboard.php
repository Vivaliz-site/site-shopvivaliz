<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process_item.php';

$db = ai_studio_db();
if ($db === null) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}

$batchResults = null;
$batchError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['run_batch'])) {
    $provider = (string) ($_POST['provider'] ?? '');
    $limit = max(1, min(50, (int) ($_POST['limit'] ?? 5)));

    if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
        $batchError = 'Selecione um provedor válido.';
    } else {
        // Prioriza produtos que ainda nunca passaram por este pipeline
        // (zero linhas em product_images_staging), para não regenerar
        // imagem de produto que já está na fila. Se quiser reprocessar um
        // produto específico, use process_item.php diretamente com o
        // product_id desejado.
        $stmt = $db->prepare(
            'SELECT p.id
             FROM produtos p
             LEFT JOIN product_images_staging s ON s.product_id = p.id
             WHERE s.id IS NULL
             ORDER BY p.id ASC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        $productIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        if ($productIds === []) {
            $batchError = 'Nenhum produto pendente de processamento encontrado (todos já têm imagens na fila).';
        } else {
            $batchResults = [];
            foreach ($productIds as $productId) {
                $batchResults[] = ai_studio_process_item($db, $productId, $provider);
            }
        }
    }
}

// --- Estatísticas da fila para exibição ---
$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $stmt = $db->query('SELECT status, COUNT(*) AS total FROM product_images_staging GROUP BY status');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string) $row['status'];
        if (isset($statusCounts[$status])) {
            $statusCounts[$status] = (int) $row['total'];
        }
    }
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler estatísticas da fila: ' . $e->getMessage());
}

$providerCounts = [];
try {
    $stmt = $db->query('SELECT provider_used, COUNT(*) AS total FROM product_images_staging GROUP BY provider_used');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $providerCounts[(string) $row['provider_used']] = (int) $row['total'];
    }
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler estatísticas por provedor: ' . $e->getMessage());
}

$recentItems = [];
try {
    $stmt = $db->query(
        "SELECT s.id, s.product_id, s.image_type, s.provider_used, s.status, s.created_at
         FROM product_images_staging s
         ORDER BY s.created_at DESC
         LIMIT 15"
    );
    $recentItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler itens recentes: ' . $e->getMessage());
}

function ai_studio_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Image Studio — ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
    .ais-wrap { max-width: 1000px; margin: 24px auto; padding: 0 16px; font-family: system-ui, -apple-system, sans-serif; }
    .ais-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .ais-card { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; padding: 16px; text-align: center; }
    .ais-card .num { font-size: 28px; font-weight: 700; }
    .ais-card.pending .num { color: #b8860b; }
    .ais-card.approved .num { color: #1a7f37; }
    .ais-card.rejected .num { color: #c62828; }
    .ais-form { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; padding: 20px; margin-bottom: 24px; }
    .ais-form label { display: block; font-weight: 600; margin-bottom: 6px; margin-top: 14px; }
    .ais-form select, .ais-form input[type=number] { padding: 8px; border: 1px solid #ccc; border-radius: 6px; width: 260px; max-width: 100%; }
    .ais-form button { margin-top: 18px; background: #1a1a2e; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .ais-form button:hover { background: #33334d; }
    .ais-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
    .ais-alert.error { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; }
    .ais-alert.info { background: #e8f4fd; color: #0c3b57; border: 1px solid #b6e0fe; }
    table.ais-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; overflow: hidden; }
    table.ais-table th, table.ais-table td { padding: 10px 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
    table.ais-table th { background: #f7f8fa; }
    .ais-badge { padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
    .ais-badge.pending { background: #fff3cd; color: #7a5c00; }
    .ais-badge.approved { background: #d4edda; color: #14532d; }
    .ais-badge.rejected { background: #f8d7da; color: #611a15; }
    .ais-badge.error { background: #f8d7da; color: #611a15; }
    .ais-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .ais-topbar a { color: #1a1a2e; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="ais-wrap">
    <div class="ais-topbar">
        <h1>🖼️ AI Image Studio</h1>
        <a href="/admin/ai-image-studio/admin_validate.php">Ir para aprovação →</a>
    </div>

    <div class="ais-cards">
        <div class="ais-card pending"><div class="num"><?= (int) $statusCounts['pending'] ?></div><div>Pendentes</div></div>
        <div class="ais-card approved"><div class="num"><?= (int) $statusCounts['approved'] ?></div><div>Aprovadas</div></div>
        <div class="ais-card rejected"><div class="num"><?= (int) $statusCounts['rejected'] ?></div><div>Rejeitadas</div></div>
    </div>

    <?php if ($batchError !== null): ?>
        <div class="ais-alert error"><?= ai_studio_h($batchError) ?></div>
    <?php endif; ?>

    <?php if ($batchResults !== null): ?>
        <?php
            $totalOk = 0;
            $totalErr = 0;
            foreach ($batchResults as $item) {
                foreach ($item['results'] as $r) {
                    if ($r['status'] === 'pending') {
                        $totalOk++;
                    } else {
                        $totalErr++;
                    }
                }
            }
        ?>
        <div class="ais-alert info">
            Lote processado: <?= count($batchResults) ?> produto(s),
            <?= $totalOk ?> imagem(ns) geradas com sucesso, <?= $totalErr ?> com erro.
            Veja detalhes na tabela "Itens recentes" abaixo ou no painel de aprovação.
        </div>
    <?php endif; ?>

    <div class="ais-form">
        <h2>Disparar geração em lote</h2>
        <form method="post">
            <label for="provider">Motor de IA</label>
            <select name="provider" id="provider" required>
                <option value="openai">OpenAI (Direto)</option>
                <option value="google">Google Gemini (Direto)</option>
                <option value="claude">Claude (Otimização + Geração)</option>
            </select>

            <label for="limit">Limite de produtos neste lote</label>
            <input type="number" name="limit" id="limit" value="5" min="1" max="50" required>

            <div>
                <button type="submit" name="run_batch" value="1">Disparar Geração em Lote</button>
            </div>
        </form>
    </div>

    <h2>Itens recentes</h2>
    <?php if ($recentItems === []): ?>
        <p>Nenhum item processado ainda.</p>
    <?php else: ?>
    <table class="ais-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Produto</th>
                <th>Tipo</th>
                <th>Provedor</th>
                <th>Status</th>
                <th>Criado em</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentItems as $item): ?>
            <tr>
                <td>#<?= (int) $item['id'] ?></td>
                <td>#<?= (int) $item['product_id'] ?></td>
                <td><?= ai_studio_h((string) $item['image_type']) ?></td>
                <td><?= ai_studio_h((string) $item['provider_used']) ?></td>
                <td><span class="ais-badge <?= ai_studio_h((string) $item['status']) ?>"><?= ai_studio_h((string) $item['status']) ?></span></td>
                <td><?= ai_studio_h((string) $item['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>
