<?php

declare(strict_types=1);

/**
 * Serviços de IA de TEXTO para otimização de cadastro de produto — módulo
 * isolado e independente do AI Image Studio (admin/ai-image-studio/), sem
 * dependências cruzadas, prefixo de classes "CatalogAi*" para não colidir.
 *
 * 3 provedores, 1 método unificado (CatalogAiTextProvider::complete):
 *   - CatalogAiOpenAiTextProvider: api.openai.com/v1/chat/completions
 *   - CatalogAiGeminiTextProvider: generativelanguage.googleapis.com (Gemini)
 *   - CatalogAiClaudeTextProvider: api.anthropic.com/v1/messages
 *
 * IMPORTANTE — EXECUÇÃO REAL, SEM MOCK: nenhuma classe aqui simula sucesso.
 * Chave ausente, erro de rede, HTTP != 2xx, ou corpo que não vira um JSON
 * válido com as chaves esperadas sempre lança CatalogAiApiException — nunca
 * retorna um array "fake" só para o chamador achar que funcionou.
 */

class CatalogAiApiException extends RuntimeException
{
}

interface CatalogAiTextProvider
{
    /**
     * Envia system prompt + user prompt para o provedor e devolve o corpo
     * JÁ decodificado como array associativo (json_decode($x, true)).
     * O provedor NÃO valida as chaves de negócio (isso é responsabilidade
     * de quem chama, em api/optimize_catalog.php) — só garante que o texto
     * devolvido é JSON sintaticamente válido, higienizando cercas de
     * markdown (```json ... ```) que o modelo às vezes insere mesmo quando
     * instruído a não fazer isso.
     *
     * @return array<string,mixed>
     * @throws CatalogAiApiException
     */
    public function complete(string $systemPrompt, string $userPrompt): array;
}

/**
 * Helper interno de cURL compartilhado pelos 3 provedores.
 */
final class CatalogAiHttpClient
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $jsonBody
     * @return array{status:int, body:string}
     */
    public static function postJson(string $url, array $headers, array $jsonBody, int $timeoutSeconds): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new CatalogAiApiException('Falha ao inicializar cURL.');
        }

        $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            curl_close($ch);
            throw new CatalogAiApiException('Falha ao codificar corpo JSON da requisição: ' . json_last_error_msg());
        }

        $curlHeaders = ['Content-Type: application/json'];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
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
            $timeoutHint = $errNo === CURLE_OPERATION_TIMEDOUT
                ? " (timeout após {$timeoutSeconds}s — considere aumentar CATALOG_AI_HTTP_TIMEOUT_SECONDS)"
                : '';
            throw new CatalogAiApiException("Erro de transporte cURL (#$errNo) chamando $url: $errMsg$timeoutHint");
        }

        if ($body === false) {
            throw new CatalogAiApiException("Resposta vazia/inválida de $url (sem corpo).");
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * Remove cercas de markdown (```json ... ``` ou ``` ... ```) que o
     * modelo às vezes insere ao redor do JSON mesmo quando instruído a não
     * fazer isso, e faz json_decode. Lança exceção clara se, mesmo depois
     * da limpeza, não for um JSON válido — nunca retorna array vazio como
     * se fosse sucesso.
     *
     * @return array<string,mixed>
     * @throws CatalogAiApiException
     */
    public static function sanitizeAndDecodeJson(string $rawText, string $context): array
    {
        $clean = trim($rawText);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        if ($clean === '') {
            throw new CatalogAiApiException("$context: resposta de texto vazia — não há o que decodificar como JSON.");
        }

        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            $snippet = substr($clean, 0, 500);
            throw new CatalogAiApiException("$context: resposta não é um JSON válido mesmo após remover cercas markdown. Corpo recebido (truncado): $snippet");
        }

        return $decoded;
    }
}

/**
 * OpenAI — /v1/chat/completions com response_format json_object, para
 * forçar retorno estritamente JSON (suportado pelos modelos gpt-4.1/gpt-4o
 * e mais recentes).
 */
