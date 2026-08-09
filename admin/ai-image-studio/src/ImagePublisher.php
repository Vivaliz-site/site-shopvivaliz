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
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Arquivo gerado não existe ou não pode ser lido.');
        }

        $extension = strtolower((string)pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('Formato de imagem não permitido.');
        }

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
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Falha ao promover a imagem aprovada.');
        }
        @chmod($target, 0644);

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
}
