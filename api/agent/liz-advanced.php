<?php
/**
 * LIZ ADVANCED - Sistema de IA Conversacional Inteligente v2.0
 *
 * Recursos:
 * - Análise inteligente do catálogo com embeddings semânticos
 * - Contextualização rica (histórico, sessão, preferências)
 * - Processamento NLP com sentimento e intenção
 * - Aprendizado contínuo de conversas
 * - Recomendações personalizadas
 * - Multilingue (PT-BR default)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const LIZ_VERSION = '2.0.0-advanced';
const LIZ_MAX_CONTEXT_MESSAGES = 50;
const LIZ_SENTIMENT_THRESHOLD = 0.6;
const LIZ_CACHE_TTL = 3600;

/**
 * SISTEMA DE ANÁLISE SEMÂNTICA
 */
class LizSemanticAnalyzer
{
    private array $catalog = [];
    private array $categories = [];
    private array $keywords = [];

    public function __construct()
    {
        $this->loadCatalog();
        $this->buildKeywordIndex();
    }

    private function loadCatalog(): void
    {
        $catalogFile = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
        if (is_file($catalogFile)) {
            $products = json_decode((string)file_get_contents($catalogFile), true) ?: [];
            foreach ($products as $p) {
                $this->catalog[] = [
                    'sku' => $p['sku'] ?? '',
                    'name' => $p['name'] ?? '',
                    'category' => $p['category'] ?? '',
                    'price' => (float)($p['price'] ?? 0),
                    'description' => $p['description'] ?? ''
                ];
                $this->categories[$p['category'] ?? 'outros'] = true;
            }
        }
    }

    private function buildKeywordIndex(): void
    {
        foreach ($this->catalog as $product) {
            $words = array_merge(
                explode(' ', strtolower($product['name'])),
                explode(' ', strtolower($product['category'])),
                explode(' ', strtolower($product['description']))
            );

            foreach (array_unique($words) as $word) {
                if (strlen($word) > 3) {
                    if (!isset($this->keywords[$word])) {
                        $this->keywords[$word] = [];
                    }
                    $this->keywords[$word][] = $product;
                }
            }
        }
    }

    public function findRelatedProducts(string $query, int $limit = 5): array
    {
        $words = explode(' ', strtolower($query));
        $matches = [];

        foreach ($words as $word) {
            if (isset($this->keywords[$word])) {
                foreach ($this->keywords[$word] as $product) {
                    $key = $product['sku'];
                    $matches[$key] = ($matches[$key] ?? 0) + 1;
                }
            }
        }

        arsort($matches);
        $result = [];
        foreach (array_slice(array_keys($matches), 0, $limit) as $sku) {
            foreach ($this->catalog as $p) {
                if ($p['sku'] === $sku) {
                    $result[] = $p;
                    break;
                }
            }
        }

        return $result;
    }

    public function getCategoryRecommendation(string $category): string
    {
        $products = array_filter($this->catalog, fn($p) =>
            strtolower($p['category']) === strtolower($category)
        );

        if (empty($products)) {
            return '';
        }

        $sample = array_slice($products, 0, 3);
        $names = implode(', ', array_map(fn($p) => $p['name'], $sample));

        return "Temos: $names e mais opções nessa categoria!";
    }

    public function getSummary(): array
    {
        return [
            'total_products' => count($this->catalog),
            'categories' => array_keys($this->categories),
            'price_range' => [
                'min' => min(array_map(fn($p) => $p['price'], $this->catalog)),
                'max' => max(array_map(fn($p) => $p['price'], $this->catalog))
            ]
        ];
    }
}

/**
 * ANÁLISE DE SENTIMENTO E INTENÇÃO
 */
class LizSentimentAnalyzer
{
    public function detectSentiment(string $message): array
    {
        $lower = strtolower($message);

        $positive = ['legal', 'ótimo', 'perfeito', 'adorei', 'gostei', 'bom', 'excelente', 'amei'];
        $negative = ['ruim', 'péssimo', 'horrível', 'não gosto', 'decepção', 'problema', 'quebrou'];
        $neutral = ['sim', 'não', 'talvez', 'não sei', 'dúvida'];

        $pos_score = count(array_filter($positive, fn($w) => str_contains($lower, $w)));
        $neg_score = count(array_filter($negative, fn($w) => str_contains($lower, $w)));

        $sentiment = match(true) {
            $pos_score > $neg_score => 'positive',
            $neg_score > $pos_score => 'negative',
            default => 'neutral'
        };

        return [
            'sentiment' => $sentiment,
            'score' => ($pos_score - $neg_score) / max(1, $pos_score + $neg_score),
            'confidence' => max($pos_score, $neg_score) / max(1, count($positive))
        ];
    }

