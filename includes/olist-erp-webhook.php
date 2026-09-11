<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-runtime.php';
require_once __DIR__ . '/tiny-order-push.php';
require_once __DIR__ . '/melhorenvio-oauth.php';

/** @return list<string> */
function svoei_supported_types(): array
{
    return ['produto', 'precos', 'estoque', 'situacao_pedido', 'rastreio', 'nota_fiscal', 'cotacao'];
}

function svoei_number(mixed $value): float
{
    if (is_int($value) || is_float($value)) return max(0.0, (float)$value);
    if (!is_string($value)) return 0.0;
    $value = trim(str_replace(',', '.', $value));
    return is_numeric($value) ? max(0.0, (float)$value) : 0.0;
}

function svoei_first_text(array $values): string
{
    foreach ($values as $value) {
        if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
    }
    return '';
}

/** @return list<array{url:string}> */
function svoei_attachments(array $item): array
{
    $out = [];
    foreach (is_array($item['anexos'] ?? null) ? $item['anexos'] : [] as $entry) {
        $url = is_array($entry) ? trim((string)($entry['url'] ?? '')) : trim((string)$entry);
        if ($url !== '' && preg_match('~^https://~i', $url) === 1) $out[] = ['url' => $url];
    }
    return $out;
}

/** @return array<string,mixed> */
function svoei_normalize_v3_product(array $item, array $stockDetail = []): array
{
    $prices = is_array($item['precos'] ?? null) ? $item['precos'] : [];
    $stock = is_array($item['estoque'] ?? null) ? $item['estoque'] : [];
    $category = is_array($item['categoria'] ?? null) ? $item['categoria'] : [];
    $dimensions = is_array($item['dimensoes'] ?? null) ? $item['dimensoes'] : [];
    $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
    $brandValue = $item['marca'] ?? '';
    $brand = is_array($brandValue) ? svoei_first_text([$brandValue['nome'] ?? '', $brandValue['name'] ?? '']) : trim((string)$brandValue);
    $sku = svoei_first_text([$item['sku'] ?? '', $item['codigo'] ?? '']);
    $name = svoei_first_text([$item['descricao'] ?? '', $item['nome'] ?? '', $sku]);
    $available = $stockDetail['disponivel'] ?? $stockDetail['saldo'] ?? $stock['quantidade'] ?? $item['estoque_disponivel'] ?? 0;
    $attachments = svoei_attachments($item);
    $image = svoei_first_text([$item['imagem_principal_url'] ?? '', $attachments[0]['url'] ?? '']);
    $slug = svoei_first_text([$seo['slug'] ?? '', $item['slug'] ?? '']);
    if ($slug === '') $slug = svcr_slug($name, $sku);

    return [
        'id' => $item['id'] ?? null,
        'sku' => $sku,
        'tipo' => (string)($item['tipo'] ?? 'P'),
        'kit' => is_array($item['kit'] ?? null) ? $item['kit'] : [],
        'descricao' => $name,
        'descricaoComplementar' => svoei_first_text([$item['descricaoComplementar'] ?? '', $item['descricao_complementar'] ?? '']),
        'situacao' => (string)($item['situacao'] ?? 'A'),
        'unidade' => (string)($item['unidade'] ?? ''),
        'gtin' => (string)($item['gtin'] ?? ''),
        'precos' => [
            'preco' => svoei_number($prices['preco'] ?? $prices['preco_venda'] ?? $item['preco'] ?? 0),
            'precoPromocional' => svoei_number($prices['precoPromocional'] ?? $prices['preco_promocional'] ?? $item['precoPromocional'] ?? 0),
        ],
        'estoque' => ['quantidade' => (int)floor(svoei_number($available))],
        'estoque_disponivel' => (int)floor(svoei_number($available)),
        'categoria' => [
            'nome' => svoei_first_text([$category['nome'] ?? '', $item['descricaoCategoria'] ?? '']),
            'caminhoCompleto' => svoei_first_text([$category['caminhoCompleto'] ?? '', $item['descricaoArvoreCategoria'] ?? '']),
        ],
        'marca' => ['nome' => $brand],
        'dimensoes' => [
            'largura' => svoei_number($dimensions['largura'] ?? $item['larguraEmbalagem'] ?? 0),
            'altura' => svoei_number($dimensions['altura'] ?? $item['alturaEmbalagem'] ?? 0),
            'comprimento' => svoei_number($dimensions['comprimento'] ?? $item['comprimentoEmbalagem'] ?? 0),
            'pesoLiquido' => svoei_number($dimensions['pesoLiquido'] ?? $dimensions['peso_liquido'] ?? $item['pesoLiquido'] ?? 0),
        ],
        'anexos' => $attachments,
        'imagem_principal_url' => $image,
        'seo_title' => svoei_first_text([$seo['titulo'] ?? '', $seo['title'] ?? '', $item['seo_title'] ?? '']),
        'seo_description' => svoei_first_text([$seo['descricao'] ?? '', $seo['description'] ?? '', $item['seo_description'] ?? '']),
        'keywords' => is_array($seo['keywords'] ?? null) ? $seo['keywords'] : [],
        'slug' => $slug,
        'video_url' => svoei_first_text([$seo['linkVideo'] ?? '', $item['video_url'] ?? '']),
        'sync_source' => 'tiny_v3',
        '_detail_synced_at' => gmdate(DATE_ATOM),
    ];
}

