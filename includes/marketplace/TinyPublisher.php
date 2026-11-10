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
        // O PUT do produto nao e um PATCH de SEO. Buscar o cadastro completo
        // antes de alterar texto preserva slug, video e palavras-chave que
        // nao tenham sido explicitamente substituidos pela proposta.
        $current = sv_market_tiny_request_v3('GET', '/produtos/' . $tinyId);
        if ((int)($current['status'] ?? 0) !== 200) {
            throw new SvMarketplaceException('Nao foi possivel ler o SEO atual no ERP antes da publicacao.', (int)($current['status'] ?? 0));
        }
        $currentJson = is_array($current['json'] ?? null) ? $current['json'] : [];
        $currentProduct = is_array($currentJson['produto'] ?? null) ? $currentJson['produto'] : $currentJson;
        $currentSku = trim((string)($currentProduct['sku'] ?? $currentProduct['codigo'] ?? ''));
        if ($currentSku === '' || strcasecmp($currentSku, $sku) !== 0) {
            throw new RuntimeException('SKU do produto ERP nao corresponde ao cadastro de origem.');
        }
        $currentSeo = is_array($currentProduct['seo'] ?? null) ? $currentProduct['seo'] : [];
        $seo = self::mergeSeo($currentSeo, $content);
        $payload = [
            'descricao' => $title,
            'sku' => $sku,
            'descricaoComplementar' => $this->notes($content),
        ];
        if ($seo !== []) {
            $payload['seo'] = $seo;
        }
        // Nao enviar preco, custo ou estoque no PUT editorial.
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
        foreach ($seo as $key => $expected) {
            if (!array_key_exists($key, $readSeo) || $readSeo[$key] !== $expected) {
                throw new RuntimeException('Tiny/Olist nao preservou o campo SEO ' . $key . ' no read-back.');
            }
        }
        // Identidade e valores comerciais nunca sao alvo da publicacao textual.
        foreach (['ncm', 'gtin', 'origem', 'codigoEspecificadorSubstituicaoTributaria', 'categoria', 'marca', 'dimensoes', 'precos', 'fornecedores', 'variacoes', 'estoque'] as $field) {
            if (array_key_exists($field, $currentProduct)
                && ($readProduct[$field] ?? null) !== $currentProduct[$field]) {
                throw new RuntimeException('Tiny/Olist mudou campo fora do escopo editorial: ' . $field);
            }
        }
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'resolved_by_sku_or_products.olist_id']);
        return [
            'status' => 'published',
            'operation' => 'PUT /public-api/v3/produtos/{id}',
            'external_id' => (string)$tinyId,
            'http_status' => (int)$response['status'],
            'request_id' => '',
            'fields' => array_merge(['descricao', 'descricaoComplementar'], array_map(static fn(string $key): string => 'seo.' . $key, array_keys($seo))),
            'response' => [
                'title_confirmed' => true,
                'description_confirmed' => $readDescription !== '',
                'seo_confirmed' => $seo === [] || $readSeo !== [],
                'seo_fields_preserved' => array_keys($seo),
                'non_editorial_fields_unchanged' => true,
            ],
            'verified' => true,
        ];
    }

    /**
     * Mescla SEO do ERP sem transformar campo omitido na proposta em limpeza.
     * A lista vazia de keywords no staging significa "sem sugestao nova",
     * nao a exclusao de palavras-chave existentes.
     *
     * @param array<string,mixed> $existingSeo
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public static function mergeSeo(array $existingSeo, array $content): array
    {
        $seo = $existingSeo;
        $title = trim((string)($content['meta_title'] ?? ''));
        $description = trim((string)($content['meta_description'] ?? ''));
        $keywords = is_array($content['seo_keywords'] ?? null)
            ? array_values(array_filter(array_map('trim', $content['seo_keywords']), static fn(string $value): bool => $value !== ''))
            : [];
        if ($title !== '') $seo['titulo'] = function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120);
        if ($description !== '') $seo['descricao'] = $description;
        if ($keywords !== []) $seo['keywords'] = $keywords;
        return $seo;
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
    /**
     * Usa apenas ID presente na listagem de produtos ATIVOS.
     * O mesmo SKU pode permanecer em um ID excluido com GET 200; o detalhe
     * do cadastro excluido nao serve como prova de que ele e o ID atual.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function activeIdFromRows(string $sku, array $rows): int
    {
        $matches = [];
        foreach ($rows as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateSku = trim((string)($candidate['codigo'] ?? $candidate['sku'] ?? ''));
            $candidateId = (int)($candidate['id'] ?? $candidate['idProduto'] ?? 0);
            if ($candidateId > 0 && strcasecmp($candidateSku, $sku) === 0) {
                $matches[$candidateId] = true;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('ERP Olist/Tiny nao encontrou exatamente um ID ativo para o SKU ' . $sku . '.');
        }
        return (int)array_key_first($matches);
    }

    private function resolveTinyId(int $productId, array $product): int
    {
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('SKU ausente para localizar o produto no Tiny/Olist.');

        // Nunca confiar apenas no mapeamento salvo ou no detalhe GET: IDs
        // historicos excluidos ainda podem responder HTTP 200 com situacao A.
        $query = http_build_query(['codigo' => $sku, 'situacao' => 'A', 'limit' => 100, 'offset' => 0]);
        $search = sv_market_tiny_request_v3('GET', '/produtos?' . $query);
        if ((int)$search['status'] !== 200) {
            throw new SvMarketplaceException('ERP Olist/Tiny v3 falhou ao consultar ID ativo para SKU ' . $sku, (int)$search['status']);
        }
        $json = is_array($search['json'] ?? null) ? $search['json'] : [];
        $rows = $json['itens'] ?? $json['data'] ?? $json['produtos'] ?? [];
        $tinyId = self::activeIdFromRows($sku, is_array($rows) ? $rows : []);

        $mapping = sv_market_mapping($this->db, $productId, 'erp');
        $mappedId = is_array($mapping) ? (int)($mapping['external_id'] ?? 0) : 0;
        $existing = (int)($product['olist_id'] ?? 0);
        if (($mappedId > 0 && $mappedId !== $tinyId) || ($existing > 0 && $existing !== $tinyId)) {
            // Nao corrigir vinculo cruzando registros historicos por SKU sozinho:
            // o novo ID pode ter outro GTIN, marca ou descricao. Auditar antes.
            throw new RuntimeException('Mapeamento ERP historico diverge do ID ativo. Validar GTIN e vinculo antes de publicar.');
        }
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'api_v3_exact_active_sku']);
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
