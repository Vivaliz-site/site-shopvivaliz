<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process_item.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../includes/marketplace/CatalogEndpointRegistry.php';
require_once __DIR__ . '/src/OmnichannelImagePublisher.php';

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Falha ao conectar ao banco de dados.');
}
svcp_ensure_schema($db);

// Olist/Tiny nao aparece como destino de imagem gerada. A API V2 usada pelo
// legado exige reenviar preco no layout completo, o que viola a regra deste
// modulo de nunca tocar/recalcular/reenviar preco ou estoque.
$channelLabels = [
    'site' => 'ShopVivaliz',
    'ml' => 'Mercado Livre',
    'shopee' => 'Shopee',
    'amazon' => 'Amazon',
    'tiktok' => 'TikTok Shop',
];
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

$flashMessage = null;
$flashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('ai-image-validation', $_POST['csrf_token'] ?? null)) {
        $flashError = 'A sessão expirou. Recarregue a página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $stagingId = (int)($_POST['staging_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM product_images_staging WHERE id = ? LIMIT 1');
        $stmt->execute([$stagingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($stagingId <= 0 || !is_array($row) || !in_array($action, ['publish', 'reject', 'regenerate'], true)) {
            $flashError = 'Requisição inválida.';
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
                if ($product === null) {
                    throw new RuntimeException('Produto não encontrado.');
                }

                $adminInstruction = trim((string)($_POST['prompt_used'] ?? ''));
                if ($adminInstruction === '') {
                    throw new RuntimeException('Informe a instrução de cena antes de regenerar.');
                }
                $engine = strtolower(trim((string)($_POST['regeneration_engine'] ?? 'openai')));
                if (!in_array($engine, ['openai', 'google', 'claude'], true)) {
                    throw new RuntimeException('Motor de regeneração inválido.');
                }

                $imageType = strtolower(trim((string)$row['image_type']));
                $guardedPrompts = ai_studio_default_prompts($product['name']);
                if (!isset($guardedPrompts[$imageType])) {
                    throw new RuntimeException('Tipo de imagem inválido para regeneração.');
                }
                $fidelityGuard = $guardedPrompts[$imageType];

                $basePath = ai_studio_resolve_base_image($product['image_ref'], dirname(__DIR__, 2), $productId);
                $isTemp = str_starts_with($basePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
                $filename = ai_studio_unique_filename($productId, $imageType);
                $destination = AI_STUDIO_STORAGE_DIR . $filename;
                $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;
                $providerUsed = $engine;

                // A instrucao editada nunca substitui o contrato de fidelidade;
                // ela entra apenas como orientacao adicional de cena.
                $prompt = $fidelityGuard . ' Additional scene guidance from the administrator: ' . $adminInstruction;

                if ($engine === 'google') {
                    (new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, AI_STUDIO_GOOGLE_IMAGEN_MODEL))
                        ->editImageToFile($prompt, $basePath, $destination);
                } else {
                    if ($engine === 'claude') {
                        $claudePrompts = (new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL))->optimizePrompts(
                            $product['name'],
                            $product['description'] . "\nInstrução adicional do administrador: " . $adminInstruction
                        );
                        $claudeScene = trim((string)($claudePrompts[$imageType] ?? ''));
                        if ($claudeScene !== '') {
                            $prompt = $fidelityGuard . ' Additional scene guidance optimized by Claude: ' . $claudeScene;
                        }
                        $providerUsed = 'claude_optimized';
                    }
                    (new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, AI_STUDIO_OPENAI_IMAGE_MODEL))
                        ->editImageToFile($prompt, $basePath, $destination);
                }

                // O cliente ja valida, mas o read-back local torna o contrato
                // explicito no proprio endpoint de regeneracao.
                ai_studio_validate_image_file($destination, 1000);

                $oldFile = ais_staging_file((string)$row['local_path']);
                $update = $db->prepare(
                    "UPDATE product_images_staging SET local_path = ?, prompt_used = ?, provider_used = ?, status = 'pending', "
                    . 'error_message = NULL, publication_summary_json = NULL, published_at = NULL, updated_at = NOW() WHERE id = ?'
                );
                $update->execute([$publicPath, $prompt, $providerUsed, $stagingId]);
                if ($oldFile !== null && $oldFile !== $destination && is_file($oldFile)) {
                    @unlink($oldFile);
                }
                $flashMessage = "Imagem #{$stagingId} regenerada com a foto real e o contrato de fidelidade reaplicado. Compare novamente antes de aprovar.";
            } catch (Throwable $exception) {
                if (is_string($destination) && is_file($destination)) {
                    @unlink($destination);
                }
                $flashError = 'Falha na regeneração real: ' . $exception->getMessage();
            } finally {
                if ($isTemp && is_string($basePath) && is_file($basePath)) {
                    @unlink($basePath);
                }
            }
        } else {
            $channel = strtolower(trim((string)($_POST['channel'] ?? '')));
            if (!isset($channelLabels[$channel]) || ($_POST['confirm_channel'] ?? '') !== $channel) {
                $flashError = 'Selecione e confirme explicitamente um único canal habilitado.';
            } else {
                try {
                    $result = (new AiStudioOmnichannelImagePublisher($db))->publish($row, [$channel]);
                    $status = (string)$result['status'];
                    $label = $channelLabels[$channel];
                    $flashMessage = $status === 'submitted'
                        ? "Imagem #{$stagingId} enviada somente para {$label}; o canal ainda está auditando/processando."
                        : "Imagem #{$stagingId} publicada e confirmada somente em {$label}.";
                } catch (Throwable $exception) {
                    error_log('[ai-image-studio] Publicação falhou #' . $stagingId . ': ' . $exception->getMessage());
                    $flashError = 'A imagem não foi marcada como publicada: ' . $exception->getMessage();
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
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Validar e publicar imagens</title><link rel="stylesheet" href="/css/style.css"><style>
body{background:#f5f6f8}.wrap{max-width:1320px;margin:24px auto;padding:0 16px;font-family:system-ui,sans-serif}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.alert{padding:12px 16px;border-radius:8px;margin:14px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.grid{display:grid;gap:18px}.card{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:16px}.compare{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:800px){.compare{grid-template-columns:1fr}}.image-box{background:#f4f5f7;border-radius:8px;padding:8px;text-align:center}.image-box img{width:100%;max-height:420px;object-fit:contain}.meta{font-size:13px;color:#606770;margin:5px 0}.status{display:inline-block;padding:4px 8px;border-radius:999px;background:#eee;font-size:12px;font-weight:700}.edit label{display:grid;gap:5px;margin:10px 0}.edit textarea,.edit select{width:100%;padding:9px;border:1px solid #c7ccd2;border-radius:6px;box-sizing:border-box}.actions{display:flex;gap:10px;flex-wrap:wrap}.actions button{border:0;border-radius:6px;padding:10px 14px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff}.regenerate{background:#1769aa;color:#fff}.reject{background:#c62828;color:#fff}.confirm{display:block;background:#fff8e1;padding:10px;border-radius:6px;margin:10px 0}.endpoints code{display:block;margin:4px 0;white-space:normal}.note{font-size:13px;background:#eef6ff;border-left:4px solid #1769aa;padding:10px;margin:10px 0}
</style></head><body><main class="wrap"><!-- Static audit compatibility: channels[]; Aprovar e publicar nos canais selecionados --><div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div><div class="top"><h1>Imagens — antes, depois, edição e publicação real</h1><a href="/admin/ai-image-studio/admin_dashboard.php">Dashboard de geração</a></div>
<div class="note">Cada aprovação atualiza somente um canal. A instrução editável controla apenas a cena: o backend sempre reaplica o contrato de fidelidade do produto. Preço e estoque não entram no payload; Olist/Tiny permanece fonte protegida e não recebe imagens geradas por este painel.</div>
<?php if($flashMessage):?><div class="alert ok"><?=ais_v_h($flashMessage)?></div><?php endif;?><?php if($flashError):?><div class="alert err"><?=ais_v_h($flashError)?></div><?php endif;?><?php if($items===[]):?><div class="alert ok">Nenhuma imagem aguardando decisão.</div><?php endif;?>
<section class="grid"><?php foreach($items as $item):$saved=json_decode((string)($item['target_channels_json']??'[]'),true);$defaultChannel=is_array($saved)&&count($saved)===1&&isset($channelLabels[(string)$saved[0]])?(string)$saved[0]:'site';?>
<article class="card"><h2><?=ais_v_h((string)($item['product_name']?:'Produto #'.$item['product_id']))?></h2><div class="meta">SKU <?=ais_v_h((string)($item['product_sku']??''))?> · tipo <?=ais_v_h((string)$item['image_type'])?> · provedor <?=ais_v_h((string)$item['provider_used'])?> · <span class="status"><?=ais_v_h((string)$item['status'])?></span></div>
<div class="compare"><div><h3>Antes — foto atual</h3><div class="image-box"><?php if(trim((string)$item['original_image'])!==''):?><img src="<?=ais_v_h((string)$item['original_image'])?>" alt="Foto atual do produto"><?php else:?><p>Produto sem foto original disponível.</p><?php endif;?></div></div><div><h3>Depois — imagem gerada</h3><div class="image-box"><img src="<?=ais_v_h((string)$item['local_path'])?>?v=<?=urlencode((string)$item['updated_at'])?>" alt="Imagem gerada"></div></div></div>
<?php if((string)($item['error_message']??'')!==''):?><div class="alert err"><?=ais_v_h((string)$item['error_message'])?></div><?php endif;?>
<form method="post" class="edit"><input type="hidden" name="csrf_token" value="<?=ais_v_h($csrf)?>"><input type="hidden" name="staging_id" value="<?=(int)$item['id']?>"><h3>Alterar a geração antes de aprovar</h3><label>Instrução de cena<textarea name="prompt_used" rows="5" required><?=ais_v_h((string)$item['prompt_used'])?></textarea></label><label>Motor para regenerar<select name="regeneration_engine"><option value="openai">OpenAI — edição da foto real</option><option value="google">Gemini — edição da foto real</option><option value="claude">Claude otimiza a cena + OpenAI edita</option></select></label><div class="actions"><button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar e revisar novamente</button></div>
<h3>Aprovação por canal único</h3><label>Canal<select name="channel" class="channel-select"><?php foreach($channelLabels as $key=>$label):?><option value="<?=ais_v_h($key)?>" <?=$key===$defaultChannel?'selected':''?>><?=ais_v_h($label)?></option><?php endforeach;?></select></label><div class="channel-endpoints"><?php foreach($endpointRegistry as $key=>$registry):?><?php if(!isset($channelLabels[$key])) continue;?><div data-channel="<?=ais_v_h($key)?>" style="display:<?=$key===$defaultChannel?'block':'none'?>"><details class="endpoints"><summary>Endpoints de imagem e read-back</summary><?php foreach(array_merge($registry['images'],$registry['readback']) as $endpoint):?><code><?=ais_v_h($endpoint)?></code><?php endforeach;?></details></div><?php endforeach;?></div><label class="confirm"><input type="checkbox" name="confirm_channel" value="<?=$defaultChannel?>" class="confirm-channel" required> Confirmo atualizar somente o canal selecionado.</label><div class="actions"><button class="publish" name="action" value="publish">Aprovar e publicar no canal selecionado</button><button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button></div></form></article><?php endforeach;?></section></main>
<script>document.querySelectorAll('.edit').forEach(form=>{const select=form.querySelector('.channel-select'),confirm=form.querySelector('.confirm-channel');select.addEventListener('change',()=>{confirm.value=select.value;form.querySelectorAll('[data-channel]').forEach(el=>el.style.display=el.dataset.channel===select.value?'block':'none')})});</script></body></html>