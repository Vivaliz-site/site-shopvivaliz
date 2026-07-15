<?php
/**
 * MODO HÍBRIDO - Monitor Inteligente
 * Sistema oferece sugestões baseadas em REGRAS (rápido)
 * Usuário clica → Chama Claude para análise detalhada
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = $input['message'] ?? '';
$action = $input['action'] ?? 'suggest'; // suggest ou detailed

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia']);
    exit;
}

$lower_msg = strtolower($message);

// ============================================================================
// MODO 1: SUGESTÕES BASEADAS EM REGRAS (Rápido, sem API)
// ============================================================================
if ($action === 'suggest') {

    // Carregar dados do sistema
    $tasks_file = __DIR__ . '/../logs/tasks-queue.json';
    $tasks = file_exists($tasks_file) ? json_decode(file_get_contents($tasks_file), true) : [];

    $pending = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
    $processing = count(array_filter($tasks, fn($t) => $t['status'] === 'processing'));
    $done = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));

    // Regras inteligentes baseadas no estado
    $suggestions = [];

    // Se há tarefas pendentes
    if ($pending > 0) {
        $suggestions[] = [
            'type' => 'action',
            'icon' => '📋',
            'text' => "Você tem $pending tarefa(s) PENDENTE(s) aguardando processamento",
            'action' => 'process-tasks',
            'details_available' => true
        ];
    }

    // Se muitas tarefas em progresso
    if ($processing > 3) {
        $suggestions[] = [
            'type' => 'warning',
            'icon' => '⚠️',
            'text' => "$processing tarefas em progresso - Sistema sobrecarregado",
            'action' => 'check-load',
            'details_available' => true
        ];
    }

    // Se pergunta sobre sincronização
    if (strpos($lower_msg, 'sincroniz') !== false || strpos($lower_msg, 'olist') !== false) {
        $suggestions[] = [
            'type' => 'info',
            'icon' => '🔄',
            'text' => 'Primeira tarefa: Sincronizar 198 produtos do Olist',
            'action' => 'sync-status',
            'details_available' => true
        ];
    }

    // Se pergunta sobre status
    if (strpos($lower_msg, 'status') !== false) {
        $suggestions[] = [
            'type' => 'info',
            'icon' => '📊',
            'text' => "Tarefas: $pending pendentes | $processing em progresso | $done completas",
            'action' => 'full-status',
            'details_available' => true
        ];
    }

    // Se pergunta sobre agentes
    if (strpos($lower_msg, 'agente') !== false) {
        $suggestions[] = [
            'type' => 'info',
            'icon' => '🤖',
            'text' => '3 agentes ativos: Claude (desenvolvedor) | Gemini (arquiteto) | GPT (integrador)',
            'action' => 'agent-status',
            'details_available' => true
        ];
    }

    // Se não há sugestões específicas
    if (empty($suggestions)) {
        $suggestions[] = [
            'type' => 'help',
            'icon' => '💡',
            'text' => 'Clique abaixo para análise detalhada com Claude',
            'action' => 'detailed-analysis',
            'details_available' => true
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'mode' => 'hybrid-suggest',
        'message_received' => $message,
        'suggestions' => $suggestions,
        'system_state' => [
            'tasks_pending' => $pending,
            'tasks_processing' => $processing,
            'tasks_done' => $done
        ],
        'offer_detailed' => count($suggestions) > 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// MODO 2: ANÁLISE DETALHADA (COM CLAUDE)
// ============================================================================
if ($action === 'detailed') {

    $claude_response = call_claude_api($message);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'mode' => 'hybrid-detailed',
        'message_received' => $message,
        'detailed_analysis' => $claude_response,
        'from_agent' => 'Claude',
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fallback
http_response_code(400);
echo json_encode(['error' => 'Ação inválida']);

// ============================================================================
// FUNÇÃO: Chamar Claude API
// ============================================================================
function call_claude_api($message) {
    $api_key = getenv('ANTHROPIC_API_KEY');

    if (!$api_key) {
        return "❌ Chave Claude não configurada. Configure ANTHROPIC_API_KEY nos secrets.";
    }

    try {
        $client = new \Anthropic\Anthropic(['apiKey' => $api_key]);

        $response = $client->messages->create([
            'model' => getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'system' => 'Você é um assistente inteligente do sistema ShopVivaliz. Analise a pergunta do usuário e responda de forma precisa e proativa. Se a pergunta for sobre o status do sistema, ofereça sugestões de ações.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ]
        ]);

        return $response->content[0]->text ?? "Sem resposta";

    } catch (\Exception $e) {
        return "⚠️ Erro ao chamar Claude: " . $e->getMessage();
    }
}
?>
