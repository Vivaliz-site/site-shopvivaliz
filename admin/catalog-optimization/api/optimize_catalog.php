<?php

declare(strict_types=1);

/**
 * Core do Admin -> Otimizacao de Cadastro.
 *
 * Regras absolutas:
 * - preco e estoque nao sao lidos, enviados a IA ou gravados por este modulo;
 * - toda saida precisa ser factual e rastreavel aos dados do produto;
 * - cada canal possui politica propria de titulo, descricao, atributos e SEO;
 * - saida fora da politica e rejeitada antes de entrar em staging.
 */

require_once __DIR__ . '/../config_optimization.php';
require_once __DIR__ . '/../src/TextAiServices.php';

function ai_catalog_scalar(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = $row[$key];
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function ai_catalog_structured_text(mixed $value): string
{
    if (is_array($value)) {
        return trim((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return trim((string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $text;
}

/**
 * Busca somente campos editoriais/identificadores. Preco e estoque nao sao
 * copiados para o array retornado, mesmo que existam na linha do banco.
 *
 * @return array<string,string>|null
 */
function ai_catalog_fetch_product(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $name = ai_catalog_scalar($row, ['name', 'nome', 'descricao']);
    if ($name === '') {
        return null;
    }

    $product = [
        'name' => $name,
        'description' => ai_catalog_scalar($row, ['description', 'descricao_completa', 'descricaoComplementar', 'descricao_complementar']),
        'category' => ai_catalog_scalar($row, ['category', 'categoria', 'category_name', 'nome_categoria']),
        'brand' => ai_catalog_scalar($row, ['brand', 'marca', 'manufacturer', 'fabricante']),
        'model' => ai_catalog_scalar($row, ['model', 'modelo', 'part_number', 'mpn']),
        'sku' => ai_catalog_scalar($row, ['sku', 'codigo', 'codigo_sku']),
        'gtin' => ai_catalog_scalar($row, ['gtin', 'ean', 'ean13', 'barcode', 'codigo_barras']),
        'color' => ai_catalog_scalar($row, ['color', 'cor']),
        'size' => ai_catalog_scalar($row, ['size', 'tamanho']),
        'material' => ai_catalog_scalar($row, ['material']),
        'specs' => ai_catalog_structured_text($row['especificacoes_tecnicas'] ?? $row['especificacoes'] ?? $row['specifications'] ?? $row['ficha_tecnica'] ?? ''),
        'olist_id' => ai_catalog_scalar($row, ['olist_id']),
    ];

    // Enriquecimento factual via Olist/Tiny. Nao existe fallback de marca.
    if ($product['olist_id'] !== '') {
        try {
            $enrich = $db->prepare('SELECT * FROM olist_products WHERE CAST(olist_id AS CHAR) = ? LIMIT 1');
            $enrich->execute([$product['olist_id']]);
            $olist = $enrich->fetch(PDO::FETCH_ASSOC);
            if (is_array($olist)) {
                if ($product['category'] === '') {
                    $product['category'] = ai_catalog_scalar($olist, ['categoria', 'category', 'category_name']);
                }
                if ($product['brand'] === '') {
                    $product['brand'] = ai_catalog_scalar($olist, ['marca', 'brand', 'manufacturer', 'fabricante']);
                }
                if ($product['model'] === '') {
                    $product['model'] = ai_catalog_scalar($olist, ['modelo', 'model', 'part_number', 'mpn']);
                }
                if ($product['gtin'] === '') {
                    $product['gtin'] = ai_catalog_scalar($olist, ['gtin', 'ean', 'codigo_barras']);
                }
                if ($product['sku'] === '') {
                    $product['sku'] = ai_catalog_scalar($olist, ['sku', 'codigo']);
                }
            }
        } catch (Throwable $e) {
            error_log('[catalog-optimization] enriquecimento Olist indisponivel para produto #' . $productId . ': ' . $e->getMessage());
        }
    }

    return $product;
}

/** @return array<string,mixed> */
function ai_catalog_policy(string $channel): array
{
    return match ($channel) {
        'ml' => [
            'title_max' => 60,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Mercado Livre (Brasil).
Priorize qualidade de catalogo e atributos, nao copy promocional.
- Titulo, quando o fluxo do item ainda exigir titulo: Produto + Marca + Modelo + especificacao realmente identificadora. Use 60 caracteres como teto conservador somente quando o max_title_length especifico da categoria nao estiver disponivel. Sem preco, parcelas, frete, desconto, emojis ou chamadas promocionais.
- No modelo User Products, o titulo pode deixar de ser enviado; nesse caso priorize marca, modelo, GTIN/MPN, variacao e atributos de identidade, porque o produto e sua familia sao definidos por dados estruturados.
- Descricao: objetiva, factual e util para reduzir duvidas. Explique uso e compatibilidade somente se estiverem nos dados de origem. Nao inclua contato externo.
- bullet_points: 3 a 5 fatos complementares.
- seo_keywords: consultas de busca plausiveis derivadas de produto, categoria, marca, modelo e atributos existentes; sem stuffing.
- marketing_hooks: [].
TXT,
        ],
        'shopee' => [
            'title_max' => 120,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Shopee Brasil.
- Titulo: claro e pesquisavel, priorizando tipo de produto, marca/modelo quando existirem e 1-3 atributos decisivos. Use 120 caracteres como alvo operacional de qualidade, nao como pretexto para preencher texto desnecessario. Nao use escassez artificial, preco, desconto, frete, cupom ou emojis decorativos.
- Descricao: leitura mobile, paragrafos curtos, 3 a 5 pontos escaneaveis e especificacoes reais. Nao prometa garantia, originalidade, certificacao, prazo ou suporte se a origem nao comprovar.
- bullet_points: 3 a 5 fatos de decisao de compra.
- seo_keywords: termos internos de descoberta derivados dos dados reais; sem marcas de terceiros para capturar trafego.
- marketing_hooks: no maximo 2 frases factuais para criativo, sem urgencia falsa.
TXT,
        ],
        'amazon' => [
            'title_max' => 200,
            'bullets_min' => 5,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Amazon.
- Titulo: obedeca ao limite oficial atual de ate 200 caracteres para a maioria das categorias, mas prefira 80-150 quando isso mantiver todos os identificadores e atributos decisivos. Estrutura preferida: Marca + tipo de produto + modelo/linha + diferenciador factual. Sem emojis, ALL CAPS, preco, promocao ou caracteres promocionais. Nao repetir a mesma palavra mais de duas vezes, exceto artigos/preposicoes/conjuncoes.
- Descricao: tecnica, objetiva e factual; nao simule A+ nem certificacoes inexistentes.
- bullet_points: exatamente 5. Cada bullet deve unir um fato/atributo real a sua utilidade direta, sem superlativos nao comprovados.
- seo_keywords: termos de busca complementares, evitando repeticao mecanica do titulo e sem inventar aplicacoes.
- marketing_hooks: use 0 ou 1 Item Highlight factual, com ate 125 caracteres; nunca urgencia/promocao.
TXT,
        ],
        'tiktok' => [
            'title_min' => 25,
            'title_max' => 200,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: TikTok Shop.
- Titulo: deve ficar entre 25 e 200 caracteres; como alvo de performance, prefira 40-150 caracteres. Inclua apenas marca autorizada quando existir, tipo de produto, aplicacao real e caracteristicas factuais relevantes. Nao mencione estoque, desconto, inventario, variantes irrelevantes ou claims subjetivos.
- Descricao: detalhada e facil de escanear. Mire 500+ caracteres quando os dados de origem fornecerem fatos suficientes; nunca alongue inventando ou repetindo. Organize 3 a 5 selling points curtos e comprovados.
- bullet_points: 3 a 5 pontos, cada um com menos de 250 caracteres.
- seo_keywords: termos de descoberta e categoria; hashtags somente se diretamente relevantes e sem claims.
- marketing_hooks: exatamente 3 ganchos curtos para video/live, todos factuais. Nao use falsa urgencia, medo, escassez ou promessa nao comprovada.
TXT,
        ],
        'site' => [
            'title_max' => 70,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Site Proprio ShopVivaliz.
- Titulo: SEO sem keyword stuffing, ate 70 caracteres, preservando marca/modelo/variacao quando existirem.
- Descricao: resposta direta no primeiro paragrafo, seguida de fatos, aplicacoes comprovadas e especificacoes. Otimize para leitura humana, busca e mecanismos de resposta/IA sem criar dores, beneficios ou promessas inexistentes.
- bullet_points: 3 a 5 criterios objetivos de decisao.
- seo_keywords: long-tail factual e semantica, baseada em produto/categoria/atributos.
- meta_title: ate 60 caracteres, unico e descritivo.
- meta_description: ate 160 caracteres, descritiva e convidativa sem promessa falsa.
- marketing_hooks: 0 a 2 frases factuais.
TXT,
        ],
        'erp' => [
            'title_max' => 120,
            'bullets_min' => 0,
            'bullets_max' => 8,
            'instructions' => <<<'TXT'
Canal: Olist / Tiny ERP (cadastro tecnico interno).
- Titulo: nomenclatura tecnica e padronizada; tipo + marca/modelo + variacao quando existirem. Ate 120 caracteres.
- Descricao: somente dados tecnicos e identificadores relevantes; sem linguagem comercial.
- bullet_points: especificacoes tecnicas factuais, ou [] se nao houver dados suficientes.
- seo_keywords: [].
- marketing_hooks: [].
- meta_title e meta_description: derivados tecnicamente do titulo/descricao apenas para manter o contrato de staging; nao adicionar SEO promocional.
TXT,
        ],
        default => throw new CatalogAiApiException("Canal invalido: '$channel'."),
    };
}

function ai_catalog_build_system_prompt(string $channel): string
{
    $policy = ai_catalog_policy($channel);
    $instructions = $policy['instructions'];

    return <<<TXT
Voce e um especialista senior em catalogo de e-commerce, SEO e qualidade de dados. Trabalhe em portugues do Brasil.

$instructions

REGRAS GLOBAIS INEGOCIAVEIS:
1. Use somente fatos presentes nos dados de origem. Nao invente marca, modelo, GTIN/EAN, material, cor, tamanho, compatibilidade, certificacao, garantia, autenticidade, desempenho, durabilidade ou aplicacao.
2. Preco e estoque sao campos protegidos e fora do escopo. Nao os mencione, estime, corrija, recomende ou inferira.
3. Nao invente urgencia, escassez, promocao, ranking, avaliacao, numero de vendas ou prova social.
4. Se um dado importante estiver ausente, omita-o em vez de preencher com algo generico.
5. Preserve exatamente numeros de modelo, SKU/MPN, GTIN/EAN, medidas e variantes quando forem fornecidos.
6. Evite keyword stuffing, repeticao e marcas de terceiros que nao constem na origem.

Responda ESTRITA e EXCLUSIVAMENTE com JSON valido, sem markdown, contendo exatamente estas chaves:
{
  "optimized_title": "string",
  "optimized_description": "string",
  "bullet_points": ["string"],
  "seo_keywords": ["string"],
  "marketing_hooks": ["string"],
  "meta_title": "string",
  "meta_description": "string"
}
TXT;
}

function ai_catalog_build_user_prompt(array $product, string $channel): string
{
    // O Admin reutiliza esta funcao na acao "regenerate". Guardamos o
    // contexto factual da ultima geracao nesta requisicao para que uma
    // chamada legada ai_catalog_validate_ai_response($data) sem argumentos
    // adicionais continue recebendo o MESMO quality gate especifico do canal.
    $GLOBALS['ai_catalog_validation_context'] = [
        'channel' => $channel,
        'product' => $product,
    ];

    $channelLabel = catalog_ai_channels()[$channel] ?? $channel;
    $fields = [
        'Nome atual' => $product['name'] ?? '',
        'Descricao atual' => $product['description'] ?? '',
        'Categoria' => $product['category'] ?? '',
        'Marca' => $product['brand'] ?? '',
        'Modelo/MPN' => $product['model'] ?? '',
        'SKU' => $product['sku'] ?? '',
        'GTIN/EAN' => $product['gtin'] ?? '',
        'Cor' => $product['color'] ?? '',
        'Tamanho' => $product['size'] ?? '',
        'Material' => $product['material'] ?? '',
        'Especificacoes' => $product['specs'] ?? '',
    ];

    $lines = ["Canal de destino: {$channelLabel}", '', 'DADOS FACTUAIS DO PRODUTO:'];
    foreach ($fields as $label => $value) {
        $value = trim((string) $value);
        $lines[] = $label . ': ' . ($value !== '' ? $value : '(nao informado)');
    }
    $lines[] = '';
    $lines[] = 'Otimize o cadastro respeitando integralmente a politica do canal e sem criar nenhum fato ausente.';

    return implode("\n", $lines);
}

function ai_catalog_text_blob(array $data): string
{
    $parts = [
        (string) ($data['optimized_title'] ?? ''),
        (string) ($data['optimized_description'] ?? ''),
        (string) ($data['meta_title'] ?? ''),
        (string) ($data['meta_description'] ?? ''),
    ];
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        foreach ((array) ($data[$key] ?? []) as $value) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }
    }
    return implode("\n", $parts);
}

function ai_catalog_source_blob(array $product): string
{
    return mb_strtolower(implode("\n", array_map('strval', array_filter($product, 'is_scalar'))), 'UTF-8');
}

function ai_catalog_has_emoji(string $text): bool
{
    return preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text) === 1;
}

/** @return array{score:int,checks:array<string,bool>} */
function ai_catalog_quality_report(array $data, string $channel, array $product): array
{
    $policy = ai_catalog_policy($channel);
    $title = trim((string) ($data['optimized_title'] ?? ''));
    $description = trim((string) ($data['optimized_description'] ?? ''));
    $bullets = array_values(array_filter((array) ($data['bullet_points'] ?? []), 'is_string'));
    $text = ai_catalog_text_blob($data);
    $source = ai_catalog_source_blob($product);

    $checks = [];
    $titleLength = mb_strlen($title, 'UTF-8');
    $checks['title_length_max'] = $titleLength <= (int) $policy['title_max'];
    if (isset($policy['title_min'])) {
        $checks['title_length_min'] = $titleLength >= (int) $policy['title_min'];
    }
    $checks['description_present'] = $description !== '';
    $checks['protected_commerce_fields_absent'] = preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', $text) !== 1;
    $checks['bullet_count'] = count($bullets) >= (int) $policy['bullets_min'] && count($bullets) <= (int) $policy['bullets_max'];
    $checks['meta_title_length'] = mb_strlen((string) ($data['meta_title'] ?? ''), 'UTF-8') <= 70;
    $checks['meta_description_length'] = mb_strlen((string) ($data['meta_description'] ?? ''), 'UTF-8') <= 160;

    $claimPatterns = [
        'garantia' => '/\bgarantia\b/iu',
        'original' => '/\b(?:100%\s*)?(?:original|aut[eê]ntic[oa])\b/iu',
        'certified' => '/\b(?:certificad[oa]|homologad[oa])\b/iu',
        'ranking' => '/\b(?:mais\s+vendid[oa]|n[uú]mero\s*1|melhor\s+do\s+mercado)\b/iu',
    ];
    foreach ($claimPatterns as $name => $pattern) {
        $generatedHas = preg_match($pattern, $text) === 1;
        $sourceHas = preg_match($pattern, $source) === 1;
        $checks['claim_' . $name . '_sourced'] = !$generatedHas || $sourceHas;
    }

    $brand = trim((string) ($product['brand'] ?? ''));
    if ($brand !== '' && in_array($channel, ['ml', 'amazon'], true)) {
        $checks['brand_preserved_in_title'] = mb_stripos($title, $brand, 0, 'UTF-8') !== false;
    }

    if (in_array($channel, ['ml', 'amazon', 'erp'], true)) {
        $checks['title_without_emoji'] = !ai_catalog_has_emoji($title);
    }

    if ($channel === 'amazon') {
        $checks['amazon_disallowed_chars_absent'] = preg_match('/[!$?_{}^¬¦]/u', $title) !== 1;
        $words = preg_split('/\s+/u', mb_strtolower($title, 'UTF-8')) ?: [];
        $ignore = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'com', 'para', 'a', 'o'];
        $counts = [];
        foreach ($words as $word) {
            $word = trim($word, " ,.;:/()[]-+");
            if ($word === '' || in_array($word, $ignore, true)) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        $checks['amazon_word_repetition'] = max($counts ?: [0]) <= 2;
    }

    if ($channel === 'tiktok') {
        $checks['tiktok_bullet_length'] = array_reduce($bullets, fn(bool $ok, string $b): bool => $ok && mb_strlen($b, 'UTF-8') < 250, true);
        $checks['tiktok_hooks_count'] = count((array) ($data['marketing_hooks'] ?? [])) === 3;
    }

    if ($channel === 'erp') {
        $checks['erp_no_marketing_hooks'] = (array) ($data['marketing_hooks'] ?? []) === [];
        $checks['erp_no_seo_keywords'] = (array) ($data['seo_keywords'] ?? []) === [];
    }

    $passed = count(array_filter($checks));
    $score = $checks === [] ? 0 : (int) round(($passed / count($checks)) * 100);
    return ['score' => $score, 'checks' => $checks];
}

/**
 * @param array<string,mixed> $data
 * @param array<string,string> $product
 */
function ai_catalog_validate_ai_response(array $data, string $channel = '', array $product = []): void
{
    foreach (['optimized_title', 'optimized_description', 'meta_title', 'meta_description'] as $key) {
        if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new CatalogAiApiException("Resposta da IA invalida em '$key'.");
        }
    }
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            throw new CatalogAiApiException("Resposta da IA invalida em '$key'.");
        }
        foreach ($data[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new CatalogAiApiException("Resposta da IA contem valor invalido em '$key'.");
            }
        }
    }

    if (preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', ai_catalog_text_blob($data)) === 1) {
        throw new CatalogAiApiException('A IA tentou incluir preco, estoque ou condicao comercial protegida.');
    }

    // Compatibilidade segura com o fluxo de regeneracao do admin_catalog.php:
    // ele historicamente chamava o validador sem canal/produto. O contexto
    // foi registrado por ai_catalog_build_user_prompt() imediatamente antes
    // da chamada ao provider. Se houver contexto, o quality gate especifico
    // do marketplace e obrigatorio; nao existe downgrade silencioso.
    if ($channel === '') {
        $context = $GLOBALS['ai_catalog_validation_context'] ?? null;
        if (is_array($context)
            && is_string($context['channel'] ?? null)
            && is_array($context['product'] ?? null)
        ) {
            $channel = (string) $context['channel'];
            $product = $context['product'];
        }
    }

    if ($channel === '') {
        return;
    }

    $report = ai_catalog_quality_report($data, $channel, $product);
    $GLOBALS['ai_catalog_last_quality_report'] = $report;
    $failed = array_keys(array_filter($report['checks'], fn(bool $ok): bool => !$ok));
    if ($failed !== []) {
        throw new CatalogAiApiException('Saida reprovada pela politica de qualidade: ' . implode(', ', $failed));
    }
}

