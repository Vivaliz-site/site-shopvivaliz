<?php
/**
 * Squad Chat API - ShopVivaliz
 * Endpoint para comunicação entre agentes autônomos e assistente virtual
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$payload = [];

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'status'    => 'error',
        'endpoint'  => 'squad-chat',
        'timestamp' => date('c'),
        'method'    => $method,
        'error'     => 'Method Not Allowed',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $body    = file_get_contents('php://input');
    $payload = json_decode($body, true) ?? [];
}

if (($_GET['health'] ?? '') === '1') {
    $response = [
        'ok' => true,
        'endpoint' => 'squad-chat',
        'providers' => [
            'gemini' => (getenv('GEMINI_API_KEY') ?: '') !== '',
        ]
    ];
    echo json_encode($response);
    exit;
}

$response = [
    'status'    => 'ok',
    'endpoint'  => 'squad-chat',
    'timestamp' => date('c'),
    'method'    => $method,
];

if ($method === 'POST' && !empty($payload['message'])) {
    $message = (string)($payload['message'] ?? '');
    $context = (string)($payload['context'] ?? 'site-shopvivaliz');

    $answer = processLizChat($message, $context);

    $response['answer'] = $answer;
    $response['received'] = [
        'message' => $message,
        'context' => $context,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function processLizChat(string $message, string $context): string
{
    $geminiKey = getenv('GEMINI_API_KEY') ?: '';
    
    // 1. Carrega dados de aprendizado dinâmico
    $learningFile = dirname(__DIR__, 2) . '/storage/private/liz_learning_base.json';
    $learningData = ['learned_facts' => [], 'history' => []];
    if (is_file($learningFile)) {
        $learningData = json_decode((string)file_get_contents($learningFile), true) ?: $learningData;
    } else {
        @mkdir(dirname($learningFile), 0777, true);
    }
    
    // 2. Carrega catálogo resumido para dar contexto à IA sobre a loja real
    $catalogFile = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
    $productsSummary = "";
    if (is_file($catalogFile)) {
        $products = json_decode((string)file_get_contents($catalogFile), true) ?: [];
        $sample = array_slice($products, 0, 25);
        foreach ($sample as $p) {
            $productsSummary .= "- SKU: {$p['sku']}, Nome: {$p['name']}, Preço: R$ {$p['price']}, Categoria: {$p['category']}\n";
        }
    }
    
    // Fallback se não tiver chave de API do Gemini configurada (ex: rodando local sem .env completo)
    if (empty($geminiKey)) {
        $lowerMsg = strtolower($message);
        $reply = "";
        if (preg_match('/(produto|item|SKU|código)/i', $lowerMsg)) {
            $reply = "Adoraria ajudar você a encontrar o produto perfeito! Atualmente temos itens de Armários, Ferramentas, Banheiro, Pet, Rodízios, Jardim, etc. Qual categoria você procura?";
        } elseif (preg_match('/(entrega|frete|prazo|demora)/i', $lowerMsg)) {
            $reply = "Nossas entregas saem super rápido via Melhor Envio! Em média, entregamos para todo o Brasil em até 10 dias úteis. Quer simular para o seu CEP?";
        } elseif (preg_match('/(seguro|confiança|pagamento|boleto|cartão|pix)/i', $lowerMsg)) {
            $reply = "A segurança é nossa prioridade! Aceitamos PIX, boleto bancário e cartão de crédito. Todas as transações são 100% protegidas e criptografadas.";
        } elseif (preg_match('/(meu nome é|me chamo|sou o|sou a) ([a-zA-ZáéíóúâêîôûãõçÁÉÍÓÚÂÊÎÔÛÃÕÇ]+)/i', $message, $matches)) {
            $name = trim($matches[2]);
            $learningData['learned_facts'][] = "Nome do usuário: " . $name;
            file_put_contents($learningFile, json_encode($learningData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $reply = "Prazer em conhecer você, {$name}! Em que posso te ajudar hoje?";
        } else {
            $knownName = "";
            foreach ($learningData['learned_facts'] as $fact) {
                if (str_starts_with($fact, "Nome do usuário:")) {
                    $knownName = trim(str_replace("Nome do usuário:", "", $fact));
                }
            }
            if ($knownName !== "") {
                $reply = "Olá {$knownName}! 😊 Como posso te ajudar hoje? Para consultas reais, nosso time está à disposição.";
            } else {
                $reply = "Olá! 😊 Eu sou a Liz. Para falar com nosso atendimento real ou obter informações sobre produtos e prazos, pode me perguntar.";
            }
        }
        return $reply;
    }

    // 3. Monta o Prompt de Sistema com as regras de comportamento da Liz
    $systemPrompt = "Você é a Liz, assistente virtual oficial e simpática da loja ShopVivaliz.\n";
    $systemPrompt .= "Seu objetivo é ajudar os clientes a encontrar produtos, tirar dúvidas de frete, pagamento e segurança de forma gentil, alegre e prestativa.\n\n";
    $systemPrompt .= "INFORMAÇÕES DA LOJA:\n";
    $systemPrompt .= "- WhatsApp Oficial: +55 (11) 99999-9999 (ou o indicado na página de contato)\n";
    $systemPrompt .= "- Prazo de Entrega: Em média até 10 dias úteis para todo o Brasil\n";
    $systemPrompt .= "- Métodos de Pagamento: Cartão de Crédito, PIX, Boleto\n\n";
    
    if ($productsSummary !== "") {
        $systemPrompt .= "PRODUTOS EM DESTAQUE NO CATÁLOGO:\n" . $productsSummary . "\n";
    }
    
    if (!empty($learningData['learned_facts'])) {
        $systemPrompt .= "FATOS QUE VOCÊ APRENDEU SOBRE O CLIENTE NESSA CONVERSA (USE ESSAS INFORMAÇÕES PARA RESPONDER DE FORMA PERSONALIZADA):\n";
        foreach ($learningData['learned_facts'] as $fact) {
            $systemPrompt .= "- " . $fact . "\n";
        }
        $systemPrompt .= "\n";
    }
    
    // 4. Monta o Histórico de Conversação
    $contents = [];
    $historySample = array_slice($learningData['history'] ?? [], -10);
    foreach ($historySample as $msg) {
        $contents[] = [
            'role' => $msg['role'] === 'bot' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ];
    }
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $message]],
    ];
    
    // 5. Envia chamada ao Gemini
    $model = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-1.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $geminiKey;
    
    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => 600, 'temperature' => 0.5],
    ];
    
    $responseChat = callGeminiAPI($url, $payload);
    $answer = trim($responseChat['candidates'][0]['content']['parts'][0]['text'] ?? '') ?: 'Desculpe, não consegui processar sua resposta agora.';
    
    // 6. Salva histórico da conversa
    $learningData['history'][] = ['role' => 'user', 'content' => $message];
    $learningData['history'][] = ['role' => 'bot', 'content' => $answer];
    if (count($learningData['history']) > 30) {
        $learningData['history'] = array_slice($learningData['history'], -30);
    }
    
    // 7. EXTRAÇÃO AUTÔNOMA DE APRENDIZADO:
    // Analisa a mensagem do usuário para capturar fatos importantes (nome, preferências, CEP, etc.)
    $extractionPrompt = "Você é um extrator de fatos de conversação. Analise a última mensagem do usuário e extraia fatos importantes para o bot lembrar nas próximas interações (ex: o nome do usuário, se ele procura um item específico, preferências de cor, CEP ou reclamação).\n";
    $extractionPrompt .= "Se houver fatos novos para lembrar, retorne-os como itens de uma lista em português.\n";
    $extractionPrompt .= "Se não houver nenhuma informação nova que valha a pena ser lembrada, responda exatamente com a palavra 'NENHUM'.\n\n";
    $extractionPrompt .= "Mensagem do usuário: \"{$message}\"";
    
    $payloadExtract = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $extractionPrompt]]]
        ],
        'generationConfig' => ['maxOutputTokens' => 100, 'temperature' => 0.0],
    ];
    
    $responseExtract = callGeminiAPI($url, $payloadExtract);
    $extractionResult = trim($responseExtract['candidates'][0]['content']['parts'][0]['text'] ?? '');
    
    if ($extractionResult !== '' && strtolower($extractionResult) !== 'nenhum') {
        $lines = explode("\n", $extractionResult);
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^[\s*\-]+/', '', trim($line)));
            if ($line !== '' && !in_array($line, $learningData['learned_facts'], true)) {
                $learningData['learned_facts'][] = $line;
            }
        }
    }
    
    file_put_contents($learningFile, json_encode($learningData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return $answer;
}

function callGeminiAPI(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        return [];
    }
    return json_decode((string)$response, true) ?: [];
}
