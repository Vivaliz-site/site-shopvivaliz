<?php

declare(strict_types=1);

class AiStudioApiException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 0, public array $response = [])
    {
        parent::__construct($message);
    }
}

final class AiStudioKeyPool
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
        if (in_array($status, [401, 402, 403, 429], true)) return true;
        $text = strtolower($message);
        foreach ([
            'insufficient_quota', 'quota exceeded', 'quota_exceeded', 'resource_exhausted',
            'rate limit', 'rate_limit', 'too many requests', 'credit balance', 'billing',
            'api key not valid', 'invalid api key', 'invalid_api_key', 'authentication',
            'unauthorized', 'permission denied', 'exceeded your current quota',
        ] as $marker) {
            if (str_contains($text, $marker)) return true;
        }
        return false;
    }
}

function ai_studio_normalize_provider(string $provider): string
{
    return match (strtolower(trim($provider))) {
        'gpt', 'openai' => 'openai',
        'gemini', 'google' => 'google',
        'claude' => 'claude',
        'groq', 'qrope' => 'groq',
        'openrouter' => 'openrouter',
        default => strtolower(trim($provider)),
    };
}

/**
 * @param list<string> $envNames
 * @return list<string>
 */
function ai_studio_secret_pool(string $constantName, array $envNames): array
{
    $values = [];
    if (defined($constantName)) {
        $value = constant($constantName);
        if (is_array($value)) {
            $values = array_merge($values, $value);
        } elseif (is_string($value) && $value !== '') {
            $values[] = $value;
        }
    }

    foreach ($envNames as $envName) {
        $value = trim((string)getenv($envName));
        if ($value !== '') {
            $values[] = $value;
        }
        for ($index = 1; $index <= 10; $index++) {
            $indexed = trim((string)getenv($envName . '_' . $index));
            if ($indexed !== '') {
                $values[] = $indexed;
            }
        }
        $plural = trim((string)getenv($envName . 'S'));
        if ($plural !== '') {
            $decoded = json_decode($plural, true);
            $items = is_array($decoded) ? $decoded : (preg_split('/[\r\n,;]+/', $plural) ?: []);
            foreach ($items as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }
    }

    return AiStudioKeyPool::normalize($values);
}

/** @return list<string> */
function ai_studio_provider_fallback_order(string $preferred): array
{
    $preferred = ai_studio_normalize_provider($preferred);
    $order = match ($preferred) {
        'openai' => ['openai', 'google', 'openrouter', 'groq', 'claude'],
        'google' => ['google', 'openai', 'openrouter', 'groq', 'claude'],
        'claude' => ['openai', 'google', 'openrouter', 'groq'],
        'openrouter' => ['openrouter', 'groq', 'openai', 'google', 'claude'],
        'groq' => ['groq', 'openrouter', 'openai', 'google', 'claude'],
        default => ['openai', 'google', 'openrouter', 'groq'],
    };
    return array_values(array_unique(array_filter($order, static fn(string $value): bool => in_array($value, ['openai', 'google', 'claude', 'openrouter', 'groq'], true))));
}

function ai_studio_provider_has_key(string $provider): bool
{
    return match (ai_studio_normalize_provider($provider)) {
        'openai' => ai_studio_secret_pool('AI_STUDIO_OPENAI_API_KEY', ['AI_STUDIO_OPENAI_API_KEY', 'OPENAI_API_KEY']) !== [],
        'google' => ai_studio_secret_pool('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', ['AI_STUDIO_GOOGLE_IMAGEN_API_KEY', 'GOOGLE_IMAGEN_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY']) !== [],
        'claude' => ai_studio_secret_pool('AI_STUDIO_CLAUDE_API_KEY', ['AI_STUDIO_CLAUDE_API_KEY', 'CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']) !== [],
        'openrouter' => ai_studio_secret_pool('AI_STUDIO_OPENROUTER_API_KEY', ['AI_STUDIO_OPENROUTER_API_KEY', 'OPENROUTER_API_KEY']) !== [],
        'groq' => ai_studio_secret_pool('AI_STUDIO_GROQ_API_KEY', ['AI_STUDIO_GROQ_API_KEY', 'GROQ_API_KEY']) !== [],
        default => false,
    };
}

function ai_studio_resolve_image_engine(string $preferred): string
{
    foreach (ai_studio_provider_fallback_order($preferred) as $candidate) {
        if (ai_studio_provider_has_key($candidate)) {
            return $candidate;
        }
    }
    throw new AiStudioApiException('Nenhuma chave de edicao de imagem (OpenAI, Gemini, Claude, OpenRouter ou Groq) está configurada no ambiente privado.');
}

final class AiStudioOpenAiCompatibleClient extends AiStudioRotatingClient
{
    public function __construct(string|array $keys, protected string $model, protected string $baseUrl, protected string $providerLabel, protected array $extraHeaders = [])
    {
        parent::__construct($keys, $model);
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath): void
    {
        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) {
            throw new AiStudioApiException('Imagem base ausente ou ilegível.');
        }
        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);
        $this->withKeyRotation(function (string $key) use ($prompt, $baseImagePath, $destinationPath, $mime): void {
            $response = AiStudioHttpClient::requestMultipart($this->baseUrl . '/images/edits', array_merge([
                'Authorization' => 'Bearer ' . $key,
            ], $this->extraHeaders), [
                'model' => $this->model,
                'prompt' => $prompt,
                'image[]' => new CURLFile($baseImagePath, $mime, basename($baseImagePath)),
                'n' => '1',
                'size' => '1024x1024',
            ], 180);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw AiStudioHttpClient::apiFailure($this->providerLabel . ' images/edits', $response);
            }
            $decoded = AiStudioHttpClient::decodeJson($response['body'], $this->providerLabel . ' images/edits');
            $item = $decoded['data'][0] ?? null;
            if (!is_array($item)) {
                throw new AiStudioApiException($this->providerLabel . ' não retornou data[0].');
            }
            if (is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
                $binary = base64_decode($item['b64_json'], true);
                if (!is_string($binary) || file_put_contents($destinationPath, $binary) === false) {
                    throw new AiStudioApiException('Falha ao gravar imagem ' . $this->providerLabel . '.');
                }
                return;
            }
            if (is_string($item['url'] ?? null) && $item['url'] !== '') {
                AiStudioHttpClient::downloadToFile($item['url'], $destinationPath);
                return;
            }
            throw new AiStudioApiException($this->providerLabel . ' não retornou b64_json nem URL.');
        }, $this->providerLabel);
        AiStudioHttpClient::validateOutputImage($destinationPath, 1000);
    }
}

