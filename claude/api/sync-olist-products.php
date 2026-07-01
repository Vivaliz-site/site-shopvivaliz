<?php
/**
 * OlistSync - Ponte Olist/Tiny ERP -> Medusa (catálogo ShopVivaliz)
 *
 * Busca produtos no Olist (via API Tiny ERP, mesmo fluxo OAuth usado em
 * claude/api/olist/auto-sync.php) e faz upsert deles no backend MedusaJS
 * através da Admin API (/admin/products).
 *
 * Uso CLI:   php claude/api/sync-olist-products.php
 * Uso HTTP:  GET/POST claude/api/sync-olist-products.php
 *
 * Variáveis de ambiente necessárias:
 *   OLIST_CLIENT_ID, OLIST_CLIENT_SECRET  - credenciais OAuth do Tiny/Olist
 *   MEDUSA_BACKEND_URL                    - ex. http://localhost:9000
 *   MEDUSA_ADMIN_API_KEY                  - API key de admin do Medusa
 */

class OlistSync
{
    private const TOKEN_URL = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    private const PRODUCTS_API = 'https://api.tiny.com.br/public-api/v3/produtos';

    private string $clientId;
    private string $clientSecret;
    private string $medusaBackendUrl;
    private string $medusaAdminApiKey;
    private array $log = [];

    public function __construct(array $config = [])
    {
        $this->clientId = $config['olist_client_id'] ?? (getenv('OLIST_CLIENT_ID') ?: '');
        $this->clientSecret = $config['olist_client_secret'] ?? (getenv('OLIST_CLIENT_SECRET') ?: '');
        $this->medusaBackendUrl = rtrim($config['medusa_backend_url'] ?? (getenv('MEDUSA_BACKEND_URL') ?: 'http://localhost:9000'), '/');
        $this->medusaAdminApiKey = $config['medusa_admin_api_key'] ?? (getenv('MEDUSA_ADMIN_API_KEY') ?: '');
    }

    /** Executa a sincronização completa e retorna um relatório. */
    public function run(): array
    {
        $this->addLog('Iniciando OlistSync');

        if ($this->clientId === '' || $this->clientSecret === '') {
            return $this->fail('OLIST_CLIENT_ID / OLIST_CLIENT_SECRET não configurados. ' .
                'Sincronização real requer credenciais do app Olist/Tiny ERP (passo humano).');
        }

        if ($this->medusaAdminApiKey === '') {
            return $this->fail('MEDUSA_ADMIN_API_KEY não configurada. ' .
                'Gere uma API key de admin em Settings > API Key Management no Medusa Admin.');
        }

        try {
            $accessToken = $this->getAccessToken();
        } catch (\Throwable $e) {
            return $this->fail('Falha ao obter token Olist: ' . $e->getMessage());
        }

        try {
            $products = $this->fetchOlistProducts($accessToken);
        } catch (\Throwable $e) {
            return $this->fail('Falha ao buscar produtos no Olist: ' . $e->getMessage());
        }

        $synced = 0;
        $errors = [];
        foreach ($products as $product) {
            try {
                $this->upsertMedusaProduct($product);
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'sku' => $product['sku'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->addLog("Sincronização concluída: {$synced}/" . count($products) . ' produtos');

        return [
            'ok' => empty($errors),
            'total_olist' => count($products),
            'synced' => $synced,
            'errors' => $errors,
            'log' => $this->log,
        ];
    }

    /** Registra o webhook do Medusa que recebe eventos do Olist (recebedor local). */
    public function registerWebhook(string $webhookUrl): array
    {
        // A API pública do Tiny/Olist não oferece registro de webhook por
        // simples POST; o cadastro é feito manualmente no painel do app
        // (Configurações > Integrações > Webhooks). Aqui documentamos e
        // validamos a URL alvo para deixar o passo pronto para o humano.
        return [
            'ok' => true,
            'action' => 'manual_registration_required',
            'webhook_url' => $webhookUrl,
            'instructions' => 'Cadastre esta URL em https://app.tiny.com.br (Configurações > ' .
                'Integrações > Webhooks) para eventos de produto/estoque.',
        ];
    }

    private function getAccessToken(): string
    {
        $response = $this->httpPost(self::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if (!isset($response['access_token'])) {
            throw new \RuntimeException('Resposta sem access_token: ' . json_encode($response));
        }

        $this->addLog('Access token obtido com sucesso');
        return $response['access_token'];
    }

    private function fetchOlistProducts(string $accessToken): array
    {
        $response = $this->httpGet(self::PRODUCTS_API, [], $accessToken);
        $products = $response['itens'] ?? $response['data'] ?? [];
        $this->addLog('Produtos obtidos do Olist: ' . count($products));
        return $products;
    }

    private function upsertMedusaProduct(array $olistProduct): void
    {
        $sku = $olistProduct['sku'] ?? null;
        if (!$sku) {
            throw new \RuntimeException('Produto Olist sem SKU, ignorado');
        }

        $payload = [
            'title' => $olistProduct['descricao'] ?? $olistProduct['nome'] ?? $sku,
            'status' => 'published',
            'variants' => [[
                'title' => 'Único',
                'sku' => $sku,
                'prices' => [[
                    'amount' => (int) round((float) ($olistProduct['preco'] ?? 0) * 100),
                    'currency_code' => 'brl',
                ]],
            ]],
        ];

        $existing = $this->medusaRequest('GET', '/admin/products?q=' . urlencode($sku));
        $found = $existing['products'][0] ?? null;

        if ($found) {
            $this->medusaRequest('POST', '/admin/products/' . $found['id'], $payload);
        } else {
            $this->medusaRequest('POST', '/admin/products', $payload);
        }
    }

    private function medusaRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->medusaBackendUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->medusaAdminApiKey,
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Erro cURL Medusa ({$method} {$path}): {$err}");
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true) ?? [];
        if ($status >= 400) {
            throw new \RuntimeException("Medusa {$method} {$path} retornou HTTP {$status}: {$raw}");
        }
        return $decoded;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Erro cURL POST {$url}: {$err}");
        }
        curl_close($ch);
        return json_decode($raw, true) ?? [];
    }

    private function httpGet(string $url, array $params, string $bearerToken): array
    {
        $fullUrl = $url . (empty($params) ? '' : '?' . http_build_query($params));
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Erro cURL GET {$fullUrl}: {$err}");
        }
        curl_close($ch);
        return json_decode($raw, true) ?? [];
    }

    private function fail(string $message): array
    {
        $this->addLog('ERRO: ' . $message);
        return [
            'ok' => false,
            'error' => $message,
            'log' => $this->log,
        ];
    }

    private function addLog(string $message): void
    {
        $this->log[] = '[' . date('c') . '] ' . $message;
    }
}

// Executa quando chamado diretamente (CLI ou HTTP), não quando incluído
// por outro script (ex. testes).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }

    $sync = new OlistSync();
    $result = $sync->run();

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
