<?php
/**
 * API: Liz - Assistente Virtual Inteligente
 * Endpoint: POST /api/liz-intelligent.php
 *
 * Implementação de IA real usando Claude API + contexto inteligente
 * - Entende perguntas naturalmente
 * - Responde sobre produtos, frete, pagamento, trocas
 * - Mantém histórico de conversa
 * - Busca produtos automaticamente
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/secure-session.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['health'] ?? '') === '1') {
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'endpoint' => 'liz-intelligent',
        'providers' => ['claude' => (getenv('ANTHROPIC_API_KEY') ?: '') !== ''],
        'version' => '2.0-intelligent',
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($input['message'] ?? ''));
$history = $input['history'] ?? [];

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message required']);
    exit;
}

// ============================================================================
// CONFIGURAR PROVEDORES DE IA (com fallback)
// ============================================================================
$providers = [];

// Tentar múltiplos nomes de variáveis para cada provedor
$geminiKey = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_GEMINI_API_KEY') ?: '');
if (!empty($geminiKey)) {
    // Limpar espaços/duplicatas
    $geminiKey = trim(explode(' ', $geminiKey)[0]);
    if (!empty($geminiKey)) {
        $providers[] = ['name' => 'gemini', 'key' => $geminiKey];
    }
}

$gptKey = getenv('OPENAI_API_KEY') ?: '';
if (!empty($gptKey)) {
    $providers[] = ['name' => 'gpt', 'key' => trim($gptKey)];
}

$claudeKey = getenv('ANTHROPIC_API_KEY') ?: '';
if (!empty($claudeKey)) {
    $providers[] = ['name' => 'claude', 'key' => trim($claudeKey)];
}

// Se nenhum provedor, usar fallback com respostas determinísticas
if (empty($providers)) {
    // Modo degradado - sem IA, apenas regras
    $providers = [['name' => 'rules', 'key' => 'fallback']];
}

// ============================================================================
// FUNÇÃO: Buscar produtos relevantes
// ============================================================================
function liz_search_products(string $query): array
{
    $catalogFile = __DIR__ . '/catalog/fallback-products.json';
    if (!is_file($catalogFile)) {
        return [];
    }

    $products = json_decode((string)file_get_contents($catalogFile), true) ?: [];
    $queryNorm = strtolower($query);
    $relevant = [];

    foreach ($products as $p) {
        $score = 0;
        $name = strtolower($p['name'] ?? '');
        $category = strtolower($p['category'] ?? '');

        // Busca simples: contém termo
        if (str_contains($name, $queryNorm) || str_contains($category, $queryNorm)) {
            $score += 10;
        }

        // Categoria match
        if ($queryNorm === $category) {
            $score += 5;
        }

        if ($score > 0) {
            $relevant[] = [
                'sku' => $p['sku'],
                'name' => $p['name'],
                'price' => $p['price'],
                'category' => $p['category'],
                'score' => $score
            ];
        }
    }

    usort($relevant, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($relevant, 0, 3);
}

// ============================================================================
// FUNÇÃO: Chamar Gemini API (modelo econômico)
// ============================================================================
function liz_call_gemini(string $message, array $history, array $products, string $apiKey): string
{
    $systemPrompt = "Você é Liz, assistente virtual inteligente da ShopVivaliz - loja online de produtos para casa.\n";
    $systemPrompt .= "Responda de forma concisa, amigável e útil. Máximo 3 linhas.\n\n";
    $systemPrompt .= "DADOS DA LOJA:\n";
    $systemPrompt .= "- Email: atendimento@shopvivaliz.com.br\n";
    $systemPrompt .= "- WhatsApp: (37) 99937-4112\n";
    $systemPrompt .= "- Frete Grátis: Acima de R$ 199 (todo Brasil)\n";
    $systemPrompt .= "- Devolução: 7 dias sem burocracia\n";
    $systemPrompt .= "- Cupom: VOLTEI5 (5% OFF primeira compra)\n";
    $systemPrompt .= "- Catálogo: 180+ produtos\n\n";

    if (!empty($products)) {
        $systemPrompt .= "PRODUTOS ENCONTRADOS:\n";
        foreach ($products as $p) {
            $systemPrompt .= "- {$p['name']} (R$ {$p['price']}, {$p['category']})\n";
        }
    }

    $contents = [];

    // Histórico (últimas 5 mensagens = mais econômico)
    foreach (array_slice($history, -5) as $msg) {
        $contents[] = [
            'role' => $msg['role'] === 'user' ? 'user' : 'model',
            'parts' => [['text' => $msg['content']]],
        ];
    }

    // Mensagem atual
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $message]],
    ];

    $model = 'gemini-1.5-flash'; // Modelo mais econômico
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $apiKey;

    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 250,
            'temperature' => 0.7,
            'topP' => 0.9,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return 'Desculpe, estou com uma pequena indisponibilidade. Fale com nosso atendimento: (37) 99937-4112';
    }

    $data = json_decode($response, true);

    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return 'Oi! Como posso te ajudar com produtos, frete ou pedidos? 😊';
    }

    return trim($data['candidates'][0]['content']['parts'][0]['text']);
}

// ============================================================================
// PRINCIPAL
// ============================================================================

// ============================================================================
// FUNÇÕES ADICIONAIS: GPT e Claude
// ============================================================================

function liz_call_gpt(string $message, array $history, array $products, string $apiKey): ?string
{
    $systemPrompt = "Você é Liz, assistente virtual da ShopVivaliz.\n";
    $systemPrompt .= "Responda de forma concisa (máximo 3 linhas).\n";
    $systemPrompt .= "- Frete Grátis: Acima de R$ 199 | Devolução: 7 dias\n";
    $systemPrompt .= "- Cupom: VOLTEI5 (5% OFF)\n";
    $systemPrompt .= "- Contato: (37) 99937-4112\n";

    $messages = [];
    foreach (array_slice($history, -5) as $msg) {
        $messages[] = [
            'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
            'content' => $msg['content']
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'system' => $systemPrompt,
        'max_tokens' => 200,
        'temperature' => 0.7,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function liz_call_claude_api(string $message, array $history, array $products, string $apiKey): ?string
{
    $systemPrompt = "Você é Liz, assistente virtual da ShopVivaliz.\n";
    $systemPrompt .= "Responda de forma concisa e útil (máximo 3 linhas).\n";
    $systemPrompt .= "Dados: Frete Grátis >R$199 | Devolução 7 dias | Cupom VOLTEI5 (5% OFF) | (37) 99937-4112";

    $messages = [];
    foreach (array_slice($history, -5) as $msg) {
        $messages[] = [
            'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
            'content' => $msg['content']
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => 'claude-3-5-haiku-20241022',
        'max_tokens' => 200,
        'system' => $systemPrompt,
        'messages' => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? null;
}

// ============================================================================
// FALLBACK: Respostas baseadas em regras (sem IA)
// ============================================================================
function liz_call_rules(string $message): string
{
    $norm = strtolower($message);
    $norm = strtr($norm, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ã'=>'a','õ'=>'o','ç'=>'c']);

    // Busca por categorias de pergunta
    if (preg_match('/(troca|devolucao|devolver|reembolso|retorno)/i', $norm)) {
        return 'Você tem 7 dias após o recebimento para devolver ou trocar seu produto sem burocracia! Fale com a gente no WhatsApp (37) 99937-4112 ou email atendimento@shopvivaliz.com.br.';
    }
    if (preg_match('/(frete|entrega|prazo|envio|demora|chega|cep)/i', $norm)) {
        return 'Entregamos para todo o Brasil! Frete GRÁTIS em compras acima de R$ 199. O prazo é calculado no carrinho conforme seu CEP.';
    }
    if (preg_match('/(pagamento|pix|boleto|cartao|credito|parce)/i', $norm)) {
        return 'Aceitamos PIX (aprovação imediata), Boleto e Cartão de Crédito até 12x. Todos pagamentos são 100% seguros!';
    }
    if (preg_match('/(cupom|desconto|promocao|oferta|voltei)/i', $norm)) {
        return 'Use o cupom VOLTEI5 para ganhar 5% OFF na sua primeira compra!';
    }
    if (preg_match('/(produto|rodizio|ferramenta|vaso|assentos|utilidade|organiza)/i', $norm)) {
        return 'Temos 180+ produtos: rodízios, ferramentas, organização, vasos e muito mais! Veja nosso catálogo completo: www.shopvivaliz.com.br/catalogo';
    }
    if (preg_match('/(oi|ola|oi tudo|como vai|tudo bem|saudacao)/i', $norm)) {
        return 'Oi! 👋 Bem-vindo à ShopVivaliz! Como posso te ajudar com produtos, frete ou pedidos?';
    }

    return 'Olá! Sou a Liz, assistente virtual da ShopVivaliz. Posso ajudar com produtos, frete, pagamento, trocas e devoluções. O que você gostaria de saber?';
}

// ============================================================================
// ORCHESTRADOR: Tentar múltiplos provedores em ordem
// ============================================================================

function liz_call_with_fallback(string $message, array $history, array $products, array $providers): array
{
    $lastError = 'Nenhum provedor disponível';
    $usedProvider = null;

    foreach ($providers as $provider) {
        $answer = null;

        if ($provider['name'] === 'gemini') {
            $answer = liz_call_gemini($message, $history, $products, $provider['key']);
        } elseif ($provider['name'] === 'gpt') {
            $answer = liz_call_gpt($message, $history, $products, $provider['key']);
        } elseif ($provider['name'] === 'claude') {
            $answer = liz_call_claude_api($message, $history, $products, $provider['key']);
        } elseif ($provider['name'] === 'rules') {
            // Modo fallback com regras inteligentes
            $answer = liz_call_rules($message);
        }

        if (!empty($answer)) {
            $usedProvider = $provider['name'];
            return [
                'success' => true,
                'answer' => trim($answer),
                'provider' => $usedProvider,
            ];
        }

        $lastError = "Falha em {$provider['name']}";
    }

    return [
        'success' => false,
        'answer' => 'Oi! Estou com uma pequena indisponibilidade. Fale com nosso atendimento: (37) 99937-4112',
        'provider' => null,
        'error' => $lastError,
    ];
}

// ============================================================================
// PRINCIPAL
// ============================================================================

// Buscar produtos relevantes
$products = liz_search_products($message);

// Chamar com fallback entre provedores
$result = liz_call_with_fallback($message, $history, $products, $providers);

// Resposta
http_response_code(200);
echo json_encode([
    'ok' => $result['success'],
    'answer' => $result['answer'],
    'provider' => $result['provider'],
    'products_found' => count($products),
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE);
