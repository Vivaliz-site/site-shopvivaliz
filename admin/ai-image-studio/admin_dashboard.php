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

function ai_studio_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$IMAGE_TYPE_LABELS = ['white' => 'Branco (estúdio)', 'hero' => 'Hero (comercial)', 'ambient' => 'Ambientada (lifestyle)'];

$batchResults = null;
$batchError = null;
$previewProducts = null; // null = nenhum preview buscado ainda; [] = buscado mas vazio; array = lista
$previewProvider = '';
$previewModel = '';

// ---------------------------------------------------------------------------
// FASE 1 (GET ?preview=1): busca produtos candidatos ao lote (respeitando o
// limite escolhido) e mostra uma prévia — nome + foto + checkboxes por tipo
// de imagem — ANTES de qualquer disparo real. Nada é gerado nesta fase.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['preview'] ?? '') === '1') {
    $previewProvider = (string) ($_GET['provider'] ?? '');
    $previewModel = trim((string) ($_GET['model'] ?? ''));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 5)));

    if (!in_array($previewProvider, ['openai', 'google', 'claude'], true)) {
        $batchError = 'Selecione um provedor válido.';
    } else {
        try {
            // `products` é o nome real da tabela em produção (confirmado via
            // admin/diagnostico-banco.php em 2026-08-05) — NÃO é `produtos`,
            // que não existe. Essa era a causa raiz do HTTP 500 anterior.
            $stmt = $db->prepare(
                'SELECT p.id, p.name, p.image_url
                 FROM products p
                 LEFT JOIN product_images_staging s ON s.product_id = p.id
                 WHERE s.id IS NULL
                 ORDER BY p.id ASC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute();
            $previewProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[ai-image-studio] Falha ao buscar produtos para preview: ' . $e->getMessage());
            $batchError = 'Falha ao buscar produtos pendentes: ' . $e->getMessage();
            $previewProducts = null;
        }
    }
}