/** @return list<array<string,mixed>> */
function svoei_cache_items(array $payload): array
{
    if (array_is_list($payload)) return array_values(array_filter($payload, 'is_array'));
    foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
        if (is_array($payload[$key] ?? null)) {
            $candidate = $payload[$key];
            if (is_array($candidate['itens'] ?? null)) $candidate = $candidate['itens'];
            if (is_array($candidate['items'] ?? null)) $candidate = $candidate['items'];
            return array_values(array_filter($candidate, 'is_array'));
        }
    }
    return [];
}

function svoei_atomic_json_write(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('catalog_directory_unavailable');
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) throw new RuntimeException('catalog_json_encode_failed');
    $tmp = tempnam($dir, '.olist-webhook-');
    if ($tmp === false || file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        if (is_string($tmp)) @unlink($tmp);
        throw new RuntimeException('catalog_write_failed');
    }
    @chmod($tmp, 0664);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('catalog_replace_failed');
    }
}

/** @param list<array<string,mixed>> $items */
function svoei_save_active_catalog(string $root, array $items): void
{
    $primary = $root . '/storage/products-cache-ativos.json';
    $existing = [];
    if (is_file($primary)) {
        $decoded = json_decode((string)file_get_contents($primary), true);
        if (is_array($decoded)) $existing = $decoded;
    }
    if (array_is_list($existing)) {
        $payload = $items;
    } else {
        $payload = $existing;
        $payload['success'] = true;
        $payload['total'] = count($items);
        $payload['updated_at'] = gmdate(DATE_ATOM);
        $payload['itens'] = $items;
        $payload['items'] = $items;
    }
    svoei_atomic_json_write($primary, $payload);

    $secondary = $root . '/storage/cache/products-cache-ativos.json';
    if (is_file($secondary)) svoei_atomic_json_write($secondary, $payload);
    foreach (glob($root . '/storage/cache/catalog-api/*.json') ?: [] as $cacheFile) {
        @unlink($cacheFile);
    }
}

/** @return list<array<string,mixed>> */
function svoei_load_active_catalog(string $root): array
{
    $path = $root . '/storage/products-cache-ativos.json';
    if (!is_file($path)) throw new RuntimeException('catalog_cache_missing');
    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) throw new RuntimeException('catalog_cache_invalid');
    return svoei_cache_items($payload);
}

