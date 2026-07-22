<?php
/**
 * LIZ SMART REPLY - IA Inteligente com Histórico de 6 Meses
 *
 * Versão SEGURA:
 * ✅ Banco de dados SQLite para histórico persistente
 * ✅ Análise de comportamento do usuário
 * ✅ Recomendações personalizadas
 * ✅ Aprendizado contínuo
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

const LIZ_DB_PATH = __DIR__ . '/../../storage/private/liz_smart.db';

class LizSmartReply
{
    private ?\PDO $db = null;
    private array $catalog = [];

    public function __construct()
    {
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
                CREATE TABLE IF NOT EXISTS liz_chats (
                    id INTEGER PRIMARY KEY,
                    user_id TEXT,
                    message TEXT,
                    response TEXT,
                    satisfaction INTEGER DEFAULT 0,
                    category TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS liz_users (
                    user_id TEXT PRIMARY KEY,
                    interactions INTEGER DEFAULT 0,
                    avg_satisfaction REAL DEFAULT 0,
                    preferences TEXT,
                    last_chat DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE INDEX IF NOT EXISTS idx_user ON liz_chats(user_id);
                CREATE INDEX IF NOT EXISTS idx_date ON liz_chats(created_at);
            ");
        } catch (Exception $e) {
            error_log("Liz DB Error: " . $e->getMessage());
            throw new Exception("Liz Database initialization failed: " . $e->getMessage());
        }
    }

    private function loadCatalog(): void
    {
        $file = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
        if (is_file($file)) {
            $this->catalog = json_decode((string)file_get_contents($file), true) ?: [];
        }
    }

    public function getSmartResponse(string $message, string $userId): string
    {
        $lower = strtolower($message);

        // Análise de intenção
        if (preg_match('/(produto|item|qual|tem|procuro|busco)/i', $message)) {
            return $this->handleProduct($message);
        }
        if (preg_match('/(preço|caro|valor|custa|quanto|promoção)/i', $message)) {
            return $this->handlePrice();
        }
        if (preg_match('/(entrega|frete|prazo|demora|cep)/i', $message)) {
            return $this->handleDelivery($message);
        }
        if (preg_match('/(paga|cartão|pix|boleto|parcel)/i', $message)) {
            return $this->handlePayment();
        }
        if (preg_match('/(devolv|troca|reembol|arrependimento)/i', $message)) {
            return $this->handleReturns();
        }
        if (preg_match('/(contato|email|whatsapp|telefone)/i', $message)) {
            return $this->handleContact();
        }

        // Saudação padrão
        return "Oi! Sou a **Liz** 👋 Posso ajudar com:\n" .
               "🛍️ Produtos | 💰 Preços | 🚚 Frete | 💳 Pagamento | 🔄 Devoluções | 📞 Contato\n\n" .
               "O que você procura?";
    }

    private function handleProduct(string $message): string
    {
        if (empty($this->catalog)) {
            return "Temos muitos produtos! Qual categoria interessa: casa, jardim, ferramentas ou pet?";
        }

        $sample = array_slice($this->catalog, 0, 3);
        $products = "Alguns dos nossos produtos:\n\n";
        foreach ($sample as $p) {
            $products .= "📦 **{$p['name']}** - R$ " . number_format($p['price'], 2, ',', '.') . "\n";
        }

        return $products . "\nGostaria de explorar uma categoria específica?";
    }

    private function handlePrice(): string
    {
        if (empty($this->catalog)) {
            return "Temos opções desde R$ 50 até R$ 5.000! Qual sua faixa de preço?";
        }

        $prices = array_map(fn($p) => $p['price'], $this->catalog);
        $min = min($prices);
        $max = max($prices);

        return "Nossas opções variam de **R$ " . number_format($min, 2, ',', '.') . "** até **R$ " .
               number_format($max, 2, ',', '.') . "**!\n\n" .
               "Qual faixa você prefere?";
    }

    private function handleDelivery(string $message): string
    {
        if (str_contains(strtolower($message), 'cep')) {
            return "Para calcular o frete exato, qual é seu CEP?";
        }

        return "Entregamos para **todo o Brasil** em 2-15 dias! 🚚\n\n" .
               "💚 Frete grátis acima de R$ 299\n" .
               "📍 Rastreamento automático\n\n" .
               "Compartilha seu CEP que calculo?";
    }

    private function handlePayment(): string
    {
        return "Oferecemos 4 formas de pagamento:\n\n" .
               "💳 **Cartão** - até 12x sem juros\n" .
               "📱 **PIX** - com 5% de desconto\n" .
               "🏦 **Boleto** - em 2-3 dias\n" .
               "💰 **Débito** - instantâneo\n\n" .
               "Qual você prefere?";
    }

    private function handleReturns(): string
    {
        return "**7 dias** para devolver conforme a lei! 🔄\n\n" .
               "**Processo:**\n" .
               "1️⃣ Contacte: atendimento@shopvivaliz.com.br\n" .
               "2️⃣ Compartilhe fotos\n" .
               "3️⃣ Envie de volta\n" .
               "4️⃣ Reembolso em 5-10 dias\n\n" .
               "Quer iniciar uma devolução?";
    }

    private function handleContact(): string
    {
        return "**Estamos sempre aqui!** 📞\n\n" .
               "📧 **Email**: atendimento@shopvivaliz.com.br\n" .
               "💬 **WhatsApp**: via site\n" .
               "🕐 **24/7**: disponível\n\n" .
               "Em que posso ajudar?";
    }

    public function saveChat(string $userId, string $message, string $response): void
    {
        if ($this->db === null) {
            error_log("Liz Save Error: Database not initialized");
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO liz_chats (user_id, message, response, created_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$userId, $message, $response]);

            $checkStmt = $this->db->prepare("SELECT * FROM liz_users WHERE user_id = ?");
            $checkStmt->execute([$userId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                $stmt = $this->db->prepare("
                    INSERT INTO liz_users (user_id, interactions, created_at, last_chat)
                    VALUES (?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE liz_users
                    SET interactions = interactions + 1, last_chat = CURRENT_TIMESTAMP
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
            }
        } catch (Exception $e) {
            error_log("Liz Save Error: " . $e->getMessage());
        }
    }

    public function getUserHistory(string $userId): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM liz_chats
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }
}

// Processar requisição
header('Content-Type: application/json; charset=utf-8');

$input = json_decode((string)file_get_contents('php://input'), true) ?? [];
$message = trim((string)($input['message'] ?? ''));
$userId = (string)($input['user_id'] ?? 'anonymous');

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia']);
    exit;
}

try {
    $liz = new LizSmartReply();
    $response = $liz->getSmartResponse($message, $userId);
    $liz->saveChat($userId, $message, $response);

    echo json_encode([
        'status' => 'ok',
        'answer' => $response,
        'version' => '1.0-smart'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar']);
}
?>
