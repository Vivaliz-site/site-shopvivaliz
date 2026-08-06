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
        $product = sv_market_product($this->db, $productId);
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
            $this->updateStorefrontCache($product, $content);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return [
            'status' => 'published',
            'operation' => 'UPDATE products + product_channel_content + storefront cache',
            'external_id' => (string)$productId,
            'http_status' => 200,
            'request_id' => '',
            'fields' => ['title', 'description', 'bullet_points', 'seo_keywords', 'marketing_hooks', 'meta_title', 'meta_description'],
            'response' => [
                'readback_confirmed' => true,
                'storefront_cache_confirmed' => true,
                'all_approved_text_fields_exposed' => true,
            ],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $content */
    private function updateStorefrontCache(array $product, array $content): void
    {
        $path = dirname(__DIR__, 3) . '/storage/products-cache-ativos.json';
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            throw new RuntimeException('Cache ativo da vitrine não está disponível para atualização real.');
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Cache ativo da vitrine contém JSON inválido.');
        }
        $sku = trim((string)($product['sku'] ?? ''));
        $externalId = trim((string)($product['olist_id'] ?? $product['olist_product_id'] ?? ''));
        $displayDescription = $this->storefrontDescription($content);
        $updated = false;
        $apply = function (array &$item) use ($sku, $externalId, $content, $displayDescription, &$updated): void {
            $itemSku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
            $itemId = trim((string)($item['id'] ?? $item['olist_product_id'] ?? ''));
            if (($sku !== '' && $itemSku === $sku) || ($externalId !== '' && $itemId === $externalId)) {
                $title = (string)$content['title'];
                $item['name'] = $title;
                $item['nome'] = $title;
                $item['descricao'] = $title;
                $item['description'] = $displayDescription;
                $item['descricaoComplementar'] = $displayDescription;
                $item['descricao_complementar'] = $displayDescription;
                $item['bullet_points'] = $content['bullet_points'];
                $item['seo_keywords'] = $content['seo_keywords'];
                $item['marketing_hooks'] = $content['marketing_hooks'];
                $item['meta_title'] = (string)$content['meta_title'];
                $item['meta_description'] = (string)$content['meta_description'];
                $updated = true;
            }
        };
        $walk = function (array &$node) use (&$walk, $apply): void {
            if (array_is_list($node)) {
                foreach ($node as &$entry) {
                    if (is_array($entry)) $apply($entry);
                }
                unset($entry);
                return;
            }
            foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $walk($node[$key]);
                }
            }
        };
        $walk($payload);
        if (!$updated) {
            throw new RuntimeException('Produto não localizado no cache ativo da vitrine; publicação do site foi abortada.');
        }
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao persistir o conteúdo otimizado no cache ativo da vitrine.');
        }
        $verify = json_decode((string)file_get_contents($path), true);
        if (!is_array($verify)) {
            throw new RuntimeException('Read-back do cache ativo da vitrine falhou.');
        }
        if (!$this->cacheContainsAllFields($verify, $sku, $externalId, $content)) {
            throw new RuntimeException('Read-back do cache não confirmou todos os campos textuais aprovados.');
        }
    }

    /** @param array<string,mixed> $content */
    private function storefrontDescription(array $content): string
    {
        $html = '<p>' . nl2br(htmlspecialchars((string)$content['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        $bullets = is_array($content['bullet_points'] ?? null) ? $content['bullet_points'] : [];
        if ($bullets !== []) {
            $html .= '<ul>';
            foreach ($bullets as $bullet) {
                $html .= '<li>' . htmlspecialchars(trim((string)$bullet), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
        }
        $hooks = is_array($content['marketing_hooks'] ?? null) ? $content['marketing_hooks'] : [];
        foreach ($hooks as $hook) {
            $text = trim((string)$hook);
            if ($text !== '') {
                $html .= '<p><strong>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
            }
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $content
     */
    private function cacheContainsAllFields(array $payload, string $sku, string $externalId, array $content): bool
    {
        $found = false;
        $walk = function (array $node) use (&$walk, &$found, $sku, $externalId, $content): void {
            if ($found) return;
            if (array_is_list($node)) {
                foreach ($node as $entry) {
                    if (!is_array($entry)) continue;
                    $entrySku = trim((string)($entry['sku'] ?? $entry['codigo'] ?? $entry['code'] ?? ''));
                    $entryId = trim((string)($entry['id'] ?? $entry['olist_product_id'] ?? ''));
                    if (($sku !== '' && $entrySku === $sku) || ($externalId !== '' && $entryId === $externalId)) {
                        $found = (string)($entry['name'] ?? '') === (string)$content['title']
                            && (array)($entry['bullet_points'] ?? []) === (array)$content['bullet_points']
                            && (array)($entry['seo_keywords'] ?? []) === (array)$content['seo_keywords']
                            && (array)($entry['marketing_hooks'] ?? []) === (array)$content['marketing_hooks']
                            && (string)($entry['meta_title'] ?? '') === (string)$content['meta_title']
                            && (string)($entry['meta_description'] ?? '') === (string)$content['meta_description'];
                        if ($found) return;
                    }
                }
                return;
            }
            foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) $walk($node[$key]);
            }
        };
        $walk($payload);
        return $found;
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
