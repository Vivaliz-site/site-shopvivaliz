<?php

declare(strict_types=1);

/**
 * Engine de processamento do AI Image Studio.
 *
 * Recebe product_id + provider ('openai', 'google' ou 'claude') e gera as
 * 3 variações de imagem (white / hero / ambient) EDITANDO a foto real do
 * produto já cadastrada no banco — nunca gerando do zero só por texto.
 * Fluxo:
 *   1. Busca a foto atual do produto na tabela `produtos` e resolve um
 *      caminho local para ela (baixa se for URL remota, ou aponta
 *      diretamente se já for um arquivo local do projeto).
 *   2. Se não houver foto cadastrada, ABORTA o processamento deste produto
 *      (não existe fallback silencioso para texto puro — ver REGRA CRÍTICA
 *      DE NEGÓCIO abaixo) e registra o erro nos 3 tipos de imagem.
 *   3. Determina os 3 prompts:
 *      - provider = 'openai' | 'google': usa os prompts padrão já blindados
 *        (contra alteração de cor/forma/tamanho do produto).
 *      - provider = 'claude': primeiro chama a API da Anthropic para
 *        reescrever os 3 prompts a partir do nome+descrição do produto
 *        (mantendo a mesma regra de fidelidade); a imagem final ainda é
 *        editada pela OpenAI — o Claude nunca toca na imagem.
 *   4. Envia a foto real + prompt para o endpoint de EDIÇÃo do provedor
 *      escolhido (OpenAI /v1/images/edits ou Google gemini-2.5-flash-image
 *      via :generateContent — ver AiServices.php).
 *
 * REGRA CRÍTICA DE NEGÓCIO (FIDELIDADE DO PRODUTO): a IA nunca gera a
 * imagem do zero. Ela sempre recebe a foto real do produto como entrada e é
 * instruída a preservar cor/forma/tamanho/design, mexendo só em
 * cenário/fundo/iluminação — daí a exigência de uma foto base válida antes
 * de chamar qualquer API de imagem.
 *
 * Cada imagem editada é salva em AI_STUDIO_STORAGE_DIR com nome único e
 * inserida em product_images_staging com status 'pending'. Erros de uma
 * variação (ex: falha da API num dos 3 tipos) não interrompem as outras —
 * cada tipo de imagem é tentado e registrado independentemente.
 *
 * Uso via linha de comando (Ubuntu, cron/batch):
 *   php process_item.php <product_id> <openai|google|claude>
 *
 * Uso via HTTP (chamado pelo admin_dashboard.php, sessão de admin exigida):
 *   POST process_item.php  { product_id: 123, provider: "openai" }
 *   -> responde JSON: { success, product_id, provider, results: [...] }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/AiServices.php';

/**
 * Prompts padrão "blindados" usados quando o provedor é OpenAI ou Google
 * diretamente (sem passar pelo Claude). [product_name] é substituído pelo
 * nome real do produto antes do envio.
 */
function ai_studio_default_prompts(string $productName): array
{
    return [
        'white' => "High-resolution studio product photography of {$productName}. The product shape, color, and design must be 100% accurate and unmodified. Solid clean white background, center frame, professional uniform lighting, 1:1 aspect ratio",
        'hero' => "Commercial hero shot photography of {$productName} with exactly accurate original colors and proportions. Dramatic cinematic studio lighting, sharp focus on product details, premium presentation, 1:1 aspect ratio",
        'ambient' => "Lifestyle product photography of {$productName}. Product physical features, color, and shape must remain completely unchanged. Placed in a realistic contextual modern environment, soft natural shadows matching the product, highly detailed, photorealistic, 1:1 aspect ratio",
    ];
}

/**
 * Busca nome + descrição + foto atual do produto na tabela `produtos`,
 * tolerando os diferentes nomes de coluna já observados em outras partes do
 * projeto (ver includes/catalog-runtime.php) — o schema real de `produtos`
 * varia conforme a origem da sincronização (Olist/Tiny vs. cadastro manual).
 *
 * @return array{name:string, description:string, image_ref:string}|null null se o produto não existir
 */
