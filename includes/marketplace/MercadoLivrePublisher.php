<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';
require_once dirname(__DIR__, 2) . '/api/ml/client.php';

final class SvMercadoLivrePublisher
{
    public function __construct(private PDO $db) {}

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente; não é possível localizar o anúncio no Mercado Livre.');
        $itemId = $this->resolveItemId($productId, $sku);
        $title = trim((string)($content['title'] ?? ''));
        $description = $this->plainDescription($content);
        if ($title === '' || $description === '') throw new RuntimeException('Título ou descrição vazios para o Mercado Livre.');
        $itemPayload = ['title' => $this->limit($title, 60)];
        sv_market_assert_no_commerce_fields($itemPayload, 'Mercado Livre item update');

        // Anuncios com family_name (user_product_listing) recusam PUT title com
        // 400 "You cannot modify the title if the item has a family_name" — o ML
        // recompoe o titulo a partir dos atributos nesses anuncios (ver
        // docs/MERCADO-LIVRE-API.md). Isso e o catalogo inteiro deste seller
        // (2026-08-13), entao o fluxo trata como esperado em vez de derrubar a
        // publicacao inteira: segue so com a descricao.
        $titleUpdated = false;
        $itemResponse = [];
        try {
            $itemResponse = ml_http_json('PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId), $itemPayload);
            $titleUpdated = true;
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'family_name') && !str_contains($exception->getMessage(), 'family name')) {
                throw $exception;
            }
        }

        $descriptionPayload = ['plain_text' => $description];
        sv_market_assert_no_commerce_fields($descriptionPayload, 'Mercado Livre description update');
        $descriptionResponse = ml_http_json('PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/description?api_version=2', $descriptionPayload);
        $itemReadback = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId));
        $descriptionReadback = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/description');
        if ($titleUpdated && trim((string)($itemReadback['title'] ?? '')) !== $itemPayload['title']) {
            throw new RuntimeException('O Mercado Livre aceitou a chamada, mas o read-back não confirmou o título.');
        }
        if (trim((string)($descriptionReadback['plain_text'] ?? '')) === '') {
            throw new RuntimeException('O Mercado Livre aceitou a chamada, mas o read-back não confirmou a descrição.');
        }
        sv_market_save_mapping($this->db, $productId, 'ml', $itemId, $sku, ['status' => $itemReadback['status'] ?? null]);
        return [
            'status' => 'published', 'operation' => 'PUT /items/{id} + PUT /items/{id}/description',
            'external_id' => $itemId, 'http_status' => 200, 'request_id' => '',
            'fields' => $titleUpdated ? ['title', 'description'] : ['description'],
            'response' => ['item_status' => $itemReadback['status'] ?? null, 'title_confirmed' => $titleUpdated, 'description_confirmed' => true,
                'title_skipped_reason' => $titleUpdated ? null : 'family_name: título não editável neste anúncio, apenas ficha técnica/descrição',
                'item_response' => array_intersect_key($itemResponse, array_flip(['id', 'title', 'status'])),
                'description_response' => array_intersect_key($descriptionResponse, array_flip(['text', 'plain_text']))],
            'verified' => true,
        ];
    }

    public function publishImages(int $productId, array $imageUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para publicação de imagens no Mercado Livre.');
        $itemId = $this->resolveItemId($productId, $sku);
        $current = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId));
        $sources = [];
        foreach ($imageUrls as $url) $sources[] = sv_market_absolute_url((string)$url);
        foreach (is_array($current['pictures'] ?? null) ? $current['pictures'] : [] as $picture) {
            $url = trim((string)($picture['secure_url'] ?? $picture['url'] ?? ''));
            if ($url !== '') $sources[] = $url;
        }
        $pictures = array_map(static fn(string $url): array => ['source' => $url], array_slice(array_values(array_unique($sources)), 0, 12));
        if ($pictures === []) throw new RuntimeException('Nenhuma imagem válida para enviar ao Mercado Livre.');
        $payload = ['pictures' => $pictures];
        sv_market_assert_no_commerce_fields($payload, 'Mercado Livre image update');
        $response = ml_http_json('PUT', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId), $payload);
        $readback = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId));
        $readPictures = is_array($readback['pictures'] ?? null) ? $readback['pictures'] : [];
        if ($readPictures === []) throw new RuntimeException('O Mercado Livre não confirmou imagens no read-back.');
        return ['status' => 'published', 'operation' => 'PUT /items/{id} pictures', 'external_id' => $itemId,
            'http_status' => 200, 'request_id' => '', 'fields' => ['pictures'],
            'response' => ['picture_count' => count($readPictures), 'status' => $response['status'] ?? null], 'verified' => true];
    }

    private function resolveItemId(int $productId, string $sku): string
    {
        $mapping = sv_market_mapping($this->db, $productId, 'ml');
        if (is_array($mapping) && trim((string)$mapping['external_id']) !== '') return trim((string)$mapping['external_id']);
        $mapPath = dirname(__DIR__, 2) . '/storage/private/ml-item-map.json';
        if (is_file($mapPath)) {
            $map = sv_market_decode_json((string)file_get_contents($mapPath));
            $itemId = trim((string)($map[$sku]['ml_item_id'] ?? ''));
            if ($itemId !== '') {
                sv_market_save_mapping($this->db, $productId, 'ml', $itemId, $sku, ['source' => 'ml-item-map.json']);
                return $itemId;
            }
        }
        $sellerId = sv_market_env('ML_SELLER_ID');
        if ($sellerId !== '') {
            $search = ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($sellerId) . '/items/search?seller_sku=' . rawurlencode($sku));
            $results = is_array($search['results'] ?? null) ? $search['results'] : [];
            if (count($results) === 1) {
                $itemId = (string)$results[0];
                sv_market_save_mapping($this->db, $productId, 'ml', $itemId, $sku, ['source' => 'seller_sku_search']);
                return $itemId;
            }
        }
        throw new RuntimeException("Anúncio Mercado Livre não localizado para o SKU {$sku}. Sincronize o mapeamento antes de aprovar.");
    }

    private function plainDescription(array $content): string
    {
        $parts = [trim((string)($content['description'] ?? ''))];
        $bullets = is_array($content['bullet_points'] ?? null) ? $content['bullet_points'] : [];
        if ($bullets !== []) $parts[] = implode("\n", array_map(static fn($value): string => '• ' . trim((string)$value), $bullets));
        return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
