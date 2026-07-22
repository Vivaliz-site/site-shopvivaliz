<?php
/**
 * LIZ INTELLIGENCE - IA Conversacional Real com Google Gemini API
 *
 * Usa integração real com Google Gemini para respostas inteligentes.
 * ✅ Contexto do catálogo
 * ✅ Histórico de conversas persistente
 * ✅ Análise de intenção automática
 * ✅ Respostas personalizadas
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

const LIZ_DB_PATH = __DIR__ . '/../../storage/private/liz_intelligence.db';

class LizIntelligence
{
    private ?\PDO $db = null;
    private array $catalog = [];
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = getenv('GOOGLE_GEMINI_API_KEY') ?: getenv('GEMINI_API_KEY') ?: '';
        $this->model = getenv('LIZ_GEMINI_MODEL') ?: getenv('GOOGLE_GEMINI_MODEL') ?: 'gemini-1.5-flash';
        $this->initDatabase();
        $this->loadCatalog();
    }

    private function initDatabase(): void
    {
        @mkdir(dirname(LIZ_DB_PATH), 0755, true);

        try {
            $this->db = new PDO('sqlite:' . LIZ_DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS liz_conversations (
                    id INTEGER PRIMARY KEY,
                    user_id TEXT,
                    message TEXT,
                    response TEXT,
                    model TEXT DEFAULT 'gemini-1.5-flash',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS liz_users (
                    user_id TEXT PRIMARY KEY,
                    total_messages INTEGER DEFAULT 0,
                    last_interaction DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE INDEX IF NOT EXISTS idx_user ON liz_conversations(user_id);
                CREATE INDEX IF NOT EXISTS idx_date ON liz_conversations(created_at);
            ");
        } catch (Exception $e) {
            error_log("Liz DB Error: " . $e->getMessage());
            $this->db = null;
        }
    }

    private function loadCatalog(): void
    {
        if (function_exists('svcr_products')) {
            $this->catalog = svcr_products();
        }
    }

    private function findRelevantProducts(string $query, int $max = 4): array
    {
        if (empty($this->catalog)) return [];
        $queryLower = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        $matches = [];

        foreach ($this->catalog as $p) {
            $name = (string)($p['name'] ?? '');
            $category = (string)($p['category'] ?? '');
            $sku = (string)($p['sku'] ?? '');
            $haystack = function_exists('mb_strtolower') ? mb_strtolower("$name $category $sku", 'UTF-8') : strtolower("$name $category $sku");

            $score = 0;
            $words = preg_split('/\s+/', $queryLower) ?: [];
            foreach ($words as $w) {
                if (strlen($w) >= 3 && str_contains($haystack, $w)) {
                    $score++;
                }
            }

            if ($score > 0 && ($p['stock'] ?? 0) > 0 && ($p['price'] ?? 0) > 0) {
                $matches[] = [
                    'name' => $name,
                    'price' => 'R$ ' . number_format((float)$p['price'], 2, ',', '.'),
                    'stock' => (int)($p['stock'] ?? 0),
                    'sku' => $sku,
                    'slug' => $p['slug'] ?? '',
                    'url' => '/produto/' . ($p['slug'] ?? svcr_slug($name, $sku)),
                    'score' => $score,
                ];
            }
        }

        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($matches, 0, $max);
    }

    private function getCatalogContext(string $query): string
    {
        $totalProducts = count($this->catalog);
        $relevant = $this->findRelevantProducts($query, 4);
        
        $relevantText = "";
        if (!empty($relevant)) {
            $relevantText = "\nProdutos encontrados relacionados com a busca do cliente:\n";
            foreach ($relevant as $item) {
                $relevantText .= "- {$item['name']} | Preço: {$item['price']} | Estoque: {$item['stock']} un | Link: https://shopvivaliz.com.br{$item['url']}\n";
            }
        }

        return "ShopVivaliz - Loja de ferramentas, rodízios, vasos decorativos e utilidades para casa. " .
            "Possuímos $totalProducts produtos em estoque. " .
            "Cupom primeira compra: VIVALIZ10 (10% desconto). " .
            "Políticas: Frete grátis conforme região/carrinho, Pix com 5% de desconto, envio rápido para todo Brasil. " .
            "Contatos: atendimento@shopvivaliz.com.br | WhatsApp: (37) 99937-4112." . $relevantText;
    }

    private function getConversationHistory(string $userId, int $limit = 6): array
    {
        if ($this->db === null) return [];

        try {
            $stmt = $this->db->prepare("
                SELECT message, response FROM liz_conversations
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $history = [];
            foreach (array_reverse($rows) as $row) {
                $history[] = ['role' => 'user', 'parts' => [['text' => $row['message']]]];
                $history[] = ['role' => 'model', 'parts' => [['text' => $row['response']]]];
            }
            return $history;
        } catch (Exception) {
            return [];
        }
    }

    private function generateIntelligentFallback(string $message): string
    {
        $relevant = $this->findRelevantProducts($message, 3);
        $msgLower = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

        if (!empty($relevant)) {
            $reply = "Encontrei opções incríveis no nosso catálogo em estoque:\n\n";
            foreach ($relevant as $item) {
                $reply .= "• [" . $item['name'] . "](" . $item['url'] . ") — **" . $item['price'] . "** (" . $item['stock'] . " un disponíveis)\n";
            }
            $reply .= "\n💡 Aproveite 5% de desconto no Pix e utilize o cupom **VIVALIZ10** na sua primeira compra!";
            return $reply;
        }

        if (str_contains($msgLower, 'frete') || str_contains($msgLower, 'entrega') || str_contains($msgLower, 'prazo')) {
            return "Entregamos para todo o Brasil com código de rastreamento! Você pode calcular o prazo e valor exato informando seu CEP no carrinho ou na página do produto. Aproveite Frete Grátis em compras elegíveis!";
        }

        if (str_contains($msgLower, 'pagamento') || str_contains($msgLower, 'pix') || str_contains($msgLower, 'cartao') || str_contains($msgLower, 'cupom')) {
            return "Aceitamos Pix (com 5% de desconto imediato), Cartões de Crédito em até 12x e Boleto Bancário via Mercado Pago. Use o cupom **VIVALIZ10** para ganhar 10% de desconto na primeira compra!";
        }

        if (str_contains($msgLower, 'contato') || str_contains($msgLower, 'atendimento') || str_contains($msgLower, 'whatsapp') || str_contains($msgLower, 'telefone')) {
            return "Nossa equipe de atendimento está disponível pelo WhatsApp [(37) 99937-4112](https://wa.me/5537999374112) ou pelo e-mail atendimento@shopvivaliz.com.br.";
        }

        return "Olá! Sou a Liz da ShopVivaliz. Temos centenas de produtos para casa, ferramentas, rodízios e organização com os melhores preços! Como posso te ajudar com produtos, frete ou pagamentos?";
    }

    public function generateResponse(string $message, string $userId): string
    {
        $history = $this->getConversationHistory($userId, 4);
        $catalogContext = $this->getCatalogContext($message);

        if (!empty($this->apiKey)) {
            $systemPrompt = "Você é Liz, a assistente virtual hyperinteligente da loja online ShopVivaliz (www.shopvivaliz.com.br). " .
                "Responda sempre em português brasileiro de forma cortês, profissional, ágil e muito útil. " .
                "Instruções:\n" .
                "1. Sempre que recomendar produtos, forneça o nome, preço e o link no formato [Nome do Produto](URL_DO_PRODUTO).\n" .
                "2. Se o cliente perguntar de entregas, frete, pagamentos (Pix, cartão, boleto) ou cupons (VIVALIZ10), responda com precisão.\n" .
                "3. Se o produto estiver esgotado ou você não tiver certeza, ofereça atendimento humano no WhatsApp (37) 99937-4112 ou e-mail atendimento@shopvivaliz.com.br.\n" .
                "Contexto do Catálogo e Loja:\n" . $catalogContext;

            $contents = array_merge($history, [
                ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\nMensagem do cliente: " . $message]]]
            ]);

            try {
                $response = $this->callGeminiAPI($contents);
                if (!empty($response)) {
                    $this->saveConversation($userId, $message, $response);
                    return $response;
                }
            } catch (Exception $e) {
                error_log("Liz Gemini API Notice: " . $e->getMessage());
            }
        }

        $fallback = $this->generateIntelligentFallback($message);
        $this->saveConversation($userId, $message, $fallback);
        return $fallback;
    }

    private function callGeminiHttp(string $url, array $payload): array
    {
        $jsonPayload = json_encode($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['code' => (int)$httpCode, 'body' => (string)$result];
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $jsonPayload,
                'timeout' => 12,
                'ignore_errors' => true,
            ]
        ];
        $ctx = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);
        $httpCode = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#HTTP/\d\.\d\s+(\d+)#i', $header, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
        return ['code' => $httpCode, 'body' => (string)$result];
    }

    private function callGeminiAPI(array $contents): string
    {
        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => 1024,
                'temperature' => 0.6,
                'topP' => 0.9,
            ]
        ];

        // Modelo Flash mais econômico e rápido
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);
        $res = $this->callGeminiHttp($url, $payload);

        if ($res['code'] === 200 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (trim($text) !== '') return $text;
        }

        if ($this->model !== 'gemini-1.5-flash') {
            $fallbackUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($this->apiKey);
            $res2 = $this->callGeminiHttp($fallbackUrl, $payload);
            if ($res2['code'] === 200 && !empty($res2['body'])) {
                $data2 = json_decode($res2['body'], true);
                $text2 = $data2['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (trim($text2) !== '') return $text2;
            }
        }

        throw new Exception("Gemini API response empty or returned " . $res['code']);
    }

    private function saveConversation(string $userId, string $message, string $response): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO liz_conversations (user_id, message, response, created_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$userId, $message, $response]);

            $checkStmt = $this->db->prepare("SELECT * FROM liz_users WHERE user_id = ?");
            $checkStmt->execute([$userId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                $stmt = $this->db->prepare("
                    INSERT INTO liz_users (user_id, total_messages, last_interaction, created_at)
                    VALUES (?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE liz_users
                    SET total_messages = total_messages + 1, last_interaction = CURRENT_TIMESTAMP
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
            }
        } catch (Exception $e) {
            error_log("Liz Save Error: " . $e->getMessage());
        }
    }
}

// Request handler
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'liz-intelligence.php' || !empty($_SERVER['HTTP_HOST'])) {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $message = trim((string)($input['message'] ?? ''));
    $userId = (string)($input['user_id'] ?? md5(($_SERVER['REMOTE_ADDR'] ?? 'anon') . date('Y-m-d')));

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem vazia'], JSON_UNESCAPED_UNICODE);
        exit;
    }

try {
    $liz = new LizIntelligence();
    $response = $liz->generateResponse($message, $userId);

    echo json_encode([
        'status' => 'ok',
        'answer' => $response,
        'version' => '2.0-intelligence',
        'powered_by' => 'Google Gemini Pro'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log("Liz Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['error' => 'Erro ao processar'], JSON_UNESCAPED_UNICODE);
}
}
?>