final class CatalogAiOpenAiTextProvider implements CatalogAiTextProvider
{
    public function __construct(private string $apiKey, private string $model, private int $timeoutSeconds)
    {
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->apiKey === '') {
            throw new CatalogAiApiException('OPENAI_API_KEY não configurada (ver .env / shared/.env). Execução abortada — sem chave, não há chamada real possível.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ];

        $response = CatalogAiHttpClient::postJson(
            'https://api.openai.com/v1/chat/completions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $payload,
            $this->timeoutSeconds
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new CatalogAiApiException(
                "OpenAI chat/completions retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new CatalogAiApiException('OpenAI chat/completions: corpo da resposta HTTP não é JSON válido.');
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new CatalogAiApiException('OpenAI chat/completions: resposta sem choices[0].message.content utilizável.');
        }

        return CatalogAiHttpClient::sanitizeAndDecodeJson($text, 'OpenAI chat/completions');
    }
}

/**
 * Google Gemini — :generateContent com generationConfig.responseMimeType =
 * application/json, para forçar retorno estritamente JSON (suportado pelo
 * gemini-2.5-pro e mais recentes). Autenticação por API key simples
 * (Google AI Studio / Gemini API — mesma família de chave usada pelo AI
 * Image Studio, mas este módulo lê sua própria variável de ambiente
 * (GOOGLE_GEMINI_API_KEY) por ser explicitamente isolado/independente).
 */
final class CatalogAiGeminiTextProvider implements CatalogAiTextProvider
{
    public function __construct(private string $apiKey, private string $model, private int $timeoutSeconds)
    {
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->apiKey === '') {
            throw new CatalogAiApiException('GOOGLE_GEMINI_API_KEY não configurada (ver .env / shared/.env). Execução abortada — sem chave, não há chamada real possível.');
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.7,
            ],
        ];

        $response = CatalogAiHttpClient::postJson($url, [], $payload, $this->timeoutSeconds);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new CatalogAiApiException(
                "Google Gemini (:generateContent) retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new CatalogAiApiException('Google Gemini: corpo da resposta HTTP não é JSON válido.');
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $blockReason = (string) ($decoded['promptFeedback']['blockReason'] ?? '');
            $extra = $blockReason !== '' ? " (bloqueado pelo Google: $blockReason)" : '';
            throw new CatalogAiApiException('Google Gemini: resposta sem candidates[0].content.parts[0].text utilizável.' . $extra);
        }

        return CatalogAiHttpClient::sanitizeAndDecodeJson($text, 'Google Gemini (:generateContent)');
    }
}

/**
 * Anthropic Claude — /v1/messages. A API do Claude não tem um "modo JSON"
 * dedicado como OpenAI/Gemini; a garantia de JSON vem do system prompt
 * (instrução rígida, ver api/optimize_catalog.php) + sanitização de cercas
 * markdown do lado do cliente antes do json_decode.
 */
final class CatalogAiClaudeTextProvider implements CatalogAiTextProvider
{
    public function __construct(private string $apiKey, private string $model, private int $timeoutSeconds)
    {
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->apiKey === '') {
            throw new CatalogAiApiException('CLAUDE_API_KEY não configurada (ver .env / shared/.env). Execução abortada — sem chave, não há chamada real possível.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $response = CatalogAiHttpClient::postJson(
            'https://api.anthropic.com/v1/messages',
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            $payload,
            $this->timeoutSeconds
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new CatalogAiApiException(
                "Claude /v1/messages retornou HTTP {$response['status']}" .
                ($apiMessage !== '' ? ": $apiMessage" : ': ' . substr($response['body'], 0, 500))
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new CatalogAiApiException('Claude /v1/messages: corpo da resposta HTTP não é JSON válido.');
        }

        $text = $decoded['content'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new CatalogAiApiException('Claude /v1/messages: resposta sem content[0].text utilizável.');
        }

        return CatalogAiHttpClient::sanitizeAndDecodeJson($text, 'Claude /v1/messages');
    }
}

/**
 * Fábrica: resolve o provedor de texto a partir da string vinda da
 * requisição ('openai' | 'gemini' | 'claude'), já injetando chave/modelo/
 * timeout das constantes de config_optimization.php.
 *
 * @throws CatalogAiApiException se o provider for desconhecido
 */
function catalog_ai_make_provider(string $provider): CatalogAiTextProvider
{
    return match ($provider) {
        'openai' => new CatalogAiOpenAiTextProvider(CATALOG_AI_OPENAI_API_KEY, CATALOG_AI_OPENAI_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        'gemini' => new CatalogAiGeminiTextProvider(CATALOG_AI_GOOGLE_GEMINI_API_KEY, CATALOG_AI_GEMINI_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        'claude' => new CatalogAiClaudeTextProvider(CATALOG_AI_CLAUDE_API_KEY, CATALOG_AI_CLAUDE_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        default => throw new CatalogAiApiException("Provider inválido: '$provider'. Use 'openai', 'gemini' ou 'claude'."),
    };
}
