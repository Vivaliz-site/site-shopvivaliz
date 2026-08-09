<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
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
                $engine = ai_studio_normalize_provider((string)($_POST['regeneration_engine'] ?? 'openai'));
                if (!in_array($engine, ['openai', 'google', 'claude'], true)) throw new RuntimeException('Motor de regeneracao invalido.');

                $targetChannel = ais_intended_channel($row);
                if ($targetChannel === '') $targetChannel = strtolower(trim((string)($_POST['channel'] ?? '')));
                if (!isset($channelProfiles[$targetChannel])) throw new RuntimeException('Marketplace de destino invalido.');

                $imageType = strtolower(trim((string)$row['image_type']));
                $guardedPrompts = ai_studio_default_prompts($product['name'], $targetChannel, $product);
                if (!isset($guardedPrompts[$imageType])) throw new RuntimeException('Tipo de imagem invalido para regeneracao.');
                $fidelityGuard = $guardedPrompts[$imageType];
                $imageEngine = ai_studio_resolve_image_engine($engine);

                $basePath = ai_studio_resolve_base_image($product['image_ref'], dirname(__DIR__, 2), $productId);
                $isTemp = str_starts_with($basePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
                $filename = ai_studio_unique_filename($productId, $imageType);
                $destination = AI_STUDIO_STORAGE_DIR . $filename;
                $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;
                $providerUsed = $imageEngine;
                $prompt = $fidelityGuard . ' Additional scene guidance from the administrator: ' . $adminInstruction;

                if ($engine === 'claude' && ai_studio_provider_has_key('claude')) {
                    $claudePrompts = (new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL))->optimizePrompts(
                        $product['name'],
                        $product['description'] . "\nInstrucao adicional do administrador: " . $adminInstruction
                    );
                    $claudeScene = trim((string)($claudePrompts[$imageType] ?? ''));
                    if ($claudeScene !== '') $prompt = $fidelityGuard . ' Additional scene guidance optimized by Claude: ' . $claudeScene;
                    $providerUsed = 'claude_optimized';
                }

                if ($imageEngine === 'google') {
                    (new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, AI_STUDIO_GOOGLE_IMAGEN_MODEL))->editImageToFile($prompt, $basePath, $destination);
                } else {
                    (new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, AI_STUDIO_OPENAI_IMAGE_MODEL))->editImageToFile($prompt, $basePath, $destination);
                }

                $profile = ai_studio_channel_profile($targetChannel);
                ai_studio_validate_image_file($destination, max(1000, (int)($profile['minimum_side'] ?? 1000)));
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
                // Item antigo foi gerado antes de existir politica visual por
                // marketplace. Publica-lo agora permitiria aprovar uma imagem
                // que nao passou pelas regras do destino. Obriga regeneracao.
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

