<?php
declare(strict_types=1);

/**
 * Olist ERP v3 commercial PDV price list is the ONLY source for storefront
 * prices. The product registration price is an input to the PDV percentage
 * rule, never a price to publish directly. All unavailable/ambiguous values
 * fail closed. Never modify ERP commercial records.
 */

function svpdv_decimal(mixed $value, string $error): float
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new RuntimeException($error);
    }
    if (!is_numeric($value) || !is_finite((float)$value)) {
        throw new RuntimeException($error);
    }
    return (float)$value;
}

/** @return array{id:int,descricao:string,acrescimoDesconto:mixed} */
function svpdv_lookup_list(string $token): array
{
    if ($token === '') throw new RuntimeException('pdv_token_missing');
    $matches = [];
    for ($offset = 0; $offset <= 10000; $offset += 100) {
        $response = svtop_tiny_get('/listas-precos?limit=100&offset=' . $offset, $token);
        if ((int)($response['status'] ?? 0) !== 200) throw new RuntimeException('pdv_list_search_failed');
        $json = $response['json'] ?? null;
        if (!is_array($json) || !is_array($json['itens'] ?? null)) {
            throw new RuntimeException('pdv_list_schema_invalid');
        }
        foreach ($json['itens'] as $item) {
            if (!is_array($item)) throw new RuntimeException('pdv_list_row_invalid');
            if (strcasecmp(trim((string)($item['descricao'] ?? '')), 'PDV') !== 0) continue;
            $id = $item['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1) {
                throw new RuntimeException('pdv_list_id_invalid');
            }
            $matches[(string)$id] = [
                'id' => (int)$id,
                'descricao' => (string)$item['descricao'],
                'acrescimoDesconto' => $item['acrescimoDesconto'] ?? null,
            ];
        }
        $total = $json['paginacao']['total'] ?? null;
        if (!is_int($total) || $total < 0) throw new RuntimeException('pdv_list_pagination_missing');
        if ($offset + 100 >= $total) break;
    }
    if (count($matches) !== 1) throw new RuntimeException('pdv_list_not_unique');
    return array_values($matches)[0];
}

/** @return array<string,mixed>|null */
function svpdv_find_exception(mixed $raw, string $productId): ?array
{
    if ($raw === null) return null;
    if (!is_array($raw)) throw new RuntimeException('pdv_exceptions_schema_invalid');
    if ($raw === []) return null;
    $entries = array_is_list($raw) ? $raw : [$raw];
    $matches = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) throw new RuntimeException('pdv_exception_invalid');
        $id = trim((string)($entry['idProduto'] ?? ''));
        if ($id !== '' && $id !== $productId) throw new RuntimeException('pdv_exception_wrong_product');
        if ($id === '') throw new RuntimeException('pdv_exception_identity_missing');
        $matches[] = $entry;
    }
    if (count($matches) > 1) throw new RuntimeException('pdv_exception_ambiguous');
    return $matches[0] ?? null;
}

/**
 * @param array<string,mixed> $erpDetail Verified GET /produtos/{id} response
 * @param array{id:int,descricao:string,acrescimoDesconto:mixed} $list
 * @return array{preco:float,precoPromocional:float,pdv_list_id:int,price_source:string}
 */
function svpdv_product_prices(string $token, string $productId, array $erpDetail, array $list): array
{
    if ($token === '' || !ctype_digit($productId) || (int)$productId < 1) {
        throw new RuntimeException('pdv_product_id_missing');
    }
    $detailId = trim((string)($erpDetail['id'] ?? ''));
    if ($detailId !== $productId) throw new RuntimeException('pdv_product_id_mismatch');
    $listId = (int)($list['id'] ?? 0);
    if ($listId < 1 || strcasecmp(trim((string)($list['descricao'] ?? '')), 'PDV') !== 0) {
        throw new RuntimeException('pdv_list_not_verified');
    }
    $response = svtop_tiny_get('/listas-precos/' . $listId . '?idProduto=' . $productId, $token);
    if ((int)($response['status'] ?? 0) !== 200) throw new RuntimeException('pdv_detail_read_failed');
    $json = $response['json'] ?? null;
    if (!is_array($json)) throw new RuntimeException('pdv_detail_schema_invalid');
    return svpdv_calculate_product_prices($productId, $erpDetail, $list, $json);
}

