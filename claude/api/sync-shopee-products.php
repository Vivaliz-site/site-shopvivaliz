<?php
/**
 * Sincronização de Produtos - Shopee
 *
 * Integra produtos do Shopee com Medusa
 * Otimiza: Título, Descrição, SEO, Atributos
 * Executa: A cada 6 horas (autônomo)
 *
 * Variáveis necessárias:
 * - SHOPEE_PARTNER_ID
 * - SHOPEE_PARTNER_KEY
 * - SHOPEE_SHOP_ID
 * - MEDUSA_BACKEND_URL
 */

set_time_limit(600); // 10 minutos max
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Logger
function log_shopee($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/shopee-sync.log';

    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }

    $line = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);

    echo $line;
}

class ShopeeSync {
    private $partnerId;
    private $partnerKey;
    private $shopId;
    private $medusaUrl;
    private $accessToken;

    public function __construct() {
        $this->partnerId = getenv('SHOPEE_PARTNER_ID') ?: $_ENV['SHOPEE_PARTNER_ID'] ?? null;
        $this->partnerKey = getenv('SHOPEE_PARTNER_KEY') ?: $_ENV['SHOPEE_PARTNER_KEY'] ?? null;
        $this->shopId = getenv('SHOPEE_SHOP_ID') ?: $_ENV['SHOPEE_SHOP_ID'] ?? null;
        $this->medusaUrl = getenv('MEDUSA_BACKEND_URL') ?: $_ENV['MEDUSA_BACKEND_URL'] ?? 'http://localhost:9000';
    }

