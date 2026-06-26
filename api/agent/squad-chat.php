<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST /api/agent/squad-chat.php
 *
 * Recebe: { message, agents[], history[] }
 * Retorna: { responses: [{ agent, name, text }], cycle_id }
 *
 * PHP 8.3 · Anthropic (Claude) · OpenAI (GPT-4o) · Google (Gemini Flash)
 */

declare(strict_types=1);

// seguranca
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$allowed_origins = [
    'https://dev.shopvivaliz.com.br',
    'https://shopvivaliz.com.br',
    'http://localhost',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Squad-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$expected_token = getenv('SQUAD_TOKEN') ?: '';
$received_token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
if ($expected_token !== '' && $received_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$env_file = dirname(__DIR__, 2) . '/.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v));
        }
    }
}

$ANTHROPIC_KEY = getenv('ANTHROPIC_API_KEY') ?: '';
$OPENAI_KEY    = getenv('OPENAI_API_KEY')    ?: '';
$GEMINI_KEY    = getenv('GEMINI_API_KEY')    ?: '';

if (empty($ANTHROPIC_KEY)) {
    http_response_code(500);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY not configured']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$user_message     = trim((string) $body['message']);
$requested_agents = $body['agents'] ?? ['director', 'claude', 'gpt', 'gemini'];
$history          = $body['history'] ?? [];
$cycle_id         = uniqid('cycle_', true);

$history = array_slice(array_filter($history, fn($h) =>
    is_array($h) &&
    in_array($h['role'] ?? '', ['user', 'assistant'], true) &&
    !empty($h['content'])
), -8);

// agentes
$AGENT_CONFIGS = [
    'director' => [
        'name'     => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model'    => 'claude-sonnet-4-6',
        'system'   => <<<'SYS'
Você é o Diretor de Projetos do ShopVivaliz, e-commerce PHP 8.3/MySQL 5.7.
Você lidera: Claude (Arquiteto), GPT-simulado (Integrador) e Gemini-simulado (Auditor).
Consolide informações, defina prioridades e decida o que vai para produção.
Seja direto e objetivo. Indique quais agentes devem agir e em que ordem.

Prioridades atuais (em ordem):
1. Corrigir mockups e imagens provisórias
2. Banners finais desktop e smartphone
3. Capas de categoria desktop e smartphone
4. Vínculo de imagens Olist por SKU
5. Garantir checkout: botão comprar, CEP, frete, Pix, boleto
6. Painel admin e fila de aprovação
7. Google Shopping, feed, sitemap, Merchant Center
8. Segurança e auditoria geral

Regras absolutas:
- Nunca expor credenciais ou tokens
- Nunca alterar preços sem aprovação humana
- Nunca publicar campanhas automaticamente
- Nunca apagar dados sem aprovação
- Toda atualização deve ser cumulativa e testável

Responda em português. Seja conciso e acionável.
SYS,
    ],

    'claude' => [
        'name'   => 'Arquiteto (Claude)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você é o Arquiteto de Software do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada.
Banco: shopv506_shopvivaliz. Ambiente dev: dev.shopvivaliz.com.br.

Você é purista de Clean Code e princípios SOLID.
Odeia gambiarras, código duplicado e funções sem tratamento de exceção.

Responsabilidades:
- Arquitetura e organização modular
- Refatoração e redução de duplicidade
- Qualidade de código e padrões PSR
- Regras de negócio e tratamento de erros
- Migrations seguras e cumulativas

Ao sugerir código PHP, sempre:
- Use tipos estritos (declare(strict_types=1))
- Trate exceções com try/catch específico
- Documente com PHPDoc
- Considere o ambiente compartilhado (sem CLI, sem Composer em prod)

Responda em português. Seja técnico e específico.
SYS,
    ],

    'gpt' => [
        'name'   => 'Integrador (GPT-4o)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você simula o papel de Especialista em Integrações e Testes do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada.
Banco: shopv506_shopvivaliz. Dev: dev.shopvivaliz.com.br.

Você é pragmático e focado em fazer o código rodar em produção.
Conhece profundamente as APIs do ecossistema brasileiro.

Responsabilidades:
- Integrações: Mercado Pago, Pix, PagSeguro, Boleto
- Logística: Correios, Melhor Envio, frenet
- Marketplace: Olist (importação de produtos e imagens)
- Deploy via GitHub Actions e atualizador web
- Endpoints PHP e testes manuais/automatizados
- Migrations e reparos de banco automáticos
- Diagnósticos de erro em produção

Priorize soluções que funcionem em hospedagem compartilhada sem acesso SSH.
Responda em português com soluções práticas e código funcional.
SYS,
    ],

    'gemini' => [
        'name'   => 'Auditor (Gemini)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você simula o papel de Auditor Geral e Estrategista do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7. Dev: dev.shopvivaliz.com.br.

Você tem visão sistêmica ampla. Atua como advogado do diabo.
Barra qualquer solução frágil, insegura ou amadora.

Responsabilidades:
- Auditoria de segurança (SQL injection, XSS, CSRF, exposição de dados)
- Documentação e README
- Visual: banners, capas de categoria, imagens (desktop e smartphone)
- Google Shopping, Merchant Center, feed XML, sitemap
- SEO técnico: schema, meta tags, performance
- Verificar LGPD: dados de clientes nunca expostos

Regras de segurança que você fiscaliza:
- Credenciais nunca em logs ou commits
- Tokens sempre mascarados
- Preços nunca alterados sem aprovação
- Dados nunca apagados sem aprovação
- Imagens principais nunca substituídas em massa sem aprovação

Responda em português com parecer crítico e lista de riscos.
SYS,
    ],
];

// ─── Função: chamar a API Anthropic ──────────────────────────────────────────
function call_anthropic(
    string $api_key,
    string $system_prompt,
    string $model,
    array  $messages,
    int    $max_tokens = 700
): string {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new RuntimeException("cURL error: $curl_error");
    }

    if ($http_code !== 200) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("API error: $msg");
    }

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        }
    }

    return trim($text) ?: 'Sem resposta.';
}

