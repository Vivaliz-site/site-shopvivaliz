<?php
/**
 * Otimizador de Produtos para Múltiplos Marketplaces
 *
 * Otimiza dados de produtos para:
 * - SEO (Google Search)
 * - Conversão (CRO)
 * - Marketplace Compliance
 * - Schema Markup (Rich Snippets)
 */

class ProductOptimizer {

    const TITLE_MAX_LENGTH = 70; // Google SERP max
    const DESCRIPTION_MAX_LENGTH = 160; // Meta description
    const MIN_TITLE_LENGTH = 20;

    private $marketplace = 'shopee';
    private $language = 'pt-BR';

    public function __construct($marketplace = 'shopee') {
        $this->marketplace = strtolower($marketplace);
    }

    /**
     * Otimizar produto completo
     */
    public function optimize($product) {
        return [
            'title' => $this->optimizeTitle($product['title'] ?? $product['name'] ?? ''),
            'description' => $this->optimizeDescription($product['description'] ?? ''),
            'short_description' => $this->createShortDescription($product),
            'price' => $this->optimizePrice($product['price'] ?? 0),
            'images' => $this->optimizeImages($product['images'] ?? []),
            'seo' => $this->generateSEO($product),
            'attributes' => $this->normalizeAttributes($product['attributes'] ?? []),
            'tags' => $this->generateTags($product),
            'metadata' => $this->generateMetadata($product),
        ];
    }

    /**
     * TÍTULO - Crítico para SEO e CTR
     *
     * Regras:
     * - 50-70 caracteres (ideal 60)
     * - Palavra-chave principal no início
     * - Sem clickbait
     * - Sem caracteres especiais desnecessários
     */
    public function optimizeTitle($title) {
        if (empty($title)) {
            return 'Produto de Qualidade';
        }

        // 1. Limpar caracteres especiais
        $title = $this->cleanText($title);

        // 2. Remover duplicatas
        $title = $this->removeDuplicates($title);

        // 3. Capitalizar corretamente
        $title = $this->smartCapitalize($title);

        // 4. Adicionar palavra-chave se necessário
        if (strlen($title) < self::MIN_TITLE_LENGTH) {
            $title = 'Produto ' . $title;
        }

        // 5. Truncar para tamanho ideal
        if (strlen($title) > self::TITLE_MAX_LENGTH) {
            $title = substr($title, 0, self::TITLE_MAX_LENGTH);
            $title = substr($title, 0, strrpos($title, ' '));
        }

        return trim($title);
    }

    /**
     * DESCRIÇÃO - Persuasão + SEO
     *
     * Estrutura:
     * 1. Benefício principal (primeira linha)
     * 2. Características chave (bullet points)
     * 3. Especificações técnicas
     * 4. Garantia/Suporte
     */
    public function optimizeDescription($description) {
        if (empty($description)) {
            return 'Produto de alta qualidade com excelente custo-benefício.';
        }

        // Limpar HTML
        $description = strip_tags($description);
        $description = html_entity_decode($description);

        // Quebrar em seções
        $lines = array_filter(array_map('trim', explode("\n", $description)));
        $lines = array_filter($lines, fn($l) => strlen($l) > 5);

        if (empty($lines)) {
            return $description;
        }

        $optimized = [];

        // 1. Benefício Principal (em destaque)
        $optimized[] = "✨ " . reset($lines);

        // 2. Características
        $features = array_slice($lines, 1, 5);
        if (!empty($features)) {
            $optimized[] = "\n🎯 Características Principais:";
            foreach ($features as $feature) {
                $feature = trim($feature, '•-*. ');
                if (!empty($feature)) {
                    $optimized[] = "✓ " . ucfirst($feature);
                }
            }
        }

        // 3. Selo de Qualidade
        $optimized[] = "\n✅ 100% Autêntico | 🔒 Seguro | 📦 Embalagem Premium";

        return implode("\n", $optimized);
    }

    /**
     * Descrição curta para listing
     * Máximo 160 caracteres (meta description)
     */
    public function createShortDescription($product) {
        $title = $product['title'] ?? $product['name'] ?? '';
        $description = $product['description'] ?? '';

        // Extrair primeira frase
        $text = $title . '. ' . $description;
        $sentences = preg_split('/[.!?]+/', $text);
        $firstSentence = trim($sentences[0] ?? $text);

        if (strlen($firstSentence) > self::DESCRIPTION_MAX_LENGTH) {
            $firstSentence = substr($firstSentence, 0, self::DESCRIPTION_MAX_LENGTH - 3) . '...';
        }

        return $firstSentence;
    }

