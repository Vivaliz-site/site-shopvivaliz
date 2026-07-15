<?php

declare(strict_types=1);

final class ShopeeListingsExtractorAgent
{
    private const API_BASE  = 'https://api.tiny.com.br/public-api/v3';
    private const TOKEN_URL = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    private const VERSION   = '9.2.85';
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES  = 50;

    public function run(): array
    {
        $result = [
            'agent'        => 'shopee_listings_extractor',
            'version'      => self::VERSION,
            'generated_at' => date('c'),
            'secrets_check' => [],
            'status'       => 'pending',
            'total_products' => 0,
            'products'     => [],
            'errors'       => [],
        ];

        $token = $this->resolveToken($result);
        if (!$token) {
            $result['status'] = 'error';
            return $result;
        }

        $products = $this->fetchAllProducts($token, $result);
        $result['total_products'] = count($products);
        $result['products']       = $products;
        $result['status']         = empty($result['errors']) ? 'success' : 'partial';

        return $result;
    }

    private function resolveToken(array &$result): ?string
    {
        // O access_token da Tiny expira em ~4h (14400s); este workflow roda
        // a cada 6h, entao um TINY_ACCESS_TOKEN estatico salvo em secret
        // sempre estara vencido quando rodar. Por isso o refresh via OAuth2
        // tem prioridade -- so cai pro token direto se o refresh falhar.
        $clientId     = getenv('TINY_CLIENT_ID')     ?: getenv('OLIST_CLIENT_ID')     ?: '';
        $clientSecret = getenv('TINY_CLIENT_SECRET') ?: getenv('OLIST_CLIENT_SECRET') ?: '';
        $refreshToken = getenv('TINY_REFRESH_TOKEN') ?: getenv('OLIST_REFRESH_TOKEN') ?: '';

        if ($clientId && $clientSecret && $refreshToken) {
            $result['secrets_check']['token_source'] = 'oauth2_refresh';
            $token = $this->refreshOAuthToken($clientId, $clientSecret, $refreshToken, $result);
            if ($token) return $token;
        }

        // Fallback: aceita qualquer variante de token direto (pode estar vencido)
        foreach (['TINY_ACCESS_TOKEN', 'TINY_API_TOKEN', 'ERP_API_TOKEN', 'OLIST_ACCESS_TOKEN'] as $name) {
            $val = getenv($name);
            if ($val !== false && $val !== '') {
                $result['secrets_check']['token_source']    = $name;
                $result['secrets_check']['token_available'] = true;
                return $val;
            }
        }

        // Relata apenas nomes ausentes, nunca valores
        $missing = [];
        if (!getenv('TINY_ACCESS_TOKEN') && !getenv('TINY_API_TOKEN') && !getenv('OLIST_ACCESS_TOKEN')) {
            $missing[] = 'TINY_ACCESS_TOKEN ou TINY_API_TOKEN';
        }
        if (!$clientId)     $missing[] = 'TINY_CLIENT_ID ou OLIST_CLIENT_ID';
        if (!$clientSecret) $missing[] = 'TINY_CLIENT_SECRET ou OLIST_CLIENT_SECRET';
        if (!$refreshToken) $missing[] = 'TINY_REFRESH_TOKEN ou OLIST_REFRESH_TOKEN';

        $result['secrets_check']['token_available']  = false;
        $result['secrets_check']['missing_secrets']  = $missing;
        $result['errors'][] = 'Credenciais ausentes: ' . implode(', ', $missing);
        return null;
    }

    private function refreshOAuthToken(string $clientId, string $clientSecret, string $refreshToken, array &$result): ?string
    {
        $response = $this->httpPost(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (!empty($response['access_token'])) {
            $result['secrets_check']['token_refreshed'] = true;
            return $response['access_token'];
        }

        $result['errors'][] = 'Falha ao renovar token: ' . ($response['error_description'] ?? $response['error'] ?? 'unknown');
        return null;
    }

    private function fetchAllProducts(string $token, array &$result): array
    {
        $all     = [];
        $seenIds = [];
        $offset  = 0;
        $page    = 0;

        while ($page < self::MAX_PAGES) {
            $page++;
            $url  = self::API_BASE . '/produtos?limit=' . self::PAGE_LIMIT . '&offset=' . $offset;
            $data = $this->httpGet($url, $token);

            if (isset($data['_http_status']) && $data['_http_status'] === 401) {
                $result['errors'][] = 'Autenticação falhou (401). Token inválido ou expirado.';
                break;
            }

            if (!isset($data['itens']) || !is_array($data['itens'])) {
                if ($page === 1) {
                    $result['errors'][] = 'Resposta inesperada da API: ' . json_encode(array_diff_key($data, ['itens' => 1]));
                }
                break;
            }

            $items = $data['itens'];
            if (empty($items)) break;

            $newCount = 0;
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if ($id === null || isset($seenIds[$id])) continue;
                $seenIds[$id] = true;
                $all[]        = $this->extractProduct($item);
                $newCount++;
            }

            // Anti-loop: sem IDs novos = fim real da lista
            if ($newCount === 0) break;

            $offset += self::PAGE_LIMIT;
            usleep(250_000); // 250ms — respeitar rate limit
        }

        return $all;
    }

    private function extractProduct(array $item): array
    {
        $imagens = [];
        foreach ($item['imagens'] ?? [] as $img) {
            $url = $img['url'] ?? null;
            if ($url) $imagens[] = $url;
        }

        return [
            'id'                => $item['id']               ?? null,
            'sku'               => $item['sku']              ?? null,
            'nome'              => $item['nome']             ?? $item['descricao'] ?? null,
            'situacao'          => $item['situacao']         ?? null,
            'preco'             => $item['preco']            ?? null,
            'preco_promocional' => $item['preco_promocional'] ?? null,
            'estoque'           => $item['estoque']          ?? null,
            'unidade'           => $item['unidade']          ?? null,
            'gtin'              => $item['gtin']             ?? null,
            'categoria'         => $item['categoria']['nome'] ?? null,
            'marca'             => $item['marca']['nome']    ?? null,
            'imagens'           => $imagens,
            'qtd_variacoes'     => count($item['variacoes'] ?? []),
        ];
    }

    private function httpGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) return ['error' => 'curl_error'];
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) return ['error' => 'invalid_json', '_http_status' => $httpCode];
        if ($httpCode !== 200) $decoded['_http_status'] = $httpCode;
        return $decoded;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode($body ?: '', true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_response'];
    }
}