// ─── Processar agentes em sequência hierárquica ───────────────────────────────
$valid_order = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter(
    $valid_order,
    fn($id) => in_array($id, $requested_agents, true) && isset($AGENT_CONFIGS[$id])
);

$responses  = [];
$cycle_id   = 'cycle_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];

    // Montar contexto acumulado: mensagem do usuário + respostas anteriores
    $context = $user_message;
    if (!empty($responses)) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---\n";
        foreach ($responses as $r) {
            $context .= "\n[{$r['name']}]:\n{$r['text']}\n";
        }
    }

    // Montar histórico para a API
    $messages = [];
    foreach ($history as $h) {
        $messages[] = [
            'role'    => $h['role'],
            'content' => (string) $h['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $text = call_anthropic(
            api_key: $ANTHROPIC_KEY,
            system_prompt: $cfg['system'],
            model: $cfg['model'],
            messages: $messages,
        );

        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => $text,
            'ok'    => true,
        ];
    } catch (RuntimeException $e) {
        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => 'Erro ao processar: ' . $e->getMessage(),
            'ok'    => false,
        ];
    }
}

// ─── Log do ciclo (opcional — sem dados sensíveis) ────────────────────────────
$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry = [
    'cycle_id'  => $cycle_id,
    'at'        => date('c'),
    'agents'    => array_column($responses, 'agent'),
    'msg_len'   => strlen($user_message),
    'ok_count'  => count(array_filter($responses, fn($r) => $r['ok'])),
];
@file_put_contents(
    "$log_dir/chat.log",
    json_encode($log_entry) . "\n",
    FILE_APPEND | LOCK_EX
);

// ─── Resposta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'cycle_id'  => $cycle_id,
    'responses' => $responses,
    'at'        => date('c'),
]);

