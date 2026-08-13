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
require_once __DIR__ . '/../../../includes/marketplace/CatalogChannelProfile.php';

function ai_catalog_scalar(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = $row[$key];
        if (is_scalar($value)) {
            $value = trim((string)$value);
            if ($value !== '') return $value;
        }
    }
    return '';
}

function ai_catalog_structured_text(mixed $value): string
{
    if (is_array($value)) {
        return trim((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (!is_scalar($value)) return '';
    $text = trim((string)$value);
    if ($text === '') return '';
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return trim((string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $text;
}

function ai_catalog_fold_text(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($folded) && $folded !== '') {
        $text = $folded;
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** @return list<string> */
function ai_catalog_hype_prefixes(): array
{
    return [
        'chega de',
        'diga adeus',
        'adeus',
        'elimine de vez',
        'elimine',
        'transforme',
        'potencialize',
        'maximize',
        'imperdivel',
        'incrivel',
        'revolucionario',
        'ultimissima chance',
        'nao perca',
        'garanta ja',
        'corra',
    ];
}

/**
 * Remove somente chamadas promocionais do inicio de um texto ja dobrado.
 * O loop e intencional: nomes legados podem carregar mais de uma chamada
 * sequencial (ex.: "Imperdivel! Transforme ...").
 */
function ai_catalog_folded_without_hype_prefix(string $text): string
{
    $normalized = ai_catalog_fold_text($text);
    if ($normalized === '') return '';

    $prefixes = ai_catalog_hype_prefixes();
    usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    for ($round = 0; $round < 8; $round++) {
        $changed = false;
        foreach ($prefixes as $prefix) {
            if ($normalized === $prefix) {
                $normalized = '';
                $changed = true;
                break;
            }
            $needle = $prefix . ' ';
            if (str_starts_with($normalized, $needle)) {
                $normalized = trim(substr($normalized, strlen($needle)));
                $changed = true;
                break;
            }
        }
        if (!$changed || $normalized === '') break;
    }
    return $normalized;
}

/** @return list<string> */
function ai_catalog_identity_candidates(array $product): array
{
    $candidates = [];
    foreach ([
        trim((string)($product['brand'] ?? '')),
        trim((string)($product['brand'] ?? '')) !== '' && trim((string)($product['model'] ?? '')) !== ''
            ? trim((string)$product['brand']) . ' ' . trim((string)$product['model'])
            : '',
        trim((string)($product['model'] ?? '')),
    ] as $candidate) {
        $candidate = ai_catalog_fold_text($candidate);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    // Nomes legados podem ter sido cadastrados com copy promocional antes da
    // identidade real. Esse preambulo nao faz parte da identidade factual e,
    // se mantido aqui, tornaria os gates "identidade primeiro" e "sem hype"
    // mutuamente impossiveis de satisfazer.
    $sourceName = ai_catalog_folded_without_hype_prefix((string)($product['name'] ?? ''));
    if ($sourceName !== '') {
        $tokens = preg_split('/\s+/u', $sourceName) ?: [];
        $picked = [];
        foreach ($tokens as $token) {
            if ($token === '') continue;
            $picked[] = $token;
            if (count($picked) >= 3) break;
        }
        if ($picked !== []) {
            $candidates[] = implode(' ', $picked);
        }
    }

    // Fallback estritamente factual para um cadastro excepcional cujo nome
    // inteiro seja apenas copy promocional e nao tenha marca/modelo.
    if ($candidates === []) {
        foreach (['sku', 'gtin'] as $key) {
            $candidate = ai_catalog_fold_text((string)($product[$key] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
                break;
            }
        }
    }

    return array_values(array_unique(array_filter($candidates, static fn(string $value): bool => $value !== '')));
}

function ai_catalog_title_starts_with_identity(string $title, array $product): bool
{
    $normalizedTitle = ai_catalog_fold_text($title);
    if ($normalizedTitle === '') return false;
    foreach (ai_catalog_identity_candidates($product) as $candidate) {
        if ($candidate !== '' && str_starts_with($normalizedTitle, $candidate)) {
            return true;
        }
    }
    return false;
}

function ai_catalog_title_has_hype_prefix(string $title): bool
{
    $normalized = ai_catalog_fold_text($title);
    if ($normalized === '') return false;
    foreach (ai_catalog_hype_prefixes() as $prefix) {
        if ($normalized === $prefix || str_starts_with($normalized, $prefix . ' ')) {
            return true;
        }
    }
    return preg_match('/[!?]/u', $title) === 1;
}

/** @return array<string,string>|null */
function ai_catalog_fetch_product(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;

    $name = ai_catalog_scalar($row, ['name', 'nome', 'descricao']);
    if ($name === '') return null;

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

    // Enriquecimento factual via Olist/Tiny, sem preco/estoque e sem fallback
    // inventado de marca/modelo.
    if ($product['olist_id'] !== '') {
        try {
            $enrich = $db->prepare('SELECT * FROM olist_products WHERE CAST(olist_id AS CHAR) = ? LIMIT 1');
            $enrich->execute([$product['olist_id']]);
            $olist = $enrich->fetch(PDO::FETCH_ASSOC);
            if (is_array($olist)) {
                if ($product['category'] === '') $product['category'] = ai_catalog_scalar($olist, ['categoria', 'category', 'category_name']);
                if ($product['brand'] === '') $product['brand'] = ai_catalog_scalar($olist, ['marca', 'brand', 'manufacturer', 'fabricante']);
                if ($product['model'] === '') $product['model'] = ai_catalog_scalar($olist, ['modelo', 'model', 'part_number', 'mpn']);
                if ($product['gtin'] === '') $product['gtin'] = ai_catalog_scalar($olist, ['gtin', 'ean', 'codigo_barras']);
                if ($product['sku'] === '') $product['sku'] = ai_catalog_scalar($olist, ['sku', 'codigo']);
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
- bullet_points: 3 a 5 fatos complementares que serao incorporados na descricao plain_text pelo publisher atual.
- seo_keywords: consultas de busca plausiveis derivadas de produto, categoria, marca, modelo e atributos existentes; servem como apoio interno porque o publisher atual nao envia um campo de keywords ao Mercado Livre.
- marketing_hooks: [].
TXT,
        ],
        'shopee' => [
            'title_max' => 120,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Shopee Brasil.
- Titulo: claro e pesquisavel, priorizando tipo de produto, marca/modelo quando existirem e 1-3 atributos decisivos. Use 120 caracteres como teto operacional; nao preencha espaco com repeticao. Nao use escassez artificial, preco, desconto, frete, cupom ou emojis decorativos.
- Descricao: leitura mobile, paragrafos curtos, 3 a 5 pontos escaneaveis e especificacoes reais. Nao prometa garantia, originalidade, certificacao, prazo ou suporte se a origem nao comprovar.
- bullet_points: 3 a 5 fatos de decisao de compra; o publisher incorpora esses pontos na descricao.
- seo_keywords: termos internos de descoberta derivados dos dados reais; o publisher atual nao envia campo externo de keywords.
- marketing_hooks: no maximo 2 frases factuais; o publisher incorpora os hooks na descricao, portanto evite repeticao.
TXT,
        ],
        'amazon' => [
            'title_max' => 200,
            'bullets_min' => 5,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Amazon.
- Titulo: ate 200 caracteres, mas prefira uma estrutura limpa com Marca + tipo de produto + modelo/linha + diferenciador factual. Sem emojis, ALL CAPS, preco, promocao ou caracteres promocionais. Nao repetir a mesma palavra mais de duas vezes, exceto artigos/preposicoes/conjuncoes.
- Descricao: tecnica, objetiva e factual; nao simule A+ nem certificacoes inexistentes.
- bullet_points: exatamente 5. Cada bullet deve unir um fato/atributo real a sua utilidade direta, sem superlativos nao comprovados.
- seo_keywords: search terms complementares; o publisher envia estes termos para generic_keyword. Evite repeticao mecanica do titulo e nao invente aplicacoes.
- marketing_hooks: use no maximo 1 destaque factual; este campo nao e publicado pelo publisher Amazon atual.
TXT,
        ],
        'tiktok' => [
            'title_min' => 1,
            'title_max' => 300,
            'title_recommended_min' => 40,
            'title_recommended_max' => 150,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: TikTok Shop Brasil.
- Titulo: entre 1 e 300 caracteres conforme o limite atual do canal; como alvo editorial, prefira 40-150 quando isso descrever o produto com clareza. Comece com a identidade principal do produto (tipo + marca/modelo quando existirem), sem chamada promocional antes da identificacao. Inclua apenas aplicacao real e caracteristicas factuais relevantes. Nao mencione estoque, desconto, inventario, variantes irrelevantes ou claims subjetivos.
- Descricao: detalhada e facil de escanear. Quando houver fatos suficientes, procure ultrapassar 300 caracteres sem alongar artificialmente. Organize 3 a 5 selling points curtos e comprovados.
- bullet_points: 3 a 5 pontos, cada um com menos de 250 caracteres. O publisher os incorpora no HTML da descricao.
- seo_keywords: termos internos de descoberta e categoria; o publisher atual nao envia um campo externo de keywords.
- marketing_hooks: 0 a 2 ganchos factuais e nao repetitivos para serem incorporados na descricao. Nao use falsa urgencia, medo, escassez ou promessa nao comprovada.
TXT,
        ],
        'site' => [
            'title_max' => 70,
            'bullets_min' => 3,
            'bullets_max' => 5,
            'instructions' => <<<'TXT'
Canal: Site Proprio ShopVivaliz.
- Titulo: SEO sem keyword stuffing, ate 70 caracteres, preservando marca/modelo/variacao quando existirem. Comece com a identidade principal do produto e evite frases promocionais antes do nome real do item.
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
    $GLOBALS['ai_catalog_validation_context'] = ['channel' => $channel, 'product' => $product];
    $channelLabel = catalog_ai_channels()[$channel] ?? $channel;
    $profile = sv_catalog_channel_profile($channel);
    $limits = is_array($profile['limits'] ?? null) ? $profile['limits'] : [];
    $fieldMap = is_array($profile['field_map'] ?? null) ? $profile['field_map'] : [];
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
    $lines = ["Canal de destino: {$channelLabel}", 'Objetivo editorial: gerar a melhor versao possivel para este canal especifico, sem reaproveitar um texto generico de outro marketplace.'];
    $brief = ai_catalog_channel_prompt_brief($product, $channel);
    if ($brief !== '') {
        $lines[] = $brief;
    }
    $factsBrief = ai_catalog_facts_brief($product);
    if ($factsBrief !== '') {
        $lines[] = $factsBrief;
    }
    if ($limits !== []) {
        $limitParts = [];
        foreach (['title_max', 'title_recommended_min', 'title_recommended_max', 'description_recommended_min', 'bullet_max'] as $key) {
            if (array_key_exists($key, $limits)) {
                $limitParts[] = $key . '=' . (string)$limits[$key];
            }
        }
        if ($limitParts !== []) {
            $lines[] = 'Alvos do canal: ' . implode(' | ', $limitParts);
        }
    }
    if ($fieldMap !== []) {
        $direct = [];
        $embedded = [];
        $internal = [];
        foreach ($fieldMap as $key => $field) {
            $label = trim((string)($field['label'] ?? $key));
            if ($label === '') {
                continue;
            }
            $mode = (string)($field['mode'] ?? 'internal');
            $target = trim((string)($field['target'] ?? ''));
            $text = $label . ($target !== '' ? ' -> ' . $target : '');
            if ($mode === 'direct') {
                $direct[] = $text;
            } elseif ($mode === 'embedded') {
                $embedded[] = $text;
            } else {
                $internal[] = $text;
            }
        }
        $lines[] = 'Campos deste canal:';
        $lines[] = 'Diretos/publicados como campo proprio: ' . ($direct !== [] ? implode(' | ', $direct) : 'nenhum');
        $lines[] = 'Incorporados em outro campo: ' . ($embedded !== [] ? implode(' | ', $embedded) : 'nenhum');
        $lines[] = 'Apoio interno/nao publicado diretamente: ' . ($internal !== [] ? implode(' | ', $internal) : 'nenhum');
        $lines[] = 'Nao transforme campo interno em promessa publicada. Quando um campo nao existir no canal, produza conteudo util para revisao interna ou deixe conservador.';
    }
    $lines[] = '';
    $lines[] = 'DADOS FACTUAIS DO PRODUTO:';
    foreach ($fields as $label => $value) {
        $value = trim((string)$value);
        $lines[] = $label . ': ' . ($value !== '' ? $value : '(nao informado)');
    }
    $lines[] = '';
    $lines[] = 'Otimize o cadastro respeitando integralmente a politica do canal e sem criar nenhum fato ausente.';
    return implode("\n", $lines);
}

function ai_catalog_text_blob(array $data): string
{
    $parts = [
        (string)($data['optimized_title'] ?? ''),
        (string)($data['optimized_description'] ?? ''),
        (string)($data['meta_title'] ?? ''),
        (string)($data['meta_description'] ?? ''),
    ];
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        foreach ((array)($data[$key] ?? []) as $value) if (is_scalar($value)) $parts[] = (string)$value;
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

/** @return list<string> */
function ai_catalog_soft_quality_checks(): array
{
    return ['title_within_recommended_range', 'description_length_min'];
}

function ai_catalog_category_focus_hint(string $category, string $channel): string
{
    $category = mb_strtolower(trim($category), 'UTF-8');
    if ($category === '') {
        return '';
    }

    if (preg_match('/\b(automot|ve[ií]culo|carro|moto|pneu|acess[oó]rio automotivo)\b/u', $category) === 1) {
        return 'Foco da ficha: compatibilidade, medidas, fixacao e identificacao tecnica, sem prometer encaixe se nao estiver nos dados.';
    }
    if (preg_match('/\b(cozinha|casa|organiz|banheiro|decor|higiene)\b/u', $category) === 1) {
        return 'Foco da ficha: uso domestico, dimensoes, acabamento e aplicacao pratica no ambiente.';
    }
    if (preg_match('/\b(eletr[oô]nic|gadget|informat|desktop|teclado|mouse|fone|audio)\b/u', $category) === 1) {
        return 'Foco da ficha: conectividade, compatibilidade, especificacoes e experiencia de uso real.';
    }
    if (preg_match('/\b(ferrament|suporte|porta|vedante|borracha|obra|constru)\b/u', $category) === 1) {
        return 'Foco da ficha: montagem, material, dimensoes e uso tecnico/instalacao.';
    }
    if (preg_match('/\b(beleza|cosm[eé]tic|perfum|higiene)\b/u', $category) === 1) {
        return 'Foco da ficha: conteudo, formula/apresentacao e modo de uso sem claims nao comprovados.';
    }

    return $channel === 'tiktok'
        ? 'Foco da ficha: leitura rapida do beneficio real e do uso pratico no mobile.'
        : 'Foco da ficha: identidade do produto, categoria e atributos decisivos.';
}

function ai_catalog_channel_prompt_brief(array $product, string $channel): string
{
    $brand = trim((string)($product['brand'] ?? ''));
    $model = trim((string)($product['model'] ?? ''));
    $category = trim((string)($product['category'] ?? ''));
    $sku = trim((string)($product['sku'] ?? ''));
    $gtin = trim((string)($product['gtin'] ?? ''));
    $parts = [];

    switch ($channel) {
        case 'ml':
            $parts[] = 'Estrutura preferida: tipo do produto + marca + modelo + atributo de identidade.';
            $parts[] = 'Se marca ou modelo estiverem ausentes, mantenha a identidade real sem preencher com generalidades.';
            break;
        case 'amazon':
            $parts[] = 'Estrutura preferida: marca + tipo do produto + modelo/linha + atributo factual que diferencie a ficha.';
            $parts[] = 'Bullets devem funcionar como leitura de especificacao, nao como copy promocional.';
            break;
        case 'shopee':
            $parts[] = 'Estrutura preferida: nome claro para mobile + marca/modelo quando existirem + atributo decisivo visivel no cadastro.';
            $parts[] = 'Otimize para descoberta rapida no app, mas sem inflar com repeticao ou exagero comercial.';
            break;
        case 'tiktok':
            $parts[] = 'Estrutura preferida: identidade do produto no primeiro bloco + atributo principal + uso real.';
            $parts[] = 'Otimize para leitura mobile e descoberta; o texto precisa parecer nativo do canal, nao uma copia de outra loja.';
            break;
        case 'erp':
            $parts[] = 'Estrutura preferida: nomenclatura tecnica interna + identificadores + especificacoes estruturadas.';
            $parts[] = 'Nao tente transformar o ERP em vitrine; mantenha o texto util para integracao e consistencia cadastral.';
            $parts[] = 'Inclua no conteudo tecnico os atributos editoriais reutilizaveis de Site, Mercado Livre, Shopee, Amazon e TikTok como metadados/atributos internos, sem prometer publicacao direta desses campos.';
            break;
        default:
            $parts[] = 'Estrutura preferida: nome principal do produto + atributo factual mais forte + contexto de uso permitido.';
            $parts[] = 'Otimize para o canal selecionado, nao para um canal generico.';
            break;
    }

    $facts = [];
    if ($category !== '') $facts[] = 'categoria=' . $category;
    if ($brand !== '') $facts[] = 'marca=' . $brand;
    if ($model !== '') $facts[] = 'modelo=' . $model;
    if ($sku !== '') $facts[] = 'sku=' . $sku;
    if ($gtin !== '') $facts[] = 'gtin=' . $gtin;
    if ($facts !== []) {
        $parts[] = 'Identificadores factuais disponiveis: ' . implode(' | ', $facts) . '.';
    }
    $focus = ai_catalog_category_focus_hint($category, $channel);
    if ($focus !== '') {
        $parts[] = $focus;
    }

    return implode(' ', $parts);
}

function ai_catalog_facts_brief(array $product): string
{
    $facts = [];
    foreach ([
        'categoria' => $product['category'] ?? '',
        'marca' => $product['brand'] ?? '',
        'modelo' => $product['model'] ?? '',
        'cor' => $product['color'] ?? '',
        'tamanho' => $product['size'] ?? '',
        'material' => $product['material'] ?? '',
        'especificacoes' => $product['specs'] ?? '',
    ] as $label => $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $facts[] = $label . '=' . $value;
        }
    }
    return $facts === [] ? '' : 'Resumo factual consolidado: ' . implode(' | ', $facts) . '.';
}

/** @return array{score:int,checks:array<string,bool>} */
function ai_catalog_quality_report(array $data, string $channel, array $product): array
{
    $policy = ai_catalog_policy($channel);
    $title = trim((string)($data['optimized_title'] ?? ''));
    $description = trim((string)($data['optimized_description'] ?? ''));
    $bullets = array_values(array_filter((array)($data['bullet_points'] ?? []), 'is_string'));
    $text = ai_catalog_text_blob($data);
    $source = ai_catalog_source_blob($product);

    $checks = [];
    $titleLength = mb_strlen($title, 'UTF-8');
    $checks['title_length_max'] = $titleLength <= (int)$policy['title_max'];
    if (isset($policy['title_min'])) $checks['title_length_min'] = $titleLength >= (int)$policy['title_min'];
    if (isset($policy['title_recommended_min']) && isset($policy['title_recommended_max'])) {
        $checks['title_within_recommended_range'] = $titleLength >= (int)$policy['title_recommended_min'] && $titleLength <= (int)$policy['title_recommended_max'];
    }
    $checks['description_present'] = $description !== '';
    if (isset($policy['description_recommended_min'])) {
        $checks['description_length_min'] = mb_strlen($description, 'UTF-8') >= (int)$policy['description_recommended_min'];
    }
    $checks['protected_commerce_fields_absent'] = preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', $text) !== 1;
    $checks['bullet_count'] = count($bullets) >= (int)$policy['bullets_min'] && count($bullets) <= (int)$policy['bullets_max'];
    $checks['meta_title_length'] = mb_strlen((string)($data['meta_title'] ?? ''), 'UTF-8') <= 70;
    $checks['meta_description_length'] = mb_strlen((string)($data['meta_description'] ?? ''), 'UTF-8') <= 160;
    $checks['title_starts_with_identity'] = ai_catalog_title_starts_with_identity($title, $product);
    $checks['title_has_no_hype_prefix'] = !ai_catalog_title_has_hype_prefix($title);

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

    $brand = trim((string)($product['brand'] ?? ''));
    if ($brand !== '' && in_array($channel, ['ml', 'amazon'], true)) {
        $checks['brand_preserved_in_title'] = mb_stripos($title, $brand, 0, 'UTF-8') !== false;
    }
    if (in_array($channel, ['ml', 'amazon', 'erp'], true)) $checks['title_without_emoji'] = !ai_catalog_has_emoji($title);

    if ($channel === 'amazon') {
        $checks['amazon_disallowed_chars_absent'] = preg_match('/[!$?_{}^¬¦]/u', $title) !== 1;
        $words = preg_split('/\s+/u', mb_strtolower($title, 'UTF-8')) ?: [];
        $ignore = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'com', 'para', 'a', 'o'];
        $counts = [];
        foreach ($words as $word) {
            $word = trim($word, " ,.;:/()[]-+");
            if ($word === '' || in_array($word, $ignore, true)) continue;
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        $checks['amazon_word_repetition'] = max($counts ?: [0]) <= 2;
    }

    if ($channel === 'tiktok') {
        $checks['tiktok_bullet_length'] = array_reduce($bullets, fn(bool $ok, string $b): bool => $ok && mb_strlen($b, 'UTF-8') < 250, true);
        $checks['tiktok_hooks_count'] = count((array)($data['marketing_hooks'] ?? [])) <= 2;
    }

    if ($channel === 'erp') {
        $checks['erp_no_marketing_hooks'] = (array)($data['marketing_hooks'] ?? []) === [];
        $checks['erp_no_seo_keywords'] = (array)($data['seo_keywords'] ?? []) === [];
    }

    $passed = count(array_filter($checks));
    $score = $checks === [] ? 0 : (int)round(($passed / count($checks)) * 100);
    return ['score' => $score, 'checks' => $checks];
}

/**
 * @return list<string>
 */
function ai_catalog_allowed_providers(): array
{
    return ['openai', 'gemini', 'claude', 'openrouter', 'groq'];
}

/** @return list<string> */
function ai_catalog_provider_candidates(string $preferred): array
{
    $order = catalog_ai_provider_fallback_order($preferred);
    $filtered = array_values(array_filter($order, static fn(string $provider): bool => catalog_ai_provider_has_keys($provider)));
    return $filtered !== [] ? $filtered : array_values(array_filter(['openai', 'gemini', 'openrouter', 'groq'], static fn(string $provider): bool => catalog_ai_provider_has_keys($provider)));
}

function ai_catalog_provider_health_path(): string
{
    return dirname(__DIR__, 3) . '/storage/ai-provider-health.json';
}

/** @return array<string,int> */
function ai_catalog_provider_health_snapshot(): array
{
    $path = ai_catalog_provider_health_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $snapshot = [];
    foreach ($decoded as $provider => $expiry) {
        $snapshot[(string)$provider] = is_numeric($expiry) ? (int)$expiry : 0;
    }
    return $snapshot;
}

function ai_catalog_provider_available(string $provider): bool
{
    $snapshot = ai_catalog_provider_health_snapshot();
    $expiry = (int)($snapshot[catalog_ai_normalize_provider($provider)] ?? 0);
    return $expiry <= time();
}

function ai_catalog_provider_audit_log(string $provider, string $channel, string $status, string $message, int $productId = 0): void
{
    $path = dirname(__DIR__, 3) . '/storage/ai-provider-audit.jsonl';
    $record = [
        'ts' => time(),
        'provider' => catalog_ai_normalize_provider($provider),
        'channel' => $channel,
        'status' => $status,
        'message' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
        'product_id' => $productId,
    ];
    @file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** @param array<string,mixed> $data @param array<string,string> $product */
function ai_catalog_validate_ai_response(array $data, string $channel = '', array $product = []): void
{
    foreach (['optimized_title', 'optimized_description', 'meta_title', 'meta_description'] as $key) {
        if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new CatalogAiApiException("Resposta da IA invalida em '$key'.");
        }
    }
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) throw new CatalogAiApiException("Resposta da IA invalida em '$key'.");
        foreach ($data[$key] as $value) {
            if (!is_string($value) || trim($value) === '') throw new CatalogAiApiException("Resposta da IA contem valor invalido em '$key'.");
        }
    }

    if (preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', ai_catalog_text_blob($data)) === 1) {
        throw new CatalogAiApiException('A IA tentou incluir preco, estoque ou condicao comercial protegida.');
    }

    if ($channel === '') {
        $context = $GLOBALS['ai_catalog_validation_context'] ?? null;
        if (is_array($context) && is_string($context['channel'] ?? null) && is_array($context['product'] ?? null)) {
            $channel = (string)$context['channel'];
            $product = $context['product'];
        }
    }
    if ($channel === '') return;

    $report = ai_catalog_quality_report($data, $channel, $product);
    $GLOBALS['ai_catalog_last_quality_report'] = $report;
    $soft = array_flip(ai_catalog_soft_quality_checks());
    $failed = array_keys(array_filter($report['checks'], fn(bool $ok, string $key): bool => !$ok && !isset($soft[$key]), ARRAY_FILTER_USE_BOTH));
    if ($failed !== []) throw new CatalogAiApiException('Saida reprovada pela politica de qualidade: ' . implode(', ', $failed));
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
    $profile = sv_catalog_channel_profile($channel);
    $metaDataJson = json_encode([
        'meta_title' => $data['meta_title'] ?? '',
        'meta_description' => $data['meta_description'] ?? '',
        'quality_score' => $quality['score'] ?? null,
        'quality_checks' => $quality['checks'] ?? [],
        'policy_version' => (string)($profile['policy_version'] ?? 'marketplace-premium-2026-08'),
        'manual_edit_pending_quality_recheck' => false,
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
        (string)($data['optimized_title'] ?? ''),
        (string)($data['optimized_description'] ?? ''),
        $bulletPointsJson !== false ? $bulletPointsJson : '[]',
        $seoKeywords,
        $marketingHooks,
        $metaDataJson !== false ? $metaDataJson : '{}',
        $status,
        $errorMessage,
    ]);
    return (int)$db->lastInsertId();
}

/** @return array<string,mixed> */
function ai_catalog_process_item(PDO $db, int $productId, string $channel, string $provider): array
{
    $channel = strtolower(trim($channel));
    $provider = catalog_ai_normalize_provider($provider);
    if (!array_key_exists($channel, catalog_ai_channels())) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Canal invalido: '$channel'."];
    }
    if (!in_array($provider, ai_catalog_allowed_providers(), true)) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Provider invalido: '$provider'."];
    }

    $product = ai_catalog_fetch_product($db, $productId);
    if ($product === null) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Produto #$productId nao encontrado ou sem nome."];
    }

    $providerCandidates = ai_catalog_provider_candidates($provider);
    $lastError = null;
    foreach ($providerCandidates as $resolvedProvider) {
        if (!ai_catalog_provider_available($resolvedProvider)) {
            ai_catalog_provider_audit_log($resolvedProvider, $channel, 'skip', 'cooldown', $productId);
            continue;
        }
        try {
            $data = catalog_ai_make_provider($resolvedProvider)->complete(
                ai_catalog_build_system_prompt($channel),
                ai_catalog_build_user_prompt($product, $channel)
            );
            ai_catalog_validate_ai_response($data, $channel, $product);
            $quality = ai_catalog_quality_report($data, $channel, $product);
            $stagingId = ai_catalog_insert_staging_row($db, $productId, $channel, $resolvedProvider, $data, 'pending', null, $quality);
            ai_catalog_provider_audit_log($resolvedProvider, $channel, 'ok', 'generated', $productId);
            return [
                'success' => true,
                'product_id' => $productId,
                'channel' => $channel,
                'provider' => $provider,
                'provider_used' => $resolvedProvider,
                'staging_id' => $stagingId,
                'quality_score' => $quality['score'],
            ];
        } catch (Throwable $e) {
            $lastError = $e;
            ai_catalog_provider_audit_log($resolvedProvider, $channel, 'fail', $e->getMessage(), $productId);
            error_log("[catalog-optimization] fallback falhou produto #$productId canal=$channel provider=$resolvedProvider: " . $e->getMessage());
            continue;
        }
    }

    $message = $lastError instanceof Throwable ? $lastError->getMessage() : 'Sem provedor disponivel.';
    try {
        $stagingId = ai_catalog_insert_staging_row(
            $db,
            $productId,
            $channel,
            $providerCandidates[0] ?? $provider,
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
            $message
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
        'error' => $message,
    ];
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
    $productId = (int)($input['product_id'] ?? 0);
    $channel = (string)($input['target_channel'] ?? $input['channel'] ?? '');
    $provider = (string)($input['provider'] ?? '');
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
