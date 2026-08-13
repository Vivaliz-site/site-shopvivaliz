<?php

declare(strict_types=1);

class CatalogAiApiException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 0, public array $response = [])
    {
        parent::__construct($message);
    }
}

interface CatalogAiTextProvider
{
    /** @return array<string,mixed> */
    public function complete(string $systemPrompt, string $userPrompt): array;
}

final class CatalogAiHttpClient
{
    /** @return array{status:int,body:string} */
    public static function postJson(string $url, array $headers, array $body, int $timeout): array
    {
        $handle = curl_init();
        if ($handle === false) throw new CatalogAiApiException('Falha ao inicializar cURL.');
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new CatalogAiApiException('Falha ao codificar JSON: ' . json_last_error_msg());
        $httpHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) $httpHeaders[] = $name . ': ' . $value;
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ]);
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($errno !== 0 || !is_string($raw)) {
            throw new CatalogAiApiException("Erro de transporte cURL #{$errno}: {$error}");
        }
        return ['status' => $status, 'body' => $raw];
    }

    /** @return array<string,mixed> */
    public static function decodeModelJson(string $text, string $context): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $decoded = json_decode(trim($clean), true);
        if (!is_array($decoded)) {
            throw new CatalogAiApiException($context . ': resposta do modelo não é JSON válido.');
        }
        return $decoded;
    }

    public static function safeMessage(string $message): string
    {
        $message = preg_replace('/\bsk-(?:proj-|ant-api\d*-)?[A-Za-z0-9_\-]{12,}\b/', '[chave-oculta]', $message) ?? $message;
        $message = preg_replace('/\bAIza[A-Za-z0-9_\-]{20,}\b/', '[chave-oculta]', $message) ?? $message;
        return function_exists('mb_substr') ? mb_substr($message, 0, 800) : substr($message, 0, 800);
    }
}

final class CatalogAiKeyPool
{
    /** @return list<string> */
    public static function normalize(string|array $keys): array
    {
        $items = is_array($keys) ? $keys : (preg_split('/[\r\n,;]+/', (string)$keys) ?: []);
        $items = array_map(
            static fn(mixed $value): string => trim((string)$value),
            $items
        );
        return array_values(array_unique(array_filter($items, static fn(string $value): bool => $value !== '')));
    }

    public static function shouldRotate(int $status, string $message): bool
    {
        if (in_array($status, [401, 403, 429], true)) return true;
        $text = strtolower($message);
        foreach ([
            'insufficient_quota', 'quota exceeded', 'quota_exceeded', 'resource_exhausted',
            'rate limit', 'rate_limit', 'too many requests', 'credit balance', 'billing',
            'api key not valid', 'invalid api key', 'invalid_api_key', 'authentication',
            'unauthorized', 'permission denied', 'token has been exhausted', 'exceeded your current quota',
        ] as $marker) {
            if (str_contains($text, $marker)) return true;
        }
        return false;
    }
}

/**
 * Aplica a normalizacao deterministica somente a respostas que pertencem a um
 * fluxo real de geracao de catalogo. ai_catalog_build_user_prompt() registra o
 * produto/canal no contexto antes da chamada ao provedor; validacoes manuais
 * que nao passam por um provedor continuam intocadas e podem ser bloqueadas
 * normalmente pelo quality gate.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function catalog_ai_normalize_generated_data(array $data): array
{
    $context = $GLOBALS['ai_catalog_validation_context'] ?? null;
    if (!is_array($context)) return $data;

    $channel = strtolower(trim((string)($context['channel'] ?? '')));
    $product = $context['product'] ?? null;
    if ($channel === '' || !is_array($product)) return $data;

    if (!function_exists('catalog_generated_normalize')) {
        $normalizer = __DIR__ . '/CatalogGeneratedDataNormalizer.php';
        if (is_file($normalizer)) require_once $normalizer;
    }
    if (!function_exists('catalog_generated_normalize')
        || !function_exists('ai_catalog_policy')
        || !function_exists('ai_catalog_source_blob')
        || !function_exists('ai_catalog_title_starts_with_identity')) {
        throw new CatalogAiApiException('Normalizador deterministico do catalogo indisponivel no contexto de geracao.');
    }

    return catalog_generated_normalize($data, $channel, $product);
}

abstract class CatalogAiRotatingProvider implements CatalogAiTextProvider
{
    /** @var list<string> */
    protected array $keys;

    public function __construct(string|array $keys, protected string $model, protected int $timeoutSeconds)
    {
        $this->keys = CatalogAiKeyPool::normalize($keys);
    }

    /** @return array<string,mixed> */
    final public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->keys === []) throw new CatalogAiApiException($this->missingKeyMessage());
        $last = null;
        foreach ($this->keys as $index => $key) {
            try {
                $data = $this->completeWithKey($key, $systemPrompt, $userPrompt);
                return catalog_ai_normalize_generated_data($data);
            } catch (CatalogAiApiException $exception) {
                $last = $exception;
                $rotate = CatalogAiKeyPool::shouldRotate($exception->httpStatus, $exception->getMessage());
                if (!$rotate || $index === array_key_last($this->keys)) throw $exception;
                error_log(sprintf('[catalog-ai] %s chave #%d indisponível por cota/autenticação; alternando para #%d.', $this->providerName(), $index + 1, $index + 2));
            }
        }
        throw $last ?? new CatalogAiApiException($this->providerName() . ': nenhuma chave disponível.');
    }

    abstract protected function providerName(): string;
    abstract protected function missingKeyMessage(): string;
    /** @return array<string,mixed> */
    abstract protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array;

    protected function failure(string $context, array $response): CatalogAiApiException
    {
        $decoded = json_decode($response['body'], true);
        $message = '';
        if (is_array($decoded)) {
            $message = (string)($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error']['status'] ?? '');
        }
        if ($message === '') $message = substr((string)$response['body'], 0, 500);
        $message = CatalogAiHttpClient::safeMessage($message);
        return new CatalogAiApiException("{$context} retornou HTTP {$response['status']}: {$message}", (int)$response['status'], is_array($decoded) ? $decoded : []);
    }
}