/**
 * Pure price derivation. Fixtures test the same invariants used at runtime.
 * @return array{preco:float,precoPromocional:float,pdv_list_id:int,price_source:string}
 */
function svpdv_calculate_product_prices(string $productId, array $erpDetail, array $list, array $json): array
{
    if (!ctype_digit($productId) || (int)$productId < 1) throw new RuntimeException('pdv_product_id_missing');
    if (trim((string)($erpDetail['id'] ?? '')) !== $productId) throw new RuntimeException('pdv_product_id_mismatch');
    $listId = (int)($list['id'] ?? 0);
    if ($listId < 1 || strcasecmp(trim((string)($list['descricao'] ?? '')), 'PDV') !== 0) {
        throw new RuntimeException('pdv_list_not_verified');
    }
    if ((int)($json['id'] ?? 0) !== $listId || strcasecmp(trim((string)($json['descricao'] ?? '')), 'PDV') !== 0) {
        throw new RuntimeException('pdv_detail_identity_mismatch');
    }
    $listedPercent = $list['acrescimoDesconto'] ?? null;
    $detailPercent = $json['acrescimoDesconto'] ?? null;
    if ($listedPercent !== null && $detailPercent !== null && abs(svpdv_decimal($listedPercent, 'pdv_percent_invalid') - svpdv_decimal($detailPercent, 'pdv_percent_invalid')) > 0.00001) {
        throw new RuntimeException('pdv_percent_changed_during_sync');
    }
    $percent = $detailPercent ?? $listedPercent;
    $exception = svpdv_find_exception($json['excecoes'] ?? null, $productId);
    $raw = is_array($erpDetail['precos'] ?? null) ? $erpDetail['precos'] : [];
    $base = $raw['preco'] ?? $raw['preco_venda'] ?? null;
    $basePromo = $raw['precoPromocional'] ?? $raw['preco_promocional'] ?? 0;
    if ($percent === null && ($exception === null || ($exception['preco'] ?? null) === null)) {
        throw new RuntimeException('pdv_percent_missing_without_fixed_price');
    }
    $factor = $percent !== null ? (1.0 + svpdv_decimal($percent, 'pdv_percent_invalid') / 100.0) : null;
    if ($factor !== null && $factor <= 0) throw new RuntimeException('pdv_percent_out_of_range');
    if (($exception['preco'] ?? null) !== null) {
        $price = svpdv_decimal($exception['preco'], 'pdv_fixed_price_invalid');
    } else {
        if ($base === null || $factor === null) throw new RuntimeException('pdv_base_unverifiable');
        $price = svpdv_decimal($base, 'pdv_base_invalid') * $factor;
    }
    if (is_array($exception) && array_key_exists('precoPromocional', $exception) && $exception['precoPromocional'] !== null) {
        $promo = svpdv_decimal($exception['precoPromocional'], 'pdv_promo_invalid');
    } elseif (is_array($exception) && array_key_exists('precoPromocional', $exception) && $exception['precoPromocional'] === null) {
        // Explicitly null in a specific product exception means no PDV promo.
        $promo = 0.0;
    } else {
        if ($factor === null && svpdv_decimal($basePromo, 'pdv_promo_invalid') > 0) {
            throw new RuntimeException('pdv_promo_percent_unverifiable');
        }
        $promo = svpdv_decimal($basePromo, 'pdv_promo_invalid') * ($factor ?? 1.0);
    }
    $price = round($price, 2, PHP_ROUND_HALF_UP);
    $promo = round($promo, 2, PHP_ROUND_HALF_UP);
    if ($price <= 0 || $promo < 0 || ($promo > 0 && $promo >= $price)) {
        throw new RuntimeException('pdv_commercial_amount_invalid');
    }
    return ['preco' => $price, 'precoPromocional' => $promo, 'pdv_list_id' => $listId, 'price_source' => 'olist_pdv'];
}
