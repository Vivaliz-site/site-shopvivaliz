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
        $apiToken = sv_market_env('OLIST_API_KEY', 'TOKEN_API_OLIST');
        if ($apiToken === '') throw new RuntimeException('Token da API V2 Tiny/Olist ausente para atualização de imagens.');
        $current = $this->v2FormRequest('https://api.tiny.com.br/api2/produto.obter.php', [
            'token' => $apiToken,
            'id' => (string)$tinyId,
            'formato' => 'JSON',
        ]);
        $currentProduct = $current['retorno']['produto'] ?? null;
        if (!is_array($currentProduct)) throw new RuntimeException('Tiny/Olist não retornou o produto atual para preservação segura.');
        $beforePrice = (string)($currentProduct['preco'] ?? '');
        $urls = array_slice(array_values(array_unique(array_map('sv_market_absolute_url', $imageUrls))), 0, 20);
        if ($urls === []) throw new RuntimeException('Nenhuma imagem válida para Tiny/Olist.');
        $existing = is_array($currentProduct['imagens_externas'] ?? null) ? $currentProduct['imagens_externas'] : [];
        $existingUrls = [];
        foreach ($existing as $entry) {
            $url = trim((string)($entry['imagem_externa']['url'] ?? $entry['url'] ?? ''));
            if ($url !== '') $existingUrls[] = $url;
        }
        $merged = array_values(array_unique(array_merge($urls, $existingUrls)));
        $imagePayload = array_map(static fn(string $url): array => ['imagem_externa' => ['url' => $url]], $merged);

        // A API V2 oficial exige o preço no layout completo de produto. Ele é
        // lido e reenviado sem qualquer modificação, e o read-back abaixo aborta
        // a publicação se houver a menor divergência. Estoque nunca é enviado.
        $safeProduct = [
            'sequencia' => '1',
            'id' => $tinyId,
            'codigo' => (string)($currentProduct['codigo'] ?? $product['sku'] ?? ''),
            'nome' => (string)($currentProduct['nome'] ?? $product['name'] ?? ''),
            'unidade' => (string)($currentProduct['unidade'] ?? 'UN'),
            'preco' => $beforePrice,
            'origem' => (string)($currentProduct['origem'] ?? '0'),
            'imagens_externas' => $imagePayload,
        ];
        foreach (['descricao_complementar', 'ncm', 'gtin', 'gtin_embalagem', 'categoria', 'classe_produto', 'seo'] as $key) {
            if (array_key_exists($key, $currentProduct) && $currentProduct[$key] !== '' && $currentProduct[$key] !== null) $safeProduct[$key] = $currentProduct[$key];
        }
        $request = ['produtos' => [['produto' => $safeProduct]]];
        $result = $this->v2FormRequest('https://api.tiny.com.br/api2/produto.alterar.php', [
            'token' => $apiToken,
            'produto' => sv_market_json($request),
            'formato' => 'JSON',
        ]);
        if (strtoupper((string)($result['retorno']['status'] ?? '')) !== 'OK') {
            throw new RuntimeException('Tiny/Olist rejeitou as imagens: ' . sv_market_safe_excerpt(sv_market_json($result['retorno']['erros'] ?? $result)));
        }
        $verify = $this->v2FormRequest('https://api.tiny.com.br/api2/produto.obter.php', [
            'token' => $apiToken,
            'id' => (string)$tinyId,
            'formato' => 'JSON',
        ]);
        $verifiedProduct = $verify['retorno']['produto'] ?? [];
        if ($beforePrice !== (string)($verifiedProduct['preco'] ?? '')) throw new RuntimeException('Proteção acionada: preço mudou durante a atualização de imagens no Tiny/Olist.');
        $verifiedImages = is_array($verifiedProduct['imagens_externas'] ?? null) ? $verifiedProduct['imagens_externas'] : [];
        $verifiedUrls = [];
        foreach ($verifiedImages as $entry) {
            $url = trim((string)($entry['imagem_externa']['url'] ?? $entry['url'] ?? ''));
            if ($url !== '') $verifiedUrls[] = $url;
        }
        if (array_intersect($urls, $verifiedUrls) === []) throw new RuntimeException('Tiny/Olist não confirmou as imagens no read-back.');
        return [
            'status' => 'published',
            'operation' => 'POST produto.alterar.php (API V2 imagens_externas)',
            'external_id' => (string)$tinyId,
            'http_status' => 200,
            'request_id' => '',
            'fields' => ['imagens_externas'],
            'response' => ['image_count' => count($verifiedUrls), 'price_preserved' => true, 'stock_untouched' => true],
            'verified' => true,
        ];
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
        $apiToken = sv_market_env('OLIST_API_KEY', 'TOKEN_API_OLIST');
        if ($apiToken === '') {
            throw new RuntimeException("Produto {$sku} sem olist_id e sem OLIST_API_KEY para resolver o ID automaticamente.");
        }
        $search = $this->v2FormRequest('https://api.tiny.com.br/api2/produtos.pesquisa.php', [
            'token' => $apiToken,
            'pesquisa' => $sku,
            'situacao' => 'A',
            'formato' => 'JSON',
        ]);
        $rows = $search['retorno']['produtos'] ?? [];
        $matches = [];
        foreach (is_array($rows) ? $rows : [] as $entry) {
            $candidate = is_array($entry['produto'] ?? null) ? $entry['produto'] : $entry;
            if (!is_array($candidate)) continue;
            $candidateSku = trim((string)($candidate['codigo'] ?? $candidate['sku'] ?? ''));
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId > 0 && strcasecmp($candidateSku, $sku) === 0) {
                $matches[$candidateId] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException("Tiny/Olist não encontrou exatamente um produto ativo para o SKU {$sku}.");
        }
        $tinyId = (int)array_key_first($matches);
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'api_v2_exact_sku_search']);
        $update = $this->db->prepare("UPDATE products SET olist_id = ?, updated_at = NOW() WHERE id = ? AND (olist_id IS NULL OR TRIM(olist_id) = '')");
        $update->execute([(string)$tinyId, $productId]);
        return $tinyId;
    }

    private function v2FormRequest(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Falha ao iniciar chamada Tiny/Olist V2.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) throw new RuntimeException('Falha de transporte Tiny/Olist V2: ' . $error);
        $json = sv_market_decode_json($raw);
        if ($status < 200 || $status >= 300 || $json === []) throw new SvMarketplaceException('Tiny/Olist V2 HTTP ' . $status . ': ' . sv_market_safe_excerpt($raw), $status, '', $json);
        if (strtoupper((string)($json['retorno']['status'] ?? 'OK')) === 'ERRO') {
            throw new SvMarketplaceException('Tiny/Olist V2: ' . sv_market_safe_excerpt(sv_market_json($json['retorno']['erros'] ?? $json)), $status, '', $json);
        }
        return $json;
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