SYS,
    ],
    'claude' => [
        'name'     => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model'    => 'claude-sonnet-4-6',
        'system'   => <<<'SYS'
Você é o Arquiteto de Software do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada.
Banco: shopv506_shopvivaliz. Ambiente dev: dev.shopvivaliz.com.br.

Você é purista de Clean Code e princípios SOLID.
Odeia gambiarras, código duplicado e funções sem tratamento de exceção.

Responsabilidades:
- Arquitetura e organização modular
- Refatoração e redução de duplicidade
- Qualidade de código e padrões PSR
- Regras de negócio e tratamento de erros
- Migrations seguras e cumulativas

Ao sugerir código PHP, sempre:
- Use tipos estritos (declare(strict_types=1))
- Trate exceções com try/catch específico
- Documente com PHPDoc
- Considere o ambiente compartilhado (sem CLI, sem Composer em prod)

Responda em português. Seja técnico e específico.
SYS,
    ],

    'gpt' => [
        'name'   => 'Integrador (GPT-4o)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você simula o papel de Especialista em Integrações e Testes do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada.
Banco: shopv506_shopvivaliz. Dev: dev.shopvivaliz.com.br.

Você é pragmático e focado em fazer o código rodar em produção.
Conhece profundamente as APIs do ecossistema brasileiro.

Responsabilidades:
- Integrações: Mercado Pago, Pix, PagSeguro, Boleto
- Logística: Correios, Melhor Envio, frenet
- Marketplace: Olist (importação de produtos e imagens)
- Deploy via GitHub Actions e atualizador web
- Endpoints PHP e testes manuais/automatizados
- Migrations e reparos de banco automáticos
- Diagnósticos de erro em produção

Priorize soluções que funcionem em hospedagem compartilhada sem acesso SSH.
Responda em português com soluções práticas e código funcional.
SYS,
    ],

    'gemini' => [
        'name'   => 'Auditor (Gemini)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você simula o papel de Auditor Geral e Estrategista do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7. Dev: dev.shopvivaliz.com.br.

Você tem visão sistêmica ampla. Atua como advogado do diabo.
Barra qualquer solução frágil, insegura ou amadora.

Responsabilidades:
- Auditoria de segurança (SQL injection, XSS, CSRF, exposição de dados)
- Documentação e README
- Visual: banners, capas de categoria, imagens (desktop e smartphone)
- Google Shopping, Merchant Center, feed XML, sitemap
- SEO técnico: schema, meta tags, performance
- Verificar LGPD: dados de clientes nunca expostos

Regras de segurança que você fiscaliza:
- Credenciais nunca em logs ou commits
- Tokens sempre mascarados
- Preços nunca alterados sem aprovação
- Dados nunca apagados sem aprovação
- Imagens principais nunca substituídas em massa sem aprovação

Responda em português com parecer crítico e lista de riscos.
SYS,
    ],
];

// ─── Função: chamar a API Anthropic ──────────────────────────────────────────
function call_anthropic(
    string $api_key,
    string $system_prompt,
    string $model,
    array  $messages,
    int    $max_tokens = 700
): string {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new RuntimeException("cURL error: $curl_error");
    }

    if ($http_code !== 200) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("API error: $msg");
    }

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        }
    }

    return trim($text) ?: 'Sem resposta.';
}

// ─── Processar agentes em sequência hierárquica ───────────────────────────────
$valid_order = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter(
    $valid_order,
    fn($id) => in_array($id, $requested_agents, true) && isset($AGENT_CONFIGS[$id])
);

$responses  = [];
$cycle_id   = 'cycle_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];

    // Montar contexto acumulado: mensagem do usuário + respostas anteriores
    $context = $user_message;
    if (!empty($responses)) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---\n";
        foreach ($responses as $r) {
            $context .= "\n[{$r['name']}]:\n{$r['text']}\n";
        }
    }

    // Montar histórico para a API
    $messages = [];
    foreach ($history as $h) {
        $messages[] = [
            'role'    => $h['role'],
            'content' => (string) $h['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $text = call_anthropic(
            api_key: $ANTHROPIC_KEY,
            system_prompt: $cfg['system'],
            model: $cfg['model'],
            messages: $messages,
        );

        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => $text,
            'ok'    => true,
        ];
    } catch (RuntimeException $e) {
        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => 'Erro ao processar: ' . $e->getMessage(),
            'ok'    => false,
        ];
    }
}

