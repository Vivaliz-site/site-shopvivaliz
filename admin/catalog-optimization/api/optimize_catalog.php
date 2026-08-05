<?php

declare(strict_types=1);

/**
 * Core Processing Engine — Otimização de Cadastro de Produtos (SEO/GEO por
 * canal), usando OpenAI, Google Gemini ou Anthropic Claude via
 * src/TextAiServices.php.
 *
 * REGRA DE NEGÓCIO CRÍTICA: preço e estoque são TOTALMENTE ignorados e
 * protegidos por este script. ai_catalog_fetch_product() abaixo só extrai
 * campos de texto/atributo (nome, descrição, categoria, marca,
 * especificações) para variáveis locais explícitas — mesmo que a linha
 * bruta do banco contenha colunas de preço/estoque, elas nunca são lidas,
 * nunca entram no prompt, e nunca são gravadas em nenhum lugar por este
 * módulo.
 *
 * IMPORTANTE — EXECUÇÃO REAL, SEM MOCK: se a chave de API do provedor
 * escolhido não estiver configurada, ou qualquer chamada de rede falhar, o
 * processamento deste item termina em erro registrado — nunca em um
 * "sucesso" fabricado. Ver CatalogAiApiException em src/TextAiServices.php.
 *
 * Uso via HTTP (chamado pelo admin_catalog.php, sessão de admin exigida):
 *   POST api/optimize_catalog.php
 *   Content-Type: application/json
 *   { "product_id": 123, "target_channel": "ml", "provider": "openai" }
 *   -> responde JSON: { success, product_id, channel, provider, staging_id }
 *
 * Também exposto como função reutilizável (ai_catalog_process_item) para
 * ser chamada diretamente por cron_bulk_optimize.php sem round-trip HTTP.
 */

require_once __DIR__ . '/../config_optimization.php';
require_once __DIR__ . '/../src/TextAiServices.php';

/**
 * Busca os dados de TEXTO do produto na tabela `produtos` — nunca preço ou
 * estoque. Usa SELECT * (não SELECT com lista fixa de colunas) porque o
 * schema real de `produtos` varia entre releases (mesma situação já
 * documentada em admin/ai-image-studio/process_item.php) e uma lista fixa
 * de colunas quebraria com "Unknown column" em produção. A proteção contra
 * vazamento de preço/estoque não vem da query, e sim do fato de que o
 * código abaixo só copia name/description/category/brand/specs para
 * $product — nenhuma outra chave do row bruto é lida ou propagada.
 *
 * @return array{name:string, description:string, category:string, brand:string, specs:string}|null
 */
