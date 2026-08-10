<?php

declare(strict_types=1);

final class AiStudioImagePublisher
{
    public function __construct(private PDO $db)
    {
    }

    /** @param array<string,mixed> $row */
    public function publishToSite(array $row): array
    {
        $productId = (int)($row['product_id'] ?? 0);
        $localPath = (string)($row['local_path'] ?? '');
        $imageType = (string)($row['image_type'] ?? '');

        if ($productId <= 0 || $localPath === '') {
            throw new RuntimeException('Registro de imagem incompleto.');
        }

        $source = $this->resolveSource($localPath);
        $image = $this->validateGeneratedImage($source);
        $extension = match ($image['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Formato de imagem não permitido.'),
        };

        $projectRoot = dirname(__DIR__, 3);
        $targetDir = $projectRoot . '/public/assets/products/generated';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Não foi possível criar o diretório público de imagens.');
        }

        $filename = sprintf('product-%d-%s-%s.%s', $productId, preg_replace('/[^a-z0-9_-]+/i', '-', $imageType) ?: 'image', bin2hex(random_bytes(6)), $extension);
        $target = $targetDir . '/' . $filename;
        $temporary = $target . '.tmp';

        if (!copy($source, $temporary)) {
            throw new RuntimeException('Falha ao copiar a imagem aprovada para o diretório público.');
        }
        $this->validateGeneratedImage($temporary);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Falha ao promover a imagem aprovada.');
        }
        @chmod($target, 0644);
        $this->validateGeneratedImage($target);

        $publicUrl = '/public/assets/products/generated/' . $filename;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE products SET image_url = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$publicUrl, $productId]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Produto não encontrado para atualização da imagem.');
            }

            $verify = $this->db->prepare('SELECT image_url FROM products WHERE id = ? LIMIT 1');
            $verify->execute([$productId]);
            if ((string)$verify->fetchColumn() !== $publicUrl) {
                throw new RuntimeException('A imagem não foi persistida no produto.');
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($target);
            throw $e;
        }

        return [
            'published' => true,
            'channel' => 'site',
            'public_url' => $publicUrl,
            'note' => "Imagem publicada no ShopVivaliz para o produto #{$productId}.",
        ];
    }

    private function resolveSource(string $localPath): string
    {
        if (str_starts_with($localPath, AI_STUDIO_STORAGE_URL_PREFIX)) {
            return AI_STUDIO_STORAGE_DIR . basename($localPath);
        }
        if (is_file($localPath)) {
            return $localPath;
        }
        return dirname(__DIR__, 3) . '/' . ltrim($localPath, '/');
    }

    /** @return array{width:int,height:int,mime:string,sha256:string,is_square:bool,square_delta_px:int} */
    private function validateGeneratedImage(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || (int)filesize($path) <= 0) {
            throw new RuntimeException('Arquivo gerado não existe, está vazio ou não pode ser lido.');
        }
        $info = @getimagesize($path);
        if (!is_array($info)) {
            throw new RuntimeException('Arquivo gerado não é uma imagem válida.');
        }
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        $mime = strtolower(trim((string)($info['mime'] ?? '')));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException("Formato real de imagem não permitido: {$mime}.");
        }
        if ($width < 1000 || $height < 1000) {
            throw new RuntimeException("Imagem gerada abaixo do padrão premium: {$width}x{$height}; mínimo 1000px por lado.");
        }
        $squareTolerance = max(8, (int)round(min($width, $height) * 0.02));
        $squareDelta = abs($width - $height);
        if ($squareDelta > $squareTolerance) {
            throw new RuntimeException("Imagem gerada não está quadrada: {$width}x{$height}; desvio de {$squareDelta}px.");
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Falha ao calcular fingerprint da imagem gerada.');
        }
        return [
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
            'sha256' => $hash,
            'is_square' => true,
            'square_delta_px' => $squareDelta,
        ];
    }
}
