<?php

declare(strict_types=1);

/**
 * Perfis editoriais do Admin de catalogo.
 *
 * O objetivo deste arquivo e separar tres conceitos que antes apareciam
 * misturados na interface:
 * 1) campos que a IA gera;
 * 2) campos que o publisher realmente envia para cada canal;
 * 3) dados reais do anuncio/listing usados apenas para auditoria.
 *
 * Preco e estoque permanecem explicitamente fora deste modulo.
 */

/** @return array<string,array<string,mixed>> */
function sv_catalog_channel_profiles(): array
{
    return [
        'site' => [
            'label' => 'Site Proprio via ERP v3',
            'policy_version' => 'site-erp-v3-mirrored-2026-08',
            'field_map' => [
                'optimized_title' => ['label' => 'Titulo', 'target' => 'proposta -> PUT /public-api/v3/produtos/{id} -> descricao -> sync site', 'mode' => 'erp_mirrored'],
                'optimized_description' => ['label' => 'Descricao', 'target' => 'proposta -> PUT /public-api/v3/produtos/{id} -> descricaoComplementar/observacoes -> sync site', 'mode' => 'erp_mirrored'],
                'bullet_points' => ['label' => 'Bullet points', 'target' => 'incorporados em descricaoComplementar/observacoes no ERP -> sync site', 'mode' => 'erp_mirrored'],
                'seo_keywords' => ['label' => 'SEO keywords', 'target' => 'PUT /public-api/v3/produtos/{id} -> seo.keywords -> sync site', 'mode' => 'erp_mirrored'],
                'marketing_hooks' => ['label' => 'Marketing hooks', 'target' => 'incorporados em campo textual do ERP quando aprovados -> sync site', 'mode' => 'erp_mirrored'],
                'meta_title' => ['label' => 'Meta title', 'target' => 'PUT /public-api/v3/produtos/{id} -> seo.titulo -> sync site', 'mode' => 'erp_mirrored'],
                'meta_description' => ['label' => 'Meta description', 'target' => 'PUT /public-api/v3/produtos/{id} -> seo.descricao -> sync site', 'mode' => 'erp_mirrored'],
            ],
            'limits' => [
                'title_max' => 70,
                'title_recommended_min' => 45,
                'title_recommended_max' => 65,
                'meta_title_target' => 60,
                'meta_description_target' => 160,
            ],
            'seo_notes' => [
                'Titulo visivel, <title>, H1 e dados estruturados devem descrever o mesmo produto sem keyword stuffing.',
                'Product structured data e Merchant Center ajudam o Google a entender o produto; meta title/description sao apoio editorial, nao garantia de ranking.',
            ],
        ],
        'ml' => [
            'label' => 'Mercado Livre',
            'policy_version' => 'ml-user-products-2026-06',
            'field_map' => [
                'optimized_title' => ['label' => 'Titulo', 'target' => 'PUT /items/{id} -> title', 'mode' => 'direct'],
                'optimized_description' => ['label' => 'Descricao', 'target' => 'PUT /items/{id}/description -> plain_text', 'mode' => 'direct'],
                'bullet_points' => ['label' => 'Bullet points', 'target' => 'incorporados em plain_text', 'mode' => 'embedded'],
                'seo_keywords' => ['label' => 'SEO keywords', 'target' => 'nao existe campo externo; serve como apoio de pesquisa', 'mode' => 'internal'],
                'marketing_hooks' => ['label' => 'Marketing hooks', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_title' => ['label' => 'Meta title', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_description' => ['label' => 'Meta description', 'target' => 'nao publicado', 'mode' => 'internal'],
            ],
            'limits' => ['title_max' => 60],
            'seo_notes' => [
                'No fluxo legado, a estrutura recomendada e Produto + Marca + Modelo + especificacoes identificadoras, sem condicao comercial.',
                'No modelo User Products, atributos estruturados e identidade do produto ganham prioridade e o titulo pode deixar de ser enviado em novos fluxos.',
                'Atributos, categoria, GTIN/MPN, variacoes e familia devem ser auditados junto do texto; keywords soltas nao sao um campo de publicacao do Mercado Livre.',
            ],
        ],
        'shopee' => [
            'label' => 'Shopee',
            'policy_version' => 'shopee-open-platform-2026-08',
            'field_map' => [
                'optimized_title' => ['label' => 'Titulo', 'target' => 'update_item -> item_name', 'mode' => 'direct'],
                'optimized_description' => ['label' => 'Descricao', 'target' => 'update_item -> description', 'mode' => 'direct'],
                'bullet_points' => ['label' => 'Bullet points', 'target' => 'incorporados em description', 'mode' => 'embedded'],
                'seo_keywords' => ['label' => 'SEO keywords', 'target' => 'nao existe campo externo neste publisher', 'mode' => 'internal'],
                'marketing_hooks' => ['label' => 'Marketing hooks', 'target' => 'incorporados em description', 'mode' => 'embedded'],
                'meta_title' => ['label' => 'Meta title', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_description' => ['label' => 'Meta description', 'target' => 'nao publicado', 'mode' => 'internal'],
            ],
            'limits' => ['title_max' => 120],
            'seo_notes' => [
                'O titulo deve ser claro e pesquisavel; categoria, marca autorizada, atributos e variacoes precisam permanecer consistentes com o item real.',
                'Marcas de terceiros, claims enganosos e termos usados apenas para capturar trafego devem ser evitados.',
            ],
        ],
        'amazon' => [
            'label' => 'Amazon',
            'policy_version' => 'amazon-listings-items-2026-08',
            'field_map' => [
                'optimized_title' => ['label' => 'Titulo', 'target' => 'Listings Items -> item_name', 'mode' => 'direct'],
                'optimized_description' => ['label' => 'Descricao', 'target' => 'Listings Items -> product_description', 'mode' => 'direct'],
                'bullet_points' => ['label' => 'Bullet points', 'target' => 'Listings Items -> bullet_point', 'mode' => 'direct'],
                'seo_keywords' => ['label' => 'Search terms', 'target' => 'Listings Items -> generic_keyword', 'mode' => 'direct'],
                'marketing_hooks' => ['label' => 'Marketing hooks', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_title' => ['label' => 'Meta title', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_description' => ['label' => 'Meta description', 'target' => 'nao publicado', 'mode' => 'internal'],
            ],
            'limits' => ['title_max' => 200, 'bullet_count' => 5],
            'seo_notes' => [
                'Product Type Definitions e o productType real determinam quais atributos sao validos/obrigatorios.',
                'Identidade, bullets, descricao, material, modelo, cor/tamanho e search terms devem ser consistentes com o listing e sem repeticao artificial.',
            ],
        ],
        'tiktok' => [
            'label' => 'TikTok Shop',
            'policy_version' => 'tiktok-shop-br-2026-08',
            'field_map' => [
                'optimized_title' => ['label' => 'Titulo', 'target' => 'partial_edit -> title', 'mode' => 'direct'],
                'optimized_description' => ['label' => 'Descricao', 'target' => 'partial_edit -> description HTML', 'mode' => 'direct'],
                'bullet_points' => ['label' => 'Selling points', 'target' => 'incorporados em description HTML', 'mode' => 'embedded'],
                'seo_keywords' => ['label' => 'Discovery keywords', 'target' => 'nao existe campo externo neste publisher', 'mode' => 'internal'],
                'marketing_hooks' => ['label' => 'Hooks', 'target' => 'incorporados em description HTML', 'mode' => 'embedded'],
                'meta_title' => ['label' => 'Meta title', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_description' => ['label' => 'Meta description', 'target' => 'nao publicado', 'mode' => 'internal'],
            ],
            'limits' => ['title_max' => 300, 'title_recommended_min' => 40, 'title_recommended_max' => 150, 'description_recommended_min' => 300, 'bullet_max' => 250],
            'seo_notes' => [
                'Para BR, a API atual aceita titulo de ate 300 caracteres; qualidade e relevancia continuam mais importantes que preencher o limite.',
                'A descricao deve ser detalhada e escaneavel; a documentacao recomenda mais de 300 caracteres quando houver fatos suficientes e 3 a 5 selling points curtos.',
                'Categoria, brand_id e product_attributes sao dados estruturados do listing e devem ser auditados junto do texto.',
            ],
        ],
        'erp' => [
            'label' => 'Olist / Tiny ERP',
            'policy_version' => 'tiny-v3-catalog-2026-08',
            'field_map' => [
                'optimized_title' => ['label' => 'Descricao/nome', 'target' => 'PUT /produtos/{id} -> descricao', 'mode' => 'direct'],
                'optimized_description' => ['label' => 'Observacoes', 'target' => 'PUT /produtos/{id} -> observacoes', 'mode' => 'direct'],
                'bullet_points' => ['label' => 'Especificacoes', 'target' => 'incorporadas em observacoes', 'mode' => 'embedded'],
                'seo_keywords' => ['label' => 'SEO keywords', 'target' => 'seo.keywords', 'mode' => 'direct'],
                'marketing_hooks' => ['label' => 'Marketing hooks', 'target' => 'nao publicado', 'mode' => 'internal'],
                'meta_title' => ['label' => 'SEO titulo', 'target' => 'seo.titulo', 'mode' => 'direct'],
                'meta_description' => ['label' => 'SEO descricao', 'target' => 'seo.descricao', 'mode' => 'direct'],
                'site_attributes' => ['label' => 'Atributos Site', 'target' => 'atributos/metadados ERP para vitrine propria', 'mode' => 'embedded'],
                'ml_attributes' => ['label' => 'Atributos Mercado Livre', 'target' => 'atributos/metadados ERP para ML', 'mode' => 'embedded'],
                'shopee_attributes' => ['label' => 'Atributos Shopee', 'target' => 'atributos/metadados ERP para Shopee', 'mode' => 'embedded'],
                'amazon_attributes' => ['label' => 'Atributos Amazon', 'target' => 'atributos/metadados ERP para Amazon', 'mode' => 'embedded'],
                'tiktok_attributes' => ['label' => 'Atributos TikTok Shop', 'target' => 'atributos/metadados ERP para TikTok', 'mode' => 'embedded'],
            ],
            'limits' => ['title_max' => 120],
            'seo_notes' => [
                'O ERP e fonte tecnica: nomenclatura, SKU, GTIN, marca, categoria e identificadores devem permanecer consistentes.',
                'Quando o canal for ERP, consolide campos editoriais e atributos dos demais canais como metadados/atributos internos para reaproveitamento posterior.',
                'Preco e estoque nao sao lidos nem alterados pelo fluxo de otimizacao textual.',
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function sv_catalog_channel_profile(string $channel): array
{
    $profiles = sv_catalog_channel_profiles();
    return $profiles[$channel] ?? [
        'label' => $channel,
        'policy_version' => 'unknown',
        'field_map' => [],
        'limits' => [],
        'seo_notes' => [],
    ];
}

/** @return list<string> */
function sv_catalog_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $entry) {
        if (is_array($entry)) {
            $candidate = trim((string)($entry['value'] ?? $entry['name'] ?? $entry['value_name'] ?? ''));
        } else {
            $candidate = is_scalar($entry) ? trim((string)$entry) : '';
        }
        if ($candidate !== '') {
            $out[] = $candidate;
        }
    }
    return array_values(array_unique($out));
}

function sv_catalog_amazon_attribute(array $attributes, string $name): string
{
    return implode("\n", sv_catalog_string_list($attributes[$name] ?? []));
}

/** @return list<string> */
function sv_catalog_attribute_lines(mixed $attributes): array
{
    if (!is_array($attributes)) {
        return [];
    }
    $lines = [];
    foreach ($attributes as $attribute) {
        if (!is_array($attribute)) {
            continue;
        }
        $name = trim((string)($attribute['name'] ?? $attribute['attribute_name'] ?? $attribute['id'] ?? $attribute['attribute_id'] ?? 'Atributo'));
        $value = trim((string)($attribute['value_name'] ?? $attribute['value'] ?? $attribute['value_id'] ?? ''));
        if ($value === '' && is_array($attribute['attribute_value_list'] ?? null)) {
            $values = [];
            foreach ($attribute['attribute_value_list'] as $entry) {
                if (!is_array($entry)) continue;
                $v = trim((string)($entry['value'] ?? $entry['value_name'] ?? $entry['value_id'] ?? ''));
                if ($v !== '') $values[] = $v;
            }
            $value = implode(', ', array_values(array_unique($values)));
        }
        if ($value !== '') {
            $lines[] = $name . ': ' . $value;
        }
    }
    return array_values(array_unique($lines));
}

/**
 * Snapshot padronizado do cadastro REAL do canal. Somente campos editoriais,
 * identificadores e atributos de qualidade sao retornados. Preco/estoque nao
 * sao copiados para o snapshot.
 *
 * @param array{name:string,description:string,sku:string,image_url:string,olist_id:string} $local
 * @return array{title:string,description:string,bullet_points:list<string>,seo_keywords:list<string>,marketing_hooks:list<string>,meta_title:string,meta_description:string,source:string,warning:string,details:array<string,string>}
 */
function sv_catalog_channel_snapshot(PDO $db, int $productId, string $channel, array $local): array
{
    $fallback = [
        'title' => (string)$local['name'],
        'description' => (string)$local['description'],
        'bullet_points' => [],
        'seo_keywords' => [],
        'marketing_hooks' => [],
        'meta_title' => '',
        'meta_description' => '',
        'source' => 'Cadastro local',
        'warning' => '',
        'details' => [
            'SKU' => (string)$local['sku'],
            'Imagem principal' => (string)$local['image_url'],
        ],
    ];

    try {
        if ($channel === 'site') {
            $stmt = $db->prepare('SELECT * FROM product_channel_content WHERE product_id = ? AND channel = ? LIMIT 1');
            $stmt->execute([$productId, 'site']);
            $saved = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved)) {
                return $fallback;
            }
            return array_merge($fallback, [
                'title' => trim((string)($saved['title'] ?? $local['name'])),
                'description' => trim((string)($saved['description'] ?? $local['description'])),
                'bullet_points' => sv_catalog_string_list(json_decode((string)($saved['bullet_points_json'] ?? '[]'), true)),
                'seo_keywords' => sv_catalog_string_list(json_decode((string)($saved['seo_keywords_json'] ?? '[]'), true)),
                'marketing_hooks' => sv_catalog_string_list(json_decode((string)($saved['marketing_hooks_json'] ?? '[]'), true)),
                'meta_title' => trim((string)($saved['meta_title'] ?? '')),
                'meta_description' => trim((string)($saved['meta_description'] ?? '')),
                'source' => 'Banco/canal ShopVivaliz',
                'details' => array_merge($fallback['details'], [
                    'Conteudo por canal' => 'product_channel_content',
                    'Ultima publicacao' => trim((string)($saved['published_at'] ?? '')),
                ]),
            ]);
        }

        $mapping = sv_market_mapping($db, $productId, $channel);
        $externalId = trim((string)($mapping['external_id'] ?? ''));

        if ($channel === 'ml') {
            if ($externalId === '') {
                return array_merge($fallback, ['warning' => 'Mapeamento do anuncio Mercado Livre ainda nao confirmado.']);
            }
            $item = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($externalId));
            $description = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($externalId) . '/description');
            $attrs = sv_catalog_attribute_lines($item['attributes'] ?? []);
            return array_merge($fallback, [
                'title' => trim((string)($item['title'] ?? '')),
                'description' => trim((string)($description['plain_text'] ?? '')),
                'source' => 'API oficial Mercado Livre',
                'details' => [
                    'Item ID' => $externalId,
                    'Categoria' => trim((string)($item['category_id'] ?? '')),
                    'Family name' => trim((string)($item['family_name'] ?? '')),
                    'User product ID' => trim((string)($item['user_product_id'] ?? '')),
                    'Catalog product ID' => trim((string)($item['catalog_product_id'] ?? '')),
                    'Condicao' => trim((string)($item['condition'] ?? '')),
                    'Atributos estruturados' => implode("\n", $attrs),
                    'Imagens no canal' => (string)count(is_array($item['pictures'] ?? null) ? $item['pictures'] : []),
                ],
            ]);
        }

        if ($channel === 'shopee') {
            if ($externalId === '') {
                return array_merge($fallback, ['warning' => 'Mapeamento do item Shopee ainda nao confirmado.']);
            }
            $client = new SvShopeeClient();
            $response = $client->request('GET', '/api/v2/product/get_item_base_info', null, [
                'item_id_list' => $externalId,
                'need_complaint_policy' => 'false',
            ]);
            $item = $response['response']['item_list'][0] ?? [];
            $brand = $item['brand'] ?? [];
            $images = $item['image']['image_id_list'] ?? $item['image_info']['image_id_list'] ?? [];
            $attrs = sv_catalog_attribute_lines($item['attribute_list'] ?? $item['attributes'] ?? []);
            return array_merge($fallback, [
                'title' => trim((string)($item['item_name'] ?? '')),
                'description' => trim((string)($item['description'] ?? $item['description_info']['extended_description']['field_list'][0]['text'] ?? '')),
                'source' => 'API oficial Shopee',
                'details' => [
                    'Item ID' => $externalId,
                    'Categoria' => trim((string)($item['category_id'] ?? '')),
                    'Marca' => is_array($brand) ? trim((string)($brand['original_brand_name'] ?? $brand['brand_name'] ?? $brand['brand_id'] ?? '')) : trim((string)$brand),
                    'Status' => trim((string)($item['item_status'] ?? '')),
                    'Atributos estruturados' => implode("\n", $attrs),
                    'Imagens no canal' => (string)count(is_array($images) ? $images : []),
                ],
            ]);
        }

        if ($channel === 'amazon') {
            $sku = trim((string)$local['sku']);
            if ($sku === '') {
                return array_merge($fallback, ['warning' => 'SKU ausente para consultar a Amazon.']);
            }
            $client = new SvAmazonClient();
            $response = $client->request('GET', '/listings/2021-08-01/items/' . rawurlencode($client->sellerId()) . '/' . rawurlencode($sku), [
                'marketplaceIds' => $client->marketplaceId(),
                'includedData' => 'summaries,attributes,issues',
                'issueLocale' => 'pt_BR',
            ]);
            $data = $response['data'];
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $summaries = is_array($data['summaries'] ?? null) ? $data['summaries'] : [];
            $summary = is_array($summaries[0] ?? null) ? $summaries[0] : [];
            $issues = is_array($data['issues'] ?? null) ? $data['issues'] : [];
            return array_merge($fallback, [
                'title' => sv_catalog_amazon_attribute($attributes, 'item_name') ?: (string)$local['name'],
                'description' => sv_catalog_amazon_attribute($attributes, 'product_description') ?: (string)$local['description'],
                'bullet_points' => sv_catalog_string_list($attributes['bullet_point'] ?? []),
                'seo_keywords' => sv_catalog_string_list($attributes['generic_keyword'] ?? []),
                'source' => 'Amazon SP-API',
                'details' => [
                    'SKU' => $sku,
                    'Product type' => trim((string)($summary['productType'] ?? $data['productType'] ?? '')),
                    'Marca' => sv_catalog_amazon_attribute($attributes, 'brand'),
                    'Modelo' => sv_catalog_amazon_attribute($attributes, 'model_name') ?: sv_catalog_amazon_attribute($attributes, 'model_number'),
                    'Cor' => sv_catalog_amazon_attribute($attributes, 'color'),
                    'Tamanho' => sv_catalog_amazon_attribute($attributes, 'size'),
                    'Material' => sv_catalog_amazon_attribute($attributes, 'material'),
                    'Issues do listing' => (string)count($issues),
                ],
            ]);
        }

        if ($channel === 'tiktok') {
            if ($externalId === '') {
                return array_merge($fallback, ['warning' => 'Mapeamento do produto TikTok Shop ainda nao confirmado.']);
            }
            $client = new SvTikTokClient();
            $response = $client->request('GET', '/product/202309/products/' . rawurlencode($externalId));
            $product = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : $response['data'];
            $attrs = sv_catalog_attribute_lines($product['product_attributes'] ?? []);
            $images = is_array($product['main_images'] ?? null) ? $product['main_images'] : [];
            return array_merge($fallback, [
                'title' => trim((string)($product['title'] ?? '')),
                'description' => trim(strip_tags((string)($product['description'] ?? ''))),
                'source' => 'API oficial TikTok Shop',
                'details' => [
                    'Product ID' => $externalId,
                    'Categoria' => trim((string)($product['category_id'] ?? $product['category']['id'] ?? '')),
                    'Brand ID' => trim((string)($product['brand_id'] ?? $product['brand']['id'] ?? '')),
                    'Status' => trim((string)($product['status'] ?? $product['product_status'] ?? '')),
                    'Atributos estruturados' => implode("\n", $attrs),
                    'Imagens no canal' => (string)count($images),
                ],
            ]);
        }

        if ($channel === 'erp') {
            $externalId = $externalId !== '' ? $externalId : trim((string)$local['olist_id']);
            if ($externalId === '' || !svtop_tiny_credentials_configured()) {
                return array_merge($fallback, ['warning' => 'ID ou OAuth Olist/Tiny indisponivel para consulta oficial.']);
            }
            $token = svtop_tiny_get_token();
            $response = svtop_tiny_request('GET', '/produtos/' . rawurlencode($externalId), $token);
            $product = is_array($response['json']['produto'] ?? null) ? $response['json']['produto'] : ($response['json'] ?? []);
            $seo = is_array($product['seo'] ?? null) ? $product['seo'] : [];
            return array_merge($fallback, [
                'title' => trim((string)($product['descricao'] ?? $product['nome'] ?? '')),
                'description' => trim((string)($product['observacoes'] ?? $product['descricaoComplementar'] ?? '')),
                'seo_keywords' => sv_catalog_string_list($seo['keywords'] ?? []),
                'meta_title' => trim((string)($seo['titulo'] ?? '')),
                'meta_description' => trim((string)($seo['descricao'] ?? '')),
                'source' => 'API oficial Olist/Tiny',
                'details' => [
                    'Produto ID' => $externalId,
                    'SKU' => trim((string)($product['sku'] ?? $product['codigo'] ?? $local['sku'])),
                    'Marca' => trim((string)($product['marca'] ?? '')),
                    'Categoria' => is_array($product['categoria'] ?? null) ? trim((string)($product['categoria']['nome'] ?? $product['categoria']['id'] ?? '')) : trim((string)($product['categoria'] ?? '')),
                    'GTIN/EAN' => trim((string)($product['gtin'] ?? $product['ean'] ?? '')),
                ],
            ]);
        }
    } catch (Throwable $exception) {
        return array_merge($fallback, [
            'warning' => 'Nao foi possivel consultar todos os campos do canal: ' . $exception->getMessage(),
        ]);
    }

    return $fallback;
}