final class AiStudioHttpClient
{
    /** @return array<string,mixed> */
    private static function sslOptions(): array
    {
        $options = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $cainfo = trim((string)(getenv('SHOPVIVALIZ_CURL_CAINFO') ?: ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: ''));
        if ($cainfo === '') {
            $fallback = dirname(__DIR__, 3) . '/storage/certs/cacert.pem';
            if (is_file($fallback)) {
                $cainfo = $fallback;
            }
        }
        if ($cainfo !== '' && is_file($cainfo) && is_readable($cainfo)) {
            $options[CURLOPT_CAINFO] = $cainfo;
        }
        return $options;
    }

    /** @return array{status:int,body:string} */
    public static function request(string $method, string $url, array $headers = [], ?array $jsonBody = null, int $timeoutSeconds = 120): array
    {
        $handle = curl_init();
        if ($handle === false) throw new AiStudioApiException('Falha ao inicializar cURL.');
        $httpHeaders = [];
        foreach ($headers as $name => $value) $httpHeaders[] = $name . ': ' . $value;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FAILONERROR => false,
        ] + self::sslOptions();
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) throw new AiStudioApiException('Falha ao codificar JSON: ' . json_last_error_msg());
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($errno !== 0 || !is_string($raw)) throw new AiStudioApiException("Erro de transporte cURL #{$errno}: {$error}");
        return ['status' => $status, 'body' => $raw];
    }

    /** @return array{status:int,body:string} */
    public static function requestMultipart(string $url, array $headers, array $fields, int $timeoutSeconds = 180): array
    {
        $handle = curl_init();
        if ($handle === false) throw new AiStudioApiException('Falha ao inicializar cURL multipart.');
        $httpHeaders = [];
        foreach ($headers as $name => $value) $httpHeaders[] = $name . ': ' . $value;
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FAILONERROR => false,
        ] + self::sslOptions());
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($errno !== 0 || !is_string($raw)) throw new AiStudioApiException("Erro de transporte cURL #{$errno}: {$error}");
        return ['status' => $status, 'body' => $raw];
    }

    public static function downloadToFile(string $url, string $destinationPath, int $timeoutSeconds = 90): void
    {
        $handle = curl_init();
        if ($handle === false) throw new AiStudioApiException('Falha ao iniciar download.');
        $file = @fopen($destinationPath, 'wb');
        if ($file === false) throw new AiStudioApiException('Diretório de destino sem permissão de escrita.');
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
        ] + self::sslOptions());
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        fclose($file);
        if ($errno !== 0 || $ok === false || $status < 200 || $status >= 300) {
            @unlink($destinationPath);
            throw new AiStudioApiException("Falha ao baixar imagem (HTTP {$status}, cURL {$errno}): {$error}", $status);
        }
    }

    /** @return array<string,mixed> */
    public static function decodeJson(string $raw, string $context): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new AiStudioApiException($context . ': resposta não é JSON válido.');
        return $decoded;
    }

    public static function detectImageMime(string $filePath): string
    {
        $info = @getimagesize($filePath);
        if (is_array($info) && is_string($info['mime'] ?? null)) return $info['mime'];
        return match (strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /**
     * Valida o arquivo produzido pelo provedor antes de qualquer caller poder
     * marcá-lo como pending. Isso protege também o fluxo "Regenerar" do Admin,
     * que chama os clientes de imagem diretamente. Como o Studio gera imagens
     * quadradas, 1000px por lado garante o patamar recomendado para zoom da
     * imagem principal Amazon e supera o minimo de 600x600 do TikTok Shop.
     *
     * @return array{width:int,height:int,mime:string,sha256:string}
     */
    public static function validateOutputImage(string $filePath, int $minimumSide = 1000): array
    {
        if (!is_file($filePath) || !is_readable($filePath) || (int)@filesize($filePath) <= 0) {
            throw new AiStudioApiException('Provedor não produziu um arquivo de imagem legível.');
        }

        $info = @getimagesize($filePath);
        if (!is_array($info)) {
            throw new AiStudioApiException('Provedor retornou conteúdo que não é uma imagem válida.');
        }

        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        $mime = strtolower(trim((string)($info['mime'] ?? '')));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AiStudioApiException("Formato real da imagem produzida não permitido: {$mime}.");
        }
        if ($width < $minimumSide || $height < $minimumSide) {
            throw new AiStudioApiException("Imagem produzida abaixo da qualidade mínima: {$width}x{$height}; mínimo {$minimumSide}px por lado.");
        }

        $hash = hash_file('sha256', $filePath);
        if (!is_string($hash) || $hash === '') {
            throw new AiStudioApiException('Falha ao calcular fingerprint da imagem produzida.');
        }

        return ['width' => $width, 'height' => $height, 'mime' => $mime, 'sha256' => $hash];
    }

    public static function apiFailure(string $context, array $response): AiStudioApiException
    {
        $decoded = json_decode((string)$response['body'], true);
        $message = is_array($decoded)
            ? (string)($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error']['status'] ?? '')
            : '';
        if ($message === '') $message = substr((string)$response['body'], 0, 500);
        $message = preg_replace('/\bsk-(?:proj-|ant-api\d*-)?[A-Za-z0-9_\-]{12,}\b/', '[chave-oculta]', $message) ?? $message;
        return new AiStudioApiException("{$context} retornou HTTP {$response['status']}: {$message}", (int)$response['status'], is_array($decoded) ? $decoded : []);
    }
}

abstract class AiStudioRotatingClient
{
    /** @var list<string> */
    protected array $keys;

    public function __construct(string|array $keys, protected string $model)
    {
        $this->keys = AiStudioKeyPool::normalize($keys);
    }

    protected function withKeyRotation(callable $operation, string $provider): mixed
    {
        if ($this->keys === []) throw new AiStudioApiException("Nenhuma chave {$provider} configurada no ambiente privado.");
        $last = null;
        foreach ($this->keys as $index => $key) {
            try {
                return $operation($key);
            } catch (AiStudioApiException $exception) {
                $last = $exception;
                if (!AiStudioKeyPool::shouldRotate($exception->httpStatus, $exception->getMessage()) || $index === array_key_last($this->keys)) {
                    throw $exception;
                }
                error_log(sprintf('[ai-image-studio] %s chave #%d indisponível por cota/autenticação; alternando para #%d.', $provider, $index + 1, $index + 2));
            }
        }
        throw $last ?? new AiStudioApiException("Nenhuma chave {$provider} disponível.");
    }
}

final class AiStudioOpenAiClient extends AiStudioRotatingClient
{
    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath, string $size = '1024x1024'): void
    {
        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) throw new AiStudioApiException('Imagem base ausente ou ilegível.');
        $fileSize = (int)@filesize($baseImagePath);
        if ($fileSize <= 0 || $fileSize > 25 * 1024 * 1024) throw new AiStudioApiException('Imagem base fora do limite de 25 MB da OpenAI.');
        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);
        $this->withKeyRotation(function (string $key) use ($prompt, $baseImagePath, $destinationPath, $size, $mime): void {
            $response = AiStudioHttpClient::requestMultipart('https://api.openai.com/v1/images/edits', [
                'Authorization' => 'Bearer ' . $key,
            ], [
                'model' => $this->model,
                'prompt' => $prompt,
                'image[]' => new CURLFile($baseImagePath, $mime, basename($baseImagePath)),
                'n' => '1',
                'size' => $size,
                'input_fidelity' => 'high',
            ]);
            if ($response['status'] < 200 || $response['status'] >= 300) throw AiStudioHttpClient::apiFailure('OpenAI images/edits', $response);
            $decoded = AiStudioHttpClient::decodeJson($response['body'], 'OpenAI images/edits');
            $item = $decoded['data'][0] ?? null;
            if (!is_array($item)) throw new AiStudioApiException('OpenAI não retornou data[0].');
            if (is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
                $binary = base64_decode($item['b64_json'], true);
                if (!is_string($binary) || file_put_contents($destinationPath, $binary) === false) throw new AiStudioApiException('Falha ao gravar imagem OpenAI.');
                return;
            }
            if (is_string($item['url'] ?? null) && $item['url'] !== '') {
                AiStudioHttpClient::downloadToFile($item['url'], $destinationPath);
                return;
            }
            throw new AiStudioApiException('OpenAI não retornou b64_json nem URL.');
        }, 'OpenAI');
        AiStudioHttpClient::validateOutputImage($destinationPath, 1000);
    }
}

