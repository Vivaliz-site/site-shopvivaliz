<?php

declare(strict_types=1);

/**
 * Clientes cURL nativos (sem Composer) para os 3 provedores usados no
 * AI Image Studio:
 *   - AiStudioOpenAiClient: EDITA a foto real do produto (endpoint
 *     /v1/images/edits, modelo gpt-image-1) — recebe a imagem original como
 *     referência + prompt, não gera do zero só por texto.
 *   - AiStudioGoogleImageEditClient: EDITA a foto real do produto via
 *     Gemini API multimodal (modelo gemini-2.5-flash-image, endpoint
 *     :generateContent) — recebe a imagem original inline (base64) + prompt.
 *   - AiStudioClaudeClient: NÃO gera nem edita imagem — reescreve/otimiza os
 *     3 prompts (white/hero/ambient) a partir do nome+descrição do produto,
 *     para depois serem enviados ao OpenAI ou ao Google junto com a foto real.
 *
 * IMPORTANTE (fidelidade do produto): tanto o endpoint da OpenAI quanto o do
 * Google recebem a imagem REAL do produto como entrada e são instruídos (via
 * prompt) a preservar cor/forma/tamanho/design exatamente como estão —
 * mexendo só em cenário/fundo/iluminação. Isso é reforçado tanto no texto do
 * prompt (ver process_item.php) quanto, no caso da OpenAI, pelo parâmetro
 * input_fidelity=high, que existe especificamente para isso.
 *
 * Todas as classes lançam AiStudioApiException em caso de erro de rede,
 * HTTP != 2xx, ou corpo de resposta que não seja o esperado — nunca
 * retornam silenciosamente um resultado inválido.
 */

class AiStudioApiException extends RuntimeException
{
}

/**
 * Helper interno de cURL compartilhado pelos 3 clientes. Centraliza timeout,
 * tratamento de erro de transporte e decodificação de JSON, para não repetir
 * a mesma lógica de tratamento de erro em cada classe. Suporta tanto corpo
 * JSON (Google/Claude) quanto multipart/form-data com upload de arquivo
 * (OpenAI /v1/images/edits).
 */
final class AiStudioHttpClient
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $jsonBody
     * @return array{status:int, body:string}
     */
    public static function request(string $method, string $url, array $headers = [], ?array $jsonBody = null, int $timeoutSeconds = 120): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new AiStudioApiException('Falha ao inicializar cURL.');
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ];

        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new AiStudioApiException('Falha ao codificar corpo JSON da requisição: ' . json_last_error_msg());
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new AiStudioApiException("Erro de transporte cURL (#$errNo) chamando $url: $errMsg");
        }

        if ($body === false) {
            throw new AiStudioApiException("Resposta vazia/inválida de $url (sem corpo).");
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * POST multipart/form-data (upload de arquivo + campos de texto), usado
     * pelo endpoint /v1/images/edits da OpenAI. `$fields` pode conter
     * strings normais e instâncias de CURLFile.
     *
     * @param array<string,string> $headers Não inclua Content-Type — o cURL
     *   define o boundary automaticamente ao usar POSTFIELDS como array.
     * @param array<string,mixed> $fields
     * @return array{status:int, body:string}
     */
    public static function requestMultipart(string $url, array $headers, array $fields, int $timeoutSeconds = 120): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new AiStudioApiException('Falha ao inicializar cURL (multipart).');
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ]);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new AiStudioApiException("Erro de transporte cURL (#$errNo) chamando $url: $errMsg");
        }

        if ($body === false) {
            throw new AiStudioApiException("Resposta vazia/inválida de $url (sem corpo).");
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * Baixa um binário (imagem) de uma URL para um arquivo local. Usado para
     * baixar a foto REAL do produto (já cadastrada no site) quando ela está
     * armazenada como URL absoluta em vez de caminho local, e também como
     * fallback caso algum provedor retorne uma URL de imagem em vez de
     * base64.
     */
    public static function downloadToFile(string $url, string $destinationPath, int $timeoutSeconds = 90): void
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new AiStudioApiException('Falha ao inicializar cURL para download de imagem.');
        }

        $fp = @fopen($destinationPath, 'wb');
        if ($fp === false) {
            curl_close($ch);
            throw new AiStudioApiException("Não foi possível abrir '$destinationPath' para escrita (permissão de pasta no Ubuntu?).");
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $ok = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($errNo !== 0 || $ok === false) {
            @unlink($destinationPath);
            throw new AiStudioApiException("Erro de transporte cURL (#$errNo) baixando imagem de $url: $errMsg");
        }

        if ($status < 200 || $status >= 300) {
            @unlink($destinationPath);
            throw new AiStudioApiException("Download de imagem falhou com HTTP $status ($url).");
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeJsonOrFail(string $body, string $context): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $snippet = substr($body, 0, 500);
            throw new AiStudioApiException("$context: resposta não é um JSON válido. Corpo recebido (truncado): $snippet");
        }

        return $decoded;
    }

    /**
     * Detecta o MIME type de uma imagem local a partir dos bytes reais do
     * arquivo (não confia só na extensão), necessário tanto para o upload
     * multipart da OpenAI quanto para o campo mime_type do Google.
     */
    public static function detectImageMime(string $filePath): string
    {
        $info = @getimagesize($filePath);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime']) && $info['mime'] !== '') {
            return $info['mime'];
        }

        // Fallback pela extensão se getimagesize falhar (arquivo corrompido
        // ou formato não suportado por ela, ex: webp em builds antigas do GD).
        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}