function ai_catalog_fetch_product(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    $name = trim((string) ($row['nome'] ?? $row['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $description = trim((string) (
        $row['descricao_completa']
        ?? $row['descricaoComplementar']
        ?? $row['descricao_complementar']
        ?? $row['descricao']
        ?? $row['description']
        ?? ''
    ));

    $categoryRaw = $row['categoria'] ?? $row['category'] ?? '';
    $category = is_array($categoryRaw)
        ? trim((string) ($categoryRaw['nome'] ?? $categoryRaw['caminhoCompleto'] ?? ''))
        : trim((string) $categoryRaw);

    $brand = trim((string) ($row['marca'] ?? $row['brand'] ?? 'Vivaliz'));

    $specsRaw = $row['especificacoes_tecnicas'] ?? $row['especificacoes'] ?? $row['specifications'] ?? $row['ficha_tecnica'] ?? '';
    $specs = is_array($specsRaw) ? trim((string) json_encode($specsRaw, JSON_UNESCAPED_UNICODE)) : trim((string) $specsRaw);

    // PROTEÇÃO EXPLÍCITA: nenhuma variável de preço/estoque é lida de $row
    // acima, propositalmente. O array abaixo é a ÚNICA coisa que sai desta
    // função — não vaza mais nada do $row bruto.
    return [
        'name' => $name,
        'description' => $description,
        'category' => $category,
        'brand' => $brand,
        'specs' => $specs,
    ];
}

/**
 * Monta o System Prompt "de último nível" para o canal solicitado, seguindo
 * exatamente as diretrizes de cada marketplace/canal definidas pelo negócio.
 * Todos os canais compartilham o mesmo contrato de saída JSON (reforçado no
 * final do prompt) para que a validação em ai_catalog_process_item() seja
 * uniforme.
 */
function ai_catalog_build_system_prompt(string $channel): string
{
    $channelInstructions = match ($channel) {
        'ml' => <<<'TXT'
Canal: Mercado Livre.
- optimized_title: focado em volume de busca orgânica pura, EXATAMENTE até 60 caracteres, sem termos banidos (sem preço, sem "frete grátis", sem superlativos proibidos, sem emojis).
- optimized_description: em Pirâmide Invertida — comece pelo benefício principal, depois a ficha técnica traduzida em texto corrido (não apenas lista crua de specs), e termine quebrando as objeções de compra mais frequentes desse tipo de produto (dúvida de compatibilidade, durabilidade, uso correto).
- bullet_points: 3 a 5 pontos-chave complementares à descrição (não repita literalmente o texto da descrição).
- seo_keywords: termos de busca reais que um comprador digitaria no Mercado Livre para este produto.
- marketing_hooks: pode retornar array vazio [] neste canal — não é o foco do Mercado Livre.
TXT,
        'shopee' => <<<'TXT'
Canal: Shopee.
- optimized_title: altamente persuasivo, com gatilhos mentais de escassez/urgência, usando emojis como delimitadores de leitura (não emoji decorativo aleatório — emoji funcional separando blocos de informação do título).
- optimized_description: focada em público jovem, apelo visual rápido, linhas curtas e espaçadas (parágrafos de 1-2 linhas), mencionando garantia e suporte, terminando com exatamente 10 hashtags estratégicas inseridas organicamente (relacionadas ao produto, categoria e público, não genéricas).
- bullet_points: 3 a 5 pontos rápidos e escaneáveis, tom informal.
- seo_keywords: termos de busca reais usados no app da Shopee.
- marketing_hooks: 2-3 chamadas curtas de urgência/escassez para usar em banners/anúncios do produto.
TXT,
        'amazon' => <<<'TXT'
Canal: Amazon.
- optimized_title: rigor absoluto nas diretrizes da Amazon. Estrutura obrigatória: [Marca] + [Linha/Modelo] + [Especificação Principal] + [Cor/Tamanho]. Sem emojis, sem caracteres especiais promocionais, sem ALL CAPS.
- optimized_description: técnica e objetiva, adequada ao padrão A+/Enhanced Brand Content da Amazon.
- bullet_points: EXATAMENTE 5 bullet points técnicos, cada um focado em um benefício + a especificação técnica que o sustenta, escritos para maximizar conversão em Amazon Ads (primeira palavra de cada bullet em destaque conceitual, ex: "DURÁVEL:", "COMPATÍVEL:").
- seo_keywords: termos de busca reais usados na busca da Amazon (backend search terms style — sem repetir palavras já usadas no título).
- marketing_hooks: pode retornar array vazio [] neste canal — a Amazon pune linguagem de marketing solto fora dos bullets/descrição.
TXT,
        'tiktok' => <<<'TXT'
Canal: TikTok Shop.
- optimized_title: extremamente dinâmico, focado em compra por impulso, linguagem de vídeo curto.
- optimized_description: tom de script de vídeo/live, direta, sem enrolação.
- bullet_points: 3 a 5 pontos rápidos, estilo "por que comprar agora".
- seo_keywords: termos de busca/hashtags de descoberta usados no TikTok Shop.
- marketing_hooks: OBRIGATORIAMENTE EXATAMENTE 3 ganchos textuais de 3 segundos, estilo "Pare de fazer isso se você...", projetados para prender atenção nos primeiros 3 segundos de um vídeo curto ou script de live — cada hook é uma frase completa pronta para narração, não um resumo.
TXT,
        'site' => <<<'TXT'
Canal: Site Próprio (shopvivaliz.com.br).
- optimized_title: copywriting de conversão profunda — foco em dor, desejo e solução, não apenas descrição factual do produto.
- optimized_description: arquitetura voltada para SEO e GEO (Generative Engine Optimization) de 2026 — escreva de um jeito que tanto um leitor humano quanto um motor de busca de IA (que resume e cita conteúdo) consigam extrair a resposta certa sobre o produto rapidamente. Estruture com uma resposta direta logo no início (útil para snippets/citação por IA), seguida de profundidade.
- bullet_points: 3 a 5 pontos-chave de decisão de compra.
- seo_keywords: termos de busca reais orientados a SEO de long-tail para este produto.
- marketing_hooks: pode retornar array vazio [] neste canal — o gancho de conversão do site vai em meta_description (CTA), não aqui.
- meta_title e meta_description (dentro do mesmo JSON, além das chaves padrão): meta_title otimizado para CTR no Google, meta_description com gatilho de Call To Action explícito para incentivar o clique no resultado de busca.
TXT,
        'erp' => <<<'TXT'
Canal: ERP (uso interno — nota fiscal e logística).
- optimized_title: ESTRITAMENTE TÉCNICO, sem nenhum adjetivo de marketing, nomenclatura padronizada e limpa, pronta para nota fiscal e gerenciamento de estoque (mesmo este módulo não lidando com o valor de estoque em si).
- optimized_description: técnica e objetiva, sem apelo comercial, só características físicas/técnicas do produto.
- bullet_points: especificações técnicas em formato de lista simples (sem linguagem de venda).
- seo_keywords: pode retornar array vazio [] neste canal — não se aplica a uso interno.
- marketing_hooks: DEVE retornar array vazio [] neste canal — marketing é proibido em texto de ERP.
TXT,
        default => throw new CatalogAiApiException("Canal inválido: '$channel'."),
    };

    return <<<TXT
Você é um especialista sênior em copywriting de e-commerce, SEO e GEO (Generative Engine Optimization) para 2026, escrevendo em português do Brasil (exceto quando o padrão do canal for tecnicamente exigir termos em inglês, ex: bullet points técnicos de categoria internacional).

$channelInstructions

REGRA ABSOLUTA DE FORMATO: responda ESTRITA e EXCLUSIVAMENTE com um objeto JSON válido, sem nenhum texto antes ou depois, sem cercas de código markdown (não use \`\`\`json), contendo EXATAMENTE estas chaves (nunca omita nenhuma, nunca deixe nenhuma vazia — use array vazio [] quando o canal explicitamente permitir, nunca string vazia ""):

{
  "optimized_title": "string",
  "optimized_description": "string",
  "bullet_points": ["string", "..."],
  "seo_keywords": ["string", "..."],
  "marketing_hooks": ["string", "..."],
  "meta_title": "string",
  "meta_description": "string"
}

Não invente características físicas, técnicas ou de composição do produto que não estejam nos dados fornecidos pelo usuário — você pode reescrever, reorganizar e enriquecer o texto de marketing/SEO livremente, mas NUNCA pode alterar fatos técnicos do produto (isso causaria problemas com clientes e com os próprios marketplaces).
TXT;
}

/**
 * Monta o prompt do usuário com os dados reais do produto — explicitamente
 * SEM preço e SEM estoque (essas informações nunca são buscadas por
 * ai_catalog_fetch_product(), então fisicamente não podem vazar aqui).
 */
function ai_catalog_build_user_prompt(array $product, string $channel): string
{
    $channelLabel = catalog_ai_channels()[$channel] ?? $channel;

    return sprintf(
        "Canal de destino: %s\n\nDados do produto (texto/atributos apenas — preço e estoque não fazem parte deste sistema e não devem ser mencionados ou inventados):\nNome atual: %s\nDescrição atual: %s\nCategoria: %s\nMarca: %s\nEspecificações técnicas: %s\n\nReescreva o cadastro deste produto seguindo rigorosamente as diretrizes do canal e o contrato de JSON definidos no system prompt.",
        $channelLabel,
        $product['name'],
        $product['description'] !== '' ? $product['description'] : '(sem descrição original cadastrada)',
        $product['category'] !== '' ? $product['category'] : '(sem categoria cadastrada)',
        $product['brand'],
        $product['specs'] !== '' ? $product['specs'] : '(sem especificações técnicas cadastradas)'
    );
}

/**
 * Valida que a resposta da IA contém todas as chaves obrigatórias do
 * contrato, com o tipo esperado. Não exige que TODAS sejam não-vazias — os
 * próprios system prompts autorizam array vazio [] em alguns canais/campos
 * (ex: marketing_hooks em 'ml') — mas cada chave precisa EXISTIR e ter o
 * tipo certo (string ou array), senão a resposta é considerada malformada.
 *
 * @param array<string,mixed> $data
 * @throws CatalogAiApiException
 */
function ai_catalog_validate_ai_response(array $data): void
{
    $stringKeys = ['optimized_title', 'optimized_description', 'meta_title', 'meta_description'];
    $arrayKeys = ['bullet_points', 'seo_keywords', 'marketing_hooks'];

    foreach ($stringKeys as $key) {
        if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new CatalogAiApiException("Resposta da IA sem a chave obrigatória '$key' (ausente, vazia, ou tipo errado).");
        }
    }

    foreach ($arrayKeys as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            throw new CatalogAiApiException("Resposta da IA sem a chave obrigatória '$key' (ausente ou não é uma lista JSON).");
        }
    }

    // optimized_title e optimized_description são o coração do cadastro —
    // esses dois nunca podem ser array vazio, mesmo em canais mais
    // permissivos com bullet_points/seo_keywords/marketing_hooks.
}

/**
 * Insere o registro gerado em catalog_optimizations_staging.
 */
function ai_catalog_insert_staging_row(
    PDO $db,
    int $productId,
    string $channel,
    string $providerUsed,
    array $data,
    string $status = 'pending',
    ?string $errorMessage = null
): int {
    $bulletPointsJson = json_encode($data['bullet_points'] ?? [], JSON_UNESCAPED_UNICODE);
    $seoKeywords = is_array($data['seo_keywords'] ?? null) ? implode(', ', $data['seo_keywords']) : (string) ($data['seo_keywords'] ?? '');
    $marketingHooks = is_array($data['marketing_hooks'] ?? null) ? implode(' | ', $data['marketing_hooks']) : (string) ($data['marketing_hooks'] ?? '');
    $metaDataJson = json_encode([
        'meta_title' => $data['meta_title'] ?? '',
        'meta_description' => $data['meta_description'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

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

/**
 * Processa um único produto para um canal + provedor.
 *
 * @return array{success:bool, product_id:int, channel:string, provider:string, staging_id?:int, error?:string}
 */
function ai_catalog_process_item(PDO $db, int $productId, string $channel, string $provider): array
{
    $channel = strtolower(trim($channel));
    $provider = strtolower(trim($provider));

    if (!array_key_exists($channel, catalog_ai_channels())) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Canal inválido: '$channel'."];
    }

    if (!in_array($provider, ['openai', 'gemini', 'claude'], true)) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Provider inválido: '$provider'. Use 'openai', 'gemini' ou 'claude'."];
    }

    $product = ai_catalog_fetch_product($db, $productId);
    if ($product === null) {
        return ['success' => false, 'product_id' => $productId, 'channel' => $channel, 'provider' => $provider, 'error' => "Produto #$productId não encontrado (ou sem nome cadastrado)."];
    }

    try {
        $aiProvider = catalog_ai_make_provider($provider);
        $systemPrompt = ai_catalog_build_system_prompt($channel);
        $userPrompt = ai_catalog_build_user_prompt($product, $channel);

        $data = $aiProvider->complete($systemPrompt, $userPrompt);
        ai_catalog_validate_ai_response($data);

        $stagingId = ai_catalog_insert_staging_row($db, $productId, $channel, $provider, $data, 'pending');

        return [
            'success' => true,
            'product_id' => $productId,
            'channel' => $channel,
            'provider' => $provider,
            'staging_id' => $stagingId,
        ];
    } catch (CatalogAiApiException $e) {
        error_log("[catalog-optimization] Falha otimizando produto #$productId (canal=$channel, provider=$provider): " . $e->getMessage());

        // Registra o erro no banco também, com status 'rejected', para
        // ficar visível no dashboard em vez de só sumir no log do servidor
        // — nunca fabricamos um registro "pending" de sucesso falso.
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
            'rejected',
            $e->getMessage()
        );

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

// ---------------------------------------------------------------------
// Entrada HTTP. Se este arquivo foi apenas incluído (require_once) por
// outro script (ex: cron_bulk_optimize.php, admin_catalog.php), o bloco
// abaixo não roda — só as funções acima ficam disponíveis.
// ---------------------------------------------------------------------

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

    $httpProductId = (int) ($input['product_id'] ?? 0);
    $httpChannel = (string) ($input['target_channel'] ?? $input['channel'] ?? '');
    $httpProvider = (string) ($input['provider'] ?? '');

    if ($httpProductId <= 0 || $httpChannel === '' || $httpProvider === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parâmetros product_id, target_channel e provider são obrigatórios.']);
        exit;
    }

    $db = catalog_ai_db();
    if ($db === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Falha ao conectar ao banco de dados.']);
        exit;
    }

    echo json_encode(ai_catalog_process_item($db, $httpProductId, $httpChannel, $httpProvider), JSON_UNESCAPED_UNICODE);
    exit;
}