final class AiStudioGoogleImageEditClient extends AiStudioRotatingClient
{
    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath): void
    {
        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) throw new AiStudioApiException('Imagem base ausente ou ilegível.');
        $binary = @file_get_contents($baseImagePath);
        if (!is_string($binary)) throw new AiStudioApiException('Falha ao ler imagem base.');
        if (strlen($binary) > 19 * 1024 * 1024) throw new AiStudioApiException('Imagem base excede o limite inline do Gemini.');
        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);
        $this->withKeyRotation(function (string $key) use ($prompt, $binary, $mime, $destinationPath): void {
            $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode($this->model), rawurlencode($key));
            $response = AiStudioHttpClient::request('POST', $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], [
                'contents' => [['parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($binary)]],
                    ['text' => $prompt],
                ]]],
                'generationConfig' => ['responseModalities' => ['IMAGE']],
            ], 180);
            if ($response['status'] < 200 || $response['status'] >= 300) throw AiStudioHttpClient::apiFailure('Gemini generateContent', $response);
            $decoded = AiStudioHttpClient::decodeJson($response['body'], 'Gemini generateContent');
            $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
            foreach (is_array($parts) ? $parts : [] as $part) {
                $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;
                $data = is_array($inline) ? ($inline['data'] ?? null) : null;
                if (!is_string($data) || $data === '') continue;
                $image = base64_decode($data, true);
                if (!is_string($image) || file_put_contents($destinationPath, $image) === false) throw new AiStudioApiException('Falha ao gravar imagem Gemini.');
                return;
            }
            $reason = (string)($decoded['promptFeedback']['blockReason'] ?? '');
            throw new AiStudioApiException('Gemini não retornou imagem utilizável' . ($reason !== '' ? ': ' . $reason : '.'));
        }, 'Gemini');
        AiStudioHttpClient::validateOutputImage($destinationPath, 1000);
    }
}