/**
 * OpenAI — EDITA a foto real do produto usando /v1/images/edits (gpt-image-1).
 * A imagem original é enviada como referência; o modelo é instruído (via
 * prompt) a preservar o produto e mudar só o cenário/fundo/iluminação.
 */
final class AiStudioOpenAiClient
{
    public function __construct(private string $apiKey, private string $model)
    {
    }

    /**
     * Edita a imagem base do produto conforme o prompt e salva o resultado
     * no caminho local informado.
     *
     * @throws AiStudioApiException
     */
    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath, string $size = '1024x1024'): void
    {
        if ($this->apiKey === '') {
            throw new AiStudioApiException('OPENAI_API_KEY não configurada (ver .env / shared/.env).');
        }

        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) {
            throw new AiStudioApiException("Imagem base do produto não encontrada/legível: '$baseImagePath'.");
        }

        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);

        // gpt-image-1 aceita PNG/WEBP/JPG < 25MB em /v1/images/edits.
        $fileSize = (int) @filesize($baseImagePath);
        if ($fileSize <= 0 || $fileSize > 25 * 1024 * 1024) {
            throw new AiStudioApiException("Imagem base do produto tem tamanho inválido ($fileSize bytes) para /v1/images/edits (limite: 25MB).");
        }

        $curlFile = new CURLFile($baseImagePath, $mime, basename($baseImagePath));

        // Campo enviado como array (image[]) — mesmo formato aceito para
        // múltiplas imagens no gpt-image-1; com uma única imagem funciona
        // igual e mantém o código pronto para aceitar mais referências no
        // futuro (ex: foto de mais de um ângulo do produto).
        $fields = [
            'model' => $this->model,
            'prompt' => $prompt,
            'image[]' => $curlFile,
            'n' => '1',
            'size' => $size,
            // Preserva ao máximo os detalhes/identidade do produto na
            // imagem original — existe exatamente para o nosso caso de uso
            // (regra crítica de negócio: produto não pode mudar).
            'input_fidelity' => 'high',
        ];

        $response = AiStudioHttpClient::requestMultipart(
            'https://api.openai.com/v1/images/edits',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $fields
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new AiStudioApiException(
                "OpenAI images/edits retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = AiStudioHttpClient::decodeJsonOrFail($response['body'], 'OpenAI images/edits');

        $item = $decoded['data'][0] ?? null;
        if (!is_array($item)) {
            throw new AiStudioApiException('OpenAI images/edits: resposta sem campo data[0].');
        }

        if (isset($item['b64_json']) && is_string($item['b64_json']) && $item['b64_json'] !== '') {
            $binary = base64_decode($item['b64_json'], true);
            if ($binary === false) {
                throw new AiStudioApiException('OpenAI images/edits: b64_json inválido (falha ao decodificar base64).');
            }
            if (@file_put_contents($destinationPath, $binary) === false) {
                throw new AiStudioApiException("Não foi possível gravar imagem em '$destinationPath' (permissão de pasta no Ubuntu?).");
            }
            return;
        }

        if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
            AiStudioHttpClient::downloadToFile($item['url'], $destinationPath);
            return;
        }

        throw new AiStudioApiException('OpenAI images/edits: resposta sem b64_json nem url utilizável.');
    }
}

