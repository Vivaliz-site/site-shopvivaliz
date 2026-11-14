<?php
declare(strict_types=1);

require_once __DIR__.'/KitCost.php';
require_once __DIR__.'/OlistChannelProgram.php';

/**
 * Reconcile the CURRENT Olist product-tab association independently of
 * historical EAN/order identity. A conflict still leaves realized sales
 * unverified; a current cost may support a separately labelled projection.
 * Only previously verified, screenshot-backed product-tab mappings are used.
 */
final class SvAmazonMarginCurrentProductLink
{
    /** @param callable(int):array<string,mixed> $readComponent */
    public static function reference(
        array $listing, array $link, array $product, callable $readComponent
    ): ?array {
        if (($link['status']??'')!=='mapped'
            || ($link['method']??'')!=='OLIST_AUTHENTICATED_PRODUCT_TAB_UI'
            || (int)($link['id']??0)<1
            || (int)($link['id']??0)!==(int)($product['erp_id']??0)
            || (string)($link['erp_sku']??'')===''
            || (string)$link['erp_sku']!==(string)($product['erp_sku']??'')
            || ($product['active']??false)!==true) {
            return null;
        }

        $cost=$product['last_cost']??null;
        $positive=is_numeric($cost) && is_finite((float)$cost) && (float)$cost>0;
        $program=SvAmazonMarginOlistChannelProgram::resolve(
            (string)($link['integration']??''),
            (string)($listing['fulfillment_channel']??'')
        );
        $ref=[
            'source'=>'OLIST_AUTHENTICATED_PRODUCT_TAB_UI_X_CURRENT_OLIST_V3',
            'erp_id'=>(int)$link['id'],
            'erp_sku'=>(string)$link['erp_sku'],
            'integration'=>(string)($link['integration']??''),
            'observed_at'=>(string)($link['observed_at']??''),
            'erp_product_type'=>(string)($product['erp_product_type']??''),
            'recorded_cost'=>$positive ? (float)$cost : null,
            'recorded_cost_date'=>$positive ? ($product['last_cost_date']??null) : null,
            'offer_cost'=>null,
            'offer_cost_basis'=>null,
            'kit_bom_cost'=>null,
            'reason'=>null,
            'status'=>'cost_history_missing',
            'program'=>$program,
            'erp_package'=>$product['erp_package']??null,
            'kit_cost_multiplier'=>null,
        ];
        if ($program===null) {
            $ref['status']='current_integration_program_mismatch';
            return $ref;
        }
        if (!$positive) return $ref;

        if (strtoupper((string)($product['erp_product_type']??''))==='K') {
            $bom=SvAmazonMarginKitCost::checkBillOfMaterials(
                (int)$product['erp_id'], $product['erp_kit_components']??[],
                (float)$cost,$readComponent
            );
            $ref['kit_bom_cost']=$bom['sum_cost'];
            if ($bom['status']!=='confirmed') {
                $ref['reason']=$bom['reason'];
            }
        }
        $resolved=SvAmazonMarginKitCost::resolve((string)($listing['title']??''),$product);
        $ref['offer_cost_basis']=$resolved['basis'];
        if ($resolved['status']!=='confirmed'
            || !is_numeric($resolved['cost']??null)
            || (float)$resolved['cost']<=0) {
            $ref['status']='kit_offer_quantity_pending';
            $ref['reason']=$resolved['reason']??'kit_cost_not_verified';
            return $ref;
        }
        $ref['offer_cost']=(float)$resolved['cost'];
        $ref['kit_cost_multiplier']=(int)$resolved['multiplier'];
        $ref['status']='offer_cost_verified';
        return $ref;
    }

    /** Current offer math only: never overwrite the historical ERP identity. */
    public static function projectionInput(array $row): array
    {
        $ref=$row['current_product_link_reference']??null;
        if (!is_array($ref)||($ref['status']??'')!=='offer_cost_verified'
            || ($row['last_cost']??null)!==null
            || !is_numeric($ref['offer_cost']??null)
            || (float)$ref['offer_cost']<=0
            || !in_array($ref['program']??'',['DBA','FBA_ONSITE'],true)) {
            return $row;
        }
        $input=$row;
        $input['last_cost']=(float)$ref['offer_cost'];
        $input['program']=$ref['program'];
        $input['erp_package']=$ref['erp_package']??null;
        $input['kit_cost_multiplier']=(int)($ref['kit_cost_multiplier']??1);
        return $input;
    }
}