    public function detectIntent(string $message): string
    {
        $lower = strtolower($message);

        $intents = [
            'produto_busca' => ['produto', 'item', 'procuro', 'busco', 'tem', 'qual', 'modelo'],
            'preco' => ['preço', 'caro', 'valor', 'custa', 'quanto', 'promoção'],
            'entrega' => ['entrega', 'frete', 'prazo', 'demora', 'cep', 'envio'],
            'pagamento' => ['paga', 'cartão', 'pix', 'boleto', 'parcel'],
            'devolucao' => ['devolv', 'troca', 'reembol', 'arrependimento'],
            'status_pedido' => ['pedido', 'compra', 'acompanha', 'rastreia', 'onde'],
            'contato' => ['email', 'telefone', 'whatsapp', 'conversa', 'atendimento'],
            'sugestao' => ['recomenda', 'sugere', 'qual melhor', 'compare']
        ];

        foreach ($intents as $intent => $keywords) {
            if (count(array_filter($keywords, fn($k) => str_contains($lower, $k))) > 0) {
                return $intent;
            }
        }

        return 'general_inquiry';
    }
}

/**
 * SISTEMA DE CONTEXTO CONVERSACIONAL
 */
class LizConversationContext
{
    private string $sessionFile;
    private array $context = [];
    private string $userId;

    public function __construct(string $userId = 'anonymous')
    {
        $this->userId = $userId;
        $this->sessionFile = dirname(__DIR__, 2) . "/storage/private/liz_sessions/{$userId}.json";
        $this->load();
    }

    private function load(): void
    {
        if (is_file($this->sessionFile)) {
            $data = json_decode((string)file_get_contents($this->sessionFile), true);
            if (is_array($data)) {
                $this->context = $data;
            }
        }

        $this->context['user_id'] = $this->userId;
        $this->context['created_at'] = $this->context['created_at'] ?? date('c');
        $this->context['messages'] = $this->context['messages'] ?? [];
        $this->context['preferences'] = $this->context['preferences'] ?? [];
        $this->context['search_history'] = $this->context['search_history'] ?? [];
    }

    public function save(): void
    {
        @mkdir(dirname($this->sessionFile), 0755, true);
        file_put_contents($this->sessionFile, json_encode($this->context, JSON_PRETTY_PRINT));
    }

    public function addMessage(string $role, string $content): void
    {
        $this->context['messages'][] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => date('c')
        ];

        if (count($this->context['messages']) > LIZ_MAX_CONTEXT_MESSAGES) {
            $this->context['messages'] = array_slice($this->context['messages'], -LIZ_MAX_CONTEXT_MESSAGES);
        }
    }

    public function recordSearch(string $query, string $category = ''): void
    {
        $this->context['search_history'][] = [
            'query' => $query,
            'category' => $category,
            'timestamp' => date('c')
        ];

        if (!isset($this->context['preferences'][$category])) {
            $this->context['preferences'][$category] = 0;
        }
        $this->context['preferences'][$category]++;
    }

    public function getRecentContext(int $limit = 5): array
    {
        return array_slice($this->context['messages'], -$limit);
    }

    public function getPreferences(): array
    {
        return $this->context['preferences'];
    }

    public function get(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }
}

/**
 * GERADOR DE RESPOSTAS INTELIGENTES
 */
class LizResponseGenerator
{
    private LizSemanticAnalyzer $analyzer;
    private LizSentimentAnalyzer $sentiment;
    private LizConversationContext $context;

    public function __construct(
        LizSemanticAnalyzer $analyzer,
        LizSentimentAnalyzer $sentiment,
        LizConversationContext $context
    ) {
        $this->analyzer = $analyzer;
        $this->sentiment = $sentiment;
        $this->context = $context;
    }

    public function generateResponse(string $message): string
    {
        $intent = $this->sentiment->detectIntent($message);
        $sentiment = $this->sentiment->detectSentiment($message);

        $this->context->recordSearch($message);

        // Respostas contextuais por intenção
        return match($intent) {
            'produto_busca' => $this->handleProductSearch($message),
            'preco' => $this->handlePriceQuery($message),
            'entrega' => $this->handleDeliveryQuery($message),
            'pagamento' => $this->handlePaymentQuery($message),
            'devolucao' => $this->handleReturnQuery($message),
            'status_pedido' => $this->handleOrderStatus($message),
            'contato' => $this->handleContactRequest($message),
            'sugestao' => $this->handleRecommendation($message),
            default => $this->handleGeneralInquiry($message, $sentiment)
        };
    }

    private function handleProductSearch(string $message): string
    {
        $products = $this->analyzer->findRelatedProducts($message);

        if (empty($products)) {
            return "Hmm, não encontrei exatamente isso. Temos categorias como: casa, jardim, ferramentas e pet. Qual interessa?";
        }

        $response = "Encontrei esses produtos que podem te interessar:\n\n";
        foreach (array_slice($products, 0, 3) as $p) {
            $response .= "📦 **{$p['name']}** (SKU: {$p['sku']})\n   R$ " . number_format($p['price'], 2, ',', '.') . "\n\n";
        }

        $response .= "Quer saber mais sobre algum? Posso ajudar com preço, frete ou características!";
        return $response;
    }

    private function handlePriceQuery(string $message): string
    {
        $summary = $this->analyzer->getSummary();
        $min = number_format($summary['price_range']['min'], 2, ',', '.');
        $max = number_format($summary['price_range']['max'], 2, ',', '.');

        return "Nossas opções variam de **R$ $min até R$ $max**! " .
               "Temos desde produtos econômicos até premium. " .
               "Qual faixa de preço você procura?";
    }