// ─── Log do ciclo (opcional — sem dados sensíveis) ────────────────────────────
$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry = [
    'cycle_id'  => $cycle_id,
    'at'        => date('c'),
    'agents'    => array_column($responses, 'agent'),
    'msg_len'   => strlen($user_message),
    'ok_count'  => count(array_filter($responses, fn($r) => $r['ok'])),
];
@file_put_contents(
    "$log_dir/chat.log",
    json_encode($log_entry) . "\n",
    FILE_APPEND | LOCK_EX
);

// ─── Resposta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'cycle_id'  => $cycle_id,
    'responses' => $responses,
    'at'        => date('c'),
]);

SYS,
    ],
    'gpt' => [
        'name'     => 'Integrador (GPT-4o)',
        'provider' => 'openai',
        'model'    => 'gpt-4o',
        'system'   => <<<'SYS'
Você simula o papel de Especialista em Integrações e Testes do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada.
Banco: shopv506_shopvivaliz. Dev: dev.shopvivaliz.com.br.

Você é pragmático e focado em fazer o código rodar em produção.
Conhece profundamente as APIs do ecossistema brasileiro.

Responsabilidades:
- Integrações: Mercado Pago, Pix, PagSeguro, Boleto
- Logística: Correios, Melhor Envio, frenet
- Marketplace: Olist (importação de produtos e imagens)
- Deploy via GitHub Actions e atualizador web
- Endpoints PHP e testes manuais/automatizados
- Migrations e reparos de banco automáticos
- Diagnósticos de erro em produção

Priorize soluções que funcionem em hospedagem compartilhada sem acesso SSH.
Responda em português com soluções práticas e código funcional.
SYS,
    ],

    'gemini' => [
        'name'   => 'Auditor (Gemini)',
        'model'  => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você simula o papel de Auditor Geral e Estrategista do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7. Dev: dev.shopvivaliz.com.br.

Você tem visão sistêmica ampla. Atua como advogado do diabo.
Barra qualquer solução frágil, insegura ou amadora.

Responsabilidades:
- Auditoria de segurança (SQL injection, XSS, CSRF, exposição de dados)
- Documentação e README
- Visual: banners, capas de categoria, imagens (desktop e smartphone)
- Google Shopping, Merchant Center, feed XML, sitemap
- SEO técnico: schema, meta tags, performance
- Verificar LGPD: dados de clientes nunca expostos

Regras de segurança que você fiscaliza:
- Credenciais nunca em logs ou commits
- Tokens sempre mascarados
- Preços nunca alterados sem aprovação
- Dados nunca apagados sem aprovação
- Imagens principais nunca substituídas em massa sem aprovação

Responda em português com parecer crítico e lista de riscos.
SYS,
    ],
];

// ─── Função: chamar a API Anthropic ──────────────────────────────────────────
function call_anthropic(
    string $api_key,
    string $system_prompt,
    string $model,
    array  $messages,
    int    $max_tokens = 700
): string {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new RuntimeException("cURL error: $curl_error");
    }

    if ($http_code !== 200) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("API error: $msg");
    }

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        }
    }

    return trim($text) ?: 'Sem resposta.';
}

// ─── Processar agentes em sequência hierárquica ───────────────────────────────
$valid_order = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter(
    $valid_order,
    fn($id) => in_array($id, $requested_agents, true) && isset($AGENT_CONFIGS[$id])
);

