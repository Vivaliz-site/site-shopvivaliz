<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config_optimization.php';

$db = catalog_ai_db();
if ($db === null) {
    http_response_code(500);
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}

/**
 * Gancho de promoção para produção — preparado e comentado. Não é chamado
 * automaticamente porque o schema exato de onde o título/descrição
 * "oficiais" do produto vivem em `produtos` não foi confirmado neste
 * checkout (nomes de coluna variam entre releases, ver
 * ai_catalog_fetch_product() em api/optimize_catalog.php). Confirme o
 * schema real antes de descomentar o corpo do método.
 */
final class CatalogOptimizationPromoter
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<string,mixed> $stagingRow linha de catalog_optimizations_staging já aprovada
     */
    public function promoteToProduction(array $stagingRow): void
    {
        // ---------------------------------------------------------------
        // GANCHO: aqui é onde o texto aprovado deveria ser escrito na
        // tabela oficial de produtos (ou numa tabela por-canal, se o site
        // mantiver textos diferentes por canal de venda simultaneamente).
        // Exemplo do que normalmente entraria aqui, ajustando nomes de
        // coluna/tabela para o schema real confirmado via
        // `SHOW CREATE TABLE produtos;`:
        //
        //   if ($stagingRow['channel'] === 'site') {
        //       $update = $this->db->prepare(
        //           'UPDATE produtos SET nome = ?, descricao = ? WHERE id = ?'
        //       );
        //       $update->execute([
        //           $stagingRow['optimized_title'],
        //           $stagingRow['optimized_description'],
        //           (int) $stagingRow['product_id'],
        //       ]);
        //   } else {
        //       // Canais de marketplace (ml/shopee/amazon/tiktok) tipicamente
        //       // vivem numa tabela de integração separada (ex:
        //       // produtos_canal_ml) em vez de sobrescrever `produtos`
        //       // diretamente — confirme a tabela certa antes de escrever.
        //   }
        //
        // Deliberadamente NÃO implementado sem essa confirmação: escrever
        // no lugar errado da tabela de produtos em produção é pior do que
        // não escrever nada.
        // ---------------------------------------------------------------
    }
}

// --- Ação AJAX: lista de product_ids pendentes para um canal (usado pelo
// painel de automação em lote antes de disparar as chamadas via fetch) ---
if (($_GET['ajax'] ?? '') === 'pending_ids') {
    header('Content-Type: application/json; charset=UTF-8');
    $channel = strtolower(trim((string) ($_GET['channel'] ?? '')));
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 10)));

    if (!array_key_exists($channel, catalog_ai_channels())) {
        http_response_code(400);
        echo json_encode(['error' => 'Canal inválido.']);
        exit;
    }

    $stmt = $db->prepare(
        'SELECT p.id
         FROM produtos p
         LEFT JOIN catalog_optimizations_staging s
            ON s.product_id = p.id AND s.channel = ?
         WHERE s.id IS NULL
         ORDER BY p.id ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$channel]);
    $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    echo json_encode(['product_ids' => $ids]);
    exit;
}

$flashMessage = null;
$flashError = null;