function ai_studio_fetch_product(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    $name = trim((string) ($row['nome'] ?? $row['name'] ?? $row['descricao'] ?? ''));
    $description = trim((string) (
        $row['descricao_completa']
        ?? $row['descricaoComplementar']
        ?? $row['descricao_complementar']
        ?? $row['descricao']
        ?? $row['description']
        ?? $name
    ));

    // Mesma tolerância de nomes de coluna usada em
    // includes/catalog-runtime.php para a imagem principal do produto.
    $imageRef = trim((string) (
        $row['imagem_principal_url']
        ?? $row['primary_image_url']
        ?? $row['image_url']
        ?? $row['imagem']
        ?? ''
    ));

    if ($name === '') {
        return null;
    }

    return ['name' => $name, 'description' => $description, 'image_ref' => $imageRef];
}

/**
 * Resolve a foto REAL do produto (valor bruto vindo de `produtos`, que pode
 * ser uma URL absoluta http(s) ou um caminho relativo dentro do próprio
 * projeto) para um arquivo local que as APIs de imagem possam ler/enviar.
 *
 * - URL http(s): baixa para AI_STUDIO_BASE_IMAGE_TMP_DIR.
 * - Caminho relativo (ex: "/public/assets/products/foo.jpg" ou
 *   "public/assets/products/foo.jpg"): resolve relativo à raiz do projeto.
 *
 * @return string caminho absoluto local do arquivo de imagem
 * @throws AiStudioApiException se `$imageRef` estiver vazio, ou o arquivo
 *   não existir/não puder ser baixado — SEM fallback silencioso, porque a
 *   regra de negócio exige uma foto real como base.
 */
