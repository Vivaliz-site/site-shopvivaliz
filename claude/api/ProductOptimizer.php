<?php
/**
 * Otimizador inteligente e factual de cadastro para marketplaces.
 *
 * PRINCIPIOS:
 * - preço e estoque são campos protegidos: nunca são alterados;
 * - SEO é derivado apenas de dados reais do produto;
 * - nenhuma avaliação, benefício, garantia, autenticidade ou especificação é inventada;
 * - título/descrição respeitam o canal sem destruir identificadores importantes;
 * - imagens são deduplicadas sem trocar a identidade do produto.
 */
class ProductOptimizer
{
    private const DEFAULT_TITLE_MAX_LENGTH = 120;
    private const META_DESCRIPTION_MAX_LENGTH = 160;

    /** @var array<string,int> */
    private const MARKETPLACE_TITLE_LIMITS = [
        'google' => 70,
        'google_shopping' => 150,
        'olist' => 120,
        'tiny' => 120,
        'shopee' => 120,
        'mercadolivre' => 60,
        'mercado_livre' => 60,
        'amazon' => 150,
    ];

    private string $marketplace = 'shopee';
    private string $language = 'pt-BR';

    public function __construct($marketplace = 'shopee')
    {
        $marketplace = strtolower(trim((string) $marketplace));
        $this->marketplace = $marketplace !== '' ? $marketplace : 'shopee';
    }

    /**
     * Otimiza somente conteúdo editorial/SEO.
     * Preço e estoque são devolvidos exatamente como recebidos.
     */
    public function optimize($product)
    {
        $product = is_array($product) ? $product : [];

        $result = [
            'title' => $this->optimizeTitle($product['title'] ?? $product['name'] ?? ''),
            'description' => $this->optimizeDescription($product['description'] ?? ''),
            'short_description' => $this->createShortDescription($product),
            'price' => $this->optimizePrice($product['price'] ?? null),
            'images' => $this->optimizeImages($product['images'] ?? []),
            'seo' => $this->generateSEO($product),
            'attributes' => $this->normalizeAttributes($product['attributes'] ?? []),
            'tags' => $this->generateTags($product),
            'metadata' => $this->generateMetadata($product),
        ];

        // Campo protegido. Se veio da origem, sai idêntico.
        if (array_key_exists('stock', $product)) {
            $result['stock'] = $product['stock'];
        }
        if (array_key_exists('stock_quantity', $product)) {
            $result['stock_quantity'] = $product['stock_quantity'];
        }
        if (array_key_exists('estoque', $product)) {
            $result['estoque'] = $product['estoque'];
        }
        if (array_key_exists('estoque_atual', $product)) {
            $result['estoque_atual'] = $product['estoque_atual'];
        }

        return $result;
    }

    /**
     * Título marketplace: limpa ruído, preserva fatos e limita pelo canal.
     */
    public function optimizeTitle($title)
    {
        $title = $this->normalizeWhitespace($this->cleanText((string) $title));
        if ($title === '') {
            return '';
        }

        $title = $this->removeConsecutiveDuplicates($title);
        $title = $this->smartCapitalize($title);

        return $this->truncateAtWord($title, $this->getTitleLimit());
    }

