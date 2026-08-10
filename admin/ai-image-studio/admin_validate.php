<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/release-info.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process_item.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogEndpointRegistry.php';
require_once __DIR__ . '/src/ImageChannelProfile.php';
require_once __DIR__ . '/src/OmnichannelImagePublisher.php';

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Falha ao conectar ao banco de dados.');
}
svcp_ensure_schema($db);
$releaseInfo = sv_release_info_from_path(__DIR__);

$channelProfiles = ai_studio_channel_profiles();
$channelLabels = [];
foreach ($channelProfiles as $key => $profile) $channelLabels[$key] = (string)$profile['label'];
$endpointRegistry = sv_market_catalog_endpoint_registry();

function ais_v_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ais_staging_file(string $publicPath): ?string
{
    if (defined('AI_STUDIO_STORAGE_URL_PREFIX') && str_starts_with($publicPath, AI_STUDIO_STORAGE_URL_PREFIX)) {
        return AI_STUDIO_STORAGE_DIR . basename($publicPath);
    }
    return null;
}

/** @return array{name:string,description:string,image_ref:string,sku:string,olist_id:string}|null */
function ais_load_product(PDO $db, int $productId): ?array
{
    return ai_studio_fetch_product($db, $productId);
}

/** @return list<string> */
function ais_saved_targets(array $row): array
{
    $saved = json_decode((string)($row['target_channels_json'] ?? '[]'), true);
    if (!is_array($saved)) return [];
    return array_values(array_filter(array_map('strval', $saved)));
}

function ais_intended_channel(array $row): string
{
    $targets = ais_saved_targets($row);
    return count($targets) === 1 ? strtolower(trim($targets[0])) : '';
}