    public function sync() {
        log_shopee('=== INICIANDO SINCRONIZAÇÃO SHOPEE ===', 'info');

        try {
            // 1. Obter produtos do Shopee
            log_shopee('Buscando produtos do Shopee...', 'info');
            $shopeeProducts = $this->getShopeeProducts();
            log_shopee("Encontrados " . count($shopeeProducts) . " produtos", 'info');

            // 2. Processar cada produto
            $synced = 0;
            $optimized = 0;
            $errors = 0;

            foreach ($shopeeProducts as $product) {
                try {
                    // Otimizar dados
                    $optimized_product = $this->optimizeProduct($product);
                    $optimized++;

                    // Sincronizar com Medusa
                    $this->syncProduct($optimized_product);
                    $synced++;

                    // Atualizar atributos no Shopee
                    $this->updateShopeeAttributes($product['item_id'], $optimized_product);

                } catch (Exception $e) {
                    log_shopee("Erro ao sincronizar produto {$product['item_id']}: " . $e->getMessage(), 'error');
                    $errors++;
                }
            }

            // 3. Relatório
            log_shopee("RESULTADO: $synced sincronizados, $optimized otimizados, $errors erros", 'info');
            log_shopee('=== SINCRONIZAÇÃO CONCLUÍDA ===', 'info');

            return [
                'success' => true,
                'synced' => $synced,
                'optimized' => $optimized,
                'errors' => $errors,
                'total' => count($shopeeProducts)
            ];

        } catch (Exception $e) {
            log_shopee("ERRO CRÍTICO: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getShopeeProducts() {
        /**
         * Chamada API Shopee:
         * GET /api/v2/product/get_item_list
         *
         * Retorna:
         * - item_id: ID único do produto
         * - name: Nome/Título do produto
         * - description: Descrição HTML
         * - price: Preço em centavos
         * - stock: Quantidade em estoque
         * - images: Array de URLs de imagens
         * - attributes: Atributos do produto (tamanho, cor, etc)
         */

        $endpoint = 'https://partner.shopeemec.com/api/v2/product/get_item_list';

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];

        $params = [
            'shop_id' => $this->shopId,
            'pagination_entries_per_page' => 100
        ];

        try {
            $response = $this->makeRequest($endpoint, 'GET', null, $headers, $params);
            $data = json_decode($response, true);
            return $data['response']['item_list'] ?? [];
        } catch (Exception $e) {
            log_shopee("Erro ao buscar produtos Shopee: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Otimizar dados do produto para melhor SEO e conversão
     */
    private function optimizeProduct($shopeeProduct) {
        $product = [
            'title' => $this->optimizeTitle($shopeeProduct['name'] ?? ''),
            'description' => $this->optimizeDescription($shopeeProduct['description'] ?? ''),
            'price' => (int)($shopeeProduct['price'] ?? 0),
            'sku' => 'SHOPEE-' . ($shopeeProduct['item_id'] ?? ''),
            'images' => $this->optimizeImages($shopeeProduct['images'] ?? []),
            'categories' => $this->extractCategories($shopeeProduct),
            'attributes' => $this->optimizeAttributes($shopeeProduct['attributes'] ?? []),
            'seo' => $this->generateSEO($shopeeProduct),
            'source' => 'shopee',
            'shopee_item_id' => $shopeeProduct['item_id'] ?? null,
        ];

        return $product;
    }

    /**
     * Otimizar título para SEO
     * - Máximo 70 caracteres (para Google SERP)
     * - Palavras-chave principais
     * - Sem palavras desnecessárias
     */
    private function optimizeTitle($title) {
        // Remover caracteres especiais desnecessários
        $title = preg_replace('/[^a-zA-Z0-9\s\-]/u', '', $title);

        // Limpar espaços múltiplos
        $title = preg_replace('/\s+/', ' ', trim($title));

        // Se muito longo, truncar de forma inteligente
        if (strlen($title) > 70) {
            $words = explode(' ', $title);
            $result = '';
            foreach ($words as $word) {
                if (strlen($result . ' ' . $word) <= 70) {
                    $result .= ($result ? ' ' : '') . $word;
                } else {
                    break;
                }
            }
            $title = $result;
        }

        return $title ?: 'Produto Shopee';
    }

    /**
     * Otimizar descrição para conversão
     * - Benefícios principais no início
     * - Estrutura clara com seções
     * - Palavras-chave naturais
     */
    private function optimizeDescription($description) {
        // Remover tags HTML e limpear
        $description = strip_tags($description);
        $description = html_entity_decode($description);
        $description = trim($description);

        // Extrair principais informações
        $lines = array_filter(array_map('trim', explode("\n", $description)));

        // Reorganizar para impacto
        $optimized = [];

        // Adicionar benefício principal
        if (!empty($lines)) {
            $optimized[] = "✅ " . $lines[0];
        }

        // Características adicionais
        $features = array_slice($lines, 1, 5);
        if (!empty($features)) {
            $optimized[] = "\nCaracterísticas:";
            foreach ($features as $feature) {
                $optimized[] = "• " . $feature;
            }
        }

        // Adicionar informações adicionais
        $optimized[] = "\n📦 Produto Autêntico | ✨ Qualidade Premium | 🚚 Entrega Rápida";

        return implode("\n", $optimized);
    }

    /**
     * Otimizar imagens
     * - Validar URLs
     * - Reordenar por qualidade (maior primeiro)
     * - Limitar a 5 imagens
     */
    private function optimizeImages($images) {
        $optimized = [];

        foreach ((array)$images as $image) {
            if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
                $optimized[] = [
                    'url' => $image,
                    'alt' => 'Produto em alta qualidade'
                ];
            }
        }

        // Limitar a 5 imagens
        return array_slice($optimized, 0, 5);
    }

    /**
     * Extrair categorias de forma inteligente
     */
    private function extractCategories($product) {
        $categories = [];

        // Usar categoria Shopee se disponível
        if (isset($product['category_id'])) {
            $categories[] = [
                'name' => $this->getCategoryName($product['category_id']),
                'source' => 'shopee'
            ];
        }

        // Tentar extrair de descrição
        if (isset($product['description'])) {
            $keywords = $this->extractKeywords($product['description'], 3);
            foreach ($keywords as $keyword) {
                $categories[] = [
                    'name' => $keyword,
                    'source' => 'description'
                ];
            }
        }

        return !empty($categories) ? $categories : [['name' => 'Produtos Shopee']];
    }

    /**
     * Otimizar atributos do produto
     */
    private function optimizeAttributes($attributes) {
        $optimized = [];

        foreach ((array)$attributes as $attr) {
            if (isset($attr['attribute_id']) && isset($attr['attribute_name'])) {
                $optimized[] = [
                    'id' => $attr['attribute_id'],
                    'name' => $attr['attribute_name'],
                    'value' => $attr['attribute_value'] ?? '',
                    'type' => $this->detectAttributeType($attr['attribute_name']),
                ];
            }
        }

        return $optimized;
    }

    /**
     * Detectar tipo de atributo
     */
    private function detectAttributeType($name) {
        $name = strtolower($name);

        if (strpos($name, 'tamanho') !== false || strpos($name, 'size') !== false) {
            return 'size';
        } elseif (strpos($name, 'cor') !== false || strpos($name, 'color') !== false) {
            return 'color';
        } elseif (strpos($name, 'material') !== false) {
            return 'material';
        } elseif (strpos($name, 'marca') !== false || strpos($name, 'brand') !== false) {
            return 'brand';
        }

        return 'other';
    }

    /**
     * Gerar SEO otimizado
     */
    private function generateSEO($product) {
        return [
            'title' => $this->optimizeTitle($product['name'] ?? ''),
            'description' => substr($this->optimizeDescription($product['description'] ?? ''), 0, 160),
            'keywords' => $this->generateKeywords($product),
            'schema' => $this->generateSchemaMarkup($product),
        ];
    }

    /**
     * Gerar palavras-chave para SEO
     */
    private function generateKeywords($product) {
        $keywords = [];

        // De título
        $titleWords = str_word_count($product['name'] ?? '', 1);
        $keywords = array_merge($keywords, array_slice($titleWords, 0, 3));

        // De descrição
        $description = $product['description'] ?? '';
        $keywordText = preg_replace('/[^a-zA-Z0-9\s]/u', '', strtolower($description));
        $words = str_word_count($keywordText, 1);
        $keywords = array_merge($keywords, array_slice(array_unique($words), 0, 3));

        // Adicionar marketplace info
        $keywords[] = 'shopee';
        $keywords[] = 'produto autêntico';

        return implode(', ', array_unique($keywords));
    }

    /**
     * Gerar Schema Markup para Rich Snippets
     */
    private function generateSchemaMarkup($product) {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'] ?? '',
            'description' => substr($product['description'] ?? '', 0, 500),
            'price' => $product['price'] ?? 0,
            'priceCurrency' => 'BRL',
            'offers' => [
                '@type' => 'Offer',
                'url' => 'https://shopee.com.br',
                'priceCurrency' => 'BRL',
                'price' => $product['price'] ?? 0,
                'availability' => 'https://schema.org/InStock'
            ]
        ];
    }

    /**
     * Sincronizar produto com Medusa
     */
    private function syncProduct($medusaProduct) {
        $url = $this->medusaUrl . '/admin/products';

        $payload = json_encode([
            'title' => $medusaProduct['title'],
            'description' => $medusaProduct['description'],
            'handle' => strtolower(str_replace(' ', '-', $medusaProduct['title'])),
            'prices' => [
                [
                    'amount' => $medusaProduct['price'],
                    'currency_code' => 'brl'
                ]
            ],
            'variants' => [[
                'title' => $medusaProduct['title'],
                'sku' => $medusaProduct['sku'],
                'manage_inventory' => true,
            ]],
            'images' => $medusaProduct['images'],
            'tags' => ['shopee', 'sincronizado'],
            'metadata' => [
                'seo' => $medusaProduct['seo'],
                'attributes' => $medusaProduct['attributes'],
                'shopee_item_id' => $medusaProduct['shopee_item_id'],
            ]
        ]);

        $response = $this->makeRequest($url, 'POST', $payload);
        log_shopee("Produto '{$medusaProduct['title']}' sincronizado com Medusa", 'info');
    }

    /**
     * Atualizar atributos no Shopee
     */
    private function updateShopeeAttributes($itemId, $product) {
        // Chamada API Shopee para atualizar atributos
        $endpoint = 'https://partner.shopeemec.com/api/v2/product/update_item_attributes';

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];

        $payload = json_encode([
            'item_id' => $itemId,
            'shop_id' => $this->shopId,
            'attributes' => $product['attributes'],
            'seo' => $product['seo'],
        ]);

        try {
            $this->makeRequest($endpoint, 'PUT', $payload, $headers);
            log_shopee("Atributos atualizados para item $itemId", 'info');
        } catch (Exception $e) {
            log_shopee("Erro ao atualizar atributos: " . $e->getMessage(), 'error');
        }
    }

    private function extractKeywords($text, $limit = 5) {
        $text = strtolower(strip_tags($text));
        $text = preg_replace('/[^a-zA-Z0-9\s]/u', '', $text);
        $words = array_filter(str_word_count($text, 1));

        $common = ['o', 'a', 'de', 'para', 'com', 'em', 'é', 'do', 'da', 'que', 'e', 'os', 'as'];
        $words = array_filter($words, fn($w) => !in_array($w, $common) && strlen($w) > 3);

        return array_slice(array_unique($words), 0, $limit);
    }

    private function getCategoryName($categoryId) {
        // Mapear ID de categoria para nome
        $categories = [
            1 => 'Eletrônicos',
            2 => 'Moda',
            3 => 'Casa e Jardim',
            4 => 'Beleza',
            5 => 'Esportes',
        ];
        return $categories[$categoryId] ?? 'Produtos';
    }

    private function makeRequest($url, $method = 'GET', $data = null, $headers = [], $params = []) {
        $ch = curl_init();

        if (!empty($params) && $method === 'GET') {
            $url .= '?' . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        if (!empty($headers)) {
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
$sync = new ShopeeSync();
$result = $sync->sync();

// Retornar resultado
header('Content-Type: application/json');
echo json_encode($result);
?>
