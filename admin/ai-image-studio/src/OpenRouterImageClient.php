<?php

declare(strict_types=1);

/**
 * Cliente da API dedicada de imagens do OpenRouter.
 *
 * Diferente da API OpenAI, OpenRouter usa POST /images e recebe a foto real
 * em input_references para image-to-image. Isso mantém a regra do Studio de
 * nunca reconstruir o produto sem uma referência do mesmo item.
 */
final class AiStudioOpenRouterImageClient extends AiStudioRotatingClient
{
    public function __construct(
        string|array $keys,
        string $model,
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private array $extraHeaders = []
    ) {
        parent::__construct($keys, $model);
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function editImageToFile(string $prompt, string $baseImagePath, string $destinationPath): void
    {
        if (!is_file($baseImagePath) || !is_readable($baseImagePath)) {
            throw new AiStudioApiException('Imagem base ausente ou ilegível.');
        }
        $binary = @file_get_contents($baseImagePath);
        if (!is_string($binary) || $binary === '') {
            throw new AiStudioApiException('Falha ao ler imagem base para OpenRouter.');
        }
        if (strlen($binary) > 20 * 1024 * 1024) {
            throw new AiStudioApiException('Imagem base excede 20 MB para referência OpenRouter.');
        }
        $mime = AiStudioHttpClient::detectImageMime($baseImagePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AiStudioApiException('Formato da foto base não suportado pelo OpenRouter: ' . $mime . '.');
        }
        $reference = 'data:' . $mime . ';base64,' . base64_encode($binary);

        $this->withKeyRotation(function (string $key) use ($prompt, $reference, $destinationPath): void {
            $response = AiStudioHttpClient::request('POST', $this->baseUrl . '/images', array_merge([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $this->extraHeaders), [
                'model' => $this->model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
                'quality' => 'high',
                'output_format' => 'png',
                'input_references' => [[
                    'type' => 'image_url',
                    'image_url' => ['url' => $reference],
                ]],
            ], 180);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw AiStudioHttpClient::apiFailure('OpenRouter images', $response);
            }
            $decoded = AiStudioHttpClient::decodeJson($response['body'], 'OpenRouter images');
            $item = $decoded['data'][0] ?? null;
            if (!is_array($item)) {
                throw new AiStudioApiException('OpenRouter não retornou data[0].');
            }
            if (is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
                $image = base64_decode($item['b64_json'], true);
                if (!is_string($image) || file_put_contents($destinationPath, $image) === false) {
                    throw new AiStudioApiException('Falha ao gravar imagem OpenRouter.');
                }
                return;
            }
            foreach (['url', 'imageUrl'] as $urlKey) {
                if (is_string($item[$urlKey] ?? null) && $item[$urlKey] !== '') {
                    AiStudioHttpClient::downloadToFile($item[$urlKey], $destinationPath);
                    return;
                }
            }
            throw new AiStudioApiException('OpenRouter não retornou b64_json nem URL de imagem.');
        }, 'OpenRouter');

        AiStudioHttpClient::validateOutputImage($destinationPath, 1000);
    }
}