    /**
     * Descrição factual: remove HTML/ruído e organiza o conteúdo existente.
     * Não adiciona claims promocionais inexistentes.
     */
    public function optimizeDescription($description)
    {
        $description = html_entity_decode(strip_tags((string) $description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = str_replace(["\r\n", "\r"], "\n", $description);

        $lines = array_values(array_filter(array_map(function ($line) {
            $line = $this->normalizeWhitespace(trim((string) $line, " \t\n\r\0\x0B•*-"));
            return $line;
        }, explode("\n", $description)), fn($line) => $line !== ''));

        if ($lines === []) {
            return '';
        }

        // Remove apenas linhas exatamente duplicadas, preservando ordem.
        $seen = [];
        $unique = [];
        foreach ($lines as $line) {
            $key = $this->lower($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $line;
        }

        return implode("\n", $unique);
    }

    public function createShortDescription($product)
    {
        $product = is_array($product) ? $product : [];
        $description = $this->optimizeDescription($product['description'] ?? '');
        $title = $this->optimizeTitle($product['title'] ?? $product['name'] ?? '');

        $source = $description !== '' ? $description : $title;
        $source = $this->normalizeWhitespace(str_replace("\n", ' ', $source));

        return $this->truncateAtWord($source, self::META_DESCRIPTION_MAX_LENGTH);
    }

    /**
     * CAMPO PROTEGIDO: preço é retornado exatamente como recebido.
     */
    public function optimizePrice($price)
    {
        return $price;
    }

    /**
     * Deduplica imagens por URL normalizada sem inventar/reassociar imagens.
     * Preserva metadados recebidos e limita somente a quantidade exibida.
     */
    public function optimizeImages($images)
    {
        $optimized = [];
        $seen = [];

        foreach ((array) $images as $image) {
            $item = is_array($image) ? $image : ['url' => $image];
            $url = trim((string) ($item['url'] ?? $item['image_url'] ?? ''));
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $identity = $this->normalizeImageIdentity($url);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $item['url'] = $url;
            $item['position'] = count($optimized);
            if (!isset($item['alt']) || trim((string) $item['alt']) === '') {
                $item['alt'] = 'Imagem do produto';
            }
            $optimized[] = $item;
        }

        return array_slice($optimized, 0, 10);
    }

    public function generateSEO($product)
    {
        $product = is_array($product) ? $product : [];
        $title = $this->optimizeTitle($product['title'] ?? $product['name'] ?? '');
        $description = $this->createShortDescription($product);

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $this->generateKeywords($product),
            'slug' => $this->generateSlug($title),
            'schema' => $this->generateSchemaMarkup($product),
            'og' => [
                'title' => $title,
                'description' => $description,
                'image' => $this->firstImageUrl($product['images'] ?? []),
                'type' => 'product',
            ],
        ];
    }

    /**
     * Keywords factuais: título + marca + categoria + atributos existentes.
     */
    public function generateKeywords($product)
    {
        $product = is_array($product) ? $product : [];
        $keywords = [];

        $title = $this->optimizeTitle($product['title'] ?? $product['name'] ?? '');
        foreach (preg_split('/\s+/u', $this->lower($title)) ?: [] as $word) {
            $word = trim($word, " ,.;:/|()[]{}\"");
            if ($this->strlen($word) >= 4 && !$this->isStopWord($word)) {
                $keywords[] = $word;
            }
        }

        foreach (['brand', 'marca', 'category', 'categoria', 'model', 'modelo', 'sku', 'gtin'] as $key) {
            if (isset($product[$key]) && !is_array($product[$key])) {
                $value = $this->normalizeWhitespace((string) $product[$key]);
                if ($value !== '') {
                    $keywords[] = $value;
                }
            }
        }

        foreach ((array) ($product['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $value = $attr['value'] ?? $attr['attribute_value'] ?? null;
            if (is_scalar($value)) {
                $value = $this->normalizeWhitespace((string) $value);
                if ($value !== '') {
                    $keywords[] = $value;
                }
            }
        }

        $keywords = array_values(array_unique(array_filter($keywords, fn($v) => trim((string) $v) !== '')));
        return implode(', ', array_slice($keywords, 0, 20));
    }

    public function normalizeAttributes($attributes)
    {
        $normalized = [];
        foreach ((array) $attributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $name = trim((string) ($attr['name'] ?? $attr['attribute_name'] ?? ''));
            $value = $attr['value'] ?? $attr['attribute_value'] ?? null;
            if ($name === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $value = $this->normalizeWhitespace((string) $value);
            if ($value === '') {
                continue;
            }

            $type = $this->detectAttributeType($name);
            $normalized[] = [
                'name' => $this->normalizeAttributeName($name),
                'value' => $this->normalizeAttributeValue($value, $type),
                'type' => $type,
            ];
        }

        return $normalized;
    }

    public function generateTags($product)
    {
        $product = is_array($product) ? $product : [];
        $tags = [];

        foreach (['category', 'categoria', 'brand', 'marca', 'model', 'modelo'] as $key) {
            if (isset($product[$key]) && is_scalar($product[$key])) {
                $value = $this->lower($this->normalizeWhitespace((string) $product[$key]));
                if ($value !== '') {
                    $tags[] = $value;
                }
            }
        }

        foreach (explode(', ', $this->generateKeywords($product)) as $keyword) {
            if ($keyword !== '') {
                $tags[] = $this->lower($keyword);
            }
        }

        return array_values(array_slice(array_unique($tags), 0, 20));
    }

    public function generateMetadata($product)
    {
        $product = is_array($product) ? $product : [];
        return [
            'source' => $this->marketplace,
            'optimized_at' => date('Y-m-d H:i:s'),
            'optimization_version' => '2.0-safe-marketplace-seo',
            'language' => $this->language,
            'protected_fields' => ['price', 'stock', 'stock_quantity', 'estoque', 'estoque_atual'],
            'marketplace_specific' => $this->getMarketplaceMetadata($product),
        ];
    }

    private function getMarketplaceMetadata($product)
    {
        $metadata = [];
        foreach (['category_id', 'sku', 'gtin', 'brand', 'marca', 'model', 'modelo', 'weight', 'dimensions'] as $key) {
            if (array_key_exists($key, $product)) {
                $metadata[$key] = $product[$key];
            }
        }
        return $metadata;
    }

    /**
     * Schema.org somente com fatos presentes na origem.
     */
    private function generateSchemaMarkup($product)
    {
        $schema = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $this->optimizeTitle($product['title'] ?? $product['name'] ?? ''),
        ];

        $description = $this->createShortDescription($product);
        if ($description !== '') {
            $schema['description'] = $description;
        }

        $image = $this->firstImageUrl($product['images'] ?? []);
        if ($image !== null) {
            $schema['image'] = $image;
        }

        $brand = $product['brand'] ?? $product['marca'] ?? null;
        if (is_scalar($brand) && trim((string) $brand) !== '') {
            $schema['brand'] = ['@type' => 'Brand', 'name' => trim((string) $brand)];
        }

        $sku = $product['sku'] ?? null;
        if (is_scalar($sku) && trim((string) $sku) !== '') {
            $schema['sku'] = trim((string) $sku);
        }

        $gtin = $product['gtin'] ?? null;
        if (is_scalar($gtin) && trim((string) $gtin) !== '') {
            $schema['gtin13'] = trim((string) $gtin);
        }

        $price = $product['price'] ?? null;
        if (is_numeric($price) && (float) $price > 0) {
            $offer = [
                '@type' => 'Offer',
                'priceCurrency' => (string) ($product['price_currency'] ?? 'BRL'),
                'price' => $price,
            ];
            if (!empty($product['url']) && filter_var($product['url'], FILTER_VALIDATE_URL)) {
                $offer['url'] = $product['url'];
            }

            $stock = $this->extractStock($product);
            if ($stock !== null) {
                $offer['availability'] = $stock > 0
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock';
            }
            $schema['offers'] = $offer;
        }

        // Avaliação só entra se existir na origem; nunca é fabricada.
        $ratingValue = $product['rating_value'] ?? null;
        $reviewCount = $product['review_count'] ?? null;
        if (is_numeric($ratingValue) && is_numeric($reviewCount) && (int) $reviewCount > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ratingValue,
                'reviewCount' => (int) $reviewCount,
            ];
        }

        return $schema;
    }

    private function extractStock(array $product): ?float
    {
        foreach (['stock', 'stock_quantity', 'estoque', 'estoque_atual'] as $key) {
            if (array_key_exists($key, $product) && is_numeric($product[$key])) {
                return (float) $product[$key];
            }
        }
        return null;
    }

    private function firstImageUrl($images): ?string
    {
        $optimized = $this->optimizeImages($images);
        return isset($optimized[0]['url']) ? (string) $optimized[0]['url'] : null;
    }

    private function normalizeImageIdentity(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = preg_replace('#/(?:150x|300x|600x)/#', '/', (string) ($parts['path'] ?? ''));
        return $scheme . '://' . $host . $path;
    }

    private function getTitleLimit(): int
    {
        return self::MARKETPLACE_TITLE_LIMITS[$this->marketplace] ?? self::DEFAULT_TITLE_MAX_LENGTH;
    }

    private function detectAttributeType($name)
    {
        $name = $this->lower((string) $name);
        $types = [
            'size' => '/tamanho|size|tam\b/u',
            'color' => '/\bcor\b|color/u',
            'material' => '/material|tecido/u',
            'brand' => '/marca|brand/u',
            'weight' => '/peso|weight/u',
            'dimension' => '/dimens|altura|comprimento|largura/u',
            'quantity' => '/quantidade|quantity|qtd/u',
            'voltage' => '/voltagem|tensao|tensão|volt/u',
            'model' => '/modelo|model/u',
        ];
        foreach ($types as $type => $pattern) {
            if (preg_match($pattern, $name)) {
                return $type;
            }
        }
        return 'general';
    }

    private function normalizeAttributeName($name)
    {
        return $this->smartCapitalize($this->normalizeWhitespace($this->cleanText((string) $name)));
    }

    private function normalizeAttributeValue($value, $type)
    {
        $value = $this->normalizeWhitespace((string) $value);
        if ($type === 'size' || $type === 'voltage') {
            return strtoupper($value);
        }
        return $value;
    }

    private function generateSlug($title)
    {
        $title = trim((string) $title);
        if ($title === '') {
            return '';
        }
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug = strtolower($transliterated !== false ? $transliterated : $title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim((string) $slug, '-');
    }

    private function cleanText($text)
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $text);
        $text = preg_replace('/[<>]/u', ' ', (string) $text);
        return (string) $text;
    }

    private function removeConsecutiveDuplicates(string $text): string
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $result = [];
        $previous = null;
        foreach ($words as $word) {
            $key = $this->lower(trim($word, " ,.;:/|()[]{}"));
            if ($key !== '' && $key === $previous) {
                continue;
            }
            $result[] = $word;
            $previous = $key;
        }
        return implode(' ', $result);
    }

    private function smartCapitalize($text)
    {
        $text = $this->normalizeWhitespace((string) $text);
        if ($text === '') {
            return '';
        }
        $articles = ['de', 'da', 'do', 'das', 'dos', 'e', 'ou', 'para', 'com', 'em', 'a', 'o'];
        $words = preg_split('/\s+/u', $text) ?: [];
        foreach ($words as $i => &$word) {
            if (preg_match('/[0-9]/', $word) || preg_match('/^[A-Z0-9-]{2,}$/', $word)) {
                continue;
            }
            $lower = $this->lower($word);
            $word = ($i > 0 && in_array($lower, $articles, true))
                ? $lower
                : $this->upperFirst($lower);
        }
        unset($word);
        return implode(' ', $words);
    }

    private function truncateAtWord(string $text, int $maxLength): string
    {
        if ($this->strlen($text) <= $maxLength) {
            return $text;
        }
        $cut = $this->substr($text, 0, $maxLength + 1);
        $spacePos = strrpos($cut, ' ');
        if ($spacePos !== false && $spacePos >= (int) ($maxLength * 0.65)) {
            $cut = substr($cut, 0, $spacePos);
        } else {
            $cut = $this->substr($cut, 0, $maxLength);
        }
        return rtrim($cut, " -_,.;:/|");
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function isStopWord(string $word): bool
    {
        static $stopWords = ['para', 'com', 'sem', 'de', 'da', 'do', 'das', 'dos', 'uma', 'uns', 'nas', 'nos', 'por', 'que', 'the'];
        return in_array($word, $stopWords, true);
    }

    private function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    private function upperFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        return ucfirst($text);
    }

    private function strlen(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function substr(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}