$flashMessage = null;
$flashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('ai-image-validation', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessao expirou. Recarregue a pagina.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'bulk_apply') {
            $bulkAction = (string)($_POST['bulk_action'] ?? '');
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])))));
            $load = $db->prepare('SELECT * FROM product_images_staging WHERE id = ? LIMIT 1');

            if ($selectedIds === [] || !in_array($bulkAction, ['publish', 'reject'], true)) {
                $flashError = 'Selecione itens e uma acao em massa valida.';
            } else {
                $processed = 0;
                $errors = [];
                $publisher = new AiStudioOmnichannelImagePublisher($db);

                foreach ($selectedIds as $selectedId) {
                    $load->execute([$selectedId]);
                    $row = $load->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) {
                        $errors[] = "#{$selectedId}: item nao encontrado.";
                        continue;
                    }

                    try {
                        if ($bulkAction === 'reject') {
                            $update = $db->prepare("UPDATE product_images_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                            $update->execute([$selectedId]);
                            $processed++;
                            continue;
                        }

                        $intended = ais_intended_channel($row);
                        if ($intended === '') {
                            throw new RuntimeException('imagem legada sem marketplace de geracao');
                        }
                        if (!isset($channelLabels[$intended])) {
                            throw new RuntimeException('marketplace persistido no staging nao e suportado');
                        }
                        $publisher->publish($row, [$intended]);
                        $processed++;
                    } catch (Throwable $exception) {
                        $errors[] = "#{$selectedId}: " . $exception->getMessage();
                    }
                }

                $labels = [
                    'publish' => 'aprovada(s) e publicada(s)',
                    'reject' => 'rejeitada(s)',
                ];
                if ($processed > 0) {
                    $flashMessage = "{$processed} imagem(ns) {$labels[$bulkAction]} com sucesso.";
                }
                if ($errors !== []) {
                    $flashError = 'Falhas em ' . count($errors) . ' item(ns): ' . implode(' | ', array_slice($errors, 0, 3));
                    if (count($errors) > 3) {
                        $flashError .= ' ...';
                    }
                }
            }
        } else {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM product_images_staging WHERE id = ? LIMIT 1');
            $stmt->execute([$stagingId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'reject', 'regenerate'], true)) {
                $flashError = 'Requisicao invalida.';
            } elseif ($action === 'reject') {
                $update = $db->prepare("UPDATE product_images_staging SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $update->execute([$stagingId]);
                $flashMessage = "Imagem #{$stagingId} rejeitada. Nenhum canal foi atualizado.";
            } elseif ($action === 'regenerate') {
                $basePath = null;
                $isTemp = false;
                $destination = null;
                try {
                    $productId = (int)$row['product_id'];
                    $product = ais_load_product($db, $productId);
                    if ($product === null) throw new RuntimeException('Produto nao encontrado.');

                    $adminInstruction = trim((string)($_POST['prompt_used'] ?? ''));
                    if ($adminInstruction === '') throw new RuntimeException('Informe a instrucao de cena antes de regenerar.');
                    $engine = strtolower(trim((string)($_POST['regeneration_engine'] ?? 'openai')));
                    if (!in_array($engine, ['openai', 'google', 'claude'], true)) throw new RuntimeException('Motor de regeneracao invalido.');

                    $targetChannel = ais_intended_channel($row);
                    if ($targetChannel === '') $targetChannel = strtolower(trim((string)($_POST['channel'] ?? '')));
                    if (!isset($channelProfiles[$targetChannel])) throw new RuntimeException('Marketplace de destino invalido.');

                    $imageType = strtolower(trim((string)$row['image_type']));
                    $guardedPrompts = ai_studio_default_prompts($product['name'], $targetChannel);
                    if (!isset($guardedPrompts[$imageType])) throw new RuntimeException('Tipo de imagem invalido para regeneracao.');
                    $fidelityGuard = $guardedPrompts[$imageType];

                    $basePath = ai_studio_resolve_base_image($product['image_ref'], dirname(__DIR__, 2), $productId);
                    $isTemp = str_starts_with($basePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
                    $filename = ai_studio_unique_filename($productId, $imageType);
                    $destination = AI_STUDIO_STORAGE_DIR . $filename;
                    $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;
                    $providerUsed = $engine;
                    $prompt = $fidelityGuard . ' Additional scene guidance from the administrator: ' . $adminInstruction;

                    if ($engine === 'google') {
                        (new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, AI_STUDIO_GOOGLE_IMAGEN_MODEL))->editImageToFile($prompt, $basePath, $destination);
                    } else {
                        if ($engine === 'claude') {
                            $claudePrompts = (new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL))->optimizePrompts(
                                $product['name'],
                                $product['description'] . "\nInstrucao adicional do administrador: " . $adminInstruction
                            );
                            $claudeScene = trim((string)($claudePrompts[$imageType] ?? ''));
                            if ($claudeScene !== '') $prompt = $fidelityGuard . ' Additional scene guidance optimized by Claude: ' . $claudeScene;
                            $providerUsed = 'claude_optimized';
                        }
                        (new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, AI_STUDIO_OPENAI_IMAGE_MODEL))->editImageToFile($prompt, $basePath, $destination);
                    }

                    $profile = ai_studio_channel_profile($targetChannel);
                    ai_studio_validate_image_file($destination, max(1000, (int)($profile['minimum_side'] ?? 1000)), true);
                    $oldFile = ais_staging_file((string)$row['local_path']);
                    $targets = json_encode([$targetChannel], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $update = $db->prepare(
                        "UPDATE product_images_staging SET local_path = ?, prompt_used = ?, provider_used = ?, target_channels_json = ?, status = 'pending', "
                        . 'error_message = NULL, publication_summary_json = NULL, published_at = NULL, updated_at = NOW() WHERE id = ?'
                    );
                    $update->execute([$publicPath, $prompt, $providerUsed, is_string($targets) ? $targets : '[]', $stagingId]);
                    if ($oldFile !== null && $oldFile !== $destination && is_file($oldFile)) @unlink($oldFile);
                    $flashMessage = "Imagem #{$stagingId} regenerada para {$channelLabels[$targetChannel]} com a foto real e as regras exclusivas desse marketplace reaplicadas.";
                } catch (Throwable $exception) {
                    if (is_string($destination) && is_file($destination)) @unlink($destination);
                    $flashError = 'Falha na regeneracao real: ' . $exception->getMessage();
                } finally {
                    if ($isTemp && is_string($basePath) && is_file($basePath)) @unlink($basePath);
                }
            } else {
                $intended = ais_intended_channel($row);
                if ($intended === '') {
                    $flashError = 'Imagem legada sem marketplace de geracao. Regere escolhendo o marketplace antes de autorizar a publicacao.';
                } elseif (!isset($channelLabels[$intended])) {
                    $flashError = 'Marketplace persistido no staging nao e suportado.';
                } elseif (($_POST['confirm_channel'] ?? '') !== $intended) {
                    $flashError = 'Confirme explicitamente o mesmo marketplace usado na geracao desta imagem.';
                } else {
                    try {
                        $result = (new AiStudioOmnichannelImagePublisher($db))->publish($row, [$intended]);
                        $status = (string)$result['status'];
                        $label = $channelLabels[$intended];
                        $flashMessage = $status === 'submitted'
                            ? "Imagem #{$stagingId} enviada somente para {$label}; o canal ainda esta auditando/processando."
                            : "Imagem #{$stagingId} publicada e confirmada somente em {$label}.";
                    } catch (Throwable $exception) {
                        error_log('[ai-image-studio] Publicacao falhou #' . $stagingId . ': ' . $exception->getMessage());
                        $flashError = 'A imagem nao foi marcada como publicada: ' . $exception->getMessage();
                    }
                }
            }
        }
    }
}

