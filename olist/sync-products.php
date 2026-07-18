<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(0);
ignore_user_abort(true);

/* ── helpers ── */
function svs_root(): string { return dirname(__DIR__); }
function svs_log(string $m): void {
    @file_put_contents(svs_root() . '/logs/olist-sync.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL, FILE_APPEND);
}
function svs_json(int $code, array $d): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ── carregar credenciais ── */
function svs_env(string ...$keys): string {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $envFile = svs_root() . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
        // tokens persistidos pelo último sync bem-sucedido
        $tf = svs_root() . '/storage/private/tokens.json';
        if (is_file($tf)) {
            $t = json_decode((string)file_get_contents($tf), true) ?: [];
            foreach ($t as $k => $v) {
                if (is_string($k) && is_string($v) && getenv($k) === false) {
                    putenv("$k=$v"); $_ENV[$k] = $v;
                }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV[$k]) && is_string($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    }
    return '';
}

function svs_save_tokens(string $access, string $refresh): void {
    $dir = svs_root() . '/storage/private';
    @mkdir($dir, 0750, true);
    file_put_contents("$dir/tokens.json", json_encode([
        'OLIST_ACCESS_TOKEN'  => $access,
        'OLIST_REFRESH_TOKEN' => $refresh,
        'updated_at'          => date('c'),
    ], JSON_PRETTY_PRINT), LOCK_EX);
}

/* ── HTTP helpers (usando stream contexts em vez de cURL) ── */
function svs_http_get(string $url, array $headers = [], int $timeout = 45): array {
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    $err = '';

    if ($http_response_header ?? null) {
        preg_match('/HTTP\/\d\.\d (\d{3})/', $http_response_header[0], $m);
        $status = (int)($m[1] ?? 0);
    } else {
        $status = 500;
        $err = 'No response headers';
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

function svs_http_post(string $url, array $fields, array $extraHeaders = []): array {
    $data = http_build_query($fields);
    $headers = array_merge(
        ['Content-Type: application/x-www-form-urlencoded', 'Content-Length: ' . strlen($data)],
        $extraHeaders
    );

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $data,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;

    if ($http_response_header ?? null) {
        preg_match('/HTTP\/\d\.\d (\d{3})/', $http_response_header[0], $m);
        $status = (int)($m[1] ?? 0);
    } else {
        $status = 500;
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/* ── OAuth: obter access_token via refresh ── */
function svs_get_access_token(): string {
    $TOKEN_URL    = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    $refresh      = svs_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN');
    $clientId     = svs_env('OLIST_CLIENT_ID',     'TINY_CLIENT_ID');
    $clientSecret = svs_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET');

    if ($refresh === '' || $clientId === '' || $clientSecret === '') {
        throw new RuntimeException(
            'credentials_missing: configure OLIST_CLIENT_ID, OLIST_CLIENT_SECRET e OLIST_REFRESH_TOKEN'
        );
    }

    $res = svs_http_post($TOKEN_URL, [
        'grant_type'    => 'refresh_token',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refresh,
    ]);

    if ($res['status'] !== 200) {
        $d = json_decode($res['body'], true);
        $e = is_array($d) ? ($d['error_description'] ?? $d['error'] ?? '') : '';
        throw new RuntimeException("oauth_refresh_failed HTTP {$res['status']}: $e");
    }

    $json = json_decode($res['body'], true);
    if (!is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException('oauth_invalid_payload: ' . substr($res['body'], 0, 200));
    }

    $newAccess  = (string)$json['access_token'];
    $newRefresh = (string)($json['refresh_token'] ?? $refresh);
    svs_save_tokens($newAccess, $newRefresh);
    svs_log('Tokens OAuth renovados com sucesso.');
    return $newAccess;
}

/* ── Tiny v3: buscar todos os produtos com paginação ── */
function svs_fetch_v3(string $token): array {
    $BASE     = 'https://api.tiny.com.br/public-api/v3';
    $headers  = [
        "Authorization: Bearer $token",
        'Accept: application/json',
        'User-Agent: ShopVivaliz-OlistSync/3.0',
    ];
    $all      = [];
    $page     = 1;
    $pageSize = 100;

    while (true) {
        $url = "$BASE/produtos?" . http_build_query([
            'situacao' => 'A',
            'limit'    => $pageSize,
            'offset'   => ($page - 1) * $pageSize,
        ]);
        $res = svs_http_get($url, $headers);

        if ($res['status'] !== 200) {
            svs_log("v3 /produtos HTTP {$res['status']}: " . substr($res['body'], 0, 200));
            break;
        }

        $json  = json_decode($res['body'], true);
        $itens = $json['itens'] ?? $json['data'] ?? $json['produtos'] ?? [];
        if (!is_array($itens) || count($itens) === 0) break;

        foreach ($itens as $i) { $all[] = $i; }

        // A API v3 real retorna 'paginacao.total', nao 'paginacao.totalRegistros'
        // (confirmado ao vivo: {"paginacao":{"limit":100,"offset":0,"total":181}}).
        // Com o nome errado, $total sempre caia no fallback count($all) -- que
        // e igual ao proprio $itens buscado, entao count($all) >= $total ficava
        // sempre verdadeiro e o sync parava sempre na 1a pagina (100 produtos),
        // mesmo com mais paginas disponiveis (confirmado: 181 produtos reais,
        // fallback-products.json ficava travado em 100 havia dias).
        $total = (int)($json['paginacao']['total'] ?? $json['paginacao']['totalRegistros'] ?? count($all));
        if (count($all) >= $total || count($itens) < $pageSize) break;
        $page++;
        usleep(400000); // 400ms entre páginas
    }

    // A listagem /produtos NÃO retorna estoque.quantidade nem anexos (imagens) --
    // esses campos só existem na resposta do endpoint de detalhe /produtos/{id}.
    // Sem isso o catálogo espelhado sempre caía com estoque=0 e sem imagem,
    // derrubando o "Catálogo em destaque" e travando o checkout. Rate limit da
    // Tiny é 60 req/min, por isso o espaçamento de ~1.1s entre chamadas.
    foreach ($all as &$item) {
        $id = $item['id'] ?? null;
        if (!$id) continue;
        $detail = svs_fetch_v3_detail((string)$id, $token);
        if ($detail !== null) {
            if (!empty($detail['anexos']) && is_array($detail['anexos'])) {
                $item['imagens'] = $detail['anexos'];
            }
            // Mescla o objeto de detalhe INTEIRO (schema completo confirmado em
            // api-docs.erp.olist.com/api-reference/produtos/obter-produto) por
            // cima do item da listagem -- a listagem /produtos so tem um
            // subconjunto raso dos campos; o detalhe tem tudo: situacao,
            // produtoPai, unidade, unidadePorCaixa, ncm, gtin, origem, garantia,
            // observacoes, categoria, marca, dimensoes, precos, estoque,
            // fornecedores, seo (titulo/descricao/keywords/slug/linkVideo),
            // tributacao, variacoes (com grade = atributos reais), kit,
            // tipoVariacao, tipo. So exclui 'anexos' (ja tratado acima como
            // 'imagens') e 'id'/'sku' (a listagem ja tem, nao precisa sobrescrever).
            foreach ($detail as $field => $value) {
                if (in_array($field, ['anexos', 'id', 'sku'], true)) continue;
                if ($value === null || $value === '' || $value === []) continue;
                $item[$field] = $value;
            }
        }
        usleep(1100000); // ~1.1s entre chamadas (limite: 60 req/min)
    }
    unset($item);

    return $all;
}

function svs_fetch_v3_detail(string $id, string $token): ?array {
    $BASE = 'https://api.tiny.com.br/public-api/v3';
    $headers = [
        "Authorization: Bearer $token",
        'Accept: application/json',
        'User-Agent: ShopVivaliz-OlistSync/3.0',
    ];
    $res = svs_http_get("$BASE/produtos/$id", $headers);
    if ($res['status'] === 429) {
        usleep(2000000);
        $res = svs_http_get("$BASE/produtos/$id", $headers);
    }
    if ($res['status'] !== 200) {
        svs_log("v3 /produtos/$id HTTP {$res['status']}");
        return null;
    }
    $json = json_decode($res['body'], true);
    return is_array($json) ? $json : null;
}

/* ── Normalizar produto bruto → formato Vivaliz ── */
function svs_normalize(array $p, string $source): array {
    $id    = (string)($p['id']      ?? $p['idProduto'] ?? '');
    $sku   = trim((string)($p['codigo'] ?? $p['sku']   ?? $p['codigoPai'] ?? ''));
    $name  = trim((string)($p['descricao'] ?? $p['nome'] ?? ''));
    $price = (float)(
        $p['preco']         ??  // v3
        $p['preco_venda']   ??  // v2
        0
    );
    // Os campos reais retornados pela API Tiny v3 sao estoque.quantidade e
    // estoque_disponivel (confirmado na resposta real de storage/products-cache-ativos.json).
    // saldoFisicoTotal/saldoEstoque nao existem nessa API -- por isso o campo
    // sempre caia no default 0 e zerava o estoque de TODOS os produtos no
    // catalogo espelhado, bloqueando toda venda no checkout.
    //
    // O fallback v2 (usado quando o OAuth v3 falha) nao tem nenhum desses
    // campos na listagem e nao faz fetch de detalhe por produto -- por isso
    // NUNCA retorna estoque real. Se tratassemos "sem campo" como estoque=0
    // aqui, toda vez que o OAuth v3 falhasse (o que ja aconteceu de forma
    // recorrente por token expirado) o catalogo inteiro seria zerado e
    // bloquearia checkout de TODOS os produtos, mesmo os com estoque real.
    // Por isso o fallback v2 retorna null (estoque desconhecido) em vez de 0,
    // e o merge em svs_mirror_catalog preserva o estoque anterior nesse caso.
    $stockRaw = $p['estoque_disponivel']          ??
        $p['estoque']['quantidade']       ??
        $p['estoque']['saldoFisicoTotal'] ??  // v3 (nome alternativo, mantido por seguranca)
        $p['saldoEstoque']                ??  // v2
        (is_scalar($p['estoque'] ?? null) ? $p['estoque'] : null) ??
        null;
    $stock = $stockRaw !== null ? (int)$stockRaw : ($source === 'tiny_v2' ? null : 0);

    // imagens
    $images = [];
    if (!empty($p['imagens']) && is_array($p['imagens'])) {
        foreach ($p['imagens'] as $img) {
            $u = is_array($img) ? ($img['url'] ?? $img['link'] ?? '') : (string)$img;
            if ($u !== '') $images[] = $u;
        }
    }
    $primaryImage = $images[0]
        ?? (string)($p['imagemURL'] ?? $p['imagem'] ?? $p['foto'] ?? '');

    $description = trim((string)(
        $p['descricaoComplementar'] ??
        $p['obs']                   ??
        $p['observacoes']           ??
        ''
    ));

    $category = trim((string)(
        $p['categoria']['nome'] ??
        (is_string($p['categoria'] ?? null) ? $p['categoria'] : '') ??
        ''
    ));

    // SEO: usa o slug/titulo/descricao/keywords ja cadastrados na Tiny
    // (bloco 'seo' do detalhe do produto) quando existirem. Sem slug real
    // cadastrado, gera um a partir do nome+sku (mesmo algoritmo usado como
    // fallback em tempo real por catalogo.php/produto.php/index.php) --
    // mas agora persistido, entao a URL do produto fica estavel mesmo se
    // esse algoritmo mudar no futuro.
    $seo = is_array($p['seo'] ?? null) ? $p['seo'] : [];
    $slug = trim((string)($seo['slug'] ?? ''));
    if ($slug === '' && $name !== '') {
        $slug = svs_slugify($name, $sku);
    }
    $seoTitle = trim((string)($seo['titulo'] ?? ''));
    $seoDescription = trim((string)($seo['descricao'] ?? ''));
    $keywords = is_array($seo['keywords'] ?? null) ? array_values(array_filter(array_map('strval', $seo['keywords']))) : [];

    $marca = trim((string)($p['marca']['nome'] ?? ''));
    $gtin = trim((string)($p['gtin'] ?? ''));
    $ncm = trim((string)($p['ncm'] ?? ''));
    $unidade = trim((string)($p['unidade'] ?? ''));

    // Restante do schema oficial de "Obter produto" que ainda nao tinha
    // campo dedicado no catalogo espelhado -- ver comentario no loop de
    // fetch de detalhe pra lista completa da doc.
    $situacao = trim((string)($p['situacao'] ?? ''));
    $tipo = trim((string)($p['tipo'] ?? ''));
    $tipoVariacao = trim((string)($p['tipoVariacao'] ?? ''));
    $unidadePorCaixa = trim((string)($p['unidadePorCaixa'] ?? ''));
    $origem = trim((string)($p['origem'] ?? ''));
    $garantia = trim((string)($p['garantia'] ?? ''));
    $notes = trim((string)($p['observacoes'] ?? ''));
    $parentProduct = is_array($p['produtoPai'] ?? null) ? [
        'id' => (string)($p['produtoPai']['id'] ?? ''),
        'sku' => (string)($p['produtoPai']['sku'] ?? ''),
        'name' => (string)($p['produtoPai']['descricao'] ?? ''),
    ] : null;
    $prices = is_array($p['precos'] ?? null) ? [
        'price' => (float)($p['precos']['preco'] ?? $price),
        'promotional_price' => (float)($p['precos']['precoPromocional'] ?? 0),
        'cost_price' => (float)($p['precos']['precoCusto'] ?? 0),
        'avg_cost_price' => (float)($p['precos']['precoCustoMedio'] ?? 0),
    ] : null;
    $stockDetail = is_array($p['estoque'] ?? null) ? [
        'controlled' => (bool)($p['estoque']['controlar'] ?? true),
        'made_to_order' => (bool)($p['estoque']['sobEncomenda'] ?? false),
        'prep_days' => (int)($p['estoque']['diasPreparacao'] ?? 0),
        'location' => (string)($p['estoque']['localizacao'] ?? ''),
        'min' => (float)($p['estoque']['minimo'] ?? 0),
        'max' => (float)($p['estoque']['maximo'] ?? 0),
    ] : null;
    $dimensions = is_array($p['dimensoes'] ?? null) ? [
        'width' => (float)($p['dimensoes']['largura'] ?? 0),
        'height' => (float)($p['dimensoes']['altura'] ?? 0),
        'length' => (float)($p['dimensoes']['comprimento'] ?? 0),
        'diameter' => (float)($p['dimensoes']['diametro'] ?? 0),
        'net_weight' => (float)($p['dimensoes']['pesoLiquido'] ?? 0),
        'gross_weight' => (float)($p['dimensoes']['pesoBruto'] ?? 0),
        'volumes' => (int)($p['dimensoes']['quantidadeVolumes'] ?? 1),
    ] : null;
    $suppliers = [];
    foreach ((is_array($p['fornecedores'] ?? null) ? $p['fornecedores'] : []) as $supplier) {
        $supplierName = trim((string)($supplier['nome'] ?? ''));
        if ($supplierName !== '') $suppliers[] = $supplierName;
    }
    $taxation = is_array($p['tributacao'] ?? null) ? [
        'gtin_packaging' => (string)($p['tributacao']['gtinEmbalagem'] ?? ''),
        'ipi_fixed_value' => (float)($p['tributacao']['valorIPIFixo'] ?? 0),
        'ipi_class' => (string)($p['tributacao']['classeIPI'] ?? ''),
    ] : null;
    $kitItems = [];
    foreach ((is_array($p['kit'] ?? null) ? $p['kit'] : []) as $kitItem) {
        $kitSku = trim((string)($kitItem['produto']['sku'] ?? ''));
        $kitQty = (float)($kitItem['quantidade'] ?? 0);
        if ($kitSku !== '') $kitItems[] = ['sku' => $kitSku, 'quantity' => $kitQty];
    }

    // 'grade' das variacoes = atributos reais do produto (ex: cor=Azul,
    // tamanho=M) -- so existe quando o produto tem variacoes cadastradas.
    $attributes = [];
    foreach ((is_array($p['variacoes'] ?? null) ? $p['variacoes'] : []) as $variation) {
        foreach ((is_array($variation['grade'] ?? null) ? $variation['grade'] : []) as $pair) {
            $key = trim((string)($pair['chave'] ?? ''));
            $value = trim((string)($pair['valor'] ?? ''));
            if ($key !== '' && $value !== '' && !in_array("$key: $value", $attributes, true)) {
                $attributes[] = "$key: $value";
            }
        }
    }

    return [
        'olist_product_id' => $id,
        'sku'              => $sku,
        'name'             => $name,
        'price'            => $price,
        'stock'            => $stock,
        'image_url'        => $primaryImage,
        'images'           => $images,
        'description'      => $description,
        'category'         => $category,
        'slug'             => $slug,
        'seo_title'        => $seoTitle,
        'seo_description'  => $seoDescription,
        'keywords'         => $keywords,
        'brand'            => $marca,
        'gtin'             => $gtin,
        'ncm'              => $ncm,
        'unit'             => $unidade,
        'attributes'       => $attributes,
        'status'           => $situacao,
        'type'             => $tipo,
        'variation_type'   => $tipoVariacao,
        'unit_per_box'     => $unidadePorCaixa,
        'origin'           => $origem,
        'warranty'         => $garantia,
        'notes'            => $notes,
        'parent_product'   => $parentProduct,
        'prices'           => $prices,
        'stock_detail'     => $stockDetail,
        'dimensions'       => $dimensions,
        'suppliers'        => $suppliers,
        'taxation'         => $taxation,
        'kit_items'        => $kitItems,
        'sync_source'      => $source,
        'synced_at'        => date('c'),
    ];
}

function svs_slugify(string $name, string $sku): string {
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $base = strtr($lower, $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

/* ── Espelho: a lista final deve ser a lista ativa retornada pela Tiny/Olist ── */
function svs_catalog_key(array $product): string {
    $id = trim((string)($product['olist_product_id'] ?? $product['id'] ?? ''));
    if ($id !== '') return 'id:' . $id;
    $sku = trim((string)($product['sku'] ?? ''));
    return $sku !== '' ? 'sku:' . strtoupper($sku) : '';
}

function svs_existing_catalog_indexes(string $catalogPath): array {
    $byId = [];
    $bySku = [];
    $rows = [];
    if (is_file($catalogPath)) {
        $raw = json_decode((string)file_get_contents($catalogPath), true);
        if (is_array($raw)) {
            foreach ($raw as $p) {
                if (!is_array($p)) continue;
                $rows[] = $p;
                $id = trim((string)($p['olist_product_id'] ?? $p['id'] ?? ''));
                $sku = trim((string)($p['sku'] ?? ''));
                if ($id !== '') $byId[$id] = $p;
                if ($sku !== '') $bySku[strtoupper($sku)] = $p;
            }
        }
    }
    return ['rows' => $rows, 'by_id' => $byId, 'by_sku' => $bySku];
}

function svs_mirror_catalog(array $fetched, string $catalogPath): array {
    $existing = svs_existing_catalog_indexes($catalogPath);
    $mirrored = [];
    $seen = [];
    foreach ($fetched as $new) {
        if (!is_array($new)) continue;
        $key = svs_catalog_key($new);
        if ($key === '') continue;

        $id = trim((string)($new['olist_product_id'] ?? ''));
        $sku = trim((string)($new['sku'] ?? ''));
        $old = [];
        if ($id !== '' && isset($existing['by_id'][$id])) {
            $old = $existing['by_id'][$id];
        } elseif ($sku !== '' && isset($existing['by_sku'][strtoupper($sku)])) {
            $old = $existing['by_sku'][strtoupper($sku)];
        }

        $merged = array_merge($old, $new);

        // Preservar enriquecimentos locais quando a API nao traz esses campos.
        foreach (['slug', 'quality_score', 'quality_label', 'tags', 'images_count'] as $f) {
            if (isset($old[$f]) && ($new[$f] ?? '') === '') {
                $merged[$f] = $old[$f];
            }
        }

        foreach (['category', 'description', 'image_url'] as $f) {
            if (isset($old[$f]) && trim((string)($new[$f] ?? '')) === '') {
                $merged[$f] = $old[$f];
            }
        }

        if (((float)($new['price'] ?? 0)) <= 0 && isset($old['price'])) {
            $merged['price'] = $old['price'];
        }

        // stock=null significa "fonte nao sabe" (fallback v2 sem dado real) --
        // preserva o estoque anterior em vez de zerar o catalogo inteiro.
        if (array_key_exists('stock', $new) && $new['stock'] === null) {
            $merged['stock'] = (int)($old['stock'] ?? 0);
        }

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $mirrored[] = $merged;
        }
    }

    return $mirrored;
}

function svs_removed_products(array $mirrored, string $catalogPath): array {
    $existing = svs_existing_catalog_indexes($catalogPath)['rows'];
    $live = [];
    foreach ($mirrored as $product) {
        $key = svs_catalog_key($product);
        if ($key !== '') $live[$key] = true;
    }

    $removed = [];
    foreach ($existing as $product) {
        $key = svs_catalog_key($product);
        if ($key !== '' && !isset($live[$key])) {
            $removed[] = [
                'olist_product_id' => (string)($product['olist_product_id'] ?? $product['id'] ?? ''),
                'sku' => (string)($product['sku'] ?? ''),
                'name' => (string)($product['name'] ?? ''),
            ];
        }
    }
    return $removed;
}

/* ════════════════════════ MAIN ════════════════════════ */
$dryRun  = isset($_GET['dry_run']) && $_GET['dry_run'] !== '0';
$errors  = [];
$fetched = [];
$source  = 'none';

// Tiny v3 OAuth -- unico caminho suportado. A API v2 legada (token estatico,
// api2/produtos.pesquisa.php) foi removida em 2026-07-18: alem de obsoleta,
// o schema dela usa terminologia de "pedido ecommerce" que nao corresponde
// a como este projeto realmente integra com o Tiny (venda direta do site,
// nao um canal de marketplace).
try {
    $token   = svs_get_access_token();
    $raw     = svs_fetch_v3($token);
    $source  = 'tiny_v3';
    foreach ($raw as $p) { $fetched[] = svs_normalize($p, $source); }
    svs_log("v3 sync: " . count($fetched) . ' produtos');
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    svs_log('v3 error: ' . $e->getMessage());
}

$catalogPath = svs_root() . '/api/catalog/fallback-products.json';
$beforeCount = 0;
if (is_file($catalogPath)) {
    $tmp = json_decode((string)file_get_contents($catalogPath), true);
    $beforeCount = is_array($tmp) ? count($tmp) : 0;
}

$catalog    = [];
$saved      = false;
$afterCount = $beforeCount;
$removedProducts = [];

if (count($fetched) > 0) {
    $catalog    = svs_mirror_catalog($fetched, $catalogPath);
    $afterCount = count($catalog);
    $removedProducts = svs_removed_products($catalog, $catalogPath);
}

if (count($fetched) > 0 && !$dryRun) {
    $tmp        = $catalogPath . '.tmp';
    if (file_put_contents($tmp, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false) {
        rename($tmp, $catalogPath);
        $saved = true;
        svs_log("Catálogo espelhado: $beforeCount → $afterCount produtos; removidos=" . count($removedProducts));

        // gravar log de sync para admin
        $syncLog = svs_root() . '/logs/olist-sync-history.jsonl';
        @file_put_contents($syncLog, json_encode([
            'ts'      => date('c'),
            'source'  => $source,
            'before'  => $beforeCount,
            'after'   => $afterCount,
            'fetched' => count($fetched),
            'removed' => count($removedProducts),
            'errors'  => $errors,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    } else {
        $errors[] = 'catalog_write_failed';
    }
}

$ok = count($fetched) > 0 && ($dryRun || $saved);
svs_json($ok ? 200 : 207, [
    'ok'           => $ok,
    'source'       => $source,
    'fetched'      => count($fetched),
    'before_count' => $beforeCount,
    'after_count'  => $afterCount,
    'removed_count'=> count($removedProducts),
    'removed_sample'=> array_slice($removedProducts, 0, 50),
    'dry_run'      => $dryRun,
    'saved'        => $saved,
    'errors'       => $errors,
    'synced_at'    => date('c'),
]);
