<?php
declare(strict_types=1);

require_once __DIR__ . '/MarketplaceRuntime.php';
require_once dirname(__DIR__) . '/tiny-order-push.php';

final class SvTinyPublisher
{
    public function __construct(private PDO $db) {}

    public function publishText(int $productId, array $content): array
    {
        $product = sv_market_product($this->db, $productId);
        $tinyId = (int)($product['olist_id'] ?? 0);
        $sku = trim((string)($product['sku'] ?? ''));
        if ($tinyId <= 0 || $sku === '') throw new RuntimeException('Produto sem olist_id/SKU para atualização no Tiny/Olist.');
        if (!svtop_tiny_credentials_configured()) throw new RuntimeException('Credenciais Tiny/Olist não configuradas.');
        $token = svtop_tiny_get_token();
        if ($token === '') throw new RuntimeException('Não foi possível obter access token Tiny/Olist.');
        $title = $this->limit(trim((string)($content['title'] ?? '')), 120);
        $description = trim((string)($content['description'] ?? ''));
        if ($title === '' || $description === '') throw new RuntimeException('Título ou descrição vazios para Tiny/Olist.');
        $payload = [
            'descricao' => $title,
            'sku' => $sku,
            'observacoes' => $this->notes($content),
            'seo' => [
                'titulo' => $this->limit((string)($content['meta_title'] ?? $title), 120),
                'descricao' => (string)($content['meta_description'] ?? $description),
                'keywords' => is_array($content['seo_keywords'] ?? null) ? array_values($content['seo_keywords']) : [],
            ],
        ];
        sv_market_assert_no_commerce_fields($payload, 'Tiny/Olist product text update');
        $response = svtop_tiny_request('PUT', '/produtos/' . $tinyId, $token, $payload);
        if (!in_array((int)$response['status'], [200, 204], true)) {
            $message = is_array($response['json'] ?? null) ? (string)($response['json']['mensagem'] ?? $response['json']['message'] ?? '') : '';
            throw new SvMarketplaceException($message !== '' ? $message : sv_market_safe_excerpt((string)$response['body']), (int)$response['status']);
        }
        $read = svtop_tiny_request('GET', '/produtos/' . $tinyId, $token);
        if ((int)$read['status'] !== 200) throw new SvMarketplaceException('Tiny/Olist aceitou o PUT, mas o read-back falhou.', (int)$read['status']);
        $readJson = is_array($read['json'] ?? null) ? $read['json'] : [];
        $readTitle = trim((string)($readJson['descricao'] ?? $readJson['produto']['descricao'] ?? ''));
        if ($readTitle !== '' && $readTitle !== $title) throw new RuntimeException('Tiny/Olist não confirmou o título atualizado no read-back.');
        sv_market_save_mapping($this->db, $productId, 'erp', (string)$tinyId, $sku, ['source' => 'products.olist_id']);
        return ['status' => 'published', 'operation' => 'PUT /public-api/v3/produtos/{id}', 'external_id' => (string)$tinyId,
            'http_status' => (int)$response['status'], 'request_id' => '', 'fields' => ['descricao', 'observacoes', 'seo'],
            'response' => ['readback_confirmed' => true], 'verified' => true];
    }

    public function publishImages(int $productId, array $imageUrls): array
    {
        $product = sv_market_product($this->db, $productId);
        $tinyId = (int)($product['olist_id'] ?? 0);
        if ($tinyId <= 0) throw new RuntimeException('Produto sem olist_id para publicar imagens no Tiny/Olist.');
        $apiToken = sv_market_env('OLIST_API_KEY', 'TOKEN_API_OLIST');
        if ($apiToken === '') throw new RuntimeException('Token da API V2 Tiny/Olist ausente para atualização de imagens.');
        $current = $this->v2FormRequest('https://api.tiny.com.br/api2/produto.obter.php', ['token' => $apiToken, 'id' => (string)$tinyId, 'formato' => 'JSON']);
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
        $safeProduct = [
            'sequencia' => '1', 'id' => $tinyId,
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
            'token' => $apiToken, 'produto' => sv_market_json($request), 'formato' => 'JSON',
        ]);
        if (strtoupper((string)($result['retorno']['status'] ?? '')) !== 'OK') {
            throw new RuntimeException('Tiny/Olist rejeitou as imagens: ' . sv_market_safe_excerpt(sv_market_json($result['retorno']['erros'] ?? $result)));
        }
        $verify = $this->v2FormRequest('https://api.tiny.com.br/api2/produto.obter.php', ['token' => $apiToken, 'id' => (string)$tinyId, 'formato' => 'JSON']);
        $verifiedProduct = $verify['retorno']['produto'] ?? [];
        if ($beforePrice !== (string)($verifiedProduct['preco'] ?? '')) throw new RuntimeException('Proteção acionada: preço mudou durante a atualização de imagens no Tiny/Olist.');
        $verifiedImages = is_array($verifiedProduct['imagens_externas'] ?? null) ? $verifiedProduct['imagens_externas'] : [];
        $verifiedUrls = [];
        foreach ($verifiedImages as $entry) {
            $url = trim((string)($entry['imagem_externa']['url'] ?? $entry['url'] ?? ''));
            if ($url !== '') $verifiedUrls[] = $url;
        }
        if (array_intersect($urls, $verifiedUrls) === []) throw new RuntimeException('Tiny/Olist não confirmou as imagens no read-back.');
        return ['status' => 'published', 'operation' => 'POST produto.alterar.php (API V2 imagens_externas)',
            'external_id' => (string)$tinyId, 'http_status' => 200, 'request_id' => '', 'fields' => ['imagens_externas'],
            'response' => ['image_count' => count($verifiedUrls), 'price_preserved' => true], 'verified' => true];
    }

    private function v2FormRequest(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Falha ao iniciar chamada Tiny/Olist V2.');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) throw new RuntimeException('Falha de transporte Tiny/Olist V2: ' . $error);
        $json = sv_market_decode_json($raw);
        if ($status < 200 || $status >= 300 || $json === []) throw new SvMarketplaceException('Tiny/Olist V2 HTTP ' . $status . ': ' . sv_market_safe_excerpt($raw), $status, '', $json);
        return $json;
    }

    private function notes(array $content): string
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