$stmt = $db->query(
    "SELECT s.*, p.name product_name, p.sku product_sku, p.image_url original_image FROM product_images_staging s "
    . 'LEFT JOIN products p ON p.id = s.product_id '
    . "WHERE s.status IN ('pending','publication_failed') ORDER BY s.created_at DESC LIMIT 100"
);
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = sv_csrf_token('ai-image-validation');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Revisao de imagens - ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
:root{color-scheme:light;--bg:#f4f7fb;--surface:#fff;--surface-soft:#f8fafc;--line:#d8e0ea;--text:#132135;--muted:#607186;--shadow:0 24px 60px rgba(15,23,42,.08);--radius:22px;--violet:#6d28d9;--blue:#1769aa;--green:#1a7f37;--red:#c62828}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:linear-gradient(180deg,#eef4fb 0%,var(--bg) 34%,#edf2f7 100%);color:var(--text)}
.wrap{max-width:1360px;margin:0 auto;padding:24px 16px 40px}
.hero,.panel,.queue-item{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
.hero{padding:26px;background:linear-gradient(135deg,#111827 0%,#312e81 54%,#6d28d9 100%);color:#fff}
.hero-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
.hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3.4rem);line-height:.96}
.hero p{margin:0;max-width:780px;color:rgba(255,255,255,.86);line-height:1.62}
.back-link,.hero-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:999px;padding:10px 14px;font-weight:800}
.back-link{background:#fff;color:#111827}
.hero-link{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);color:#fff}
.release-pill{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16);color:#fff;font-size:.82rem;font-weight:800;line-height:1.2}
.eyebrow{margin:0 0 10px;text-transform:uppercase;letter-spacing:.14em;font-size:.74rem;font-weight:800;color:rgba(255,255,255,.74)}
.alert{padding:14px 16px;border-radius:18px;margin:14px 0}
.ok{background:#e8f5e9;color:#175b28}
.err{background:#fdecea;color:#7b1717}
.warn{background:#fff8e1;color:#6b5300}
.panel{padding:22px}
.queue-toolbar{position:sticky;top:16px;z-index:5;display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px;margin:14px 0}
.queue-toolbar button{border:0;border-radius:999px;padding:12px 16px;font-weight:800;cursor:pointer;font:inherit}
.publish{background:var(--green);color:#fff}
.reject{background:var(--red);color:#fff}
.queue-meta{color:var(--muted);font-size:.95rem;margin-left:auto}
.queue-item{margin-top:14px;overflow:hidden}
.queue-item.is-selected{background:#eef6ff;border-color:#93c5fd}
.queue-item summary{list-style:none;cursor:pointer;padding:18px}
.queue-item summary::-webkit-details-marker{display:none}
.queue-item[open] summary{border-bottom:1px solid var(--line)}
.queue-summary{display:flex;align-items:flex-start;gap:14px;width:100%}
.queue-summary input[type=checkbox]{margin-top:5px}
.queue-main{flex:1;min-width:0}
.queue-title{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-weight:800}
.badge,.status{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:.78rem;font-weight:800}
.badge{background:#eef2ff;color:#3730a3}
.status{background:#f8fafc;color:#334155}
.status.fail{background:#fff7ed;color:#9a3412}
.meta{margin-top:8px;color:var(--muted);font-size:.9rem;display:flex;gap:10px;flex-wrap:wrap}
.queue-body{padding:18px}
.compare{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.compare>*{min-width:0}
.image-box{background:#f4f5f7;border-radius:18px;padding:10px;text-align:center}
.image-box img{width:100%;max-height:440px;object-fit:contain}
.edit label{display:grid;gap:6px;margin:12px 0;font-weight:700}
.edit textarea,.edit select{width:100%;padding:12px 14px;border:1px solid #c7ccd2;border-radius:14px;font:inherit;font-size:15px}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.actions button{border:0;border-radius:999px;padding:12px 16px;font-weight:800;cursor:pointer;font:inherit}
.regenerate{background:var(--blue);color:#fff}
.confirm{display:block;background:#fff8e1;padding:12px;border-radius:16px;margin:12px 0}
.endpoints code{display:block;margin:4px 0;white-space:normal;overflow-wrap:anywhere}
.note,.profile{font-size:.9rem;background:#eef6ff;border-left:4px solid var(--blue);padding:12px;margin:12px 0;border-radius:16px}
.profile ul{margin:6px 0;padding-left:20px}
.quality-ok{color:#166534}
.quality-warn{color:#92400e}
@media(max-width:860px){.compare{grid-template-columns:minmax(0,1fr)}.wrap{padding:14px 10px 32px}}
</style>
</head>
<body>
<main class="wrap">
    <section class="hero">
        <div class="hero-top">
            <div>
                <a class="back-link" href="/admin/">Voltar ao admin</a>
                <p class="eyebrow" style="margin-top:16px">Tela 2 - Revisao em accordion</p>
                <h1>Imagens em revisao por marketplace</h1>
                <p>
                    Use a lista colapsavel para revisar as imagens geradas, expandindo apenas o que precisar.
                    Voce pode selecionar varios itens ao mesmo tempo e aprovar ou rejeitar em massa.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
                    <a class="hero-link" href="/admin/ai-image-studio/admin_dashboard.php">Voltar para geracao</a>
                    <span class="release-pill" title="Release detectado via <?= ais_v_h($releaseInfo['source']) ?>">
                        Release <?= ais_v_h($releaseInfo['identifier']) ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <div class="note">O marketplace escolhido na geracao e imutavel durante a aprovacao. Para outro canal, gere ou regere uma versao especifica com as regras daquele destino.</div>
    <?php if ($flashMessage): ?><div class="alert ok"><?= ais_v_h($flashMessage) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert err"><?= ais_v_h($flashError) ?></div><?php endif; ?>

    <section class="panel">
        <form id="bulk-image-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= ais_v_h($csrf) ?>">
            <input type="hidden" name="action" value="bulk_apply">
            <div class="queue-toolbar">
                <button class="publish" type="submit" name="bulk_action" value="publish">Aprovar selecionados</button>
                <button class="reject" type="submit" name="bulk_action" value="reject">Rejeitar selecionados</button>
                <button type="button" id="select-all-queue">Selecionar todos</button>
                <button type="button" id="clear-all-queue">Limpar marcacoes</button>
                <button type="button" id="expand-all-queue">Expandir tudo</button>
                <button type="button" id="collapse-all-queue">Recolher tudo</button>
                <span class="queue-meta"><strong id="bulk-selected-count">0</strong> item(ns) selecionado(s)</span>
            </div>
        </form>

        <?php if ($items === []): ?>
            <div class="alert ok">Nenhuma imagem aguardando decisao.</div>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <?php
            $intended = ais_intended_channel($item);
            $legacy = $intended === '';
            $defaultChannel = $legacy ? 'site' : $intended;
            $profile = ai_studio_channel_profile($defaultChannel);
            $generatedFile = ais_staging_file((string)$item['local_path']);
            $imgInfo = is_string($generatedFile) && is_file($generatedFile) ? @getimagesize($generatedFile) : false;
            $w = is_array($imgInfo) ? (int)$imgInfo[0] : 0;
            $h = is_array($imgInfo) ? (int)$imgInfo[1] : 0;
            $recommended = (int)($profile['recommended_side'] ?? 1000);
            $meetsRec = $w >= $recommended && $h >= $recommended;
            $squareTolerance = max(8, (int)round(min($w, $h) * 0.02));
            $isSquare = $w > 0 && $h > 0 && abs($w - $h) <= $squareTolerance;
            $statusClass = (string)$item['status'] === 'publication_failed' ? 'status fail' : 'status';
            ?>
            <details class="queue-item" data-image-item>
                <summary>
                    <div class="queue-summary">
                        <input type="checkbox" class="queue-select" name="selected_ids[]" value="<?= (int)$item['id'] ?>" form="bulk-image-form">
                        <div class="queue-main">
                            <div class="queue-title">
                                <span class="badge"><?= $legacy ? 'SEM DESTINO' : ais_v_h((string)($channelLabels[$defaultChannel] ?? $defaultChannel)) ?></span>
                                <span>#<?= (int)$item['id'] ?></span>
                                <span><?= ais_v_h((string)($item['product_name'] ?: 'Produto #' . $item['product_id'])) ?></span>
                                <span class="<?= ais_v_h($statusClass) ?>"><?= ais_v_h((string)$item['status']) ?></span>
                            </div>
                            <div class="meta">
                                <span>SKU <?= ais_v_h((string)($item['product_sku'] ?? '')) ?></span>
                                <span>Tipo <?= ais_v_h((string)$item['image_type']) ?></span>
                                <span>Provedor <?= ais_v_h((string)$item['provider_used']) ?></span>
                                <span>Criado em <?= ais_v_h((string)$item['created_at']) ?></span>
                            </div>
                        </div>
                    </div>
                </summary>

                <div class="queue-body">
                    <?php if ($legacy): ?>
                        <div class="alert warn"><strong>Publicacao bloqueada:</strong> esta imagem foi gerada antes da separacao por marketplace. Regere escolhendo um destino antes de aprovar.</div>
                    <?php endif; ?>

                    <div class="profile">
                        <strong>Auditoria visual de <?= $legacy ? 'destino ainda nao definido' : ais_v_h((string)$profile['label']) ?></strong>
                        <?php if (!$legacy): ?>
                            <ul>
                                <?php foreach ((array)($profile['audit_notes'] ?? []) as $note): ?>
                                    <li><?= ais_v_h((string)$note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($w > 0): ?>
                            <div class="<?= $meetsRec ? 'quality-ok' : 'quality-warn' ?>">
                                Arquivo gerado: <?= $w ?>x<?= $h ?>. Alvo recomendado: <?= $recommended ?>x<?= $recommended ?>.
                                <?= $isSquare ? 'Formato quadrado confirmado.' : 'O formato ainda precisa ser revisado.' ?>
                                <?= $meetsRec ? 'Atende o alvo recomendado.' : 'A imagem pode ser tecnicamente valida, mas esta abaixo do alvo recomendado.' ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="compare">
                        <div>
                            <h3>Antes - foto real</h3>
                            <div class="image-box">
                                <?php if (trim((string)$item['original_image']) !== ''): ?>
                                    <img src="<?= ais_v_h((string)$item['original_image']) ?>" alt="Foto real do produto">
                                <?php else: ?>
                                    <p>Produto sem foto original disponivel.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <h3>Depois - imagem em revisao</h3>
                            <div class="image-box">
                                <img src="<?= ais_v_h((string)$item['local_path']) ?>?v=<?= urlencode((string)$item['updated_at']) ?>" alt="Imagem gerada">
                            </div>
                        </div>
                    </div>

                    <?php if ((string)($item['error_message'] ?? '') !== ''): ?>
                        <div class="alert err"><?= ais_v_h((string)$item['error_message']) ?></div>
                    <?php endif; ?>

                    <form method="post" class="edit">
                        <input type="hidden" name="csrf_token" value="<?= ais_v_h($csrf) ?>">
                        <input type="hidden" name="staging_id" value="<?= (int)$item['id'] ?>">
                        <?php if (!$legacy): ?>
                            <input type="hidden" name="channel" value="<?= ais_v_h($defaultChannel) ?>">
                        <?php endif; ?>

                        <h3>Regenerar mantendo produto + regras do canal</h3>
                        <?php if ($legacy): ?>
                            <label>
                                Marketplace obrigatorio antes de regenerar
                                <select name="channel" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($channelLabels as $key => $label): ?>
                                        <option value="<?= ais_v_h($key) ?>"><?= ais_v_h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php else: ?>
                            <p>Destino de regeneracao bloqueado: <strong><?= ais_v_h((string)$profile['label']) ?></strong>.</p>
                        <?php endif; ?>

                        <label>
                            Instrucao adicional de cena
                            <textarea name="prompt_used" rows="5" required><?= ais_v_h((string)$item['prompt_used']) ?></textarea>
                        </label>

                        <label>
                            Motor
                            <select name="regeneration_engine">
                                <option value="openai">OpenAI - edicao da foto real</option>
                                <option value="google">Gemini - edicao da foto real</option>
                                <option value="claude">Claude otimiza cena + OpenAI edita</option>
                            </select>
                        </label>

                        <div class="actions">
                            <button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar com politica do marketplace</button>
                        </div>

                        <h3>Publicacao real</h3>
                        <?php if ($legacy): ?>
                            <div class="alert warn">Indisponivel ate regenerar para um marketplace especifico.</div>
                        <?php else: ?>
                            <p>Destino bloqueado no staging: <strong><?= ais_v_h((string)$profile['label']) ?></strong>. Nenhum outro marketplace sera chamado.</p>
                            <?php $reg = $endpointRegistry[$defaultChannel] ?? null; if (is_array($reg)): ?>
                                <details class="profile endpoints">
                                    <summary>Endpoints de imagem e read-back</summary>
                                    <?php foreach (array_merge($reg['images'], $reg['readback']) as $endpoint): ?>
                                        <code><?= ais_v_h((string)$endpoint) ?></code>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                            <label class="confirm">
                                <input type="checkbox" name="confirm_channel" value="<?= ais_v_h($defaultChannel) ?>" required>
                                Confirmo atualizar somente <strong><?= ais_v_h((string)$profile['label']) ?></strong>.
                            </label>
                            <div class="actions">
                                <button class="publish" name="action" value="publish">Aprovar e publicar somente neste marketplace</button>
                                <button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
</main>

<script>
(() => {
    const checkboxes = Array.from(document.querySelectorAll('.queue-select'));
    const counter = document.getElementById('bulk-selected-count');
    const details = Array.from(document.querySelectorAll('[data-image-item]'));

    function updateState() {
        let count = 0;
        checkboxes.forEach((input) => {
            input.closest('.queue-item')?.classList.toggle('is-selected', input.checked);
            if (input.checked) {
                count += 1;
            }
        });
        if (counter) {
            counter.textContent = String(count);
        }
    }

    checkboxes.forEach((input) => {
        input.addEventListener('click', (event) => event.stopPropagation());
        input.addEventListener('change', updateState);
    });

    document.getElementById('select-all-queue')?.addEventListener('click', () => {
        checkboxes.forEach((input) => {
            input.checked = true;
        });
        updateState();
    });

    document.getElementById('clear-all-queue')?.addEventListener('click', () => {
        checkboxes.forEach((input) => {
            input.checked = false;
        });
        updateState();
    });

    document.getElementById('expand-all-queue')?.addEventListener('click', () => {
        details.forEach((detail) => {
            detail.open = true;
        });
    });

    document.getElementById('collapse-all-queue')?.addEventListener('click', () => {
        details.forEach((detail) => {
            detail.open = false;
        });
    });

    updateState();
})();
</script>
</body>
</html>