/**
 * Google — EDITA a foto real do produto via Gemini API multimodal
 * (modelo gemini-2.5-flash-image, também conhecido como "Nano Banana"),
 * endpoint :generateContent com a imagem enviada inline (base64) junto do
 * prompt de texto. Autenticação por API key simples (mesma chave da Gemini
 * API / Google AI Studio — GOOGLE_IMAGEN_API_KEY).
 *
 * NOTA: o endpoint clássico de Imagen (:predict, aiplatform.googleapis.com
 * via Vertex AI, ou o :predict da própria Gemini API) é TEXT-TO-IMAGE puro —
 * não aceita imagem de entrada. Por isso este módulo usa o modelo
 * gemini-2.5-flash-image via :generateContent, que é multimodal (aceita
 * imagem + texto na entrada e devolve imagem editada na saída) — é o
 * equivalente funcional ao /v1/images/edits da OpenAI para o ecossistema
 * Google.
 */
final class AiStudioGoogleImageEditClient
{
    public function __construct(private string $apiKey, private string $model)
    {
    }

    /**
     * Edita a imagem base do produto conforme o prompt e salva o resultado
     * no caminho local informado.
     *
     * @throws AiStudioApiException
     */
    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath): void
    {
        if ($this->apiKey === '') {
            throw new AiStudioApiException('GOOGLE_IMAGEN_API_KEY não configurada (ver .env / shared/.env).');
        }

        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) {
            throw new AiStudioApiException("Imagem base do produto não encontrada/legível: '$baseImagePath'.");
        }

        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);
        $binary = @file_get_contents($baseImagePath);
        if ($binary === false) {
            throw new AiStudioApiException("Falha ao ler imagem base do produto: '$baseImagePath'.");
        }

        // Limite documentado da Gemini API para conteúdo inline (texto +
        // imagens) é 20MB por requisição.
        if (strlen($binary) > 19 * 1024 * 1024) {
            throw new AiStudioApiException('Imagem base do produto excede o limite de ~20MB para envio inline na Gemini API.');
        }

        $base64 = base64_encode($binary);

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'inline_data' => [
                                'mime_type' => $mime,
                                'data' => $base64,
                            ],
                        ],
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];

        $response = AiStudioHttpClient::request(
            'POST',
            $url,
            ['Content-Type' => 'application/json'],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new AiStudioApiException(
                "Google Gemini (:generateContent) retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = AiStudioHttpClient::decodeJsonOrFail($response['body'], 'Google Gemini (:generateContent)');

        $parts = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            $blockReason = (string) ($decoded['promptFeedback']['blockReason'] ?? '');
            $extra = $blockReason !== '' ? " (bloqueado pelo Google: $blockReason)" : '';
            throw new AiStudioApiException('Google Gemini: resposta sem candidates[0].content.parts.' . $extra);
        }

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            // A API aceita/retorna tanto inlineData (camelCase, formato de
            // resposta padrão) quanto inline_data (snake_case, formato usado
            // nos exemplos oficiais de request) — checamos os dois por
            // tolerância, já que isso não é garantido pela documentação.
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!is_array($inline)) {
                continue;
            }

            $data = $inline['data'] ?? null;
            if (!is_string($data) || $data === '') {
                continue;
            }

            $imageBinary = base64_decode($data, true);
            if ($imageBinary === false) {
                throw new AiStudioApiException('Google Gemini: campo data (imagem) inválido (falha ao decodificar base64).');
            }

            if (@file_put_contents($destinationPath, $imageBinary) === false) {
                throw new AiStudioApiException("Não foi possível gravar imagem em '$destinationPath' (permissão de pasta no Ubuntu?).");
            }

            return;
        }

        throw new AiStudioApiException('Google Gemini: resposta não continha nenhuma parte de imagem (inlineData) utilizável — o modelo pode ter respondido só com texto.');
    }
}