// --- Ação: salvar edição + aprovar, ou rejeitar (POST, mesma página) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $stagingId = (int) ($_POST['staging_id'] ?? 0);

    if ($stagingId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $flashError = 'Requisição inválida.';
    } else {
        $stmt = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
        $stmt->execute([$stagingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $flashError = "Item #$stagingId não encontrado.";
        } elseif ($action === 'reject') {
            $update = $db->prepare('UPDATE catalog_optimizations_staging SET status = ?, updated_at = NOW() WHERE id = ?');
            $update->execute(['rejected', $stagingId]);
            $flashMessage = "Item #$stagingId rejeitado.";
        } else {
            // approve: grava as edições feitas no formulário (o admin pode
            // ter corrigido o texto gerado pela IA antes de aprovar) e só
            // então marca como approved.
            $editedTitle = trim((string) ($_POST['optimized_title'] ?? $row['optimized_title']));
            $editedDescription = trim((string) ($_POST['optimized_description'] ?? $row['optimized_description']));
            $editedBulletPointsRaw = trim((string) ($_POST['bullet_points'] ?? ''));
            $editedSeoKeywords = trim((string) ($_POST['seo_keywords'] ?? $row['seo_keywords']));
            $editedMarketingHooks = trim((string) ($_POST['marketing_hooks'] ?? $row['marketing_hooks']));
            $editedMetaTitle = trim((string) ($_POST['meta_title'] ?? ''));
            $editedMetaDescription = trim((string) ($_POST['meta_description'] ?? ''));

            // bullet_points chega do <textarea> como uma linha por item —
            // reconverte para o formato JSON armazenado na coluna.
            $bulletPointsArray = $editedBulletPointsRaw === ''
                ? []
                : array_values(array_filter(array_map('trim', explode("\n", $editedBulletPointsRaw)), static fn (string $line) => $line !== ''));
            $bulletPointsJson = json_encode($bulletPointsArray, JSON_UNESCAPED_UNICODE);

            $existingMeta = json_decode((string) $row['meta_data_json'], true);
            $existingMeta = is_array($existingMeta) ? $existingMeta : [];
            $metaDataJson = json_encode([
                'meta_title' => $editedMetaTitle !== '' ? $editedMetaTitle : (string) ($existingMeta['meta_title'] ?? ''),
                'meta_description' => $editedMetaDescription !== '' ? $editedMetaDescription : (string) ($existingMeta['meta_description'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);

            $update = $db->prepare(
                'UPDATE catalog_optimizations_staging
                 SET optimized_title = ?, optimized_description = ?, bullet_points_json = ?, seo_keywords = ?, marketing_hooks = ?, meta_data_json = ?, status = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $update->execute([
                $editedTitle,
                $editedDescription,
                $bulletPointsJson !== false ? $bulletPointsJson : '[]',
                $editedSeoKeywords,
                $editedMarketingHooks,
                $metaDataJson !== false ? $metaDataJson : '{}',
                'approved',
                $stagingId,
            ]);

            // Recarrega a linha atualizada para passar ao gancho de promoção.
            $stmt = $db->prepare('SELECT * FROM catalog_optimizations_staging WHERE id = ? LIMIT 1');
            $stmt->execute([$stagingId]);
            $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($updatedRow)) {
                (new CatalogOptimizationPromoter($db))->promoteToProduction($updatedRow);
            }

            $flashMessage = "Item #$stagingId salvo e aprovado. Promoção para a tabela oficial de produtos não é automática — ver gancho comentado em CatalogOptimizationPromoter::promoteToProduction().";
        }
    }
}

// --- Bloco 1: métricas ---
$pendingTotal = 0;
try {
    $pendingTotal = (int) $db->query("SELECT COUNT(*) FROM catalog_optimizations_staging WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {
    error_log('[catalog-optimization] Falha ao contar pendentes: ' . $e->getMessage());
}

$byChannel = [];
try {
    $stmt = $db->query(
        "SELECT channel, status, COUNT(*) AS total
         FROM catalog_optimizations_staging
         GROUP BY channel, status"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byChannel[(string) $row['channel']][(string) $row['status']] = (int) $row['total'];
    }
} catch (Throwable $e) {
    error_log('[catalog-optimization] Falha ao contar por canal: ' . $e->getMessage());
}

// --- Bloco 3: grid antes/depois (itens pending) ---
$pendingItems = [];
try {
    $stmt = $db->query(
        "SELECT * FROM catalog_optimizations_staging WHERE status = 'pending' ORDER BY created_at DESC LIMIT 30"
    );
    $pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[catalog-optimization] Falha ao listar pendentes: ' . $e->getMessage());
}

$originalByProductId = [];
$uniqueProductIds = array_unique(array_map(static fn (array $r) => (int) $r['product_id'], $pendingItems));
foreach ($uniqueProductIds as $pid) {
    try {
        $pStmt = $db->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
        $pStmt->execute([$pid]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($pRow)) {
            $originalByProductId[$pid] = [
                'name' => (string) ($pRow['nome'] ?? $pRow['name'] ?? ''),
                'description' => (string) ($pRow['descricao_completa'] ?? $pRow['descricao'] ?? $pRow['description'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[catalog-optimization] Falha ao buscar produto original #' . $pid . ': ' . $e->getMessage());
    }
}

function cat_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$channels = catalog_ai_channels();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Otimização de Cadastro — ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
    .cat-wrap { max-width: 1300px; margin: 24px auto; padding: 0 16px; font-family: system-ui, -apple-system, sans-serif; }
    .cat-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .cat-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .cat-metric-card { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; padding: 16px; }
    .cat-metric-card .num { font-size: 26px; font-weight: 700; }
    .cat-metric-card .label { font-size: 13px; color: #666; margin-top: 4px; }
    .cat-metric-card table { width: 100%; font-size: 12px; margin-top: 8px; }
    .cat-metric-card table td { padding: 2px 0; }
    .cat-form { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; padding: 20px; margin-bottom: 24px; }
    .cat-form label { display: block; font-weight: 600; margin-bottom: 6px; margin-top: 14px; }
    .cat-form select, .cat-form input[type=number] { padding: 8px; border: 1px solid #ccc; border-radius: 6px; width: 260px; max-width: 100%; }
    .cat-form button { margin-top: 18px; background: #1a1a2e; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .cat-form button:disabled { opacity: 0.6; cursor: not-allowed; }
    .cat-progress-wrap { margin-top: 16px; display: none; }
    .cat-progress-bar-track { background: #eee; border-radius: 8px; height: 18px; overflow: hidden; }
    .cat-progress-bar-fill { background: #1a7f37; height: 100%; width: 0%; transition: width .2s ease; }
    .cat-progress-log { font-size: 12px; color: #555; margin-top: 8px; max-height: 140px; overflow-y: auto; background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 8px; }
    .cat-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
    .cat-alert.error { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; }
    .cat-alert.info { background: #e8f4fd; color: #0c3b57; border: 1px solid #b6e0fe; }
    .cat-compare-card { background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; padding: 18px; margin-bottom: 18px; }
    .cat-compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 800px) { .cat-compare-grid { grid-template-columns: 1fr; } }
    .cat-compare-col h4 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; color: #888; }
    .cat-compare-col textarea, .cat-compare-col input[type=text] { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; margin-bottom: 10px; }
    .cat-compare-col .original-box { background: #f7f8fa; border: 1px solid #eee; border-radius: 6px; padding: 10px; margin-bottom: 10px; white-space: pre-wrap; font-size: 14px; }
    .cat-compare-meta { font-size: 12px; color: #888; margin-bottom: 10px; }
    .cat-compare-actions { display: flex; gap: 10px; margin-top: 12px; }
    .cat-compare-actions button { padding: 9px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .cat-compare-actions .approve { background: #1a7f37; color: #fff; }
    .cat-compare-actions .reject { background: #c62828; color: #fff; }
    .cat-empty { padding: 40px; text-align: center; color: #666; background: #fff; border: 1px dashed #ccc; border-radius: 10px; }
</style>
</head>
<body>
<div class="cat-wrap">
    <div class="cat-topbar">
        <h1>📝 Otimização de Cadastro (SEO/GEO)</h1>
    </div>

    <?php if ($flashError !== null): ?>
        <div class="cat-alert error"><?= cat_h($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashMessage !== null): ?>
        <div class="cat-alert info"><?= cat_h($flashMessage) ?></div>
    <?php endif; ?>

    <!-- Bloco 1: métricas -->
    <div class="cat-metrics">
        <div class="cat-metric-card">
            <div class="num"><?= $pendingTotal ?></div>
            <div class="label">Total pendente (todos os canais)</div>
        </div>
        <?php foreach ($channels as $channelKey => $channelLabel): ?>
            <?php $counts = $byChannel[$channelKey] ?? []; ?>
            <div class="cat-metric-card">
                <div class="num"><?= (int) ($counts['approved'] ?? 0) ?></div>
                <div class="label">Aprovados — <?= cat_h($channelLabel) ?></div>
                <table>
                    <tr><td>Pendentes</td><td style="text-align:right"><?= (int) ($counts['pending'] ?? 0) ?></td></tr>
                    <tr><td>Rejeitados</td><td style="text-align:right"><?= (int) ($counts['rejected'] ?? 0) ?></td></tr>
                </table>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Bloco 2: automação em lote -->
    <div class="cat-form">
        <h2>Processar fila em lote</h2>
        <label for="cat-provider">Provedor de IA</label>
        <select id="cat-provider">
            <option value="openai">OpenAI</option>
            <option value="gemini">Google Gemini</option>
            <option value="claude">Anthropic Claude</option>
        </select>

        <label for="cat-channel">Canal alvo</label>
        <select id="cat-channel">
            <?php foreach ($channels as $channelKey => $channelLabel): ?>
                <option value="<?= cat_h($channelKey) ?>"><?= cat_h($channelLabel) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="cat-limit">Limite de produtos neste lote</label>
        <input type="number" id="cat-limit" value="10" min="1" max="200">

        <div>
            <button type="button" id="cat-run-batch">Processar Fila em Lote via Cron</button>
        </div>

        <div class="cat-progress-wrap" id="cat-progress-wrap">
            <div class="cat-progress-bar-track">
                <div class="cat-progress-bar-fill" id="cat-progress-fill"></div>
            </div>
            <div id="cat-progress-label" style="font-size:13px;margin-top:6px;color:#555;"></div>
            <div class="cat-progress-log" id="cat-progress-log"></div>
        </div>
    </div>

    <!-- Bloco 3: grid antes/depois -->
    <h2>Comparação de cadastro — Antes e Depois (pendentes)</h2>
    <?php if ($pendingItems === []): ?>
        <div class="cat-empty">Nenhum item pendente de aprovação no momento.</div>
    <?php else: ?>
        <?php foreach ($pendingItems as $item): ?>
            <?php
                $productId = (int) $item['product_id'];
                $original = $originalByProductId[$productId] ?? ['name' => '', 'description' => ''];
                $bulletPoints = json_decode((string) $item['bullet_points_json'], true);
                $bulletPointsText = is_array($bulletPoints) ? implode("\n", $bulletPoints) : '';
                $metaData = json_decode((string) $item['meta_data_json'], true);
                $metaData = is_array($metaData) ? $metaData : [];
            ?>
            <div class="cat-compare-card">
                <div class="cat-compare-meta">
                    #<?= (int) $item['id'] ?> · Produto #<?= $productId ?> · Canal: <?= cat_h($channels[$item['channel']] ?? (string) $item['channel']) ?> · Provedor: <?= cat_h((string) $item['provider_used']) ?> · <?= cat_h((string) $item['created_at']) ?>
                </div>
                <?php if (!empty($item['error_message'])): ?>
                    <div class="cat-alert error">Erro registrado: <?= cat_h((string) $item['error_message']) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="staging_id" value="<?= (int) $item['id'] ?>">
                    <div class="cat-compare-grid">
                        <div class="cat-compare-col">
                            <h4>Antes (cadastro original)</h4>
                            <div class="original-box"><strong><?= cat_h($original['name']) ?></strong>

<?= cat_h($original['description']) ?></div>
                        </div>
                        <div class="cat-compare-col">
                            <h4>Depois (gerado por IA — editável)</h4>
                            <label>Título otimizado</label>
                            <input type="text" name="optimized_title" value="<?= cat_h((string) $item['optimized_title']) ?>">
                            <label>Descrição otimizada</label>
                            <textarea name="optimized_description" rows="5"><?= cat_h((string) $item['optimized_description']) ?></textarea>
                            <label>Bullet points (um por linha)</label>
                            <textarea name="bullet_points" rows="4"><?= cat_h($bulletPointsText) ?></textarea>
                            <label>SEO keywords</label>
                            <input type="text" name="seo_keywords" value="<?= cat_h((string) $item['seo_keywords']) ?>">
                            <label>Marketing hooks</label>
                            <input type="text" name="marketing_hooks" value="<?= cat_h((string) $item['marketing_hooks']) ?>">
                            <label>Meta title</label>
                            <input type="text" name="meta_title" value="<?= cat_h((string) ($metaData['meta_title'] ?? '')) ?>">
                            <label>Meta description</label>
                            <input type="text" name="meta_description" value="<?= cat_h((string) ($metaData['meta_description'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="cat-compare-actions">
                        <button type="submit" name="action" value="approve" class="approve">Salvar Edição e Aprovar</button>
                        <button type="submit" name="action" value="reject" class="reject">Rejeitar</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    var runBtn = document.getElementById('cat-run-batch');
    var progressWrap = document.getElementById('cat-progress-wrap');
    var progressFill = document.getElementById('cat-progress-fill');
    var progressLabel = document.getElementById('cat-progress-label');
    var progressLog = document.getElementById('cat-progress-log');

    function logLine(text) {
        var line = document.createElement('div');
        line.textContent = text;
        progressLog.appendChild(line);
        progressLog.scrollTop = progressLog.scrollHeight;
    }

    async function fetchPendingIds(channel, limit) {
        var url = '/admin/catalog-optimization/admin_catalog.php?ajax=pending_ids&channel=' + encodeURIComponent(channel) + '&limit=' + encodeURIComponent(limit);
        var res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) {
            throw new Error('Falha ao buscar produtos pendentes (HTTP ' + res.status + ').');
        }
        var data = await res.json();
        if (data.error) {
            throw new Error(data.error);
        }
        return data.product_ids || [];
    }

    async function processOne(productId, channel, provider) {
        var res = await fetch('/admin/catalog-optimization/api/optimize_catalog.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: productId, target_channel: channel, provider: provider })
        });
        var data = await res.json();
        return data;
    }

    runBtn.addEventListener('click', async function () {
        var provider = document.getElementById('cat-provider').value;
        var channel = document.getElementById('cat-channel').value;
        var limit = parseInt(document.getElementById('cat-limit').value, 10) || 10;

        runBtn.disabled = true;
        progressWrap.style.display = 'block';
        progressFill.style.width = '0%';
        progressLog.innerHTML = '';
        progressLabel.textContent = 'Buscando produtos pendentes...';

        var ids;
        try {
            ids = await fetchPendingIds(channel, limit);
        } catch (err) {
            progressLabel.textContent = 'Erro ao buscar fila: ' + err.message;
            runBtn.disabled = false;
            return;
        }

        if (ids.length === 0) {
            progressLabel.textContent = 'Nenhum produto pendente encontrado para este canal.';
            runBtn.disabled = false;
            return;
        }

        var total = ids.length;
        var done = 0;
        var successCount = 0;
        var errorCount = 0;

        for (var i = 0; i < ids.length; i++) {
            var productId = ids[i];
            progressLabel.textContent = 'Processando ' + (i + 1) + '/' + total + ' — produto #' + productId + '...';

            try {
                var result = await processOne(productId, channel, provider);
                done++;
                if (result.success) {
                    successCount++;
                    logLine('[' + (i + 1) + '/' + total + '] Produto #' + productId + ' OK (staging_id=' + result.staging_id + ')');
                } else {
                    errorCount++;
                    logLine('[' + (i + 1) + '/' + total + '] Produto #' + productId + ' FALHOU: ' + (result.error || 'erro desconhecido'));
                }
            } catch (err) {
                done++;
                errorCount++;
                logLine('[' + (i + 1) + '/' + total + '] Produto #' + productId + ' FALHOU (rede): ' + err.message);
            }

            progressFill.style.width = Math.round((done / total) * 100) + '%';
        }

        progressLabel.textContent = 'Concluído: ' + successCount + ' sucesso(s), ' + errorCount + ' falha(s), ' + total + ' total.';
        runBtn.disabled = false;

        if (successCount > 0) {
            setTimeout(function () { window.location.reload(); }, 1500);
        }
    });
})();
</script>
</body>
</html>