function ai_studio_resolve_base_image(string $imageRef, string $projectRoot, int $productId): string
{
    if ($imageRef === '') {
        throw new AiStudioApiException(
            "Produto #$productId não tem foto cadastrada em `produtos` (imagem_principal_url/primary_image_url/image_url/imagem todos vazios). " .
            'Não é possível gerar imagens sem uma foto real de referência (regra de fidelidade do produto).'
        );
    }

    if (preg_match('#^https?://#i', $imageRef) === 1) {
        $extension = strtolower((string) pathinfo(parse_url($imageRef, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $tmpPath = AI_STUDIO_BASE_IMAGE_TMP_DIR . 'base-' . $productId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

        AiStudioHttpClient::downloadToFile($imageRef, $tmpPath);

        return $tmpPath;
    }

    // Caminho local: normaliza barra inicial e resolve a partir da raiz do
    // projeto (mesma raiz usada por AI_STUDIO_STORAGE_DIR, definida em
    // config.php via __DIR__ . '/../../').
    $relative = ltrim($imageRef, '/');
    $localPath = $projectRoot . '/' . $relative;

    if (!is_file($localPath) || !is_readable($localPath)) {
        throw new AiStudioApiException(
            "Produto #$productId: foto cadastrada ('$imageRef') não foi encontrada no disco em '$localPath'."
        );
    }

    return $localPath;
}

/**
 * Gera um nome de arquivo único e seguro para a imagem de staging.
 */
function ai_studio_unique_filename(int $productId, string $imageType, string $extension = 'png'): string
{
    $random = bin2hex(random_bytes(6));
    $timestamp = date('Ymd-His');
    return "product-{$productId}-{$imageType}-{$timestamp}-{$random}.{$extension}";
}

/**
 * Insere um registro pending (ou de erro) em product_images_staging.
 */
function ai_studio_insert_staging_row(
    PDO $db,
    int $productId,
    string $imageType,
    string $providerUsed,
    ?string $localPath,
    ?string $promptUsed,
    string $status,
    ?string $errorMessage = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO product_images_staging
            (product_id, image_type, provider_used, local_path, prompt_used, status, error_message, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $productId,
        $imageType,
        $providerUsed,
        $localPath ?? '',
        $promptUsed,
        $status,
        $errorMessage,
    ]);

    return (int) $db->lastInsertId();
}

/**
 * Processa um único produto para um provedor, gerando as 3 variações.
 *
 * @return array{success:bool, product_id:int, provider:string, results:array<int,array<string,mixed>>}
 */
function ai_studio_process_item(PDO $db, int $productId, string $provider): array
{
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
        return [
            'success' => false,
            'product_id' => $productId,
            'provider' => $provider,
            'results' => [],
            'error' => "Provider inválido: '$provider'. Use 'openai', 'google' ou 'claude'.",
        ];
    }

    $product = ai_studio_fetch_product($db, $productId);
    if ($product === null) {
        return [
            'success' => false,
            'product_id' => $productId,
            'provider' => $provider,
            'results' => [],
            'error' => "Produto #$productId não encontrado (ou sem nome cadastrado).",
        ];
    }

    // Resolve a foto REAL do produto ANTES de chamar qualquer API de
    // imagem — sem ela, não há o que editar (ver REGRA CRÍTICA DE NEGÓCIO
    // no cabeçalho deste arquivo). $baseImagePath aponta para um arquivo
    // local; se veio de download remoto, é apagado no finally no fim desta
    // função.
    $baseImagePath = null;
    $baseImageIsTemp = false;
    try {
        $projectRoot = dirname(__DIR__, 2);
        $baseImagePath = ai_studio_resolve_base_image($product['image_ref'], $projectRoot, $productId);
        $baseImageIsTemp = str_starts_with($baseImagePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
    } catch (AiStudioApiException $e) {
        error_log("[ai-image-studio] Falha ao resolver foto base do produto #$productId: " . $e->getMessage());

        $results = [];
        foreach (['white', 'hero', 'ambient'] as $imageType) {
            $id = ai_studio_insert_staging_row(
                $db,
                $productId,
                $imageType,
                $provider === 'claude' ? 'claude_optimized' : $provider,
                null,
                null,
                'rejected',
                $e->getMessage()
            );
            $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
        }

        return [
            'success' => false,
            'product_id' => $productId,
            'provider' => $provider,
            'results' => $results,
            'error' => 'Sem foto real de referência: ' . $e->getMessage(),
        ];
    }

    try {
        // Determina os 3 prompts finais e qual engine de imagem efetivamente
        // edita o arquivo (Claude nunca gera/edita imagem — só reescreve o
        // prompt).
        $imageEngine = $provider; // 'openai' ou 'google'
        $providerUsedLabel = $provider; // como fica gravado em provider_used

        if ($provider === 'claude') {
            try {
                $claudeClient = new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL);
                $prompts = $claudeClient->optimizePrompts($product['name'], $product['description']);
            } catch (AiStudioApiException $e) {
                error_log('[ai-image-studio] Claude optimizePrompts falhou: ' . $e->getMessage());
                return [
                    'success' => false,
                    'product_id' => $productId,
                    'provider' => $provider,
                    'results' => [],
                    'error' => 'Falha ao otimizar prompts com Claude: ' . $e->getMessage(),
                ];
            }

            // Depois de otimizado pelo Claude, a imagem final ainda é
            // editada pela OpenAI (motor padrão para esse caminho). O tipo
            // gravado no banco fica marcado como 'claude_optimized' para
            // diferenciar de um prompt padrão direto.
            $imageEngine = 'openai';
            $providerUsedLabel = 'claude_optimized';
        } else {
            $prompts = ai_studio_default_prompts($product['name']);
        }

        $results = [];

        foreach (['white', 'hero', 'ambient'] as $imageType) {
            $prompt = $prompts[$imageType];
            $filename = ai_studio_unique_filename($productId, $imageType);
            $destinationPath = AI_STUDIO_STORAGE_DIR . $filename;
            $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;

            try {
                // Sempre EDITA a foto real ($baseImagePath) — nenhum dos
                // dois caminhos abaixo gera imagem do zero só por texto.
                if ($imageEngine === 'openai') {
                    $client = new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, AI_STUDIO_OPENAI_IMAGE_MODEL);
                    $client->editImageToFile($prompt, $baseImagePath, $destinationPath);
                } else {
                    $client = new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, AI_STUDIO_GOOGLE_IMAGEN_MODEL);
                    $client->editImageToFile($prompt, $baseImagePath, $destinationPath);
                }

                $id = ai_studio_insert_staging_row(
                    $db,
                    $productId,
                    $imageType,
                    $providerUsedLabel,
                    $publicPath,
                    $prompt,
                    'pending'
                );

                $results[] = [
                    'image_type' => $imageType,
                    'status' => 'pending',
                    'staging_id' => $id,
                    'local_path' => $publicPath,
                ];
            } catch (AiStudioApiException $e) {
                error_log("[ai-image-studio] Falha editando imagem '$imageType' para produto #$productId via $imageEngine: " . $e->getMessage());

                // Registra o erro no banco também (status 'rejected' com
                // error_message) para o erro ficar visível no dashboard, em vez
                // de só sumir nos logs do servidor.
                $id = ai_studio_insert_staging_row(
                    $db,
                    $productId,
                    $imageType,
                    $providerUsedLabel,
                    null,
                    $prompt,
                    'rejected',
                    $e->getMessage()
                );

                $results[] = [
                    'image_type' => $imageType,
                    'status' => 'error',
                    'staging_id' => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $anySuccess = array_reduce($results, static fn (bool $carry, array $r) => $carry || $r['status'] === 'pending', false);

        return [
            'success' => $anySuccess,
            'product_id' => $productId,
            'provider' => $provider,
            'results' => $results,
        ];
    } finally {
        // Cópia temporária da foto real (baixada de URL remota) nunca deve
        // sobreviver além deste processamento — evita acumular lixo em
        // storage/base-image-tmp/. Se a foto já era um arquivo local do
        // projeto, não apagamos (não é nossa cópia).
        if ($baseImageIsTemp && $baseImagePath !== null) {
            @unlink($baseImagePath);
        }
    }
}

// ---------------------------------------------------------------------
// Entradas externas: CLI (cron/batch) ou HTTP (chamado pelo dashboard).
// Se este arquivo foi apenas incluído (require_once) por outro script
// (ex: admin_dashboard.php processando um lote), nenhuma das duas rodam —
// só as funções acima ficam disponíveis.
// ---------------------------------------------------------------------

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $cliProductId = (int) ($argv[1] ?? 0);
    $cliProvider = (string) ($argv[2] ?? '');

    if ($cliProductId <= 0 || $cliProvider === '') {
        fwrite(STDERR, "Uso: php process_item.php <product_id> <openai|google|claude>\n");
        exit(1);
    }

    $db = ai_studio_db();
    if ($db === null) {
        fwrite(STDERR, "Falha ao conectar ao banco de dados.\n");
        exit(1);
    }

    $result = ai_studio_process_item($db, $cliProductId, $cliProvider);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit($result['success'] ? 0 : 1);
}

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    require_once __DIR__ . '/../../includes/admin-guard.php';

    header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        exit;
    }

    $httpProductId = (int) ($_POST['product_id'] ?? 0);
    $httpProvider = (string) ($_POST['provider'] ?? '');

    if ($httpProductId <= 0 || $httpProvider === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parâmetros product_id e provider são obrigatórios.']);
        exit;
    }

    $db = ai_studio_db();
    if ($db === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Falha ao conectar ao banco de dados.']);
        exit;
    }

    echo json_encode(ai_studio_process_item($db, $httpProductId, $httpProvider), JSON_UNESCAPED_UNICODE);
    exit;
}
