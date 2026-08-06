<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/marketplace/MarketplaceRuntime.php';
require_once __DIR__ . '/../../../includes/marketplace/MercadoLivrePublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/ShopeePublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/TikTokPublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/AmazonPublisher.php';
require_once __DIR__ . '/../../../includes/marketplace/TinyPublisher.php';

final class CatalogOptimizationPublisher
{
    public function __construct(private PDO $db)
    {
        svcp_ensure_schema($db);
    }

    /**
     * Publica o item no canal real e só confirma sucesso após resposta/read-back.
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function publish(array $row): array
    {
        $stagingId = (int)($row['id'] ?? 0);
        $productId = (int)($row['product_id'] ?? 0);
        $channel = strtolower(trim((string)($row['channel'] ?? '')));
        if ($stagingId <= 0 || $productId <= 0 || $channel === '') {
            throw new RuntimeException('Registro de staging incompleto.');
        }

        $content = $this->content($row);
        sv_market_save_channel_content($this->db, $productId, $channel, $content, false);
        $this->setStagingState($stagingId, 'publishing', null, null, null);

        try {
            $result = match ($channel) {
                'site' => $this->publishSite($productId, $content),
                'ml' => (new SvMercadoLivrePublisher($this->db))->publishText($productId, $content),
                'shopee' => (new SvShopeePublisher($this->db))->publishText($productId, $content),
                'tiktok' => (new SvTikTokPublisher($this->db))->publishText($productId, $content),
                'amazon' => (new SvAmazonPublisher($this->db))->publishText($productId, $content),
                'erp' => (new SvTinyPublisher($this->db))->publishText($productId, $content),
                default => throw new RuntimeException("Canal não suportado: {$channel}"),
            };

            $status = (string)($result['status'] ?? 'publication_failed');
            if (!in_array($status, ['published', 'submitted'], true)) {
                throw new RuntimeException('O canal não confirmou publicação ou submissão real.');
            }
            sv_market_save_channel_content($this->db, $productId, $channel, $content, $status === 'published');
            sv_market_write_publication($this->db, array_merge($result, [
                'publication_type' => 'text',
                'staging_id' => $stagingId,
                'product_id' => $productId,
                'channel' => $channel,
            ]));
            $this->setStagingState(
                $stagingId,
                $status,
                null,
                $result,
                $status === 'published' ? date('Y-m-d H:i:s') : null
            );
            return $result;
        } catch (Throwable $e) {
            $httpStatus = $e instanceof SvMarketplaceException ? $e->httpStatus : 0;
            $requestId = $e instanceof SvMarketplaceException ? $e->requestId : '';
            $response = $e instanceof SvMarketplaceException ? $e->response : [];
            $error = $this->limit($e->getMessage(), 1000);
            sv_market_write_publication($this->db, [
                'publication_type' => 'text',
                'staging_id' => $stagingId,
                'product_id' => $productId,
                'channel' => $channel,
                'status' => 'publication_failed',
                'operation' => 'publish_text',
                'http_status' => $httpStatus,
                'request_id' => $requestId,
                'fields' => ['title', 'description', 'bullet_points', 'seo_keywords', 'marketing_hooks', 'meta'],
                'response' => $response,
                'error_message' => $error,
            ]);
            $this->setStagingState($stagingId, 'publication_failed', $error, [
                'status' => 'publication_failed',
                'http_status' => $httpStatus,
                'request_id' => $requestId,
            ], null);
            throw $e;
        }
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function publishSite(int $productId, array $content): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE products SET name = ?, description = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([(string)$content['title'], (string)$content['description'], $productId]);
            if ($stmt->rowCount() < 1) {
                $exists = $this->db->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
                $exists->execute([$productId]);
                if ((int)$exists->fetchColumn() === 0) {
                    throw new RuntimeException('Produto do site não encontrado.');
                }
            }
            $verify = $this->db->prepare('SELECT name, description FROM products WHERE id = ? LIMIT 1');
            $verify->execute([$productId]);
            $read = $verify->fetch(PDO::FETCH_ASSOC);
            if (!is_array($read) || (string)$read['name'] !== (string)$content['title'] || (string)$read['description'] !== (string)$content['description']) {
                throw new RuntimeException('Read-back do ShopVivaliz não confirmou título e descrição.');
            }
            sv_market_save_channel_content($this->db, $productId, 'site', $content, true);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return [
            'status' => 'published',
            'operation' => 'UPDATE products + product_channel_content',
            'external_id' => (string)$productId,
            'http_status' => 200,
            'request_id' => '',
            'fields' => ['title', 'description', 'bullet_points', 'seo_keywords', 'marketing_hooks', 'meta_title', 'meta_description'],
            'response' => ['readback_confirmed' => true],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function content(array $row): array
    {
        $bullets = json_decode((string)($row['bullet_points_json'] ?? '[]'), true);
        $bullets = is_array($bullets) ? array_values(array_filter(array_map('strval', $bullets), static fn(string $v): bool => trim($v) !== '')) : [];
        $meta = json_decode((string)($row['meta_data_json'] ?? '{}'), true);
        $meta = is_array($meta) ? $meta : [];
        return [
            'title' => trim((string)($row['optimized_title'] ?? '')),
            'description' => trim((string)($row['optimized_description'] ?? '')),
            'bullet_points' => $bullets,
            'seo_keywords' => $this->splitList((string)($row['seo_keywords'] ?? ''), ','),
            'marketing_hooks' => $this->splitList((string)($row['marketing_hooks'] ?? ''), '|'),
            'meta_title' => trim((string)($meta['meta_title'] ?? '')),
            'meta_description' => trim((string)($meta['meta_description'] ?? '')),
        ];
    }

    /** @return list<string> */
    private function splitList(string $value, string $separator): array
    {
        if (trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode($separator, $value)), static fn(string $item): bool => $item !== ''));
    }

    /** @param array<string,mixed>|null $summary */
    private function setStagingState(int $stagingId, string $status, ?string $error, ?array $summary, ?string $publishedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE catalog_optimizations_staging '
            . 'SET status = ?, error_message = ?, publication_summary_json = ?, published_at = ?, updated_at = NOW() '
            . 'WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $error,
            $summary !== null ? sv_market_json($summary) : null,
            $publishedAt,
            $stagingId,
        ]);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