final class CatalogAiOpenAiTextProvider extends CatalogAiRotatingProvider
{
    protected function providerName(): string { return 'OpenAI'; }
    protected function missingKeyMessage(): string { return 'Nenhuma OPENAI_API_KEY configurada no ambiente privado.'; }

    protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array
    {
        $response = CatalogAiHttpClient::postJson('https://api.openai.com/v1/chat/completions', [
            'Authorization' => 'Bearer ' . $key,
        ], [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ], $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300) throw $this->failure('OpenAI chat/completions', $response);
        $decoded = json_decode($response['body'], true);
        $text = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') throw new CatalogAiApiException('OpenAI retornou resposta sem conteúdo utilizável.');
        return CatalogAiHttpClient::decodeModelJson($text, 'OpenAI');
    }
}

final class CatalogAiGeminiTextProvider extends CatalogAiRotatingProvider
{
    protected function providerName(): string { return 'Gemini'; }
    protected function missingKeyMessage(): string { return 'Nenhuma GEMINI_API_KEY configurada no ambiente privado.'; }

    protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array
    {
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode($this->model), rawurlencode($key));
        $response = CatalogAiHttpClient::postJson($url, [], [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.7],
        ], $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300) throw $this->failure('Gemini generateContent', $response);
        $decoded = json_decode($response['body'], true);
        $text = is_array($decoded) ? ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            $reason = is_array($decoded) ? (string)($decoded['promptFeedback']['blockReason'] ?? '') : '';
            throw new CatalogAiApiException('Gemini retornou resposta sem texto utilizável' . ($reason !== '' ? ': ' . $reason : '.'));
        }
        return CatalogAiHttpClient::decodeModelJson($text, 'Gemini');
    }
}

final class CatalogAiClaudeTextProvider extends CatalogAiRotatingProvider
{
    protected function providerName(): string { return 'Claude'; }
    protected function missingKeyMessage(): string { return 'Nenhuma ANTHROPIC_API_KEY configurada no ambiente privado.'; }

    protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array
    {
        $response = CatalogAiHttpClient::postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ], [
            'model' => $this->model,
            'max_tokens' => 4096,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
        ], $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300) throw $this->failure('Claude messages', $response);
        $decoded = json_decode($response['body'], true);
        $text = is_array($decoded) ? ($decoded['content'][0]['text'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') throw new CatalogAiApiException('Claude retornou resposta sem texto utilizável.');
        return CatalogAiHttpClient::decodeModelJson($text, 'Claude');
    }
}

final class CatalogAiOpenAiCompatibleTextProvider extends CatalogAiRotatingProvider
{
    public function __construct(
        string|array $keys,
        protected string $model,
        protected int $timeoutSeconds,
        protected string $baseUrl,
        protected string $providerLabel,
        protected array $extraHeaders = []
    ) {
        parent::__construct($keys, $model, $timeoutSeconds);
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    protected function providerName(): string { return $this->providerLabel; }
    protected function missingKeyMessage(): string { return 'Nenhuma chave configurada para ' . $this->providerLabel . ' no ambiente privado.'; }

    protected function completeWithKey(string $key, string $systemPrompt, string $userPrompt): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
        ];
        // OpenRouter pode usar o limite máximo do modelo quando max_tokens não
        // é informado. Para este JSON de catálogo isso é desnecessário e pode
        // transformar saldo suficiente em HTTP 402. Mantemos um teto explícito
        // sem alterar o comportamento do Groq.
        if (strcasecmp($this->providerLabel, 'OpenRouter') === 0) {
            $payload['max_tokens'] = 3200;
        }
        $response = CatalogAiHttpClient::postJson($this->baseUrl . '/chat/completions', array_merge([
            'Authorization' => 'Bearer ' . $key,
        ], $this->extraHeaders), $payload, $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300) throw $this->failure($this->providerLabel . ' chat/completions', $response);
        $decoded = json_decode($response['body'], true);
        $text = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            throw new CatalogAiApiException($this->providerLabel . ' retornou resposta sem conteúdo utilizável.');
        }
        return CatalogAiHttpClient::decodeModelJson($text, $this->providerLabel);
    }
}

