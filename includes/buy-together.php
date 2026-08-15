<?php
declare(strict_types=1);

/**
 * Politica oficial do "Compre Junto" da ShopVivaliz.
 *
 * Regra comercial:
 * - qualquer carrinho com 2 ou mais SKUs distintos recebe automaticamente
 *   3% de desconto sobre todos os itens elegiveis do carrinho;
 * - quantidades adicionais dos SKUs presentes tambem recebem os 3%;
 * - o desconto independe de cupom ou de um par pre-configurado;
 * - preco, quantidade, elegibilidade e valor do beneficio sao calculados
 *   exclusivamente a partir dos itens autoritativos do servidor;
 * - quando houver cupom, ele e validado depois do Compre Junto para evitar
 *   aplicar cupom sobre valor que ja foi abatido.
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
        'label' => 'Compre junto: 3% OFF com 2+ produtos diferentes',
        'error' => $error,
    ];
}

/**
 * Calcula automaticamente a promocao a partir do carrinho autoritativo.
 * O argumento $rawOffer e mantido apenas por compatibilidade com clientes
 * antigos; nenhuma informacao financeira vinda do navegador e confiada.
 *
 * @param array<int,array<string,mixed>> $authoritativeItems
 * @return array{active:bool,type:string,skus:array,percent:float,eligible_subtotal:float,amount:float,label:string,error:string}
 */
function svbt_validate_offer(mixed $rawOffer, array $authoritativeItems): array
{
    $distinct = [];
    $eligibleSubtotal = 0.0;
    $amount = 0.0;

    foreach ($authoritativeItems as $item) {
        if (!is_array($item)) continue;

        $sku = trim((string)($item['sku'] ?? ''));
        $price = round(max(0.0, (float)($item['price'] ?? 0)), 2);
        $quantity = max(0, (int)($item['quantity'] ?? 0));
        if ($sku === '' || $price <= 0 || $quantity <= 0) continue;

        $key = strtolower($sku);
        $distinct[$key] = $sku;

        $lineSubtotal = round($price * $quantity, 2);
        $discountedLine = round($lineSubtotal * (1 - SVBT_DISCOUNT_PERCENT / 100), 2);
        $eligibleSubtotal += $lineSubtotal;
        $amount += $lineSubtotal - $discountedLine;
    }

    if (count($distinct) < 2) {
        return svbt_empty_result('requires_two_distinct_skus');
    }

    $eligibleSubtotal = round($eligibleSubtotal, 2);
    $amount = round($amount, 2);
    if ($eligibleSubtotal <= 0 || $amount <= 0) {
        return svbt_empty_result('offer_discount_zero');
    }

    return [
        'active' => true,
        'type' => SVBT_OFFER_TYPE,
        'skus' => array_values($distinct),
        'percent' => SVBT_DISCOUNT_PERCENT,
        'eligible_subtotal' => $eligibleSubtotal,
        'amount' => $amount,
        'label' => 'Compre junto: 3% OFF com 2+ produtos diferentes',
        'error' => '',
    ];
}
