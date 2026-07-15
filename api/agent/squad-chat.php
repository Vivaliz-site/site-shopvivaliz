<?php
/**
 * Squad Chat API - canal duplo:
 * - Liz: atendimento da loja
 * - Operations: intervencao humana real sobre agentes autonomos
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$body = $method === 'POST' ? (string) file_get_contents('php://input') : '';
$payload = json_decode($body, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

function squad_root(): string
{
    return dirname(__DIR__, 2);
}

function squad_read_jsonl(string $path, int $limit = 100): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $items = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    return $items;
}

function squad_append_jsonl(string $path, array $payload): void
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function squad_operations_mode(array $payload): bool
{
    if (isset($_GET['mode']) && strtolower((string) $_GET['mode']) === 'operations') {
        return true;
    }
    return isset($payload['agent_id']) || isset($payload['mode']) && strtolower((string) $payload['mode']) === 'operations';
}

function squad_send_intervention(array $payload): array
{
    $agentId = strtolower(trim((string) ($payload['agent_id'] ?? '')));
    $message = trim((string) ($payload['message'] ?? ''));
    if ($agentId === '' || $message === '') {
        http_response_code(422);
        return ['status' => 'error', 'message' => 'agent_id e message sao obrigatorios para intervencao operacional.'];
    }

    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'agent_id' => $agentId,
        'message' => $message,
        'source' => trim((string) ($payload['source'] ?? 'squad-chat')),
        'created_at' => date('c'),
        'status' => 'queued',
        'kind' => 'human-intervention',
    ];

    squad_append_jsonl(squad_root() . '/storage/private/agent-interventions.jsonl', $entry);

    return [
        'status' => 'ok',
        'endpoint' => 'squad-chat',
        'mode' => 'operations',
        'message' => 'Intervencao enviada ao agente e aguardando consumo pelo executor.',
        'command' => $entry,
    ];
}

function squad_read_intervention_thread(string $agentId): array
{
    $commands = squad_read_jsonl(squad_root() . '/storage/private/agent-interventions.jsonl', 200);
    $responses = squad_read_jsonl(squad_root() . '/storage/private/agent-intervention-responses.jsonl', 200);

    return [
        'commands' => array_values(array_filter($commands, static function (array $row) use ($agentId): bool {
            return strtolower((string) ($row['agent_id'] ?? '')) === $agentId;
        })),
        'responses' => array_values(array_filter($responses, static function (array $row) use ($agentId): bool {
            return strtolower((string) ($row['agent_id'] ?? '')) === $agentId;
        })),
    ];
}

if (($_GET['health'] ?? '') === '1') {
    echo json_encode([
        'ok' => true,
        'endpoint' => 'squad-chat',
        'providers' => ['gemini' => (getenv('GEMINI_API_KEY') ?: '') !== ''],
    ]);
    exit;
}

if (squad_operations_mode($payload)) {
    if ($method === 'POST') {
        echo json_encode(squad_send_intervention($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $agentId = strtolower(trim((string) ($_GET['agent_id'] ?? '')));
    echo json_encode([
        'status' => 'ok',
        'endpoint' => 'squad-chat',
        'mode' => 'operations',
        'agent_id' => $agentId,
        'thread' => $agentId !== '' ? squad_read_intervention_thread($agentId) : ['commands' => [], 'responses' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$response = [
    'status' => 'ok',
    'endpoint' => 'squad-chat',
    'timestamp' => date('c'),
    'method' => $method,
];

if ($method === 'POST' && !empty($payload['message'])) {
    $message = (string) ($payload['message'] ?? '');
    $context = (string) ($payload['context'] ?? 'site-shopvivaliz');
    $response['answer'] = processLizChat($message, $context);
    $response['received'] = ['message' => $message, 'context' => $context];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function processLizChat(string $message, string $context): string
{
    $geminiKey = getenv('GEMINI_API_KEY') ?: '';
    $learningFile = dirname(__DIR__, 2) . '/storage/private/liz_learning_base.json';
    $learningData = ['learned_facts' => [], 'history' => []];
    if (is_file($learningFile)) {
        $learningData = json_decode((string) file_get_contents($learningFile), true) ?: $learningData;
    } else {
        @mkdir(dirname($learningFile), 0777, true);
    }

    $catalogFile = dirname(__DIR__, 2) . '/api/catalog/fallback-products.json';
    $productsSummary = '';
    if (is_file($catalogFile)) {
        $products = json_decode((string) file_get_contents($catalogFile), true) ?: [];
        foreach (array_slice($products, 0, 25) as $p) {
            $productsSummary .= "- SKU: {$p['sku']}, Nome: {$p['name']}, Preço: R$ {$p['price']}, Categoria: {$p['category']}\n";
        }
    }

    if ($geminiKey === '') {
        $lowerMsg = strtolower($message);
        if (preg_match('/(produto|item|sku|código)/i', $lowerMsg)) {
            return 'Posso te ajudar a encontrar o item ideal. Temos linhas como casa, jardim, organização, ferramentas e pet. Qual categoria você procura?';
        }
        if (preg_match('/(entrega|frete|prazo|demora)/i', $lowerMsg)) {
            return 'Entregamos para todo o Brasil. O prazo e o frete variam conforme o CEP e os itens do carrinho.';
        }
        if (preg_match('/(seguro|pagamento|boleto|cartão|pix)/i', $lowerMsg)) {
            return 'Aceitamos PIX, boleto e cartão, com transações protegidas e ambiente seguro.';
        }
        if (preg_match('/(troca|devolução|reembolso)/i', $lowerMsg)) {
            return 'Você pode solicitar troca ou devolução dentro do prazo legal, e nosso atendimento orienta todo o processo.';
        }
        if (preg_match('/(contato|email|telefone|whatsapp)/i', $lowerMsg)) {
            return 'Você pode falar com a equipe pelo e-mail atendimento@shopvivaliz.com.br.';
        }
        return 'Olá, eu sou a Liz. Posso te ajudar com produtos, frete, pagamento e informações da loja.';
    }

    $systemPrompt = "Você é a Liz, assistente virtual oficial da ShopVivaliz.\n";
    $systemPrompt .= "Atenda o cliente de forma objetiva, gentil e útil.\n\n";
    $systemPrompt .= "Dados da loja:\n";
    $systemPrompt .= "- Atendimento: atendimento@shopvivaliz.com.br\n";
    $systemPrompt .= "- Contexto: {$context}\n";
    if ($productsSummary !== '') {
        $systemPrompt .= "Produtos de contexto:\n{$productsSummary}\n";
    }

    $contents = [];
    foreach (array_slice($learningData['history'] ?? [], -10) as $msg) {
        $contents[] = [
            'role' => $msg['role'] === 'bot' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $model = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-1.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $geminiKey;
    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => 500, 'temperature' => 0.5],
    ];

    $chat = callGeminiAPI($url, $payload);
    $answer = trim($chat['candidates'][0]['content']['parts'][0]['text'] ?? '') ?: 'Desculpe, não consegui responder agora.';

    $learningData['history'][] = ['role' => 'user', 'content' => $message];
    $learningData['history'][] = ['role' => 'bot', 'content' => $answer];
    $learningData['history'] = array_slice($learningData['history'], -30);
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
    return json_decode((string) $response, true) ?: [];
}
