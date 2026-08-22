<?php

declare(strict_types=1);

/** @return array<string,array{label:string,text:list<string>,images:list<string>,readback:list<string>} > */
function sv_market_catalog_endpoint_registry(): array
{
    return [
        'site' => [
            'label' => 'ShopVivaliz',
            'text' => ['UPDATE products', 'UPDATE product_channel_content', 'UPDATE storage/products-cache-ativos.json'],
            'images' => ['INSERT/UPDATE product_images', 'UPDATE products.image_url', 'UPDATE storefront cache'],
            'readback' => ['SELECT products', 'SELECT product_channel_content', 'read-back do cache JSON'],
        ],
        'ml' => [
            'label' => 'Mercado Livre',
            'text' => ['PUT /items/{item_id}', 'PUT /items/{item_id}/description?api_version=2'],
            'images' => ['PUT /items/{item_id} (pictures)'],
            'readback' => ['GET /items/{item_id}', 'GET /items/{item_id}/description'],
        ],
        'shopee' => [
            'label' => 'Shopee',
            'text' => ['POST /api/v2/product/update_item'],
            'images' => ['POST /api/v2/media_space/upload_image', 'POST /api/v2/product/update_item (image)'],
            'readback' => ['GET /api/v2/product/get_item_list', 'GET /api/v2/product/get_item_base_info', 'GET /api/v2/product/get_model_list'],
        ],
        'amazon' => [
            'label' => 'Amazon',
            'text' => ['GET /definitions/2020-09-01/productTypes/{productType}', 'PATCH /listings/2021-08-01/items/{sellerId}/{sku}'],
            'images' => ['PATCH /listings/2021-08-01/items/{sellerId}/{sku} (image locator attributes)'],
            'readback' => ['GET /listings/2021-08-01/items/{sellerId}/{sku}?includedData=summaries,attributes,issues'],
        ],
        'tiktok' => [
            'label' => 'TikTok Shop',
            'text' => ['POST /product/202509/products/{product_id}/partial_edit'],
            'images' => ['POST /product/202309/images/upload', 'POST /product/202509/products/{product_id}/partial_edit (main_images)'],
            'readback' => ['POST /product/202309/products/search', 'GET /product/202309/products/{product_id}', 'GET audit version with return_under_review_version=true'],
        ],
        'erp' => [
            'label' => 'Olist / Tiny ERP',
            'text' => ['PUT /public-api/v3/produtos/{id}'],
            'images' => ['GET /public-api/v3/produtos/{id} anexos/imagens; escrita somente por endpoint ERP v3 oficial habilitado'],
            'readback' => ['GET /public-api/v3/produtos/{id}', 'GET /public-api/v3/produtos?pesquisa={sku}&situacao=A'],
        ],
    ];
}