final class AiStudioClaudeClient extends AiStudioRotatingClient
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Você é um diretor de fotografia de e-commerce. Reescreva este produto em 4 prompts de imagem fotorrealistas em inglês para os modos cover, white, hero e ambient. Preserve integralmente cor, formato, proporções, marca, rótulos e design do produto real. Altere somente cenário, fundo e iluminação. Nunca invente acessórios, acabamento, tamanho ou compatibilidade. Use o contexto factual do catálogo quando houver. Retorne exclusivamente JSON com as chaves cover, white, hero e ambient.
PROMPT;

    /** @return array{cover:string,white:string,hero:string,ambient:string} */
    public function optimizePrompts(string $productName, string $productDescription, array $productContext = []): array
    {
        return $this->withKeyRotation(function (string $key) use ($productName, $productDescription, $productContext): array {
            $contextBrief = ai_studio_catalog_context_brief($productContext);
            $contextLine = $contextBrief !== '' ? "\nContexto factual do catálogo: {$contextBrief}" : '';
            $response = AiStudioHttpClient::request('POST', 'https://api.anthropic.com/v1/messages', [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => self::SYSTEM_PROMPT,
                'messages' => [[
                    'role' => 'user',
                    'content' => "Produto: {$productName}\nDescrição: {$productDescription}{$contextLine}\nRegras obrigatorias: preserve identidade real, nao invente atributos e mantenha o produto como unico sujeito visual.",
                ]],
            ]);
            if ($response['status'] < 200 || $response['status'] >= 300) throw AiStudioHttpClient::apiFailure('Claude messages', $response);
            $decoded = AiStudioHttpClient::decodeJson($response['body'], 'Claude messages');
            $text = $decoded['content'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') throw new AiStudioApiException('Claude não retornou texto utilizável.');
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? trim($text);
            $prompts = json_decode($clean, true);
            if (!is_array($prompts)) throw new AiStudioApiException('Claude não retornou JSON válido.');
            foreach (['cover', 'white', 'hero', 'ambient'] as $name) {
                if (!is_string($prompts[$name] ?? null) || trim($prompts[$name]) === '') throw new AiStudioApiException("Claude não retornou o prompt {$name}.");
            }
            return ['cover' => trim($prompts['cover']), 'white' => trim($prompts['white']), 'hero' => trim($prompts['hero']), 'ambient' => trim($prompts['ambient'])];
        }, 'Claude');
    }
}

