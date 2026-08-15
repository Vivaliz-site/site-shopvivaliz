<?php
declare(strict_types=1);

/**
 * Politica oficial do "Compre Junto" da ShopVivaliz.
 *
 * - 3% de desconto sobre uma unidade de cada um dos dois SKUs do par.
 * - Quantidades extras permanecem no preco normal, evitando ampliar a oferta
 *   alem do par anunciado na pagina do produto.
 * - O navegador apenas informa quais SKUs compoem a oferta.
 * - Preco, estoque, quantidade elegivel e valor do desconto sao sempre
 *   recalculados a partir dos itens autoritativos ja validados pelo servidor.
 * - O beneficio pode coexistir com cupom; o cupom e calculado depois deste
 *   desconto para evitar cupom sobre valor que ja foi abatido.
 */

const SVBT_DISCOUNT_PERCENT = 3.0;
const SVBT_OFFER_TYPE = 'buy_together';

/** @return array{active:bool,type:string,skus:array,percent:float,eligible_subtotal:float,amount:float,label:string,error:string} */
function svbt_empty_result(string $error = ''): array
{
    return [
        'active' => false,
        'type' => SVBT_OFFER_TYPE,
        'skus' => [],
        'percent' => SVBT_DISCOUNT_PERCENT,
        'eligible_subtotal' => 0.0,
        'amount' => 0.0,
        'label' => 'Compre junto: 3% OFF nos 2 itens',
        'error' => $error,
    ];
}

function svbt_parse_offer(mixed $rawOffer): array
{
    if (is_string($rawOffer)) {
        $rawOffer = trim($rawOffer);
        if ($rawOffer === '') return [];
        $decoded = json_decode($rawOffer, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($rawOffer) ? $rawOffer : [];
}

/**
 * @param array<int,array<string,mixed>> $authoritativeItems
 * @return array{active:bool,type:string,skus:array,percent:float,eligible_subtotal:float,amount:float,label:string,error:string}
 */
function svbt_validate_offer(mixed $rawOffer, array $authoritativeItems): array
{
    $offer = svbt_parse_offer($rawOffer);
    if ($offer === []) return svbt_empty_result();

    $type = strtolower(trim((string)($offer['type'] ?? '')));
    if ($type !== SVBT_OFFER_TYPE) return svbt_empty_result('invalid_offer_type');

    $rawSkus = is_array($offer['skus'] ?? null) ? $offer['skus'] : [];
    $skus = [];
    foreach ($rawSkus as $rawSku) {
        $sku = trim((string)$rawSku);
        if ($sku === '' || strlen($sku) > 80) continue;
        $key = strtolower($sku);
        if (!isset($skus[$key])) $skus[$key] = $sku;
    }
    if (count($skus) !== 2) return svbt_empty_result('offer_requires_two_distinct_skus');

    $itemsBySku = [];
    foreach ($authoritativeItems as $item) {
        if (!is_array($item)) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        if ($sku !== '') $itemsBySku[strtolower($sku)] = $item;
    }

    $eligibleSubtotal = 0.0;
    $amount = 0.0;
    $validatedSkus = [];
    foreach ($skus as $key => $originalSku) {
        $item = $itemsBySku[$key] ?? null;
        if (!is_array($item)) return svbt_empty_result('offer_item_missing');

        $price = round(max(0.0, (float)($item['price'] ?? 0)), 2);
        $quantity = max(0, (int)($item['quantity'] ?? 0));
        if ($price <= 0 || $quantity <= 0) return svbt_empty_result('offer_item_invalid');

        // Uma unidade de cada SKU forma o par promocional. O arredondamento e
        // feito por item para que "3% em ambos" seja financeiramente consistente.
        $discountedUnit = round($price * (1 - SVBT_DISCOUNT_PERCENT / 100), 2);
        $eligibleSubtotal += $price;
        $amount += $price - $discountedUnit;
        $validatedSkus[] = (string)($item['sku'] ?? $originalSku);
    }

    $eligibleSubtotal = round($eligibleSubtotal, 2);
    $amount = round($amount, 2);
    if ($amount <= 0) return svbt_empty_result('offer_discount_zero');

    return [
        'active' => true,
        'type' => SVBT_OFFER_TYPE,
        'skus' => $validatedSkus,
        'percent' => SVBT_DISCOUNT_PERCENT,
        'eligible_subtotal' => $eligibleSubtotal,
        'amount' => $amount,
        'label' => 'Compre junto: 3% OFF nos 2 itens',
        'error' => '',
    ];
}