// ---------------------------------------------------------------------------
// FASE 2 (POST run_batch): dispara de fato — só produtos e tipos de imagem
// explicitamente confirmados na tela de prévia chegam até aqui.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['run_batch'])) {
    $provider = (string) ($_POST['provider'] ?? '');
    $model = trim((string) ($_POST['model'] ?? ''));
    $productIds = array_map('intval', (array) ($_POST['product_ids'] ?? []));
    $imageTypesByProduct = (array) ($_POST['image_types'] ?? []); // [productId => ['white','hero',...]]

    if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
        $batchError = 'Selecione um provedor válido.';
    } elseif ($productIds === []) {
        $batchError = 'Nenhum produto selecionado. Volte e busque produtos primeiro.';
    } else {
        $batchResults = [];
        foreach ($productIds as $productId) {
            $types = array_values(array_map('strval', (array) ($imageTypesByProduct[(string) $productId] ?? [])));
            if ($types === []) {
                // Produto ficou na lista mas o admin desmarcou os 3 tipos —
                // pula em vez de gerar erro, já que "gerar zero imagens" foi
                // uma escolha explícita dele.
                continue;
            }
            $batchResults[] = ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null);
        }
        if ($batchResults === []) {
            $batchError = 'Nenhum produto tinha ao menos 1 tipo de imagem marcado — nada foi gerado.';
            $batchResults = null;
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
    .ais-form select, .ais-form input[type=number], .ais-form input[type=text] { padding: 8px; border: 1px solid #ccc; border-radius: 6px; width: 320px; max-width: 100%; }
    .ais-form button { margin-top: 18px; background: #1a1a2e; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .ais-form button:hover { background: #33334d; }
    .ais-form small { display: block; color: #666; margin-top: 4px; max-width: 500px; }
    .ais-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
    .ais-alert.error { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; }
    .ais-alert.info { background: #e8f4fd; color: #0c3b57; border: 1px solid #b6e0fe; }
    .ais-alert.note { background: #fff8e1; color: #6b5300; border: 1px solid #f5e6a3; }
    table.ais-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e4ea; border-radius: 10px; overflow: hidden; }
    table.ais-table th, table.ais-table td { padding: 10px 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
    table.ais-table th { background: #f7f8fa; }
    .ais-badge { padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
    .ais-badge.pending { background: #fff3cd; color: #7a5c00; }
    .ais-badge.approved { background: #d4edda; color: #14532d; }
    .ais-badge.rejected { background: #f8d7da; color: #611a15; }
    .ais-badge.error { background: #f8d7da; color: #611a15; }
    .ais-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
    .ais-topbar a { color: #1a1a2e; text-decoration: none; font-weight: 600; }
    .ais-back { margin-bottom: 16px; }
    .ais-back a { color: #555; text-decoration: none; font-size: 14px; }
    .ais-back a:hover { text-decoration: underline; }
    .ais-preview-list { display: flex; flex-direction: column; gap: 10px; margin: 16px 0; }
    .ais-preview-item { display: flex; align-items: center; gap: 14px; background: #fafbfc; border: 1px solid #e6e8ed; border-radius: 8px; padding: 10px 14px; }
    .ais-preview-item img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; background: #eee; flex-shrink: 0; }
    .ais-preview-item .ais-pi-info { flex: 1; min-width: 160px; }
    .ais-preview-item .ais-pi-name { font-weight: 600; font-size: 14px; }
    .ais-preview-item .ais-pi-id { color: #888; font-size: 12px; }
    .ais-pi-types { display: flex; gap: 14px; flex-wrap: wrap; }
    .ais-pi-types label { display: flex; align-items: center; gap: 5px; font-weight: 400; margin: 0; font-size: 13px; }
</style>
</head>
<body>
<div class="ais-wrap">
    <div class="ais-back"><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div>

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

    <?php if ($previewProducts === null): ?>
        <!-- Passo 1: escolher provedor/modelo/limite e buscar candidatos. Nada é gerado aqui. -->
        <div class="ais-form">
            <h2>Passo 1 — Buscar produtos para o lote</h2>
            <p style="color:#666; margin-top:0;">Cada geração produz até <strong>3 imagens por produto</strong> (branco, hero, ambientada). No próximo passo você verá cada produto com foto atual e poderá desmarcar tipos que não quiser gerar.</p>
            <form method="get">
                <input type="hidden" name="preview" value="1">
                <label for="provider">Motor de IA</label>
                <select name="provider" id="provider" onchange="aisUpdateModelDefault()" required>
                    <option value="openai">OpenAI (Direto)</option>
                    <option value="google">Google Gemini (Direto)</option>
                    <option value="claude">Claude (Otimização de prompt + Geração via OpenAI)</option>
                </select>

                <label for="model">Modelo de imagem (opcional — padrão recomendado já preenchido)</label>
                <input type="text" name="model" id="model" value="gpt-image-1">
                <small>
                    OpenAI: <code>gpt-image-1</code> é o único modelo da OpenAI com suporte real a edição de imagem com foto de referência (<code>dall-e-3</code> não aceita imagem de entrada, por isso não é oferecido).
                    Google: <code>gemini-2.5-flash-image</code> ("Nano Banana") é o único modelo multimodal do Google compatível com esse fluxo — os modelos Imagen clássicos são texto-para-imagem puro e não aceitam foto de referência.
                    Deixe o padrão a menos que saiba que precisa de outro.
                </small>

                <label for="limit">Limite de produtos neste lote</label>
                <input type="number" name="limit" id="limit" value="5" min="1" max="50" required>

                <div>
                    <button type="submit">Buscar produtos deste lote →</button>
                </div>
            </form>
        </div>
        <script>
        function aisUpdateModelDefault() {
            var provider = document.getElementById('provider').value;
            var modelInput = document.getElementById('model');
            var defaults = { openai: 'gpt-image-1', claude: 'gpt-image-1', google: 'gemini-2.5-flash-image' };
            // Só troca se o campo ainda está em um dos valores padrão conhecidos
            // (evita sobrescrever um valor customizado que o admin já digitou).
            if (Object.values(defaults).indexOf(modelInput.value) !== -1) {
                modelInput.value = defaults[provider] || '';
            }
        }
        </script>
    <?php else: ?>
        <!-- Passo 2: prévia dos produtos que serão processados + checkboxes por tipo de imagem. -->
        <div class="ais-form">
            <h2>Passo 2 — Confirmar produtos e tipos de imagem</h2>
            <p style="margin-top:0;color:#666;">
                Provedor: <strong><?= ai_studio_h($previewProvider) ?></strong>
                <?php if ($previewModel !== ''): ?> · Modelo: <strong><?= ai_studio_h($previewModel) ?></strong><?php endif; ?>
                — <?= count($previewProducts) ?> produto(s) encontrado(s) sem imagens na fila.
            </p>

            <?php if ($previewProducts === []): ?>
                <div class="ais-alert note">Nenhum produto pendente encontrado (todos já têm imagens na fila, ou não há produtos cadastrados). <a href="/admin/ai-image-studio/admin_dashboard.php">← Buscar de novo</a></div>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="run_batch" value="1">
                <input type="hidden" name="provider" value="<?= ai_studio_h($previewProvider) ?>">
                <input type="hidden" name="model" value="<?= ai_studio_h($previewModel) ?>">

                <div class="ais-preview-list">
                <?php foreach ($previewProducts as $p): ?>
                    <?php
                        $pid = (int) $p['id'];
                        $pname = trim((string) ($p['name'] ?? '')) !== '' ? (string) $p['name'] : "Produto #$pid";
                        $pimg = trim((string) ($p['image_url'] ?? ''));
                    ?>
                    <div class="ais-preview-item">
                        <input type="hidden" name="product_ids[]" value="<?= $pid ?>">
                        <?php if ($pimg !== ''): ?>
                            <img src="<?= ai_studio_h($pimg) ?>" alt="">
                        <?php else: ?>
                            <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='56' height='56'><rect width='56' height='56' fill='%23eee'/></svg>" alt="">
                        <?php endif; ?>
                        <div class="ais-pi-info">
                            <div class="ais-pi-name"><?= ai_studio_h($pname) ?></div>
                            <div class="ais-pi-id">#<?= $pid ?><?= $pimg === '' ? ' · sem foto cadastrada (será rejeitado)' : '' ?></div>
                        </div>
                        <div class="ais-pi-types">
                            <?php foreach ($IMAGE_TYPE_LABELS as $typeKey => $typeLabel): ?>
                                <label>
                                    <input type="checkbox" name="image_types[<?= $pid ?>][]" value="<?= $typeKey ?>" checked>
                                    <?= ai_studio_h($typeLabel) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <div>
                    <button type="submit">🔥 Confirmar e Disparar Geração (<?= count($previewProducts) ?> produto(s))</button>
                    &nbsp; <a href="/admin/ai-image-studio/admin_dashboard.php">Cancelar / buscar outro lote</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
