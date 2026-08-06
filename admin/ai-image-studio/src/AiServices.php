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
        $items = is_array($keys) ? $keys : [$keys];
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $items
        ), static fn(string $value): bool => $value !== '')));
    }

    public static function shouldRotate(int $status, string $message): bool
    {
        if (in_array($status, [401, 403, 429], true)) return true;
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

final class AiStudioHttpClient
{
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ];
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ]);
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
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
    }
}

final class AiStudioClaudeClient extends AiStudioRotatingClient
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Você é um diretor de fotografia de e-commerce. Reescreva este produto em 3 prompts de imagem fotorrealistas em inglês para os modos white, hero e ambient. Preserve integralmente cor, formato, proporções, marca, rótulos e design do produto real. Altere somente cenário, fundo e iluminação. Retorne exclusivamente JSON com as chaves white, hero e ambient.
PROMPT;

    /** @return array{white:string,hero:string,ambient:string} */
    public function optimizePrompts(string $productName, string $productDescription): array
    {
        return $this->withKeyRotation(function (string $key) use ($productName, $productDescription): array {
            $response = AiStudioHttpClient::request('POST', 'https://api.anthropic.com/v1/messages', [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => self::SYSTEM_PROMPT,
                'messages' => [['role' => 'user', 'content' => "Produto: {$productName}\nDescrição: {$productDescription}"]],
            ]);
            if ($response['status'] < 200 || $response['status'] >= 300) throw AiStudioHttpClient::apiFailure('Claude messages', $response);
            $decoded = AiStudioHttpClient::decodeJson($response['body'], 'Claude messages');
            $text = $decoded['content'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') throw new AiStudioApiException('Claude não retornou texto utilizável.');
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? trim($text);
            $prompts = json_decode($clean, true);
            if (!is_array($prompts)) throw new AiStudioApiException('Claude não retornou JSON válido.');
            foreach (['white', 'hero', 'ambient'] as $name) {
                if (!is_string($prompts[$name] ?? null) || trim($prompts[$name]) === '') throw new AiStudioApiException("Claude não retornou o prompt {$name}.");
            }
            return ['white' => trim($prompts['white']), 'hero' => trim($prompts['hero']), 'ambient' => trim($prompts['ambient'])];
        }, 'Claude');
    }
}
