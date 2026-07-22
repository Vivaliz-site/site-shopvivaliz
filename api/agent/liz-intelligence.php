<?php
/**
 * LIZ INTELLIGENCE - IA Conversacional Real com Claude API
 *
 * Usa integração real com Anthropic Claude para respostas inteligentes.
 * ✅ Contexto do catálogo
 * ✅ Histórico de conversas persistente
 * ✅ Análise de intenção automática
 * ✅ Respostas personalizadas
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

const LIZ_DB_PATH = __DIR__ . '/../../storage/private/liz_intelligence.db';
const LIZ_API_URL = 'https://api.anthropic.com/v1/messages';

class LizIntelligence
{
    private ?\PDO $db = null;
    private array $catalog = [];
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
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
                    model TEXT DEFAULT 'claude-3-5-sonnet-20241022',
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
            throw new Exception("Liz Database initialization failed");
        }
    }

    private function loadCatalog(): void
    {
        $file = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
        if (is_file($file)) {
            $this->catalog = json_decode((string)file_get_contents($file), true) ?: [];
        }
    }

    private function getCatalogContext(): string
    {
        if (empty($this->catalog)) {
            return "Temos um catálogo variado de produtos para casa, ferragens e organização.";
        }

        $categories = [];
        $totalProducts = count($this->catalog);
        $priceRange = [PHP_INT_MAX, 0];

        foreach ($this->catalog as $p) {
            $cat = $p['category'] ?? 'Diversos';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
            $price = (float)($p['price'] ?? 0);
            if ($price > 0) {
                $priceRange[0] = min($priceRange[0], $price);
                $priceRange[1] = max($priceRange[1], $price);
            }
        }

        $catList = implode(', ', array_keys(array_slice($categories, 0, 5, true)));
        $minPrice = $priceRange[0] !== PHP_INT_MAX ? number_format($priceRange[0], 2, ',', '.') : 'desde R$ 50';
        $maxPrice = number_format($priceRange[1], 2, ',', '.');

        return "Catálogo com $totalProducts produtos em categorias como: $catList. Preços de R$ $minPrice até R$ $maxPrice.";
    }

    private function getConversationHistory(string $userId, int $limit = 5): array
    {
        if ($this->db === null) {
            return [];
        }

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
                $history[] = ['role' => 'user', 'content' => $row['message']];
                $history[] = ['role' => 'assistant', 'content' => $row['response']];
            }
            return $history;
        } catch (Exception $e) {
            return [];
        }
    }

    public function generateResponse(string $message, string $userId): string
    {
        if (empty($this->apiKey)) {
            return "Assistente temporariamente indisponível. Favor usar contato@shopvivaliz.com.br";
        }

        $history = $this->getConversationHistory($userId, 3);

        $systemPrompt = "Você é Liz, assistente inteligente da loja Vivaliz. " .
            "Responda em português brasileiro, de forma amigável e concisa. " .
            "Contexto: " . $this->getCatalogContext() . " " .
            "Você ajuda com: produtos, preços, frete, pagamentos, devoluções e contato. " .
            "Se não sabe a resposta, sugira contato com atendimento@shopvivaliz.com.br ou WhatsApp.";

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message]
        ]);

        try {
            $response = $this->callClaudeAPI($systemPrompt, $messages);
            $this->saveConversation($userId, $message, $response);
            return $response;
        } catch (Exception $e) {
            error_log("Liz API Error: " . $e->getMessage());
            return "Desculpe, encontrei um erro ao processar sua mensagem. Tente novamente ou entre em contato.";
        }
    }

    private function callClaudeAPI(string $system, array $messages): string
    {
        $payload = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $messages
        ];

        $ch = curl_init(LIZ_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$result) {
            throw new Exception("Claude API returned $httpCode");
        }

        $data = json_decode($result, true);
        return $data['content'][0]['text'] ?? 'Desculpe, não consegui gerar uma resposta.';
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
        'powered_by' => 'Claude 3.5 Sonnet'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar'], JSON_UNESCAPED_UNICODE);
}
?>
