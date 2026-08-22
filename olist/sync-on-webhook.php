<?php
/**
 * Sincronização AO VIVO via Webhook
 * Chamado automaticamente quando ERP notifica mudanças
 */

declare(strict_types=1);

// API v3 is mandatory for ERP/site mirroring. Do not use API v2, scraping,
// local CSVs or legacy snapshots as product registration sources.

$root = dirname(__DIR__);
$env_file = $root . '/.env';
$token = '';

// Carregar token
foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
        $token = explode('=', $line, 2)[1] ?? '';
        break;
    }
}

$token = trim($token);

if (!$token) {
    error_log("[webhook-sync] Token não encontrado");
    exit(1);
}


function svow_fetch_product_detail(string $id, string $token): ?array
{
    if ($id === '') return null;
    $url = 'https://api.tiny.com.br/public-api/v3/produtos/' . rawurlencode($id);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpStatus === 429 && $attempt < 3) {
            sleep(2 * $attempt);
            continue;
        }
        if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
            error_log("[webhook-sync] detalhe produto {$id} falhou: HTTP {$httpStatus} {$curlError}");
            return null;
        }
        $json = json_decode((string)$response, true);
        return is_array($json) ? $json : null;
    }
    return null;
}

function svow_collect_detail_images(array $detail): array
{
    $images = [];
    $push = static function (mixed $value) use (&$images): void {
        if (is_array($value)) {
            foreach (['url', 'link', 'src', 'imagem', 'image_url'] as $key) {
                if (!empty($value[$key]) && is_scalar($value[$key])) {
                    $url = trim((string)$value[$key]);
                    if ($url !== '' && preg_match('~^https?://~i', $url) && !in_array($url, $images, true)) $images[] = $url;
                }
            }
            return;
        }
        if (is_scalar($value)) {
            $url = trim((string)$value);
            if ($url !== '' && preg_match('~^https?://~i', $url) && !in_array($url, $images, true)) $images[] = $url;
        }
    };
    foreach (['anexos', 'imagens', 'fotos', 'photos', 'attachments'] as $field) {
        if (!empty($detail[$field]) && is_array($detail[$field])) {
            foreach ($detail[$field] as $entry) $push($entry);
        }
    }
    foreach (['imagemURL', 'imagem', 'foto', 'image_url', 'primary_image_url', 'imagem_principal_url'] as $field) {
        if (!empty($detail[$field])) $push($detail[$field]);
    }
    return array_slice($images, 0, 12);
}


function svow_detail_video_url(array $detail): string
{
    $candidates = [
        $detail['video_url'] ?? '',
        $detail['urlVideo'] ?? '',
        $detail['linkVideo'] ?? '',
    ];
    if (is_array($detail['seo'] ?? null)) {
        $candidates[] = $detail['seo']['linkVideo'] ?? '';
        $candidates[] = $detail['seo']['urlVideo'] ?? '';
        $candidates[] = $detail['seo']['video_url'] ?? '';
    }
    foreach ($candidates as $candidate) {
        $url = trim((string)$candidate);
        if ($url !== '' && preg_match('~^https?://~i', $url) === 1) return $url;
    }
    return '';
}

function svow_enrich_item_with_detail(array $item, string $token): array
{
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') return $item;
    $detail = svow_fetch_product_detail($id, $token);
    if (!is_array($detail)) return $item;

    $images = svow_collect_detail_images($detail);
    if ($images !== []) {
        $item['imagens'] = $images;
        $item['images'] = $images;
        $item['imagem_principal_url'] = $images[0];
        $item['primary_image_url'] = $images[0];
        $item['image_url'] = $images[0];
        $item['images_count'] = count($images);
    }

    $videoUrl = svow_detail_video_url($detail);
    if ($videoUrl !== '') {
        $item['video_url'] = $videoUrl;
        $item['linkVideo'] = $videoUrl;
    }

    // Preserve id/sku from the listing, but copy non-commercial descriptive fields
    // and dimensions/SEO that are absent from GET /produtos list response.
    foreach (['descricaoComplementar','categoria','marca','dimensoes','seo','ncm','gtin','garantia','observacoes','precos','estoque','anexos'] as $field) {
        if (!array_key_exists($field, $item) && array_key_exists($field, $detail) && $detail[$field] !== null && $detail[$field] !== '' && $detail[$field] !== []) {
            $item[$field] = $detail[$field];
        }
    }
    return $item;
}

// ============================================================
// Buscar produtos ATIVOS via API V3
// ============================================================

$all_products = [];
$offset = 0;
$limit = 100;

while (true) {
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit={$limit}&offset={$offset}";

    // file_get_contents()/stream_context_create() era rejeitado com 401 pela
    // Cloudflare do Tiny mesmo com token e permissoes corretas (confirmado em
    // 2026-08-11: a mesma chamada via cURL funciona normalmente). Usar cURL
    // aqui evita esse bloqueio silencioso.
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
        error_log("[webhook-sync] Falha ao buscar produtos: HTTP {$httpStatus} {$curlError}");
        break;
    }

    $data = json_decode($response, true);

    if (!isset($data['itens']) || empty($data['itens'])) {
        break;
    }

    // Filtrar apenas ATIVOS (situacao == 'A'). NAO preencher estoque_disponivel
    // aqui: o campo estoque.quantidade da listagem em lote (GET /produtos) nao
    // e confiavel (fica zerado/desatualizado, especialmente em kits) -- a
    // fonte correta e GET /estoque/{id} (campo disponivel), buscada depois por
    // olist/fetch-estoque-v3.php, que só preenche quando a chave ainda não
    // existe (ver docs/TINY-ERP-API-V3.md, secao "GET /produtos/{id} vs GET
    // /estoque/{id}"). Se setarmos aqui, o enriquecimento nunca roda.
    foreach ($data['itens'] as $item) {
        if ($item['situacao'] === 'A') {
            $hasImage = false;
            foreach (['imagem_principal_url', 'primary_image_url', 'image_url', 'imagem', 'imagens', 'images', 'anexos'] as $imageField) {
                if (!empty($item[$imageField])) { $hasImage = true; break; }
            }
            // A listagem /produtos frequentemente omite anexos/imagens. Quando
            // isso acontecer, busca /produtos/{id}, que é onde o ERP/Tiny expõe
            // as imagens reais do cadastro.
            if (!$hasImage) {
                $item = svow_enrich_item_with_detail($item, $token);
                usleep(1100000);
            }
            $all_products[] = $item;
        }
    }

    if (count($data['itens']) < $limit) {
        break;
    }

    $offset += $limit;
    usleep(500000);
}

// ============================================================
// Salvar em JSON
// ============================================================

if ($all_products === []) {
    // Nao sobrescreve um cache bom anterior com uma lista vazia quando a
    // busca falhou de verdade (ex: token/rede) -- so grava se a API
    // realmente respondeu 0 produtos ativos (situacao improvavel, mas nao
    // impossivel, entao nao trata como erro fatal).
    error_log("[webhook-sync] Nenhum produto ativo retornado; cache anterior preservado");
    exit(1);
}

$output = [
    'total' => count($all_products),
    'timestamp' => date('Y-m-d H:i:s'),
    'itens' => $all_products
];

$output_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($output_file), 0755, true);

file_put_contents($output_file, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

error_log("[webhook-sync] Sincronizados " . count($all_products) . " produtos ativos");