function svoei_catalog_mutate(string $root, callable $mutator): mixed
{
    $lockPath = $root . '/storage/olist-erp-webhook.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false) throw new RuntimeException('catalog_lock_unavailable');
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('catalog_lock_failed');
    }
    try {
        $items = svoei_load_active_catalog($root);
        $result = $mutator($items);
        svoei_save_active_catalog($root, $items);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function svoei_item_matches(array $item, string $sku, string $id = ''): bool
{
    $itemSku = trim((string)($item['sku'] ?? $item['codigo'] ?? ''));
    $itemId = trim((string)($item['id'] ?? $item['olist_product_id'] ?? ''));
    return ($sku !== '' && strcasecmp($itemSku, $sku) === 0) || ($id !== '' && $itemId === $id);
}

function svoei_upsert_catalog_product(string $root, array $product): void
{
    $sku = trim((string)($product['sku'] ?? ''));
    $id = trim((string)($product['id'] ?? ''));
    if ($sku === '' && $id === '') throw new InvalidArgumentException('product_identifier_missing');
    svoei_catalog_mutate($root, static function (array &$items) use ($product, $sku, $id): void {
        foreach ($items as $index => $item) {
            if (svoei_item_matches($item, $sku, $id)) {
                $items[$index] = array_replace($item, $product);
                return;
            }
        }
        $items[] = $product;
    });
}

function svoei_update_catalog_price(string $root, string $sku, float $price, float $promotional = 0.0): void
{
    $sku = trim($sku);
    if ($sku === '') throw new InvalidArgumentException('sku_missing');
    svoei_catalog_mutate($root, static function (array &$items) use ($sku, $price, $promotional): void {
        foreach ($items as $index => $item) {
            if (!svoei_item_matches($item, $sku)) continue;
            $prices = is_array($item['precos'] ?? null) ? $item['precos'] : [];
            $prices['preco'] = max(0.0, $price);
            $prices['precoPromocional'] = max(0.0, $promotional);
            $items[$index]['precos'] = $prices;
            $items[$index]['sync_source'] = 'tiny_v3';
            $items[$index]['_detail_synced_at'] = gmdate(DATE_ATOM);
            return;
        }
        throw new RuntimeException('catalog_product_not_found');
    });
}

function svoei_update_catalog_stock(string $root, string $sku, int $quantity): void
{
    $sku = trim($sku);
    if ($sku === '') throw new InvalidArgumentException('sku_missing');
    $quantity = max(0, $quantity);
    svoei_catalog_mutate($root, static function (array &$items) use ($sku, $quantity): void {
        foreach ($items as $index => $item) {
            if (!svoei_item_matches($item, $sku)) continue;
            $items[$index]['estoque_disponivel'] = $quantity;
            $items[$index]['estoque'] = is_array($item['estoque'] ?? null) ? $item['estoque'] : [];
            $items[$index]['estoque']['quantidade'] = $quantity;
            $items[$index]['sync_source'] = 'tiny_v3';
            $items[$index]['_detail_synced_at'] = gmdate(DATE_ATOM);
            return;
        }
        throw new RuntimeException('catalog_product_not_found');
    });
}

/** @return list<array<string,string>> */
function svoei_product_mapping_response(array $product, string $baseUrl): array
{
    $baseUrl = rtrim($baseUrl, '/');
    $parentName = svoei_first_text([$product['nome'] ?? '', $product['descricao'] ?? '', $product['codigo'] ?? '']);
    $parentSku = svoei_first_text([$product['skuMapeamento'] ?? '', $product['codigo'] ?? '', $product['sku'] ?? '', $product['id'] ?? '']);
    $parentImage = svoei_first_text([svoei_attachments($product)[0]['url'] ?? '']);
    $parentSlug = svcr_slug($parentName, $parentSku);
    $parentUrl = $baseUrl !== '' && $parentSlug !== '' ? $baseUrl . '/produto/' . rawurlencode($parentSlug) : '';
    $rows = [$product];
    foreach (is_array($product['variacoes'] ?? null) ? $product['variacoes'] : [] as $variation) {
        if (is_array($variation)) $rows[] = $variation;
    }
    $out = [];
    foreach ($rows as $index => $row) {
        $sku = svoei_first_text([$row['skuMapeamento'] ?? '', $row['codigo'] ?? '', $row['sku'] ?? '', $row['id'] ?? '']);
        $mappingId = svoei_first_text([$row['idMapeamento'] ?? '', $row['id'] ?? '']);
        $image = svoei_first_text([svoei_attachments($row)[0]['url'] ?? '', $parentImage]);
        $mapped = [
            'idMapeamento' => $mappingId,
            'skuMapeamento' => $sku,
        ];
        if ($parentUrl !== '') $mapped['urlProduto'] = $parentUrl;
        if ($image !== '') $mapped['urlImagem'] = $image;
        $out[] = $mapped;
    }
    return $out;
}

function svoei_unwrap_product(array $json): array
{
    foreach (['produto', 'data'] as $key) {
        if (is_array($json[$key] ?? null)) {
            $candidate = $json[$key];
            if (is_array($candidate['produto'] ?? null)) return $candidate['produto'];
            return $candidate;
        }
    }
    return $json;
}

function svoei_resolve_v3_product_id(string $sku, string $token): string
{
    $sku = trim($sku);
    if ($sku === '') throw new InvalidArgumentException('sku_missing');
    $query = http_build_query(['pesquisa' => $sku, 'situacao' => 'A', 'limit' => 100, 'offset' => 0]);
    $response = svtop_tiny_get('/produtos?' . $query, $token);
    if ((int)($response['status'] ?? 0) !== 200) throw new RuntimeException('erp_product_search_failed');
    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $rows = $json['itens'] ?? $json['data'] ?? $json['produtos'] ?? [];
    $matches = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) continue;
        $rowSku = svoei_first_text([$row['sku'] ?? '', $row['codigo'] ?? '']);
        $rowId = svoei_first_text([$row['id'] ?? '', $row['idProduto'] ?? '']);
        if ($rowId !== '' && strcasecmp($rowSku, $sku) === 0) $matches[$rowId] = true;
    }
    if (count($matches) !== 1) throw new RuntimeException('erp_product_resolution_not_unique');
    return (string)array_key_first($matches);
}