/**
 * Editor de imagem gratuito via Hugging Face Inference API (InstructPix2Pix
 * por padrao). Mesmo contrato dos demais editores: parte sempre da foto real
 * do produto e aplica o prompt como instrucao de edicao, nunca gera do zero.
 * A Inference API devolve os bytes da imagem direto no corpo da resposta em
 * caso de sucesso; em falha (modelo ainda carregando, cota, chave invalida)
 * devolve JSON, entao a distincao e feita pelo Content-Type da resposta.
 */
final class AiStudioHuggingFaceImageEditClient extends AiStudioRotatingClient
{
    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath): void
    {
        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) throw new AiStudioApiException('Imagem base ausente ou ilegível.');
        $binary = @file_get_contents($baseImagePath);
        if (!is_string($binary)) throw new AiStudioApiException('Falha ao ler imagem base.');
        if (strlen($binary) > 10 * 1024 * 1024) throw new AiStudioApiException('Imagem base excede o limite pratico de 10 MB do Hugging Face.');
        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);

        $this->withKeyRotation(function (string $key) use ($prompt, $binary, $mime, $destinationPath): void {
            // api-inference.huggingface.co foi descontinuado; o substituto
            // direto e o roteador de Inference Providers, path "hf-inference"
            // (mesmo contrato de payload do endpoint classico).
            $url = 'https://router.huggingface.co/hf-inference/models/' . rawurlencode($this->model);
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($binary);
            [$status, $body, $contentType] = self::requestRaw($url, [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'image/png, application/json',
                'X-Wait-For-Model' => 'true',
            ], [
                'inputs' => $dataUri,
                'parameters' => [
                    'prompt' => $prompt,
                    'image_guidance_scale' => 1.5,
                    'guidance_scale' => 7.5,
                ],
            ]);

            $looksLikeImage = str_starts_with($contentType, 'image/')
                || str_starts_with($body, "\x89PNG")
                || str_starts_with($body, "\xFF\xD8\xFF");

            if ($status < 200 || $status >= 300 || !$looksLikeImage) {
                $decoded = json_decode($body, true);
                $message = is_array($decoded)
                    ? (string)($decoded['error'] ?? $decoded['message'] ?? substr($body, 0, 300))
                    : substr($body, 0, 300);
                if (is_array($decoded) && isset($decoded['estimated_time'])) {
                    $message = 'Modelo ainda carregando no Hugging Face (estimated_time=' . $decoded['estimated_time'] . 's); tente novamente em instantes. ' . $message;
                }
                throw new AiStudioApiException("Hugging Face Inference API retornou HTTP {$status}: {$message}", $status, is_array($decoded) ? $decoded : []);
            }

            if (file_put_contents($destinationPath, $body) === false) {
                throw new AiStudioApiException('Falha ao gravar imagem Hugging Face.');
            }
        }, 'Hugging Face');
        AiStudioHttpClient::validateOutputImage($destinationPath, 512);
    }

    /** @return array{0:int,1:string,2:string} status, body, content-type */
    private static function requestRaw(string $url, array $headers, array $jsonBody): array
    {
        $handle = curl_init();
        if ($handle === false) throw new AiStudioApiException('Falha ao inicializar cURL (Hugging Face).');
        $httpHeaders = [];
        foreach ($headers as $name => $value) $httpHeaders[] = $name . ': ' . $value;
        $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new AiStudioApiException('Falha ao codificar JSON (Hugging Face): ' . json_last_error_msg());
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower((string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
        curl_close($handle);
        if ($errno !== 0 || !is_string($raw)) throw new AiStudioApiException("Erro de transporte cURL (Hugging Face) #{$errno}: {$error}");
        return [$status, $raw, $contentType];
    }
}
