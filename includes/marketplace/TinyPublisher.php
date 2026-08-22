<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';
require_once __DIR__ . '/TinyV3Runtime.php';
require_once dirname(__DIR__) . '/tiny-order-push.php';

final class SvTinyPublisher
{
    public function __construct(private PDO $db) {}

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('Produto sem SKU para atualização no Tiny/Olist.');
        $tinyId = $this->resolveTinyId($productId, $product);
        if (!svtop_tiny_credentials_configured()) throw new RuntimeException('Credenciais Tiny/Olist não configuradas.');
        $title = $this->limit(trim((string)($content['title'] ?? '')), 120);
        $description = trim((string)($content['description'] ?? ''));
        if ($title === '' || $description === '') throw new RuntimeException('Título ou descrição vazios para Tiny/Olist.');
        $payload = [
            'descricao' => $title,
            'sku' => $sku,
            'descricaoComplementar' => $this->notes($content),
            'seo' => [
                'titulo' => $this->limit((string)($content['meta_title'] ?? $title), 120),
                'descricao' => (string)($content['meta_description'] ?? $description),
                'keywords' => is_array($content['seo_keywords'] ?? null) ? array_values($content['seo_keywords']) : [],
            ],
        ];
        sv_market_assert_no_commerce_fields($payload, 'Tiny/Olist product text update');
        $response = sv_market_tiny_request_v3('PUT', '/produtos/' . $tinyId, $payload);
        if (!in_array((int)$response['status'], [200, 204], true)) {
            $message = is_array($response['json'] ?? null) ? (string)($response['json']['mensagem'] ?? $response['json']['message'] ?? '') : '';
            throw new SvMarketplaceException($message !== '' ? $message : sv_market_safe_excerpt((string)$response['body']), (int)$response['status']);
        }
        $read = sv_market_tiny_request_v3('GET', '/produtos/' . $tinyId);
        if ((int)$read['status'] !== 200) throw new SvMarketplaceException('Tiny/Olist aceitou o PUT, mas o read-back falhou.', (int)$read['status']);
        $readJson = is_array($read['json'] ?? null) ? $read['json'] : [];
        $readProduct = is_array($readJson['produto'] ?? null) ? $readJson['produto'] : $readJson;
        $readTitle = trim((string)($readProduct['descricao'] ?? ''));
        if ($readTitle !== '' && $readTitle !== $title) throw new RuntimeException('Tiny/Olist não confirmou o título atualizado no read-back.');
        $readDescription = trim((string)($readProduct['descricaoComplementar'] ?? $readProduct['descricao_complementar'] ?? ''));
        if ($readDescription !== '' && $this->normalizeText($readDescription) !== $this->normalizeText((string)$payload['descricaoComplementar'])) {
            throw new RuntimeException('Tiny/Olist não confirmou a descrição complementar no read-back.');
        }
        $readSeo = is_array($readProduct['seo'] ?? null) ? $readProduct['seo'] : [];
        if ($readSeo !== []) {
            $readSeoTitle = trim((string)($readSeo['titulo'] ?? ''));
            $readSeoDescription = trim((string)($readSeo['descricao'] ?? ''));
            if ($readSeoTitle !== '' && $readSeoTitle !== trim((string)$payload['seo']['titulo'])) {
                throw new RuntimeException('Tiny/Olist não confirmou o título SEO no read-back.');
            }
            if ($readSeoDescription !== '' && $readSeoDescription !== trim((string)$payload['seo']['descricao'])) {
                throw new RuntimeException('Tiny/Olist não confirmou a descrição SEO no read-back.');
            }
        }
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'resolved_by_sku_or_products.olist_id']);
        return [
            'status' => 'published',
            'operation' => 'PUT /public-api/v3/produtos/{id}',
            'external_id' => (string)$tinyId,
            'http_status' => (int)$response['status'],
            'request_id' => '',
            'fields' => ['descricao', 'descricaoComplementar', 'seo.titulo', 'seo.descricao', 'seo.keywords'],
            'response' => [
                'title_confirmed' => true,
                'description_confirmed' => $readDescription !== '',
                'seo_confirmed' => $readSeo !== [],
            ],
            'verified' => true,
        ];
    }

    public function publishImages(int $productId, array $imageUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $tinyId = $this->resolveTinyId($productId, $product);
        $read = sv_market_tiny_request_v3('GET', '/produtos/' . $tinyId);
        if ((int)$read['status'] !== 200) {
            throw new SvMarketplaceException('ERP Olist/Tiny v3 nao confirmou o produto para sincronizacao de imagens.', (int)$read['status']);
        }
        throw new RuntimeException('Escrita de imagens por fonte antiga foi removida. Imagens publicas devem vir do ERP Olist/Tiny v3; habilitar escrita somente com endpoint v3 oficial validado em homologacao.');
    }

    /** @param array<string,mixed> $product */
    private function resolveTinyId(int $productId, array $product): int
    {
        $mapping = sv_market_mapping($this->db, $productId, 'erp');
        if (is_array($mapping) && (int)($mapping['external_id'] ?? 0) > 0) {
            return (int)$mapping['external_id'];
        }
        $existing = (int)($product['olist_id'] ?? 0);
        if ($existing > 0) return $existing;
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para localizar o produto no Tiny/Olist.');
        $query = http_build_query(['pesquisa' => $sku, 'situacao' => 'A', 'limit' => 100, 'offset' => 0]);
        $search = sv_market_tiny_request_v3('GET', '/produtos?' . $query);
        if ((int)$search['status'] !== 200) {
            throw new SvMarketplaceException('ERP Olist/Tiny v3 falhou ao resolver SKU ' . $sku, (int)$search['status']);
        }
        $json = is_array($search['json'] ?? null) ? $search['json'] : [];
        $rows = $json['itens'] ?? $json['data'] ?? $json['produtos'] ?? [];
        $matches = [];
        foreach (is_array($rows) ? $rows : [] as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateSku = trim((string)($candidate['codigo'] ?? $candidate['sku'] ?? ''));
            $candidateId = (int)($candidate['id'] ?? $candidate['idProduto'] ?? 0);
            if ($candidateId > 0 && strcasecmp($candidateSku, $sku) === 0) {
                $matches[$candidateId] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException("ERP Olist/Tiny v3 não encontrou exatamente um produto ativo para o SKU {$sku}.");
        }
        $tinyId = (int)array_key_first($matches);
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'api_v3_exact_sku_search']);
        $update = $this->db->prepare("UPDATE products SET olist_id = ?, updated_at = NOW() WHERE id = ? AND (olist_id IS NULL OR TRIM(olist_id) = '')");
        $update->execute([(string)$tinyId, $productId]);
        return $tinyId;
    }

    private function notes(array $content): string
    {
        $parts = [trim((string)($content['description'] ?? ''))];
        $bullets = is_array($content['bullet_points'] ?? null) ? $content['bullet_points'] : [];
        if ($bullets !== []) $parts[] = implode("\n", array_map(static fn($value): string => '• ' . trim((string)$value), $bullets));
        return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        return trim($value);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
