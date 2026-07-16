<?php
/**
 * Sincronização de Produtos - Olist/Tiny
 *
 * Integra produtos do Olist/Tiny com Medusa
 * Executa: A cada 6 horas (autônomo)
 *
 * Variáveis necessárias:
 * - OLIST_CLIENT_ID
 * - OLIST_CLIENT_SECRET
 * - TINY_TOKEN
 * - MEDUSA_BACKEND_URL
 */

set_time_limit(300); // 5 minutos max
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Logger
function log_sync($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/olist-sync.log';

    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }

    $line = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);

    echo $line;
}

class OlistSync {
    private $clientId;
    private $clientSecret;
    private $tinyToken;
    private $medusaUrl;

    public function __construct() {
        $this->clientId = getenv('OLIST_CLIENT_ID') ?: $_ENV['OLIST_CLIENT_ID'] ?? null;
        $this->clientSecret = getenv('OLIST_CLIENT_SECRET') ?: $_ENV['OLIST_CLIENT_SECRET'] ?? null;
        $this->tinyToken = getenv('TINY_TOKEN') ?: $_ENV['TINY_TOKEN'] ?? null;
        $this->medusaUrl = getenv('MEDUSA_BACKEND_URL') ?: $_ENV['MEDUSA_BACKEND_URL'] ?? 'http://localhost:9000';
    }

    public function sync() {
        log_sync('=== INICIANDO SINCRONIZAÇÃO OLIST ===', 'info');

        try {
            // 1. Obter produtos do Olist
            log_sync('Buscando produtos do Olist...', 'info');
            $olistProducts = $this->getOlistProducts();
            log_sync("Encontrados " . count($olistProducts) . " produtos", 'info');

            // 2. Processar cada produto
            $synced = 0;
            $errors = 0;

            foreach ($olistProducts as $product) {
                try {
                    $this->syncProduct($product);
                    $synced++;
                } catch (Exception $e) {
                    log_sync("Erro ao sincronizar produto {$product['id']}: " . $e->getMessage(), 'error');
                    $errors++;
                }
            }

            // 3. Relatório
            log_sync("RESULTADO: $synced sincronizados, $errors erros", 'info');
            log_sync('=== SINCRONIZAÇÃO CONCLUÍDA ===', 'info');

            return [
                'success' => true,
                'synced' => $synced,
                'errors' => $errors,
                'total' => count($olistProducts)
            ];

        } catch (Exception $e) {
            log_sync("ERRO CRÍTICO: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getOlistProducts() {
        // Implementar chamada para API Olist
        // ou Tiny se usar integrador

        // Por enquanto, retornar exemplo
        $url = 'https://api.olist.com/products/';

        $headers = [
            'Authorization: Bearer ' . $this->clientSecret,
            'Content-Type: application/json'
        ];

        try {
            $response = $this->makeRequest($url, 'GET', null, $headers);
            return json_decode($response, true)['products'] ?? [];
        } catch (Exception $e) {
            log_sync("Erro ao buscar produtos Olist: " . $e->getMessage(), 'error');
            return [];
        }
    }

    private function syncProduct($olistProduct) {
        // Mapear produto Olist para formato Medusa
        $medusaProduct = [
            'title' => $olistProduct['name'] ?? 'Sem título',
            'description' => $olistProduct['description'] ?? '',
            'price' => (int)($olistProduct['price'] * 100), // Converter para centavos
            'sku' => $olistProduct['sku'] ?? $olistProduct['id'],
            'images' => array_map(fn($img) => ['url' => $img['url']], $olistProduct['images'] ?? []),
            'categories' => [
                'name' => $olistProduct['category'] ?? 'Geral'
            ]
        ];

        // Enviar para Medusa
        $url = $this->medusaUrl . '/admin/products';

        $response = $this->makeRequest($url, 'POST', json_encode($medusaProduct));

        log_sync("Produto '{$medusaProduct['title']}' sincronizado", 'info');
    }

    private function makeRequest($url, $method = 'GET', $data = null, $headers = []) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("HTTP $httpCode: $response");
        }

        return $response;
    }
}

// Executar sincronização
$sync = new OlistSync();
$result = $sync->sync();

// Retornar resultado
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($result);
?>
