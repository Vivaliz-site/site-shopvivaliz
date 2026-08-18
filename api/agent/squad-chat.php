<?php
/**
 * Squad Chat API - canal duplo:
 * - Liz: atendimento da loja
 * - Operations: intervencao humana real sobre agentes autonomos
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/config/agent-keys.php';
require_once dirname(__DIR__, 2) . '/includes/order-rate-limit.php';

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
if ($method === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'Payload muito grande.'], JSON_UNESCAPED_UNICODE);
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

function squad_request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? '';
    return is_string($value) ? trim($value) : '';
}

function squad_authorize_operations(): bool
{
    $expected = getenv('SHOPVIVALIZ_AGENT_KEY');
    if (!is_string($expected) || trim($expected) === '') {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'endpoint' => 'squad-chat',
            'message' => 'Canal operacional indisponivel.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false;
    }

    $provided = squad_request_header('X-ShopVivaliz-Agent-Key');
    if ($provided === '') {
        $authorization = squad_request_header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = trim($matches[1]);
        }
    }

    if ($provided === '' || !hash_equals(trim($expected), $provided)) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="ShopVivaliz Operations"');
        echo json_encode([
            'status' => 'error',
            'endpoint' => 'squad-chat',
            'message' => 'Nao autorizado.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false;
    }

    return true;
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
    return isset($payload['agent_id']) || (isset($payload['mode']) && strtolower((string) $payload['mode']) === 'operations');
}

function squad_send_intervention(array $payload): array
{
    $agentId = strtolower(trim((string) ($payload['agent_id'] ?? '')));
    $message = trim((string) ($payload['message'] ?? ''));
    if ($agentId === '' || $message === '') {
        http_response_code(422);
        return ['status' => 'error', 'message' => 'agent_id e message sao obrigatorios para intervencao operacional.'];
    }
    if (!preg_match('/^[a-z0-9._-]{1,80}$/', $agentId)) {
        http_response_code(422);
        return ['status' => 'error', 'message' => 'agent_id invalido.'];
    }
    if (strlen($message) > 4000) {
        http_response_code(413);
        return ['status' => 'error', 'message' => 'Mensagem operacional excede o limite permitido.'];
    }

    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'agent_id' => $agentId,
        'message' => $message,
        'source' => 'squad-chat',
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
    if (!squad_authorize_operations()) {
        exit;
    }

    if ($method === 'POST') {
        echo json_encode(squad_send_intervention($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $agentId = strtolower(trim((string) ($_GET['agent_id'] ?? '')));
    if ($agentId !== '' && !preg_match('/^[a-z0-9._-]{1,80}$/', $agentId)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'agent_id invalido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'status' => 'ok',
        'endpoint' => 'squad-chat',
        'mode' => 'operations',
        'agent_id' => $agentId,
        'thread' => $agentId !== '' ? squad_read_intervention_thread($agentId) : ['commands' => [], 'responses' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && !svorl_allow(20, 300, 'squad-chat-public')) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Muitas mensagens. Aguarde alguns instantes.'], JSON_UNESCAPED_UNICODE);
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
    $root = dirname(__DIR__, 2);
    require_once $root . '/includes/catalog-runtime.php';
    $products = svcr_products();
    $productsSummary = '';
    foreach (array_slice($products, 0, 25) as $product) {
        $sku = trim((string)($product['sku'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));
        $category = trim((string)($product['category'] ?? ''));
        if ($sku === '' || $name === '') continue;
        $productsSummary .= "- SKU: {$sku}; nome: {$name}; categoria: {$category}\n";
    }

    $normMsg = strtr(strtolower($message), [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'
    ]);

    if ($geminiKey === '') {
        if (preg_match('/(troca|devolucao|devolver|reembolso)/i', $normMsg)) {
            return 'Você pode solicitar a devolução em até 7 dias corridos após o recebimento. Envie um e-mail para atendimento@shopvivaliz.com.br ou fale no WhatsApp (37) 99937-4112 e informe o número do pedido.';
        }
        if (preg_match('/(entrega|frete|prazo|demora|envio|chega)/i', $normMsg)) {
            return 'O valor e o prazo de entrega são calculados pelo CEP no carrinho. A opção disponível depende do endereço e dos itens do pedido.';
        }
        if (preg_match('/(seguro|pagamento|boleto|cartao|pix|parcela)/i', $normMsg)) {
            return 'O checkout oferece Pix, boleto e cartão conforme o gateway escolhido. Parcelas, taxas e valor final são mostrados antes da confirmação do pagamento.';
        }
        if (preg_match('/(contato|email|telefone|whatsapp|atendimento|falar)/i', $normMsg)) {
            return 'Você pode falar com nosso atendimento humano pelo WhatsApp (37) 99937-4112 ou e-mail atendimento@shopvivaliz.com.br.';
        }
        if (preg_match('/(desconto|cupom|promocao|oferta)/i', $normMsg)) {
            return 'Cupons e promoções só são válidos quando aparecem no site e são revalidados no checkout. Não consigo prometer um desconto sem essa confirmação.';
        }
        if (preg_match('/(rodizio|rodinha|roda|silicone|gel)/i', $normMsg)) {
            return 'Você pode consultar as opções atuais de rodízios no catálogo. Confira medida, material, capacidade e tipo de fixação na página do produto; se precisar, informe o SKU ao atendimento.';
        }
        if (preg_match('/(produto|item|sku|codigo|catalogo|comprar)/i', $normMsg)) {
            return $products !== []
                ? 'Consulte o catálogo atual e informe o nome, uso ou SKU do item para eu ajudar a localizar a opção correta.'
                : 'Não consegui confirmar o catálogo agora. Tente novamente em instantes ou fale com o atendimento informando o item que procura.';
        }
        return 'Olá! Sou a Liz, assistente virtual da ShopVivaliz. Posso te ajudar com produtos, frete, cupons, trocas e devoluções. Como posso te ajudar hoje?';
    }

    $systemPrompt = "Você é a Liz, assistente virtual oficial da ShopVivaliz.\n";
    $systemPrompt .= "Atenda o cliente de forma objetiva, gentil e útil.\n\n";
    $systemPrompt .= "Dados da loja:\n";
    $systemPrompt .= "- Atendimento: atendimento@shopvivaliz.com.br / WhatsApp (37) 99937-4112\n";
    $systemPrompt .= "- Devolução: solicitação em até 7 dias corridos após o recebimento, via e-mail ou WhatsApp\n";
    $systemPrompt .= "- Frete e prazo: somente o cálculo por CEP no carrinho é autoritativo\n";
    $systemPrompt .= "- Pagamento e promoções: nunca invente parcelas, desconto, cupom ou frete grátis; remeta às condições mostradas e revalidadas no checkout\n";
    $systemPrompt .= "- Catálogo: use somente os itens canônicos listados abaixo; sem item correspondente, diga que não conseguiu confirmar\n";
    $systemPrompt .= "- Contexto: {$context}\n";
    if ($productsSummary !== '') {
        $systemPrompt .= "Produtos de contexto:\n{$productsSummary}\n";
    }

    // Este endpoint legado não possui identidade de conversa. Não misturar o
    // histórico de um visitante com outro nem persistir mensagens públicas.
    $contents = [['role' => 'user', 'parts' => [['text' => $message]]]];

    $model = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-1.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $geminiKey;
    $requestPayload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => 500, 'temperature' => 0.5],
    ];

    $chat = callGeminiAPI($url, $requestPayload);
    $answer = trim($chat['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($answer === '') {
        if (preg_match('/(troca|devolucao|devolver|reembolso)/i', $normMsg)) {
            $answer = 'Você pode solicitar a devolução em até 7 dias corridos após o recebimento. Envie um e-mail para atendimento@shopvivaliz.com.br ou fale no WhatsApp (37) 99937-4112 e informe o número do pedido.';
        } elseif (preg_match('/(rodizio|rodinha|roda|silicone|gel)/i', $normMsg)) {
            $answer = 'Consulte as opções atuais de rodízios no catálogo e confira medida, material, capacidade e tipo de fixação antes da compra.';
        } elseif (preg_match('/(produto|item|sku|codigo|comprar)/i', $normMsg)) {
            $answer = 'Informe o nome, uso ou SKU do item. Eu só confirmo produtos que estejam no catálogo atual.';
        } elseif (preg_match('/(entrega|frete|prazo|envio)/i', $normMsg)) {
            $answer = 'O valor e o prazo de entrega são calculados pelo CEP no carrinho e dependem dos itens e do endereço.';
        } else {
            $answer = 'Olá! Sou a Liz, assistente virtual da ShopVivaliz. Posso te ajudar a localizar produtos no catálogo, consultar frete, trocas ou cupom de desconto. Como posso te ajudar hoje?';
        }
    }

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
