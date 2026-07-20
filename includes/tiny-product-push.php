<?php
declare(strict_types=1);

/**
 * Push de atualizacoes de produto (feitas no admin do site) de volta pro Tiny ERP.
 * Schema real confirmado em docs/TINY-ERP-API-V3.md (PUT /produtos/{id}) --
 * 'descricao' e 'sku' sao obrigatorios no payload mesmo quando nao mudam (a Tiny
 * exige os dois em toda atualizacao). Campos que exigem lookup de ID no Tiny que
 * nao temos localmente (ex: marca.id, categoria.id) sao deliberadamente NAO
 * enviados aqui para evitar mandar ID errado/zerado -- edicao de marca/categoria
 * continua sendo feita no proprio Tiny.
 */

require_once __DIR__ . '/tiny-order-push.php';

function svtpp_build_payload(array $product): array
{
    $payload = [
        'descricao' => (string)($product['name'] ?? ''),
        'sku'       => (string)($product['sku'] ?? ''),
    ];

    if (($product['ncm'] ?? '') !== '') {
        $payload['ncm'] = (string)$product['ncm'];
    }
    if (($product['gtin'] ?? '') !== '') {
        $payload['gtin'] = (string)$product['gtin'];
    }
    if (($product['unit'] ?? '') !== '') {
        $payload['unidade'] = (string)$product['unit'];
    }
    if (($product['notes'] ?? '') !== '') {
        $payload['observacoes'] = (string)$product['notes'];
    }

    $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
    $precos = [];
    if (isset($product['price'])) {
        $precos['preco'] = (float)$product['price'];
    }
    if (($prices['promotional_price'] ?? 0) > 0) {
        $precos['precoPromocional'] = (float)$prices['promotional_price'];
    }
    if (($prices['cost_price'] ?? 0) > 0) {
        $precos['precoCusto'] = (float)$prices['cost_price'];
    }
    if ($precos !== []) {
        $payload['precos'] = $precos;
    }

    $dim = is_array($product['dimensions'] ?? null) ? $product['dimensions'] : [];
    $dimensoes = [];
    if (($dim['width'] ?? 0) > 0) $dimensoes['largura'] = (float)$dim['width'];
    if (($dim['height'] ?? 0) > 0) $dimensoes['altura'] = (float)$dim['height'];
    if (($dim['length'] ?? 0) > 0) $dimensoes['comprimento'] = (float)$dim['length'];
    if (($dim['net_weight'] ?? 0) > 0) $dimensoes['pesoLiquido'] = (float)$dim['net_weight'];
    if (($dim['gross_weight'] ?? 0) > 0) $dimensoes['pesoBruto'] = (float)$dim['gross_weight'];
    if ($dimensoes !== []) {
        $payload['dimensoes'] = $dimensoes;
    }

    $seo = [];
    if (($product['seo_title'] ?? '') !== '') $seo['titulo'] = (string)$product['seo_title'];
    if (($product['seo_description'] ?? '') !== '') $seo['descricao'] = (string)$product['seo_description'];
    if (!empty($product['keywords']) && is_array($product['keywords'])) $seo['keywords'] = array_values($product['keywords']);
    if (($product['slug'] ?? '') !== '') $seo['slug'] = (string)$product['slug'];
    if ($seo !== []) {
        $payload['seo'] = $seo;
    }

    return $payload;
}

/**
 * @return array{ok: bool, status: int, error: string}
 */
function svtpp_push_product_update(int $tinyProductId, array $product): array
{
    if (!svtop_tiny_credentials_configured()) {
        return ['ok' => false, 'status' => 0, 'error' => 'credenciais_tiny_nao_configuradas'];
    }
    if ($tinyProductId <= 0) {
        return ['ok' => false, 'status' => 0, 'error' => 'id_produto_invalido'];
    }
    if (trim((string)($product['sku'] ?? '')) === '' || trim((string)($product['name'] ?? '')) === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'sku_ou_nome_ausente'];
    }

    $token = svtop_tiny_get_token();
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'falha_ao_obter_token'];
    }

    $payload = svtpp_build_payload($product);
    $res = svtop_tiny_request('PUT', "/produtos/{$tinyProductId}", $token, $payload);

    if ($res['status'] === 204 || $res['status'] === 200) {
        return ['ok' => true, 'status' => $res['status'], 'error' => ''];
    }

    $msg = is_array($res['json']) ? (string)($res['json']['mensagem'] ?? '') : '';
    return ['ok' => false, 'status' => $res['status'], 'error' => $msg !== '' ? $msg : substr($res['body'], 0, 300)];
}
