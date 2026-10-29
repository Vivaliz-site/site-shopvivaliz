<?php
declare(strict_types=1);

/**
 * Site prices come exclusively from the Olist/Tiny "Amazon" price list.
 * This does NOT read Amazon marketplace offers and never writes to the ERP.
 */
const SV_AMAZON_PRICE_LIST_ID = 991;

/** @return array{percent:float, exceptions:array<string,array{price:float,promo:float}>} */
function sveap_parse_table(array $payload): array
{
    if ((int)($payload['id'] ?? 0) !== SV_AMAZON_PRICE_LIST_ID
        || strcasecmp(trim((string)($payload['descricao'] ?? '')), 'Amazon') !== 0
        || !isset($payload['acrescimoDesconto']) || !is_numeric($payload['acrescimoDesconto'])
        || !is_array($payload['excecoes'] ?? null)) {
        throw new RuntimeException('amazon_price_table_invalid');
    }

    $percent = (float)$payload['acrescimoDesconto'];
    if (!is_finite($percent) || $percent <= -100 || $percent > 1000) {
        throw new RuntimeException('amazon_price_table_percent_invalid');
    }
    $exceptions = [];
    foreach ($payload['excecoes'] as $row) {
        if (!is_array($row) || !isset($row['idProduto'], $row['preco'])
            || !ctype_digit((string)$row['idProduto']) || !is_numeric($row['preco'])
            || !is_finite((float)$row['preco']) || (float)$row['preco'] <= 0) {
            throw new RuntimeException('amazon_price_table_exception_invalid');
        }
        $id = (string)$row['idProduto'];
        if (isset($exceptions[$id])) {
            throw new RuntimeException('amazon_price_table_duplicate_exception');
        }
        $promo = $row['precoPromocional'] ?? 0;
        if (!is_numeric($promo) || !is_finite((float)$promo) || (float)$promo < 0) {
            throw new RuntimeException('amazon_price_table_promo_invalid');
        }
        $exceptions[$id] = ['price' => (float)$row['preco'], 'promo' => (float)$promo];
    }
    return ['percent' => $percent, 'exceptions' => $exceptions];
}

/** @return array{percent:float, exceptions:array<string,array{price:float,promo:float}>} */
function sveap_read_table(string $token): array
{
    if (trim($token) === '') throw new RuntimeException('erp_access_token_missing');
    require_once __DIR__ . '/tiny-order-push.php';
    $response = svtop_tiny_get('/listas-precos/' . SV_AMAZON_PRICE_LIST_ID, $token);
    if ((int)($response['status'] ?? 0) !== 200 || !is_array($response['json'] ?? null)) {
        throw new RuntimeException('amazon_price_table_read_failed');
    }
    return sveap_parse_table($response['json']);
}

/**
 * Apply overrides by exact ERP product ID; do not multiply an already
 * overridden price. Retain the original ERP detail for later reconciliation.
 */
function sveap_apply_table(array $product, array $table): array
{
    $base = $product['_erp_base_prices'] ?? $product['precos'] ?? null;
    if (!is_array($base) || !isset($base['preco']) || !is_numeric($base['preco'])) {
        throw new RuntimeException('erp_product_base_price_missing');
    }
    $baseRegular = (float)$base['preco'];
    $basePromo = $base['precoPromocional'] ?? 0;
    if (!is_finite($baseRegular) || $baseRegular < 0
        || !is_numeric($basePromo) || !is_finite((float)$basePromo) || (float)$basePromo < 0) {
        throw new RuntimeException('erp_product_base_price_invalid');
    }
    $id = trim((string)($product['id'] ?? $product['olist_product_id'] ?? ''));
    if ($id === '' || !ctype_digit($id)) throw new RuntimeException('erp_product_id_missing');
    if (!isset($table['percent'], $table['exceptions']) || !is_array($table['exceptions'])) {
        throw new RuntimeException('amazon_price_table_missing');
    }

    $factor = 1.0 + (float)$table['percent'] / 100.0;
    $exception = $table['exceptions'][$id] ?? null;
    $regular = $exception !== null ? $exception['price'] : $baseRegular * $factor;
    $promo = $exception !== null ? $exception['promo'] : (float)$basePromo * $factor;
    $product['_erp_base_prices'] = ['preco' => $baseRegular, 'precoPromocional' => (float)$basePromo];
    $product['precos'] = [
        'preco' => round($regular, 2, PHP_ROUND_HALF_UP),
        'precoPromocional' => round($promo, 2, PHP_ROUND_HALF_UP),
    ];
    $product['sync_source'] = 'tiny_v3';
    $product['_price_source'] = 'olist_tiny_amazon';
    $product['_price_list_id'] = SV_AMAZON_PRICE_LIST_ID;
    return $product;
}