/** @return array<string,mixed> */
function svoei_refresh_product_from_v3(string $root, string $productId = '', string $sku = ''): array
{
    $token = svtop_tiny_get_token();
    if ($token === '') throw new RuntimeException('erp_access_token_missing');
    $productId = trim($productId);
    if ($productId === '') $productId = svoei_resolve_v3_product_id($sku, $token);
    $detailResponse = svtop_tiny_get('/produtos/' . rawurlencode($productId), $token);
    if ((int)($detailResponse['status'] ?? 0) !== 200) throw new RuntimeException('erp_product_read_failed');
    $detail = svoei_unwrap_product(is_array($detailResponse['json'] ?? null) ? $detailResponse['json'] : []);
    if ($detail === []) throw new RuntimeException('erp_product_payload_empty');

    $stockResponse = svtop_tiny_get('/estoque/' . rawurlencode($productId), $token);
    if ((int)($stockResponse['status'] ?? 0) !== 200) throw new RuntimeException('erp_stock_read_failed');
    $stock = is_array($stockResponse['json'] ?? null) ? $stockResponse['json'] : [];
    if (is_array($stock['estoque'] ?? null)) $stock = $stock['estoque'];
    $product = svoei_normalize_v3_product($detail, $stock);
    svoei_upsert_catalog_product($root, $product);
    return $product;
}

function svoei_refresh_stock_from_v3(string $root, string $productId, string $mappedSku, string $erpSku = ''): int
{
    $token = svtop_tiny_get_token();
    if ($token === '') throw new RuntimeException('erp_access_token_missing');
    $productId = trim($productId);
    if ($productId === '') $productId = svoei_resolve_v3_product_id($erpSku !== '' ? $erpSku : $mappedSku, $token);
    $response = svtop_tiny_get('/estoque/' . rawurlencode($productId), $token);
    if ((int)($response['status'] ?? 0) !== 200) throw new RuntimeException('erp_stock_read_failed');
    $stock = is_array($response['json'] ?? null) ? $response['json'] : [];
    if (is_array($stock['estoque'] ?? null)) $stock = $stock['estoque'];
    if (!array_key_exists('disponivel', $stock) && !array_key_exists('saldo', $stock) && !array_key_exists('quantidade', $stock)) {
        throw new RuntimeException('erp_stock_payload_missing_quantity');
    }
    $quantity = (int)floor(svoei_number($stock['disponivel'] ?? $stock['saldo'] ?? $stock['quantidade'] ?? 0));
    try {
        svoei_update_catalog_stock($root, $mappedSku !== '' ? $mappedSku : $erpSku, $quantity);
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'catalog_product_not_found') throw $e;
        $product = svoei_refresh_product_from_v3($root, $productId, $erpSku);
        $targetSku = trim((string)($product['sku'] ?? ''));
        if ($targetSku === '') throw new RuntimeException('erp_product_sku_missing');
        svoei_update_catalog_stock($root, $targetSku, $quantity);
    }
    return $quantity;
}

