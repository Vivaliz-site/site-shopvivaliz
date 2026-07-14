<?php
declare(strict_types=1);

/**
 * Canonical read-only catalog source for storefront, checkout and health APIs.
 * Prefer the curated fallback when populated; otherwise normalize the live
 * Olist/Tiny detail cache produced by daemon-sync-products.py.
 */
function svcr_products(): array {
    $root = dirname(__DIR__);
    $fallback = $root . '/api/catalog/fallback-products.json';
    $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
    if (is_array($rows) && $rows !== []) {
        return array_values(array_filter($rows, 'is_array'));
    }

    $cache = $root . '/storage/products-cache-ativos.json';
    $payload = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
    $items = is_array($payload['itens'] ?? null) ? $payload['itens'] : [];
    $products = [];
    foreach ($items as $item) {
        if (!is_array($item) || (($item['situacao'] ?? 'A') !== 'A')) continue;
        $attachments = is_array($item['anexos'] ?? null) ? $item['anexos'] : [];
        $image = trim((string)($item['imagem_principal_url'] ?? ''));
        if ($image === '') {
            foreach ($attachments as $attachment) {
                $candidate = is_array($attachment) ? trim((string)($attachment['url'] ?? '')) : '';
                if (preg_match('~^https://~i', $candidate)) { $image = $candidate; break; }
            }
        }
        $prices = is_array($item['precos'] ?? null) ? $item['precos'] : [];
        $stockInfo = is_array($item['estoque'] ?? null) ? $item['estoque'] : [];
        $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
        $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : [];
        $sku = trim((string)($item['sku'] ?? $item['codigo'] ?? ''));
        if ($sku === '') continue;
        $products[] = [
            'id' => (string)($item['id'] ?? $sku),
            'sku' => $sku,
            'olist_product_id' => (string)($item['id'] ?? ''),
            'name' => trim((string)($item['descricao'] ?? $item['nome'] ?? $sku)),
            'description' => trim((string)($item['descricaoComplementar'] ?? $item['descricao_complementar'] ?? $item['descricao'] ?? '')),
            'price' => (float)($prices['preco'] ?? $prices['preco_venda'] ?? $item['preco'] ?? 0),
            'stock' => max(0, (int)($item['estoque_disponivel'] ?? $stockInfo['quantidade'] ?? 0)),
            'image_url' => $image,
            'images_count' => count($attachments),
            'category' => trim((string)($category['nome'] ?? $category['caminhoCompleto'] ?? '')),
            'weight' => (float)($dimensions['pesoLiquido'] ?? $dimensions['peso_liquido'] ?? $item['peso'] ?? 0),
            'width' => (float)($dimensions['largura'] ?? 0),
            'height' => (float)($dimensions['altura'] ?? 0),
            'length' => (float)($dimensions['comprimento'] ?? 0),
            'status' => 'active',
        ];
    }
    return $products;
}
