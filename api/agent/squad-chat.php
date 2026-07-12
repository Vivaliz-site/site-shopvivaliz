<?php
/**
 * Squad Chat API - ShopVivaliz
 * Endpoint para comunicação entre agentes autônomos
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

$response = [
    'status'    => 'ok',
    'endpoint'  => 'squad-chat',
    'timestamp' => date('c'),
    'method'    => $method,
    'health'    => 'ok',
    'providers' => [
        'openai' => (getenv('OPENAI_API_KEY') ?: '') !== '',
        'gemini' => (getenv('GEMINI_API_KEY') ?: '') !== '',
        'anthropic' => (getenv('ANTHROPIC_API_KEY') ?: '') !== '',
    ],
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
    $providers = [
        'openai'    => getenv('OPENAI_API_KEY'),
        'gemini'    => getenv('GEMINI_API_KEY'),
        'anthropic' => getenv('ANTHROPIC_API_KEY'),
    ];

    // Fallback simples: retorna resposta template se nenhum provider disponível
    if (empty(array_filter($providers))) {
        return "Olá! Sou a Liz. Desculpe, estou em modo demo agora. Posso ajudar com informações sobre nossos produtos e entrega. Qual sua dúvida?";
    }

    // Template de resposta com base na pergunta
    $lowerMsg = strtolower($message);

    if (preg_match('/(produto|item|SKU|código)/i', $lowerMsg)) {
        return "Adoraria ajudar você a encontrar o produto perfeito! Você está procurando algo específico? Posso filtrar por categoria, preço ou características.";
    }
    if (preg_match('/(entrega|frete|prazo|demora)/i', $lowerMsg)) {
        return "Nossas entregas saem rápido! Dependendo de onde você está, entregamos em até 10 dias úteis. Quer saber mais sobre as regiões que atendemos?";
    }
    if (preg_match('/(seguro|confiança|pagamento|boleto|cartão|pix)/i', $lowerMsg)) {
        return "A segurança é nossa prioridade! Aceitamos PIX, boleto e cartão de crédito. Todas as transações são protegidas. Você tem alguma dúvida sobre algum método?";
    }
    if (preg_match('/(problema|defeito|qualidade|reclamação)/i', $lowerMsg)) {
        return "Lamentamos se algo não saiu perfeito. Nosso time de atendimento está aqui para ajudar! Você pode descrever melhor o que aconteceu?";
    }

    return "Entendi sua pergunta! 😊 Para informações mais específicas, nosso time está disponível no WhatsApp ou email. Posso ajudar com mais alguma coisa?";
}