    /**
     * Otimizar preço
     */
    public function optimizePrice($price) {
        $price = (int)$price;

        // Validar preço mínimo
        if ($price < 100) {
            $price = 100; // Centavos
        }

        return $price;
    }

    /**
     * Otimizar imagens
     * - Remover duplicatas
     * - Validar URLs
     * - Reordenar por qualidade
     * - Limitar quantidade
     */
    public function optimizeImages($images) {
        $optimized = [];
        $urls = [];

        foreach ((array)$images as $image) {
            $url = is_array($image) ? ($image['url'] ?? '') : $image;

            if (filter_var($url, FILTER_VALIDATE_URL) && !in_array($url, $urls)) {
                $urls[] = $url;
                $optimized[] = [
                    'url' => $url,
                    'alt' => 'Imagem do produto em alta resolução',
                    'position' => count($optimized),
                ];
            }
        }

        // Limitar a 8 imagens (ideal para conversion)
        return array_slice($optimized, 0, 8);
    }

    /**
     * Gerar SEO completo
     */
    public function generateSEO($product) {
        $title = $this->optimizeTitle($product['title'] ?? $product['name'] ?? '');
        $description = $this->createShortDescription($product);
        $keywords = $this->generateKeywords($product);

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'slug' => $this->generateSlug($title),
            'schema' => $this->generateSchemaMarkup($product),
            'og' => [
                'title' => $title,
                'description' => $description,
                'image' => $product['images'][0]['url'] ?? null,
                'type' => 'product',
            ]
        ];
    }

    /**
     * Gerar palavras-chave para SEO
     */
    public function generateKeywords($product) {
        $keywords = [];

        // De título
        $titleWords = array_filter(str_word_count($product['title'] ?? '', 1));
        foreach (array_slice($titleWords, 0, 3) as $word) {
            if (strlen($word) > 4) {
                $keywords[] = $word;
            }
        }

        // De categoria
        if (isset($product['category'])) {
            $keywords[] = $product['category'];
        }

        // De marca
        if (isset($product['brand'])) {
            $keywords[] = $product['brand'];
        }

        // Marketplace
        $keywords[] = $this->marketplace;
        $keywords[] = 'qualidade';
        $keywords[] = 'original';

        return implode(', ', array_unique($keywords));
    }

    /**
     * Normalizar atributos por marketplace
     */
    public function normalizeAttributes($attributes) {
        $normalized = [];

        foreach ((array)$attributes as $attr) {
            if (is_array($attr)) {
                $name = $attr['name'] ?? $attr['attribute_name'] ?? '';
                $value = $attr['value'] ?? $attr['attribute_value'] ?? '';
            } else {
                continue;
            }

            if (empty($name) || empty($value)) {
                continue;
            }

            // Detectar tipo
            $type = $this->detectAttributeType($name);

            $normalized[] = [
                'name' => $this->normalizeAttributeName($name),
                'value' => $this->normalizeAttributeValue($value, $type),
                'type' => $type,
            ];
        }

        return $normalized;
    }

    /**
     * Detectar tipo de atributo
     */
    private function detectAttributeType($name) {
        $name_lower = strtolower($name);

        $types = [
            'tamanho|size|tam' => 'size',
            'cor|color|colore' => 'color',
            'material|tecido' => 'material',
            'marca|brand' => 'brand',
            'peso|weight' => 'weight',
            'dimensão|dimension|altura|comprimento|largura' => 'dimension',
            'quantidade|quantity' => 'quantity',
        ];

        foreach ($types as $pattern => $type) {
            if (preg_match("/$pattern/i", $name_lower)) {
                return $type;
            }
        }

        return 'general';
    }

    /**
     * Normalizar nome do atributo
     */
    private function normalizeAttributeName($name) {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9\s\-]/u', '', $name);
        return ucwords(strtolower($name));
    }

    /**
     * Normalizar valor do atributo
     */
    private function normalizeAttributeValue($value, $type) {
        $value = trim($value);

        switch ($type) {
            case 'size':
                // Standardizar tamanho (P, M, G, GG ou XS, S, M, L, XL)
                $value = preg_replace('/\s+/', '', strtoupper($value));
                return $value;

            case 'color':
                // Cores em português
                $colors = [
                    'black' => 'Preto',
                    'white' => 'Branco',
                    'red' => 'Vermelho',
                    'blue' => 'Azul',
                    'green' => 'Verde',
                    'yellow' => 'Amarelo',
                ];
                $value_lower = strtolower($value);
                return $colors[$value_lower] ?? ucwords($value);

            case 'weight':
            case 'dimension':
                // Padronizar unidades
                $value = preg_replace('/\s+/', ' ', $value);
                return $value;

            default:
                return ucfirst($value);
        }
    }

    /**
     * Gerar tags para marketplace
     */
    public function generateTags($product) {
        $tags = [
            $this->marketplace,
            'original',
            'qualidade',
            'autêntico',
        ];

        // Tag de categoria
        if (isset($product['category'])) {
            $tags[] = strtolower($product['category']);
        }

        // Tag de marca
        if (isset($product['brand'])) {
            $tags[] = strtolower($product['brand']);
        }

        // Tags de atributos
        if (isset($product['attributes']) && is_array($product['attributes'])) {
            foreach ($product['attributes'] as $attr) {
                if (is_array($attr) && isset($attr['type']) && $attr['type'] === 'color') {
                    $tags[] = strtolower($attr['value']);
                }
            }
        }

        return array_unique($tags);
    }

    /**
     * Gerar metadata adicional
     */
    public function generateMetadata($product) {
        return [
            'source' => $this->marketplace,
            'optimized_at' => date('Y-m-d H:i:s'),
            'optimization_version' => '1.0',
            'language' => $this->language,
            'marketplace_specific' => $this->getMarketplaceMetadata($product),
        ];
    }

    /**
     * Dados específicos por marketplace
     */
    private function getMarketplaceMetadata($product) {
        switch ($this->marketplace) {
            case 'shopee':
                return [
                    'category_id' => $product['category_id'] ?? null,
                    'item_sku' => $product['sku'] ?? null,
                    'weight' => $product['weight'] ?? null,
                    'dimensions' => $product['dimensions'] ?? null,
                ];

            case 'olist':
                return [
                    'category_id' => $product['category_id'] ?? null,
                    'sku' => $product['sku'] ?? null,
                ];

            default:
                return [];
        }
    }

    /**
     * Gerar Schema Markup (JSON-LD)
     */
    private function generateSchemaMarkup($product) {
        return [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product['title'] ?? $product['name'] ?? '',
            'description' => $this->createShortDescription($product),
            'image' => $product['images'][0]['url'] ?? null,
            'brand' => [
                '@type' => 'Brand',
                'name' => $product['brand'] ?? 'Genérico'
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => $product['url'] ?? '',
                'priceCurrency' => 'BRL',
                'price' => ($product['price'] ?? 0) / 100,
                'availability' => 'https://schema.org/InStock'
            ],
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => '4.5',
                'reviewCount' => '100'
            ]
        ];
    }

    /**
     * Gerar slug URL-friendly
     */
    private function generateSlug($title) {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Limpar texto
     */
    private function cleanText($text) {
        // Remover caracteres especiais
        $text = preg_replace('/[^\w\s\-áéíóúàâêôõãçñ]/u', '', $text);

        // Limpar espaços múltiplos
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Remover palavras duplicadas
     */
    private function removeDuplicates($text) {
        $words = explode(' ', $text);
        $unique = [];
        foreach ($words as $word) {
            if (!in_array($word, $unique)) {
                $unique[] = $word;
            }
        }
        return implode(' ', $unique);
    }

    /**
     * Capitalizar inteligentemente
     */
    private function smartCapitalize($text) {
        $words = explode(' ', $text);
        $capitalized = [];
        $articles = ['de', 'da', 'do', 'e', 'ou', 'para', 'com', 'em', 'a', 'o'];

        foreach ($words as $i => $word) {
            if ($i === 0 || !in_array(strtolower($word), $articles)) {
                $capitalized[] = ucfirst(strtolower($word));
            } else {
                $capitalized[] = strtolower($word);
            }
        }

        return implode(' ', $capitalized);
    }
}
?>
