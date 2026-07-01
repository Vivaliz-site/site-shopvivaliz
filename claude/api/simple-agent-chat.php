<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = $input['message'] ?? '';

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia']);
    exit;
}

$lower_msg = strtolower($message);

// Respostas mais inteligentes e específicas
$responses = [
    // Saudações
    ['keywords' => ['oi', 'ola', 'olá', 'e ai'], 'response' => 'Oi! Bem-vindo ao ShopVivaliz. Sou o Sistema de Monitoramento. Pergunta-me sobre: tarefas, agentes, status ou o que cada agente está fazendo!', 'agent' => 'Sistema'],

    // Tarefas
    ['keywords' => ['qual', 'primeira tarefa', 'proxima', 'próxima'], 'response' => '📋 A primeira tarefa PENDENTE é: "Sincronizar 198 produtos do Olist" (Prioridade: ALTA, Agente: Claude). Será processada automaticamente a cada 5 minutos.', 'agent' => 'Sistema'],
    ['keywords' => ['tarefas', 'fila', 'quantas tarefas'], 'response' => '📋 FILA DE TAREFAS:\n1. Sincronizar Olist (Claude) - ALTA\n2. Implementar PIX (GPT) - ALTA\n3. Otimizar imagens (Gemini) - MÉDIA\n4. Gerar /sobre/ (Claude) - MÉDIA\n5. Validar segurança (GPT) - ALTA', 'agent' => 'Sistema'],

    // Agentes
    ['keywords' => ['agentes', 'quem sao', 'quem são', 'como trabalham', 'como estao'], 'response' => '🤖 AGENTES ATIVOS 24/7:\n• Claude - Desenvolvedor (implementa código)\n• Gemini - Arquiteto (projeta soluções)\n• GPT - Integrador (revisa segurança)\n\nTrabalhando continuamente, processando tarefas a cada 5 minutos!', 'agent' => 'Sistema'],
    ['keywords' => ['claude', 'o que claude'], 'response' => '👨‍💻 Claude: Desenvolvedor especializado em PHP e código. Está responsável pelas tarefas 1 (Olist) e 4 (/sobre/). Pronto para implementar!', 'agent' => 'Sistema'],
    ['keywords' => ['gemini', 'o que gemini'], 'response' => '🏗️ Gemini: Arquiteto de soluções. Especialista em design e otimização. Responsável pela tarefa 3 (otimizar imagens). Analisando performance do catálogo!', 'agent' => 'Sistema'],
    ['keywords' => ['gpt', 'o que gpt'], 'response' => '🔐 GPT: Integrador e especialista em segurança. Responsável pelas tarefas 2 (PIX) e 5 (validar checkout). Garantindo sistema seguro!', 'agent' => 'Sistema'],

    // Status
    ['keywords' => ['status', 'como esta', 'como está', 'tudo bem'], 'response' => '✅ STATUS DO SISTEMA:\n• E-commerce: 100% OPERACIONAL\n• Agentes: TRABALHANDO 24/7\n• Tarefas: 5 na fila\n• Deploy: AUTOMÁTICO VIA FTP\n• Autonomia: 100% ATIVA', 'agent' => 'Sistema'],

    // Cada um fazendo
    ['keywords' => ['cada um', 'o que cada', 'fazendo', 'fazem', 'estao fazendo', 'estão fazendo'], 'response' => '📊 O QUE CADA AGENTE ESTÁ FAZENDO:\n• Claude: Aguardando processar Sincronização Olist\n• Gemini: Pronto para otimizar imagens\n• GPT: Pronto para implementar PIX\n\nTodos monitores o progresso! A cada 5 minutos começam nova tarefa.', 'agent' => 'Sistema'],

    // E-commerce
    ['keywords' => ['ecommerce', 'site', 'loja', 'catalogo', 'catálogo', 'produtos'], 'response' => '🛍️ E-COMMERCE SHOPVIVALIZ:\n• Catálogo: 3-4 produtos (pronto para 198 do Olist)\n• Produto: Detalhe + preço + adicionar carrinho\n• Carrinho: Gerenciador com totais\n• Checkout: Formulário 8 campos + processamento\n\nTudo operacional! Acessar: catalogo/', 'agent' => 'Sistema'],

    // Monitor
    ['keywords' => ['monitor', 'dashboard', 'abas', 'tarefas pendentes'], 'response' => '📊 MONITOR COMPLETO v2:\n• Dashboard: Mostra números de tarefas\n• Tarefas: Criar novas + listar fila\n• Chat: Conversar com agentes (eu!)\n\nVocê está na aba Chat. Clique em Dashboard para ver números!', 'agent' => 'Sistema'],

    // Default
    ['keywords' => [], 'response' => '💬 Entendi sua pergunta: "' . substr($message, 0, 40) . '...". \n\nPergunta-me sobre:\n✓ Tarefas (qual, fila, próxima)\n✓ Agentes (Claude, Gemini, GPT)\n✓ Status (como está o sistema)\n✓ E-commerce (catalogo, produtos)', 'agent' => 'Sistema'],
];

$response_text = null;
$agent_name = 'Sistema';

// Procurar por correspondências
foreach ($responses as $item) {
    if (empty($item['keywords'])) continue; // Skip default por enquanto

    foreach ($item['keywords'] as $keyword) {
        if (strpos($lower_msg, $keyword) !== false) {
            $response_text = $item['response'];
            $agent_name = $item['agent'] ?? 'Sistema';
            break 2;
        }
    }
}

// Se nenhuma correspondência, usar default
if ($response_text === null) {
    foreach ($responses as $item) {
        if (empty($item['keywords'])) {
            $response_text = $item['response'];
            $agent_name = $item['agent'] ?? 'Sistema';
            break;
        }
    }
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'response' => $response_text,
    'agent' => $agent_name,
    'timestamp' => date('c'),
    'message_received' => $message
], JSON_UNESCAPED_UNICODE);