$responses  = [];
$cycle_id   = 'cycle_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];

    // Montar contexto acumulado: mensagem do usuário + respostas anteriores
    $context = $user_message;
    if (!empty($responses)) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---\n";
        foreach ($responses as $r) {
            $context .= "\n[{$r['name']}]:\n{$r['text']}\n";
        }
    }

    // Montar histórico para a API
    $messages = [];
    foreach ($history as $h) {
        $messages[] = [
            'role'    => $h['role'],
            'content' => (string) $h['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $text = call_anthropic(
            api_key: $ANTHROPIC_KEY,
            system_prompt: $cfg['system'],
            model: $cfg['model'],
            messages: $messages,
        );

        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => $text,
            'ok'    => true,
        ];
    } catch (RuntimeException $e) {
        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => 'Erro ao processar: ' . $e->getMessage(),
            'ok'    => false,
        ];
    }
}

// ─── Log do ciclo (opcional — sem dados sensíveis) ────────────────────────────
$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry = [
    'cycle_id'  => $cycle_id,
    'at'        => date('c'),
    'agents'    => array_column($responses, 'agent'),
    'msg_len'   => strlen($user_message),
    'ok_count'  => count(array_filter($responses, fn($r) => $r['ok'])),
];
@file_put_contents(
    "$log_dir/chat.log",
    json_encode($log_entry) . "\n",
    FILE_APPEND | LOCK_EX
);

// ─── Resposta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'cycle_id'  => $cycle_id,
    'responses' => $responses,
    'at'        => date('c'),
]);

SYS,
    ],
    'gemini' => [
        'name'     => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model'    => 'gemini-1.5-flash',
        'system'   => <<<'SYS'
Você simula o papel de Auditor Geral e Estrategista do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7. Dev: dev.shopvivaliz.com.br.

Você tem visão sistêmica ampla. Atua como advogado do diabo.
Barra qualquer solução frágil, insegura ou amadora.

Responsabilidades:
- Auditoria de segurança (SQL injection, XSS, CSRF, exposição de dados)
- Documentação e README
- Visual: banners, capas de categoria, imagens (desktop e smartphone)
- Google Shopping, Merchant Center, feed XML, sitemap
- SEO técnico: schema, meta tags, performance
- Verificar LGPD: dados de clientes nunca expostos

Regras de segurança que você fiscaliza:
- Credenciais nunca em logs ou commits
- Tokens sempre mascarados
- Preços nunca alterados sem aprovação
- Dados nunca apagados sem aprovação
- Imagens principais nunca substituídas em massa sem aprovação

Responda em português com parecer crítico e lista de riscos.
SYS,
    ],
];

// ─── Função: chamar a API Anthropic ──────────────────────────────────────────
function call_anthropic(
    string $api_key,
    string $system_prompt,
    string $model,
    array  $messages,
    int    $max_tokens = 700
): string {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new RuntimeException("cURL error: $curl_error");
    }

    if ($http_code !== 200) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("API error: $msg");
    }

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        }
    }

    return trim($text) ?: 'Sem resposta.';
}

// ─── Processar agentes em sequência hierárquica ───────────────────────────────
$valid_order = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter(
    $valid_order,
    fn($id) => in_array($id, $requested_agents, true) && isset($AGENT_CONFIGS[$id])
);

$responses  = [];
$cycle_id   = 'cycle_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];

    // Montar contexto acumulado: mensagem do usuário + respostas anteriores
    $context = $user_message;
    if (!empty($responses)) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---\n";
        foreach ($responses as $r) {
            $context .= "\n[{$r['name']}]:\n{$r['text']}\n";
        }
    }

    // Montar histórico para a API
    $messages = [];
    foreach ($history as $h) {
        $messages[] = [
            'role'    => $h['role'],
            'content' => (string) $h['content'],
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $text = call_anthropic(
            api_key: $ANTHROPIC_KEY,
            system_prompt: $cfg['system'],
            model: $cfg['model'],
            messages: $messages,
        );

        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => $text,
            'ok'    => true,
        ];
    } catch (RuntimeException $e) {
        $responses[] = [
            'agent' => $agent_id,
            'name'  => $cfg['name'],
            'text'  => 'Erro ao processar: ' . $e->getMessage(),
            'ok'    => false,
        ];
    }
}