function svoei_log(string $root, string $action, array $context = []): void
{
    $safe = [];
    foreach (['tipo', 'sku', 'product_id', 'order_id', 'status'] as $key) {
        if (isset($context[$key]) && is_scalar($context[$key])) $safe[$key] = (string)$context[$key];
    }
    $line = json_encode(['timestamp' => gmdate(DATE_ATOM), 'action' => $action] + $safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($line)) @file_put_contents($root . '/logs/olist-erp-webhook.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** @return array{status:string,tracking:string,tracking_url:string,estimated_delivery:string,invoice_id:string} */
function svoei_order_state_from_v3(array $json): array
{
    $order = is_array($json['pedido'] ?? null) ? $json['pedido'] : $json;
    return [
        'status' => svoei_first_text([$order['situacao'] ?? '']),
        'tracking' => svoei_first_text([
            $order['codigoRastreamento'] ?? '',
            $order['rastreamento']['codigo'] ?? '',
            $order['transportador']['codigoRastreamento'] ?? '',
        ]),
        'tracking_url' => svoei_first_text([
            $order['urlRastreamento'] ?? '',
            $order['rastreamento']['url'] ?? '',
            $order['transportador']['urlRastreamento'] ?? '',
        ]),
        'estimated_delivery' => svoei_first_text([$order['dataPrevista'] ?? '', $order['dataEntrega'] ?? '']),
        'invoice_id' => svoei_first_text([$order['idNotaFiscal'] ?? '']),
    ];
}

/** @return list<array{codigo:string,preco:float,prazo:int}> */
function svoei_map_shipping_options(array $decoded): array
{
    $quotes = [];
    foreach ($decoded as $option) {
        if (!is_array($option) || !empty($option['error'])) continue;
        $price = svoei_number($option['price'] ?? 0);
        if ($price <= 0) continue;
        $quotes[] = [
            'codigo' => svoei_first_text([$option['id'] ?? '', $option['name'] ?? 'frete']),
            'preco' => round($price, 2),
            'prazo' => max(0, (int)($option['delivery_time'] ?? 0)),
        ];
    }
    usort($quotes, static fn(array $a, array $b): int => $a['preco'] <=> $b['preco']);
    return array_slice($quotes, 0, 8);
}

/** @return array{cotacoes:list<array{codigo:string,preco:float,prazo:int}>} */
function svoei_quote_freight(array $data): array
{
    $from = preg_replace('/\D+/', '', (string)($data['cep_origem'] ?? '')) ?: '';
    $to = preg_replace('/\D+/', '', (string)($data['cep_destino'] ?? '')) ?: '';
    $items = is_array($data['itens'] ?? null) ? $data['itens'] : [];
    if (strlen($from) !== 8 || strlen($to) !== 8 || $items === []) throw new InvalidArgumentException('invalid_freight_payload');
    $products = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $products[] = [
            'id' => svoei_first_text([$item['identificador'] ?? '', 'produto']),
            'width' => max(1, (int)ceil(svoei_number($item['largura'] ?? 0))),
            'height' => max(1, (int)ceil(svoei_number($item['altura'] ?? 0))),
            'length' => max(1, (int)ceil(svoei_number($item['comprimento'] ?? 0))),
            'weight' => max(0.1, svoei_number($item['peso'] ?? 0)),
            'insurance_value' => 1.0,
            'quantity' => max(1, (int)($item['qtd'] ?? 1)),
        ];
    }
    if ($products === []) throw new InvalidArgumentException('empty_freight_items');
    $token = me_current_access_token() ?: svtop_env('MELHORENVIO_ACCESS_TOKEN', 'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN', 'MELHORENVIO_API_KEY');
    if ($token === '') throw new RuntimeException('shipping_access_token_missing');
    $payload = ['from' => ['postal_code' => $from], 'to' => ['postal_code' => $to], 'products' => $products, 'options' => ['receipt' => false, 'own_hand' => false, 'collect' => false]];
    $ch = curl_init(me_api_base() . '/api/v2/me/shipment/calculate');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token, 'User-Agent: ShopVivaliz/Olist-Freight']]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($status < 200 || $status >= 300 || !is_string($raw)) throw new RuntimeException('shipping_provider_failed');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException('shipping_provider_invalid_json');
    $quotes = svoei_map_shipping_options($decoded);
    if ($quotes === []) throw new RuntimeException('shipping_options_empty');
    return ['cotacoes' => $quotes];
}
