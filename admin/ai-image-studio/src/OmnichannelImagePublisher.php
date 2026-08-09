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
    private const COVER_FIRST_CHANNELS = ['ml', 'shopee', 'amazon', 'tiktok', 'erp'];

    public function __construct(private PDO $db)
    {
        svcp_ensure_schema($db);
    }

    /** @return array{status:string,results:array<string,array<string,mixed>>,public_url:string} */
    public function publish(array $row, array $channels): array
    {
        $stagingId = (int)($row['id'] ?? 0);
        $productId = (int)($row['product_id'] ?? 0);
        $imageType = strtolower(trim((string)($row['image_type'] ?? '')));
        $channels = array_values(array_unique(array_intersect(self::CHANNELS, array_map('strtolower', $channels))));
        if ($stagingId <= 0 || $productId <= 0 || $imageType === '' || count($channels) !== 1) {
            throw new RuntimeException('A aprovação de imagem exige exatamente um canal de destino.');
        }

        sv_market_product($this->db, $productId);

        $this->setStaging($stagingId, 'publishing', null, $channels, null, null);
        [$publicUrl, $publicFile] = $this->ensurePublicAsset($row);
        $results = [];
        $failures = [];

        foreach ($channels as $channel) {
            try {
                if ($channel === 'site') {
                    $channelUrls = $this->sitePublicUrls($productId, $imageType, $publicUrl);
                } else {
                    $channelUrls = $this->approvedChannelUrls($productId, $channel, $stagingId, $imageType, $publicUrl);
                }
                $channelFiles = in_array($channel, ['shopee', 'tiktok'], true) ? $this->localFiles($channelUrls) : [];

                // Amazon substitui os locators informados no patch. Preserva a
                // galeria já existente, mas mantém as imagens explicitamente
                // aprovadas pelo Admin na frente e sempre com white como capa.
                if ($channel === 'amazon') {
                    $channelUrls = $this->amazonUrlsWithExisting($productId, $channelUrls);
                }

                $result = match ($channel) {
                    'site' => $this->publishSite($stagingId, $productId, $imageType, $publicUrl, $channelUrls),
                    'ml' => (new SvMercadoLivrePublisher($this->db))->publishImages($productId, $channelUrls),
                    'shopee' => (new SvShopeePublisher($this->db))->publishImages($productId, $channelFiles),
                    'tiktok' => (new SvTikTokPublisher($this->db))->publishImages($productId, $channelFiles),
                    'amazon' => (new SvAmazonPublisher($this->db))->publishImages($productId, $channelUrls),
                    'erp' => throw new RuntimeException('Publicação de imagem gerada no Olist/Tiny está bloqueada: a API V2 exige reenviar preço no layout completo. O ERP permanece fonte protegida; preço e estoque não são tocados pelo AI Image Studio.'),
                    default => throw new RuntimeException("Canal de imagem não suportado: {$channel}"),
                };
                $results[$channel] = $result;
                sv_market_write_publication($this->db, array_merge($result, [
                    'publication_type' => 'image',
                    'staging_id' => $stagingId,
                    'product_id' => $productId,
                    'channel' => $channel,
                ]));
            } catch (Throwable $exception) {
                $httpStatus = $exception instanceof SvMarketplaceException ? $exception->httpStatus : 0;
                $requestId = $exception instanceof SvMarketplaceException ? $exception->requestId : '';
                $response = $exception instanceof SvMarketplaceException ? $exception->response : [];
                $error = $this->limit($exception->getMessage(), 1000);
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

        if ($failures !== [] && count($failures) === count($channels)) {
            $finalStatus = 'publication_failed';
        } elseif ($failures !== []) {
            $finalStatus = 'partial_published';
        } elseif (in_array('submitted', array_column($results, 'status'), true)) {
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
        $error = $failures === [] ? null : $this->limit(implode(' | ', array_map(
            static fn(string $channel, string $message): string => $channel . ': ' . $message,
            array_keys($failures),
            array_values($failures)
        )), 1000);

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

    /** @return array{0:string,1:string} */
    private function ensurePublicAsset(array $row): array
    {
        $stagingId = (int)$row['id'];
        $productId = (int)$row['product_id'];
        $imageType = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$row['image_type']) ?: 'image';
        $source = $this->resolveSource((string)$row['local_path']);
        $image = $this->validateGeneratedImage($source);

        $extension = match ($image['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Formato de imagem não permitido.'),
        };

        $root = dirname(__DIR__, 3);
        $targetDir = $root . '/public/assets/products/generated';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Não foi possível criar o diretório público de imagens.');
        }

        $filename = sprintf('product-%d-%s-staging-%d.%s', $productId, $imageType, $stagingId, $extension);
        $target = $targetDir . '/' . $filename;
        if (!is_file($target)) {
            $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (!copy($source, $temporary)) {
                @unlink($temporary);
                throw new RuntimeException('Falha ao copiar a imagem para promoção.');
            }
            try {
                $this->validateGeneratedImage($temporary);
            } catch (Throwable $e) {
                @unlink($temporary);
                throw $e;
            }
            if (!rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Falha ao promover a imagem para o diretório público.');
            }
            @chmod($target, 0644);
        }
        $this->validateGeneratedImage($target);

        return ['/public/assets/products/generated/' . $filename, $target];
    }

    /** @return array{width:int,height:int,mime:string,sha256:string} */
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
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Falha ao calcular fingerprint da imagem gerada.');
        }
        return ['width' => $width, 'height' => $height, 'mime' => $mime, 'sha256' => $hash];
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

    /** @return list<string> */
    private function sitePublicUrls(int $productId, string $currentType, string $currentUrl): array
    {
        $candidates = [['type' => $currentType, 'url' => $currentUrl, 'order' => 0]];
        $stmt = $this->db->prepare('SELECT image_type, public_url FROM product_images WHERE product_id = ? ORDER BY id DESC');
        $stmt->execute([$productId]);
        $order = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $candidates[] = [
                'type' => strtolower(trim((string)($row['image_type'] ?? 'other'))),
                'url' => trim((string)($row['public_url'] ?? '')),
                'order' => $order++,
            ];
        }
        return $this->orderImageCandidates($candidates);
    }

    /**
     * Retorna somente a imagem atual e imagens que já foram aprovadas para o
     * MESMO canal. Nunca reaproveita silenciosamente a galeria do site em um
     * marketplace diferente.
     *
     * @return list<string>
     */
    private function approvedChannelUrls(int $productId, string $channel, int $currentStagingId, string $currentType, string $currentUrl): array
    {
        $candidates = [['type' => $currentType, 'url' => $currentUrl, 'order' => 0]];
        $stmt = $this->db->prepare(
            "SELECT id, image_type, target_channels_json, publication_summary_json, status "
            . "FROM product_images_staging WHERE product_id = ? AND id <> ? "
            . "AND status IN ('published','partial_published','submitted') "
            . 'ORDER BY COALESCE(published_at, updated_at, created_at) DESC, id DESC'
        );
        $stmt->execute([$productId, $currentStagingId]);
        $order = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targets = json_decode((string)($row['target_channels_json'] ?? ''), true);
            if (!is_array($targets) || !in_array($channel, array_map('strval', $targets), true)) {
                continue;
            }
            $summary = json_decode((string)($row['publication_summary_json'] ?? ''), true);
            if (!is_array($summary)) {
                continue;
            }
            $channelResult = $summary['channels'][$channel] ?? null;
            $channelStatus = is_array($channelResult) ? strtolower(trim((string)($channelResult['status'] ?? ''))) : '';
            if (!in_array($channelStatus, ['published', 'submitted'], true)) {
                continue;
            }
            $url = trim((string)($summary['public_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $candidates[] = [
                'type' => strtolower(trim((string)($row['image_type'] ?? 'other'))),
                'url' => $url,
                'order' => $order++,
            ];
        }

        $urls = $this->orderImageCandidates($candidates);
        $whitePresent = false;
        foreach ($candidates as $candidate) {
            if (($candidate['type'] ?? '') === 'white' && trim((string)($candidate['url'] ?? '')) !== '') {
                $whitePresent = true;
                break;
            }
        }
        if (in_array($channel, self::COVER_FIRST_CHANNELS, true) && !$whitePresent) {
            throw new RuntimeException("A imagem white deve ser aprovada primeiro para {$channel}; hero/ambient nunca podem virar capa automaticamente.");
        }
        return $urls;
    }

    /**
     * Mantém no máximo a versão mais recente de cada tipo e garante a ordem
     * white -> hero -> ambient -> outros. O primeiro URL é a capa nos
     * publishers externos, portanto esta função é parte do contrato de
     * segurança da publicação.
     *
     * @param list<array{type:string,url:string,order:int}> $candidates
     * @return list<string>
     */
    private function orderImageCandidates(array $candidates): array
    {
        $rank = ['white' => 0, 'hero' => 1, 'ambient' => 2];
        $seenTypes = [];
        $selected = [];
        foreach ($candidates as $candidate) {
            $type = strtolower(trim((string)($candidate['type'] ?? 'other')));
            $url = trim((string)($candidate['url'] ?? ''));
            if ($url === '' || isset($seenTypes[$type])) {
                continue;
            }
            $seenTypes[$type] = true;
            $selected[] = [
                'type' => $type,
                'url' => $url,
                'order' => (int)($candidate['order'] ?? 9999),
                'rank' => $rank[$type] ?? 50,
            ];
        }
        usort($selected, static function (array $a, array $b): int {
            return [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']];
        });
        return array_values(array_unique(array_map(static fn(array $row): string => $row['url'], $selected)));
    }

    /** @return list<string> */
    private function amazonUrlsWithExisting(int $productId, array $approvedUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') {
            throw new RuntimeException('SKU ausente para preservar a galeria Amazon.');
        }

        $client = new SvAmazonClient();
        $listing = $client->request(
            'GET',
            '/listings/2021-08-01/items/' . rawurlencode($client->sellerId()) . '/' . rawurlencode($sku),
            [
                'marketplaceIds' => $client->marketplaceId(),
                'includedData' => 'attributes',
                'issueLocale' => 'pt_BR',
            ]
        );
        $attributes = is_array($listing['data']['attributes'] ?? null) ? $listing['data']['attributes'] : [];
        $existing = [];
        foreach (array_merge(['main_product_image_locator'], array_map(static fn(int $i): string => 'other_product_image_locator_' . $i, range(1, 8))) as $key) {
            foreach (is_array($attributes[$key] ?? null) ? $attributes[$key] : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $url = trim((string)($entry['media_location'] ?? $entry['value'] ?? ''));
                if ($url !== '') {
                    $existing[] = $url;
                }
            }
        }
        return array_slice(array_values(array_unique(array_merge($approvedUrls, $existing))), 0, 9);
    }

    /** @return list<string> */
    private function localFiles(array $urls): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $local = $root . '/' . ltrim(is_string($path) ? $path : $url, '/');
            if (is_file($local) && is_readable($local)) {
                $this->validateGeneratedImage($local);
                $files[] = $local;
            }
        }
        if ($files === []) {
            throw new RuntimeException('Nenhum arquivo público local válido disponível para upload.');
        }
        return array_values(array_unique($files));
    }

    private function registerSiteImage(int $stagingId, int $productId, string $type, string $url): void
    {
        if ($type === 'white') {
            $clear = $this->db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?');
            $clear->execute([$productId]);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_type, public_url, is_primary, source_staging_id) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE image_type = VALUES(image_type), is_primary = VALUES(is_primary), source_staging_id = VALUES(source_staging_id)'
        );
        $stmt->execute([$productId, $type, $url, $type === 'white' ? 1 : 0, $stagingId]);
    }

    private function publishSite(int $stagingId, int $productId, string $imageType, string $publicUrl, array $publicUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $this->db->beginTransaction();
        try {
            $this->registerSiteImage($stagingId, $productId, $imageType, $publicUrl);
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

            $this->updateStorefrontCache($product, $imageType, $publicUrl, $publicUrls);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'status' => 'published',
            'operation' => 'product_images + storefront cache' . ($imageType === 'white' ? ' + products.image_url' : ''),
            'external_id' => (string)$productId,
            'http_status' => 200,
            'request_id' => '',
            'fields' => ['image_type', 'public_url', 'gallery'],
            'response' => ['readback_confirmed' => true, 'storefront_cache_confirmed' => true],
            'verified' => true,
        ];
    }

    private function updateStorefrontCache(array $product, string $imageType, string $publicUrl, array $publicUrls): void
    {
        $path = dirname(__DIR__, 3) . '/storage/products-cache-ativos.json';
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            throw new RuntimeException('Cache ativo da vitrine indisponível.');
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Cache ativo da vitrine contém JSON inválido.');
        }

        $sku = trim((string)($product['sku'] ?? ''));
        $externalId = trim((string)($product['olist_id'] ?? $product['olist_product_id'] ?? ''));
        if ($sku === '' && $externalId === '') {
            throw new RuntimeException('Produto sem SKU e sem ID externo; cache não pode ser atualizado com segurança.');
        }

        $absoluteUrls = array_values(array_unique(array_map('sv_market_absolute_url', $publicUrls)));
        $matched = 0;
        $apply = function (array &$item) use ($sku, $externalId, $imageType, $publicUrl, $absoluteUrls, &$matched): void {
            if (!$this->cacheItemMatchesProduct($item, $sku, $externalId)) {
                return;
            }
            $matched++;

            $existing = [];
            foreach (['images', 'imagens', 'gallery', 'galeria'] as $field) {
                foreach (is_array($item[$field] ?? null) ? $item[$field] : [] as $entry) {
                    $url = is_string($entry) ? trim($entry) : trim((string)($entry['url'] ?? $entry['src'] ?? ''));
                    if ($url !== '') {
                        $existing[] = $url;
                    }
                }
            }

            $gallery = array_slice(array_values(array_unique(array_merge($absoluteUrls, $existing))), 0, 12);
            $item['images'] = $gallery;
            $item['imagens'] = array_map(static fn(string $url): array => ['url' => $url], $gallery);
            if ($imageType === 'white') {
                foreach (['image_url', 'primary_image_url', 'imagem_principal_url', 'imagem'] as $field) {
                    $item[$field] = $publicUrl;
                }
            }
        };

        $walk = function (array &$node) use (&$walk, $apply): void {
            if (array_is_list($node)) {
                foreach ($node as &$entry) {
                    if (is_array($entry)) {
                        $apply($entry);
                    }
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

        if ($matched === 0) {
            throw new RuntimeException('Produto não localizado no cache ativo com identidade estrita.');
        }
        if ($matched > 1) {
            throw new RuntimeException("Identidade ambígua no cache: {$matched} registros correspondem ao mesmo produto. Nenhuma alteração foi persistida.");
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao persistir a galeria no cache ativo.');
        }
        $readBack = json_decode((string)file_get_contents($path), true);
        if (!is_array($readBack)) {
            throw new RuntimeException('Read-back do cache ativo falhou.');
        }
    }

    private function cacheItemMatchesProduct(array $item, string $sku, string $externalId): bool
    {
        $itemSku = trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? ''));
        $idCandidates = [];
        foreach (['olist_id', 'olist_product_id', 'idProduto', 'external_id', 'id'] as $field) {
            $value = trim((string)($item[$field] ?? ''));
            if ($value !== '') {
                $idCandidates[] = $value;
            }
        }
        $idCandidates = array_values(array_unique($idCandidates));

        $skuMatches = $sku !== '' && $itemSku !== '' && hash_equals($sku, $itemSku);
        $idMatches = $externalId !== '' && in_array($externalId, $idCandidates, true);

        if ($sku !== '' && $externalId !== '') {
            return $skuMatches && $idMatches;
        }
        if ($sku !== '') {
            return $skuMatches;
        }
        return $idMatches;
    }

    private function setStaging(int $id, string $status, ?string $error, array $channels, ?array $summary, ?string $publishedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images_staging SET status = ?, error_message = ?, target_channels_json = ?, publication_summary_json = ?, published_at = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $error, sv_market_json($channels), $summary !== null ? sv_market_json($summary) : null, $publishedAt, $id]);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