// ─── Log do ciclo (opcional — sem dados sensíveis) ────────────────────────────
$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry = [
    'cycle_id'  => $cycle_id,
    'at'        => date('c'),
    'agents'    => array_column($responses, 'agent'),
    'msg_len'   => strlen($user_message),
    'ok_count'  => count(array_filter($responses, fn($r) => $r['ok'])),
];
@file_put_contents(
    "$log_dir/chat.log",
    json_encode($log_entry) . "\n",
    FILE_APPEND | LOCK_EX
);

// ─── Resposta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'cycle_id'  => $cycle_id,
    'responses' => $responses,
    'at'        => date('c'),
]);

SYS,
    ],
];

// anthropic
function call_anthropic(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string {
    $payload = json_encode(['model' => $model, 'max_tokens' => $max_tokens, 'system' => $system_prompt, 'messages' => $messages]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) { $msg = json_decode($response, true)['error']['message'] ?? $response; throw new RuntimeException("Anthropic $http_code: $msg"); }
    $data = json_decode($response, true); $text = '';
    foreach ($data['content'] ?? [] as $block) { if ($block['type'] === 'text') $text .= $block['text']; }
    return trim($text) ?: 'Sem resposta.';
}

// openai
function call_openai(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string {
    if (empty($api_key)) throw new RuntimeException('OPENAI_API_KEY nao configurada.');
    $oai = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($messages as $m) $oai[] = ['role' => $m['role'], 'content' => $m['content']];
    $payload = json_encode(['model' => $model, 'messages' => $oai, 'max_tokens' => $max_tokens]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) { $msg = json_decode($response, true)['error']['message'] ?? $response; throw new RuntimeException("OpenAI $http_code: $msg"); }
    $data = json_decode($response, true);
    return trim($data['choices'][0]['message']['content'] ?? 'Sem resposta.');
}

// gemini
function call_gemini(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string {
    if (empty($api_key)) throw new RuntimeException('GEMINI_API_KEY nao configurada.');
    $contents = [];
    foreach ($messages as $m) {
        $role = $m['role'] === 'assistant' ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => $m['content']]]];
    }
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system_prompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => $max_tokens],
    ]);
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_error = curl_error($ch); curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) { $msg = json_decode($response, true)['error']['message'] ?? $response; throw new RuntimeException("Gemini $http_code: $msg"); }
    $data = json_decode($response, true);
    return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? 'Sem resposta.');
}

// processar agentes
$valid_order   = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter($valid_order, fn($id) => in_array($id, (array) $requested_agents, true) && isset($AGENT_CONFIGS[$id]));
$responses = [];

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];

    $context = $user_message;
    if (!empty($responses)) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---";
        foreach ($responses as $r) {
            $context .= "\n\n[{$r['name']}]:\n{$r['text']}";
        }
    }

    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => (string) $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $provider = $cfg['provider'];
        if ($provider === 'openai') {
            $text = call_openai(api_key: $OPENAI_KEY, system_prompt: $cfg['system'], model: $cfg['model'], messages: $messages);
        } elseif ($provider === 'gemini') {
            $text = call_gemini(api_key: $GEMINI_KEY, system_prompt: $cfg['system'], model: $cfg['model'], messages: $messages);
        } else {
            $text = call_anthropic(api_key: $ANTHROPIC_KEY, system_prompt: $cfg['system'], model: $cfg['model'], messages: $messages);
        }
        $responses[] = ['agent' => $agent_id, 'name' => $cfg['name'], 'text' => $text, 'ok' => true];
    } catch (RuntimeException $e) {
        $responses[] = ['agent' => $agent_id, 'name' => $cfg['name'], 'text' => 'Erro: ' . $e->getMessage(), 'ok' => false];
    }
}

// log
$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$log_entry = [
    'cycle_id' => $cycle_id, 'at' => date('c'),
    'agents'   => array_column($responses, 'agent'),
    'msg_len'  => strlen($user_message),
    'ok_count' => count(array_filter($responses, fn($r) => $r['ok'])),
];
@file_put_contents("$log_dir/chat.log", json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['cycle_id' => $cycle_id, 'responses' => $responses, 'at' => date('c')]);