/**
 * Claude — NÃO gera nem edita imagem. Recebe nome+descrição do produto e
 * devolve os 3 prompts fotográficos otimizados (white/hero/ambient) em JSON
 * estrito, que depois são enviados junto com a foto real do produto ao
 * AiStudioOpenAiClient ou AiStudioGoogleImageEditClient.
 */
final class AiStudioClaudeClient
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Você é um diretor de fotografia de e-commerce. Reescreva este produto em 3 prompts de imagem fotorrealistas em inglês para os modos: White Background, Hero Shot e Lifestyle Ambiented. ATENÇÃO: O produto em si não pode sofrer NENHUMA alteração de cor, formato, tamanho ou design. Foque as melhorias estritamente no realismo do cenário, reflexos coerentes e iluminação de estúdio ao redor do produto. Retorne estritamente um JSON com as chaves 'white', 'hero' e 'ambient', sem textos explicativos.
PROMPT;

    public function __construct(private string $apiKey, private string $model)
    {
    }

    /**
     * @return array{white:string, hero:string, ambient:string}
     * @throws AiStudioApiException
     */
    public function optimizePrompts(string $productName, string $productDescription): array
    {
        if ($this->apiKey === '') {
            throw new AiStudioApiException('CLAUDE_API_KEY não configurada (ver .env / shared/.env).');
        }

        $userMessage = "Produto: {$productName}\nDescrição: {$productDescription}\n\nLembrete: os 3 prompts serão usados para EDITAR a foto real já existente deste produto (não para gerar do zero) — descreva apenas as mudanças de cenário/fundo/iluminação desejadas para cada modo, mantendo claro que o produto em si não deve mudar.";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $response = AiStudioHttpClient::request(
            'POST',
            'https://api.anthropic.com/v1/messages',
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new AiStudioApiException(
                "Claude /v1/messages retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = AiStudioHttpClient::decodeJsonOrFail($response['body'], 'Claude /v1/messages');

        $text = $decoded['content'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiStudioApiException('Claude /v1/messages: resposta sem content[0].text utilizável.');
        }

        // O modelo às vezes envolve o JSON em ```json ... ``` mesmo quando
        // instruído a não fazer isso — removemos a cerca de código se vier.
        $cleanText = trim($text);
        $cleanText = preg_replace('/^```(?:json)?\s*/i', '', $cleanText) ?? $cleanText;
        $cleanText = preg_replace('/\s*```$/', '', $cleanText) ?? $cleanText;

        $prompts = json_decode($cleanText, true);
        if (!is_array($prompts)) {
            throw new AiStudioApiException('Claude retornou um texto que não é JSON válido: ' . substr($cleanText, 0, 500));
        }

        foreach (['white', 'hero', 'ambient'] as $key) {
            if (!isset($prompts[$key]) || !is_string($prompts[$key]) || trim($prompts[$key]) === '') {
                throw new AiStudioApiException("Claude retornou JSON sem a chave obrigatória '$key' (ou vazia).");
            }
        }

        return [
            'white' => $prompts['white'],
            'hero' => $prompts['hero'],
            'ambient' => $prompts['ambient'],
        ];
    }
}