$stmt = $db->query(
    "SELECT s.*, p.name product_name, p.sku product_sku, p.image_url original_image FROM product_images_staging s "
    . 'LEFT JOIN products p ON p.id = s.product_id '
    . "WHERE s.status IN ('pending','publication_failed') ORDER BY s.created_at DESC LIMIT 100"
);
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$csrf = sv_csrf_token('ai-image-validation');
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Validar e publicar imagens</title><link rel="stylesheet" href="/css/style.css"><style>
*{box-sizing:border-box}body{background:#f5f6f8}.wrap{max-width:1320px;margin:24px auto;padding:0 16px;font-family:system-ui,-apple-system,sans-serif}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.alert{padding:12px 16px;border-radius:8px;margin:14px 0}.ok{background:#e8f5e9;color:#175b28}.err{background:#fdecea;color:#7b1717}.warn{background:#fff8e1;color:#6b5300}.grid{display:grid;gap:18px}.card{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:16px;min-width:0}.compare{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.compare>*{min-width:0}@media(max-width:800px){.compare{grid-template-columns:minmax(0,1fr)}.wrap{padding:0 10px;margin-top:12px}}.image-box{background:#f4f5f7;border-radius:8px;padding:8px;text-align:center}.image-box img{width:100%;max-height:440px;object-fit:contain}.meta{font-size:13px;color:#606770;margin:5px 0;overflow-wrap:anywhere}.status,.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#e8f4fd;font-size:12px;font-weight:700}.edit label{display:grid;gap:5px;margin:10px 0}.edit textarea,.edit select{width:100%;padding:9px;border:1px solid #c7ccd2;border-radius:6px;font:inherit;font-size:16px}.actions{display:flex;gap:10px;flex-wrap:wrap}.actions button{border:0;border-radius:6px;padding:10px 14px;font-weight:700;cursor:pointer}.publish{background:#1a7f37;color:#fff}.regenerate{background:#1769aa;color:#fff}.reject{background:#c62828;color:#fff}.confirm{display:block;background:#fff8e1;padding:10px;border-radius:6px;margin:10px 0}.endpoints code{display:block;margin:4px 0;white-space:normal;overflow-wrap:anywhere}.note,.profile{font-size:13px;background:#eef6ff;border-left:4px solid #1769aa;padding:10px;margin:10px 0}.profile ul{margin:6px 0;padding-left:20px}.quality-ok{color:#166534}.quality-warn{color:#92400e}
</style></head><body><main class="wrap"><div><a href="/admin/menu-completo.php">← Voltar ao Admin</a></div><div class="top"><h1>Imagens — auditoria por marketplace</h1><a href="/admin/ai-image-studio/admin_dashboard.php">Dashboard de geracao</a></div>
<div class="note">O marketplace escolhido na geracao e imutavel durante a aprovacao. Para outro canal, gere/regere uma versao especifica com as regras daquele marketplace. Imagens legadas sem destino persistido nao podem ser publicadas sem regeneracao.</div>
<?php if($flashMessage):?><div class="alert ok"><?=ais_v_h($flashMessage)?></div><?php endif;?><?php if($flashError):?><div class="alert err"><?=ais_v_h($flashError)?></div><?php endif;?><?php if($items===[]):?><div class="alert ok">Nenhuma imagem aguardando decisao.</div><?php endif;?>
<section class="grid"><?php foreach($items as $item):$intended=ais_intended_channel($item);$legacy=$intended==='';$defaultChannel=$legacy?'site':$intended;$profile=ai_studio_channel_profile($defaultChannel);$generatedFile=ais_staging_file((string)$item['local_path']);$imgInfo=is_string($generatedFile)&&is_file($generatedFile)?@getimagesize($generatedFile):false;$w=is_array($imgInfo)?(int)$imgInfo[0]:0;$h=is_array($imgInfo)?(int)$imgInfo[1]:0;$recommended=(int)($profile['recommended_side']??1000);$meetsRec=$w>=$recommended&&$h>=$recommended;?>
<article class="card"><div class="top"><h2><?=ais_v_h((string)($item['product_name']?:'Produto #'.$item['product_id']))?></h2><span class="badge"><?=$legacy?'SEM DESTINO':ais_v_h((string)($channelLabels[$defaultChannel]??$defaultChannel))?></span></div><div class="meta">SKU <?=ais_v_h((string)($item['product_sku']??''))?> · tipo <?=ais_v_h((string)$item['image_type'])?> · provedor <?=ais_v_h((string)$item['provider_used'])?> · <span class="status"><?=ais_v_h((string)$item['status'])?></span></div>
<?php if($legacy):?><div class="alert warn"><strong>Publicacao bloqueada:</strong> esta imagem foi gerada antes da separacao por marketplace. Escolha um destino abaixo e use <strong>Regenerar</strong>; somente a nova versao podera ser autorizada.</div><?php endif;?>
<div class="profile"><strong>Auditoria visual de <?=$legacy?'destino ainda nao definido':ais_v_h((string)$profile['label'])?></strong><?php if(!$legacy):?><ul><?php foreach((array)($profile['audit_notes']??[]) as $note):?><li><?=ais_v_h((string)$note)?></li><?php endforeach;?></ul><?php endif;?><?php if($w>0):?><div class="<?=$meetsRec?'quality-ok':'quality-warn'?>">Arquivo gerado: <?=$w?>x<?=$h?>. Alvo recomendado: <?=$recommended?>x<?=$recommended?>. <?=$meetsRec?'Atende o alvo recomendado.':'Publicacao pode ser tecnicamente valida, mas esta abaixo do alvo recomendado e deve ser revisada.'?></div><?php endif;?></div>
<div class="compare"><div><h3>Antes — foto real</h3><div class="image-box"><?php if(trim((string)$item['original_image'])!==''):?><img src="<?=ais_v_h((string)$item['original_image'])?>" alt="Foto real do produto"><?php else:?><p>Produto sem foto original disponivel.</p><?php endif;?></div></div><div><h3>Depois — imagem em revisao</h3><div class="image-box"><img src="<?=ais_v_h((string)$item['local_path'])?>?v=<?=urlencode((string)$item['updated_at'])?>" alt="Imagem gerada"></div></div></div>
<?php if((string)($item['error_message']??'')!==''):?><div class="alert err"><?=ais_v_h((string)$item['error_message'])?></div><?php endif;?>
<form method="post" class="edit"><input type="hidden" name="csrf_token" value="<?=ais_v_h($csrf)?>"><input type="hidden" name="staging_id" value="<?=(int)$item['id']?>"><?php if(!$legacy):?><input type="hidden" name="channel" value="<?=ais_v_h($defaultChannel)?>"><?php endif;?><h3>Regenerar mantendo produto + regras do canal</h3><?php if($legacy):?><label>Marketplace obrigatorio antes de regenerar<select name="channel" required><option value="">Selecione...</option><?php foreach($channelLabels as $key=>$label):?><option value="<?=ais_v_h($key)?>"><?=ais_v_h($label)?></option><?php endforeach;?></select></label><?php else:?><p>Destino de regeneracao bloqueado: <strong><?=ais_v_h((string)$profile['label'])?></strong>.</p><?php endif;?><label>Instrucao adicional de cena<textarea name="prompt_used" rows="5" required><?=ais_v_h((string)$item['prompt_used'])?></textarea></label><label>Motor<select name="regeneration_engine"><option value="openai">OpenAI — edicao da foto real</option><option value="google">Gemini — edicao da foto real</option><option value="claude">Claude otimiza cena + OpenAI edita</option></select></label><div class="actions"><button class="regenerate" name="action" value="regenerate" formnovalidate>Regenerar com politica do marketplace</button></div>
<h3>Publicacao real</h3><?php if($legacy):?><div class="alert warn">Indisponivel ate regenerar para um marketplace especifico.</div><?php else:?><p>Destino bloqueado no staging: <strong><?=ais_v_h((string)$profile['label'])?></strong>. Nenhum outro marketplace sera chamado.</p><?php $reg=$endpointRegistry[$defaultChannel]??null;if(is_array($reg)):?><details class="endpoints"><summary>Endpoints de imagem e read-back</summary><?php foreach(array_merge($reg['images'],$reg['readback']) as $endpoint):?><code><?=ais_v_h((string)$endpoint)?></code><?php endforeach;?></details><?php endif;?><label class="confirm"><input type="checkbox" name="confirm_channel" value="<?=ais_v_h($defaultChannel)?>" required> Confirmo atualizar somente <strong><?=ais_v_h((string)$profile['label'])?></strong>.</label><div class="actions"><button class="publish" name="action" value="publish">Aprovar e publicar somente neste marketplace</button><?php endif;?><button class="reject" name="action" value="reject" formnovalidate>Rejeitar sem publicar</button><?php if(!$legacy):?></div><?php endif;?></form></article><?php endforeach;?></section></main></body></html>