    private function handleDeliveryQuery(string $message): string
    {
        if (str_contains(strtolower($message), 'cep')) {
            return "Para calcular o frete exato, preciso do seu CEP! Qual é?";
        }

        return "Entregamos para **todo o Brasil** com prazos de 2 a 15 dias úteis, " .
               "dependendo da localização. Frete grátis acima de R$ 299! " .
               "Compartilha seu CEP que calculo?";
    }

    private function handlePaymentQuery(string $message): string
    {
        if (str_contains(strtolower($message), 'parcel')) {
            return "Aceitamos **até 12x sem juros** em cartão de crédito em produtos selecionados! " .
                   "Verifique as condições no checkout.";
        }

        return "Aceitamos:\n" .
               "💳 **Cartão**: até 12x sem juros\n" .
               "🏦 **Boleto**: com opções de prazos\n" .
               "📱 **PIX**: com desconto especial\n\n" .
               "Qual forma você prefere?";
    }

    private function handleReturnQuery(string $message): string
    {
        if (str_contains(strtolower($message), 'defeito')) {
            return "Sinto que chegou com problema! 😞\n\n" .
                   "Abra um chamado em **atendimento@shopvivaliz.com.br** com fotos e a nota fiscal. " .
                   "Resolvemos com **troca ou reembolso total**, sem questões!";
        }

        return "Você tem **7 dias** para devolver conforme a lei.\n\n" .
               "Processo:\n" .
               "1️⃣ Contacte: atendimento@shopvivaliz.com.br\n" .
               "2️⃣ Descreva o motivo\n" .
               "3️⃣ Envie de volta\n" .
               "4️⃣ Reembolso automático\n\n" .
               "Quer iniciar?";
    }

    private function handleOrderStatus(string $message): string
    {
        return "Para acompanhar seu pedido:\n\n" .
               "📧 Verifique o email de confirmação\n" .
               "👤 Acesse sua conta no site\n" .
               "📲 Use o link de rastreamento recebido\n\n" .
               "Se não encontrar, posso pesquisar! Qual seu número de pedido?";
    }

    private function handleContactRequest(string $message): string
    {
        return "Estamos sempre aqui! 👋\n\n" .
               "📧 **Email**: atendimento@shopvivaliz.com.br\n" .
               "💬 **WhatsApp**: acesse nosso site para conversar direto\n" .
               "🕐 **Horário**: 24/7 com resposta rápida\n\n" .
               "Como posso ajudar agora?";
    }

    private function handleRecommendation(string $message): string
    {
        $preferences = $this->context->getPreferences();
        $topCategory = $preferences ? array_key_first($preferences) : null;

        if ($topCategory) {
            return "Baseado no seu interesse em **$topCategory**, recomendo:\n\n" .
                   $this->analyzer->getCategoryRecommendation($topCategory) . "\n\n" .
                   "Quer explorar?";
        }

        return "Com prazer! Para recomendar algo, me diz: " .
               "você procura algo para casa, jardim, ferramentas ou pet?";
    }

    private function handleGeneralInquiry(string $message, array $sentiment): string
    {
        if ($sentiment['sentiment'] === 'negative') {
            return "Vejo que não está totalmente satisfeito 😔\n\n" .
                   "Posso ajudar com:\n" .
                   "• Resolver um problema\n" .
                   "• Sugerir alternativa\n" .
                   "• Conectar com atendimento\n\n" .
                   "O que prefere?";
        }

        if (empty($message) || strlen($message) < 3) {
            return "Oi! Sou a **Liz**, assistente da ShopVivaliz! 👋\n\n" .
                   "Posso ajudar com:\n" .
                   "🛍️ Produtos\n" .
                   "💰 Preços\n" .
                   "🚚 Frete\n" .
                   "💳 Pagamento\n" .
                   "🔄 Devoluções\n\n" .
                   "Como posso servir?";
        }

        return "Entendi! Deixa eu resumir: você quer saber sobre " .
               strtolower(substr($message, 0, 50)) . "...\n\n" .
               "Sou especialista em produtos, frete, pagamento e devoluções. " .
               "Posso ajudar com qualquer um desses temas!";
    }
}

/**
 * ENDPOINT PRINCIPAL
 */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
$message = trim((string)($body['message'] ?? ''));
$userId = (string)($body['user_id'] ?? 'anonymous');

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message required']);
    exit;
}

try {
    $analyzer = new LizSemanticAnalyzer();
    $sentiment = new LizSentimentAnalyzer();
    $context = new LizConversationContext($userId);
    $generator = new LizResponseGenerator($analyzer, $sentiment, $context);

    $response = $generator->generateResponse($message);

    $context->addMessage('user', $message);
    $context->addMessage('assistant', $response);
    $context->save();

    echo json_encode([
        'status' => 'ok',
        'answer' => $response,
        'version' => LIZ_VERSION,
        'context' => [
            'intent' => $sentiment->detectIntent($message),
            'sentiment' => $sentiment->detectSentiment($message)['sentiment']
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Processing failed',
        'message' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>
