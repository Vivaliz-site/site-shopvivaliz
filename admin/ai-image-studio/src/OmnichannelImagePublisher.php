<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/marketplace/MarketplaceRuntime.php';
require_once __DIR__ . '/../../../includes/marketplace/MercadoLivrePublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/ShopeePublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/TikTokPublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/AmazonPublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/TinyPublisher.php';

final class AiStudioOmnichannelImagePublisher
{
    private const CHANNELS = ['site', 'ml', 'shopee', 'amazon', 'tiktok', 'erp'];

    public function __construct(private PDO $db)
    {
        svcp_ensure_schema($db);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $channels
     * @return array{status:string,results:array<string,array<string,mixed>>,public_url:string}
     */
    public function publish(array $row, array $channels): array
    {
        $stagingId = (int)($row['id'] ?? 0);
        $productId = (int)($row['product_id'] ?? 0);
        $imageType = strtolower(trim((string)($row['image_type'] ?? '')));
        $channels = array_values(array_unique(array_intersect(self::CHANNELS, array_map('strtolower', $channels))));
        if ($stagingId <= 0 || $productId <= 0 || $imageType === '' || $channels === []) {
            throw new RuntimeException('Imagem, produto ou canais de destino inválidos.');
        }

        $this->setStaging($stagingId, 'publishing', null, $channels, null, null);
        [$publicUrl, $publicFile] = $this->ensurePublicAsset($row);
        $this->registerImage($stagingId, $productId, $imageType, $publicUrl, in_array('site', $channels, true));

        $publicUrls = $this->publicUrls($productId);
        $localFiles = $this->localFiles($publicUrls);
        $results = [];
        $failures = [];

        foreach ($channels as $channel) {
            try {
                $result = match ($channel) {
                    'site' => $this->publishSite($productId, $imageType, $publicUrl),
                    'ml' => (new SvMercadoLivrePublisher($this->db))->publishImages($productId, $publicUrls),
                    'shopee' => (new SvShopeePublisher($this->db))->publishImages($productId, $localFiles),
                    'tiktok' => (new SvTikTokPublisher($this->db))->publishImages($productId, $localFiles),
                    'amazon' => (new SvAmazonPublisher($this->db))->publishImages($productId, $publicUrls),
                    'erp' => (new SvTinyPublisher($this->db))->publishImages($productId, $publicUrls),
                    default => throw new RuntimeException("Canal de imagem não suportado: {$channel}"),
                };
                $results[$channel] = $result;
                sv_market_write_publication($this->db, array_merge($result, [
                    'publication_type' => 'image',
                    'staging_id' => $stagingId,
                    'product_id' => $productId,
                    'channel' => $channel,
                ]));
            } catch (Throwable $e) {
                $httpStatus = $e instanceof SvMarketplaceException ? $e->httpStatus : 0;
                $requestId = $e instanceof SvMarketplaceException ? $e->requestId : '';
                $response = $e instanceof SvMarketplaceException ? $e->response : [];
                $error = $this->limit($e->getMessage(), 1000);
                $result = [
                    'status' => 'publication_failed',
                    'operation' => 'publish_image',
                    'external_id' => '',
                    'http_status' => $httpStatus,
                    'request_id' => $requestId,
                    'fields' => ['images'],
                    'response' => $response,
                    'error_message' => $error,
                ];
                $results[$channel] = $result;
                $failures[$channel] = $error;
                sv_market_write_publication($this->db, array_merge($result, [
                    'publication_type' => 'image',
                    'staging_id' => $stagingId,
                    'product_id' => $productId,
                    'channel' => $channel,
                ]));
            }
        }

        $statuses = array_column($results, 'status');
        if ($failures !== [] && count($failures) === count($channels)) {
            $finalStatus = 'publication_failed';
        } elseif ($failures !== []) {
            $finalStatus = 'partial_published';
        } elseif (in_array('submitted', $statuses, true)) {
            $finalStatus = 'submitted';
        } else {
            $finalStatus = 'published';
        }
        $summary = [
            'status' => $finalStatus,
            'public_url' => $publicUrl,
            'public_file_exists' => is_file($publicFile),
            'channels' => $results,
        ];
        $error = $failures !== [] ? $this->limit(implode(' | ', array_map(
            static fn(string $channel, string $message): string => $channel . ': ' . $message,
            array_keys($failures),
            array_values($failures)
        )), 1000) : null;
        $this->setStaging(
            $stagingId,
            $finalStatus,
            $error,
            $channels,
            $summary,
            in_array($finalStatus, ['published', 'partial_published', 'submitted'], true) ? date('Y-m-d H:i:s') : null
        );

        return ['status' => $finalStatus, 'results' => $results, 'public_url' => $publicUrl];
    }

    /** @param array<string,mixed> $row @return array{0:string,1:string} */
    private function ensurePublicAsset(array $row): array
    {
        $stagingId = (int)$row['id'];
        $productId = (int)$row['product_id'];
        $imageType = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$row['image_type']) ?: 'image';
        $source = $this->resolveSource((string)$row['local_path']);
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Arquivo gerado não existe ou não pode ser lido.');
        }
        $extension = strtolower((string)pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('Formato de imagem não permitido.');
        }
        $root = dirname(__DIR__, 3);
        $targetDir = $root . '/public/assets/products/generated';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Não foi possível criar o diretório público de imagens.');
        }
        $filename = sprintf('product-%d-%s-staging-%d.%s', $productId, $imageType, $stagingId, $extension);
        $target = $targetDir . '/' . $filename;
        if (!is_file($target)) {
            $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (!copy($source, $temporary) || !rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Falha ao promover a imagem para o diretório público.');
            }
            @chmod($target, 0644);
        }
        return ['/public/assets/products/generated/' . $filename, $target];
    }

    private function resolveSource(string $localPath): string
    {
        if (defined('AI_STUDIO_STORAGE_URL_PREFIX') && str_starts_with($localPath, AI_STUDIO_STORAGE_URL_PREFIX)) {
            return AI_STUDIO_STORAGE_DIR . basename($localPath);
        }
        if (is_file($localPath)) {
            return $localPath;
        }
        return dirname(__DIR__, 3) . '/' . ltrim($localPath, '/');
    }

    private function registerImage(int $stagingId, int $productId, string $type, string $url, bool $publishSite): void
    {
        if ($publishSite && $type === 'white') {
            $clear = $this->db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?');
            $clear->execute([$productId]);
        }
        $primary = $publishSite && $type === 'white' ? 1 : 0;
        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_type, public_url, is_primary, source_staging_id) '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary), source_staging_id = VALUES(source_staging_id)'
        );
        $stmt->execute([$productId, $type, $url, $primary, $stagingId]);
    }

    /** @return list<string> */
    private function publicUrls(int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT public_url FROM product_images WHERE product_id = ? "
            . "ORDER BY CASE image_type WHEN 'white' THEN 1 WHEN 'hero' THEN 2 WHEN 'ambient' THEN 3 ELSE 4 END, id DESC"
        );
        $stmt->execute([$productId]);
        return array_values(array_unique(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
    }

    /** @param list<string> $urls @return list<string> */
    private function localFiles(array $urls): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $path = is_string($path) ? $path : $url;
            $local = $root . '/' . ltrim($path, '/');
            if (is_file($local) && is_readable($local)) {
                $files[] = $local;
            }
        }
        if ($files === []) {
            throw new RuntimeException('Nenhum arquivo público local disponível para upload aos canais.');
        }
        return $files;
    }

    /** @return array<string,mixed> */
    private function publishSite(int $productId, string $imageType, string $publicUrl): array
    {
        if ($imageType === 'white') {
            $stmt = $this->db->prepare('UPDATE products SET image_url = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$publicUrl, $productId]);
            $verify = $this->db->prepare('SELECT image_url FROM products WHERE id = ? LIMIT 1');
            $verify->execute([$productId]);
            if ((string)$verify->fetchColumn() !== $publicUrl) {
                throw new RuntimeException('ShopVivaliz não confirmou a imagem principal.');
            }
        }
        $exists = $this->db->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND public_url = ?');
        $exists->execute([$productId, $publicUrl]);
        if ((int)$exists->fetchColumn() < 1) {
            throw new RuntimeException('Galeria do ShopVivaliz não confirmou a imagem.');
        }
        return [
            'status' => 'published',
            'operation' => 'product_images' . ($imageType === 'white' ? ' + products.image_url' : ''),
            'external_id' => (string)$productId,
            'http_status' => 200,
            'request_id' => '',
            'fields' => ['image_type', 'public_url'],
            'response' => ['image_type' => $imageType, 'readback_confirmed' => true],
            'verified' => true,
        ];
    }

    /** @param list<string> $channels @param array<string,mixed>|null $summary */
    private function setStaging(int $id, string $status, ?string $error, array $channels, ?array $summary, ?string $publishedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images_staging SET status = ?, error_message = ?, target_channels_json = ?, '
            . 'publication_summary_json = ?, published_at = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $error,
            sv_market_json($channels),
            $summary !== null ? sv_market_json($summary) : null,
            $publishedAt,
            $id,
        ]);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