function catalog_ai_normalize_provider(string $provider): string
{
    return match (strtolower(trim($provider))) {
        'gpt', 'openai' => 'openai',
        'gemini' => 'gemini',
        'claude' => 'claude',
        'openrouter' => 'openrouter',
        'groq', 'qrope' => 'groq',
        default => strtolower(trim($provider)),
    };
}

/** @return list<string> */
function catalog_ai_provider_fallback_order(string $preferred): array
{
    $preferred = catalog_ai_normalize_provider($preferred);
    $order = match ($preferred) {
        'openai' => ['openai', 'gemini', 'openrouter', 'groq', 'claude'],
        'gemini' => ['gemini', 'openai', 'openrouter', 'groq', 'claude'],
        'claude' => ['claude', 'openai', 'gemini', 'openrouter', 'groq'],
        'openrouter' => ['openrouter', 'groq', 'openai', 'gemini', 'claude'],
        'groq' => ['groq', 'openrouter', 'openai', 'gemini', 'claude'],
        default => ['openai', 'gemini', 'openrouter', 'groq', 'claude'],
    };
    return array_values(array_unique(array_filter($order, static fn(string $value): bool => in_array($value, ['openai', 'gemini', 'claude', 'openrouter', 'groq'], true))));
}

function catalog_ai_provider_has_keys(string $provider): bool
{
    return match (catalog_ai_normalize_provider($provider)) {
        'openai' => CatalogAiKeyPool::normalize(CATALOG_AI_OPENAI_API_KEY) !== [],
        'gemini' => CatalogAiKeyPool::normalize(CATALOG_AI_GOOGLE_GEMINI_API_KEY) !== [],
        'claude' => CatalogAiKeyPool::normalize(CATALOG_AI_CLAUDE_API_KEY) !== [],
        'openrouter' => CatalogAiKeyPool::normalize(CATALOG_AI_OPENROUTER_API_KEY) !== [],
        'groq' => CatalogAiKeyPool::normalize(CATALOG_AI_GROQ_API_KEY) !== [],
        default => false,
    };
}

function catalog_ai_resolve_provider_name(string $preferred): string
{
    foreach (catalog_ai_provider_fallback_order($preferred) as $candidate) {
        if (catalog_ai_provider_has_keys($candidate)) {
            return $candidate;
        }
    }
    throw new CatalogAiApiException('Nenhum provedor de texto possui chave configurada no ambiente privado.');
}

function catalog_ai_make_provider(string $provider): CatalogAiTextProvider
{
    return match (catalog_ai_normalize_provider($provider)) {
        'openai' => new CatalogAiOpenAiTextProvider(CATALOG_AI_OPENAI_API_KEY, CATALOG_AI_OPENAI_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        'gemini' => new CatalogAiGeminiTextProvider(CATALOG_AI_GOOGLE_GEMINI_API_KEY, CATALOG_AI_GEMINI_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        'claude' => new CatalogAiClaudeTextProvider(CATALOG_AI_CLAUDE_API_KEY, CATALOG_AI_CLAUDE_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS),
        'openrouter' => new CatalogAiOpenAiCompatibleTextProvider(CATALOG_AI_OPENROUTER_API_KEY, CATALOG_AI_OPENROUTER_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS, CATALOG_AI_OPENROUTER_API_BASE_URL, 'OpenRouter', [
            'HTTP-Referer' => CATALOG_AI_OPENROUTER_HTTP_REFERER,
            'X-OpenRouter-Title' => CATALOG_AI_OPENROUTER_APP_TITLE,
        ]),
        'groq' => new CatalogAiOpenAiCompatibleTextProvider(CATALOG_AI_GROQ_API_KEY, CATALOG_AI_GROQ_MODEL, CATALOG_AI_HTTP_TIMEOUT_SECONDS, CATALOG_AI_GROQ_API_BASE_URL, 'Groq'),
        default => throw new CatalogAiApiException("Provider inválido: {$provider}."),
    };
}