function ai_catalog_insert_staging_row(
    PDO $db,
    int $productId,
    string $channel,
    string $providerUsed,
    array $data,
    string $status = 'pending',
    ?string $errorMessage = null,
    array $quality = []
): int {
    $bulletPointsJson = json_encode($data['bullet_points'] ?? [], JSON_UNESCAPED_UNICODE);
    $seoKeywords = is_array($data['seo_keywords'] ?? null) ? implode(', ', $data['seo_keywords']) : '';
    $marketingHooks = is_array($data['marketing_hooks'] ?? null) ? implode(' | ', $data['marketing_hooks']) : '';
    $metaDataJson = json_encode([
        'meta_title' => $data['meta_title'] ?? '',
        'meta_description' => $data['meta_description'] ?? '',
        'quality_score' => $quality['score'] ?? null,
        'quality_checks' => $quality['checks'] ?? [],
        'policy_version' => 'marketplace-premium-2026-08',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare(
        'INSERT INTO catalog_optimizations_staging
            (product_id, channel, provider_used, optimized_title, optimized_description, bullet_points_json, seo_keywords, marketing_hooks, meta_data_json, status, error_message, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $productId,
        $channel,
        $providerUsed,
        (string) ($data['optimized_title'] ?? ''),
        (string) ($data['optimized_description'] ?? ''),
        $bulletPointsJson !== false ? $bulletPointsJson : '[]',
        $seoKeywords,
        $marketingHooks,
        $metaDataJson !== false ? $metaDataJson : '{}',
        $status,
        $errorMessage,
    ]);

    return (int) $db->lastInsertId();
}

/** @return array<string,mixed> */
function ai_catalog_process_item(PDO $db, int $productId, string $channel, string $provider): array
{
    $channel = strtolower(trim($channel));
    $provider = strtolower(trim($provider));

    if (!array_key_exists($channel, catalog_ai_channels())) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Canal invalido: '$channel'."];
    }
    if (!in_array($provider, ['openai', 'gemini', 'claude'], true)) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Provider invalido: '$provider'."];
    }

    $product = ai_catalog_fetch_product($db, $productId);
    if ($product === null) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Produto #$productId nao encontrado ou sem nome."];
    }

    try {
        $data = catalog_ai_make_provider($provider)->complete(
            ai_catalog_build_system_prompt($channel),
            ai_catalog_build_user_prompt($product, $channel)
        );
        ai_catalog_validate_ai_response($data, $channel, $product);
        $quality = ai_catalog_quality_report($data, $channel, $product);
        $stagingId = ai_catalog_insert_staging_row($db, $productId, $channel, $provider, $data, 'pending', null, $quality);

        return [
            'success' => true,
            'product_id' => $productId,
            'channel' => $channel,
            'provider' => $provider,
            'staging_id' => $stagingId,
            'quality_score' => $quality['score'],
        ];
    } catch (Throwable $e) {
        error_log("[catalog-optimization] falha produto #$productId canal=$channel provider=$provider: " . $e->getMessage());
        try {
            $stagingId = ai_catalog_insert_staging_row(
                $db,
                $productId,
                $channel,
                $provider,
                [
                    'optimized_title' => '',
                    'optimized_description' => '',
                    'bullet_points' => [],
                    'seo_keywords' => [],
                    'marketing_hooks' => [],
                    'meta_title' => '',
                    'meta_description' => '',
                ],
                'failed',
                $e->getMessage()
            );
        } catch (Throwable) {
            $stagingId = 0;
        }

        return [
            'success' => false,
            'product_id' => $productId,
            'channel' => $channel,
            'provider' => $provider,
            'staging_id' => $stagingId,
            'error' => $e->getMessage(),
        ];
    }
}

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    require_once __DIR__ . '/../../../includes/admin-guard.php';
    header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        exit;
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $jsonInput = json_decode($rawBody, true);
    $input = is_array($jsonInput) ? $jsonInput : $_POST;
    $productId = (int) ($input['product_id'] ?? 0);
    $channel = (string) ($input['target_channel'] ?? $input['channel'] ?? '');
    $provider = (string) ($input['provider'] ?? '');

    if ($productId <= 0 || $channel === '' || $provider === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id, target_channel e provider sao obrigatorios.']);
        exit;
    }

    $db = catalog_ai_db();
    if (!$db instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
        exit;
    }

    echo json_encode(ai_catalog_process_item($db, $productId, $channel, $provider), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
