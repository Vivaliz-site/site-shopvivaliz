<?php
/**
 * Sincronizacao Olist ERP -> Catalogo MedusaJS
 *
 * Busca produtos na API da Olist (Tiny ERP) e faz upsert no catalogo do
 * backend Medusa via Admin REST API, permitindo que o headless commerce
 * (claude/medusa) reflita o estoque/preco cadastrados na Olist.
 *
 * Uso CLI:  php claude/api/sync-olist-products.php
 * Uso HTTP: GET/POST claude/api/sync-olist-products.php
 *
 * Variaveis de ambiente necessarias:
 *   OLIST_CLIENT_ID, OLIST_CLIENT_SECRET   - credenciais OAuth da Olist/Tiny
 *   MEDUSA_API_URL                         - ex. https://api.shopvivaliz.com.br
 *   MEDUSA_ADMIN_EMAIL, MEDUSA_ADMIN_PASSWORD - login do usuario admin Medusa
 *      (ou MEDUSA_ADMIN_API_TOKEN, se um token de longa duracao ja existir)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

class OlistSync
{
    private const TOKEN_URL = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    private const PRODUCTS_API = 'https://api.tiny.com.brapi/v2/produtos.json';
    private const LOG_FILE = __DIR__ . '/../../storage/logs/olist-medusa-sync.log';

    private string $olistClientId;
    private string $olistClientSecret;
    private string $medusaApiUrl;
    private string $medusaAdminEmail;
    private string $medusaAdminPassword;
    private string $medusaAdminToken;
    private $db;

    public function __construct()
    {
        $this->olistClientId = getenv('OLIST_CLIENT_ID') ?: '';
        $this->olistClientSecret = getenv('OLIST_CLIENT_SECRET') ?: '';
        $this->medusaApiUrl = rtrim(getenv('MEDUSA_API_URL') ?: 'http://localhost:9000', '/');
        $this->medusaAdminEmail = getenv('MEDUSA_ADMIN_EMAIL') ?: '';
        $this->medusaAdminPassword = getenv('MEDUSA_ADMIN_PASSWORD') ?: '';
        $this->medusaAdminToken = getenv('MEDUSA_ADMIN_API_TOKEN') ?: '';
        $this->db = Database::getInstance();
    }

    public function run(): array
    {
        $this->log('=== INICIO SYNC OLIST -> MEDUSA ===');

        if (!$this->olistClientId || !$this->olistClientSecret) {
            return $this->error('OLIST_CLIENT_ID/OLIST_CLIENT_SECRET nao configurados');
        }

        $olistToken = $this->getOlistAccessToken();
        if (!$olistToken) {
            return $this->error('Falha ao obter access token da Olist. Faca login em /olist/connect.php primeiro.');
        }

        $produtos = $this->fetchOlistProducts($olistToken);
        if ($produtos === null) {
            return $this->error('Falha ao buscar produtos na API da Olist');
        }

        if (!$this->medusaAdminToken) {
            $this->medusaAdminToken = $this->getMedusaAdminToken();
        }
        if (!$this->medusaAdminToken) {
            return $this->error('Falha ao autenticar no Medusa Admin API (verifique MEDUSA_ADMIN_EMAIL/PASSWORD ou MEDUSA_ADMIN_API_TOKEN)');
        }

        $resultado = $this->syncToMedusa($produtos);

        $this->log(sprintf(
            '=== FIM SYNC: %d produtos, %d criados, %d atualizados, %d erros ===',
            count($produtos),
            $resultado['criados'],
            $resultado['atualizados'],
            $resultado['erros']
        ));

        return [
            'sucesso' => true,
            'total_produtos_olist' => count($produtos),
            'criados' => $resultado['criados'],
            'atualizados' => $resultado['atualizados'],
            'erros' => $resultado['erros'],
            'detalhes_erros' => $resultado['detalhes_erros'],
            'timestamp' => date('c'),
        ];
    }

    private function getOlistAccessToken(): ?string
    {
        $refreshToken = $this->getSetting('olist_refresh_token');

        $params = $refreshToken
            ? [
                'grant_type' => 'refresh_token',
                'client_id' => $this->olistClientId,
                'client_secret' => $this->olistClientSecret,
                'refresh_token' => $refreshToken,
            ]
            : [
                'grant_type' => 'client_credentials',
                'client_id' => $this->olistClientId,
                'client_secret' => $this->olistClientSecret,
            ];

        $response = $this->httpPostForm(self::TOKEN_URL, $params);

        if (!$response || !isset($response->access_token)) {
            $this->log('Falha ao obter token Olist: ' . json_encode($response));
            return null;
        }

        if (isset($response->refresh_token)) {
            $this->setSetting('olist_refresh_token', $response->refresh_token);
        }

        return $response->access_token;
    }

    private function fetchOlistProducts(string $accessToken): ?array
    {
        $produtos = [];
        $pagina = 1;
        $limite = 50;

        while ($pagina <= 20) {
            $response = $this->httpGet(self::PRODUCTS_API, [
                'limite' => $limite,
                'pagina' => $pagina,
                'formato' => 'json',
            ], $accessToken);

            if (!$response || !isset($response->produtos)) {
                if ($pagina === 1) {
                    $this->log('Falha na primeira pagina de produtos: ' . json_encode($response));
                    return null;
                }
                break;
            }

            $lote = is_array($response->produtos) ? $response->produtos : [];
            $produtos = array_merge($produtos, $lote);

            if (count($lote) < $limite) {
                break;
            }

            $pagina++;
        }

        return $produtos;
    }

    private function getMedusaAdminToken(): ?string
    {
        if (!$this->medusaAdminEmail || !$this->medusaAdminPassword) {
            return null;
        }

        $response = $this->httpPostJson($this->medusaApiUrl . '/auth/user/emailpass', [
            'email' => $this->medusaAdminEmail,
            'password' => $this->medusaAdminPassword,
        ]);

        return $response->token ?? null;
    }

    private function syncToMedusa(array $produtos): array
    {
        $criados = 0;
        $atualizados = 0;
        $erros = 0;
        $detalhesErros = [];

        foreach ($produtos as $p) {
            $sku = $p->codigo ?? $p->sku ?? null;
            if (!$sku) {
                continue;
            }

            $payload = [
                'title' => $p->nome ?? "Produto {$sku}",
                'status' => 'published',
                'variants' => [[
                    'title' => 'Default',
                    'sku' => $sku,
                    'prices' => [[
                        // Medusa v2 armazena "amount" como valor decimal (ex. 69.9 = R$69,90),
                        // nao em centavos - nao multiplicar por 100 aqui.
                        'amount' => round(floatval($p->preco_venda ?? $p->preco ?? 0), 2),
                        'currency_code' => 'brl',
                    ]],
                    'manage_inventory' => true,
                ]],
                'metadata' => [
                    'olist_id' => $p->id ?? $p->idProduto ?? null,
                    'estoque_atual' => intval($p->estoque_atual ?? $p->estoque ?? 0),
                ],
            ];

            $existing = $this->httpGetMedusaAdmin('/admin/products', ['q' => $sku]);
            $productId = $existing->products[0]->id ?? null;

            try {
                if ($productId) {
                    $this->httpRequestMedusaAdmin('POST', "/admin/products/{$productId}", $payload);
                    $atualizados++;
                } else {
                    $this->httpRequestMedusaAdmin('POST', '/admin/products', $payload);
                    $criados++;
                }
            } catch (Exception $e) {
                $erros++;
                $detalhesErros[] = "SKU {$sku}: " . $e->getMessage();
                $this->log("Erro ao sincronizar SKU {$sku}: " . $e->getMessage());
            }
        }

        return [
            'criados' => $criados,
            'atualizados' => $atualizados,
            'erros' => $erros,
            'detalhes_erros' => $detalhesErros,
        ];
    }

    private function httpGetMedusaAdmin(string $path, array $query = []): object
    {
        $url = $this->medusaApiUrl . $path . '?' . http_build_query($query);
        $response = $this->httpRequestMedusaAdmin('GET', $path . '?' . http_build_query($query));
        return $response ?: (object) ['products' => []];
    }

    private function httpRequestMedusaAdmin(string $method, string $path, ?array $body = null)
    {
        $ch = curl_init($this->medusaApiUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->medusaAdminToken,
            ],
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro de rede: {$error}");
        }
        if ($status >= 400) {
            throw new Exception("Medusa Admin API retornou {$status}: {$response}");
        }

        return json_decode($response);
    }

    private function httpPostForm(string $url, array $data)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status === 200 ? json_decode($response) : null;
    }

    private function httpPostJson(string $url, array $data)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status === 200 ? json_decode($response) : (object) [];
    }

    private function httpGet(string $url, array $params, string $token)
    {
        $ch = curl_init($url . '?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status === 200 ? json_decode($response) : null;
    }

    private function getSetting(string $key): ?string
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0 ? $result->fetch_assoc()['value'] : null;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }

    private function error(string $message): array
    {
        $this->log('ERRO: ' . $message);
        return ['sucesso' => false, 'erro' => $message, 'timestamp' => date('c')];
    }

    private function log(string $message): void
    {
        @mkdir(dirname(self::LOG_FILE), 0755, true);
        @file_put_contents(self::LOG_FILE, '[' . date('Y-m-d H:i:s') . "] {$message}\n", FILE_APPEND);
    }
}

$sync = new OlistSync();
$resultado = $sync->run();

http_response_code($resultado['sucesso'] ? 200 : 400);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
