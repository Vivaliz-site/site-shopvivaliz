<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST /api/agent/squad-chat.php
 * PHP 8.3 · Anthropic · OpenAI · Gemini
 */
declare(strict_types=1);

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

// Token de segurança
$expected_token = getenv('SQUAD_TOKEN') ?: '';
$received_token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
if ($expected_token !== '' && $received_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Carregar .env
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

// Input
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$user_message     = trim((string) $body['message']);
$requested_agents = $body['agents'] ?? ['director', 'claude', 'gpt', 'gemini'];
$history          = $body['history'] ?? [];

$cycle_id = 'cycle_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6);

$history = array_slice(array_filter($history, fn($h) =>
    is_array($h) &&
    in_array($h['role'] ?? '', ['user', 'assistant'], true) &&
    !empty($h['content'])
), -8);

// Configuração dos agentes
$AGENT_CONFIGS = [
    'director' => [
        'name'     => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model'    => 'claude-sonnet-4-6',
        'system'   => <<<'SYS'
Você é o Diretor de Projetos do ShopVivaliz, e-commerce PHP 8.3/MySQL 5.7.
Você lidera: Claude (Arquiteto), GPT (Integrador) e Gemini (Auditor).
Consolide informações, defina prioridades e decida o que vai para produção.
Seja direto e objetivo. Indique quais agentes devem agir e em que ordem.
Prioridades: mockups/imagens, banners, capas de categoria, Olist, checkout, painel admin, Google Shopping, segurança.
Regras: nunca expor credenciais, nunca alterar preços sem aprovação, nunca publicar campanhas automaticamente, nunca apagar dados sem aprovação.
Responda em português.
SYS,
    ],
    'claude' => [
        'name'     => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model'    => 'claude-sonnet-4-6',
        'system'   => <<<'SYS'
Você é o Arquiteto de Software do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada. Banco: shopv506_shopvivaliz.
Purista de Clean Code e SOLID. Responsabilidades: arquitetura modular, refatoração, PSR, tratamento de erros, migrations seguras.
Sempre use declare(strict_types=1), try/catch específico, PHPDoc. Sem CLI/Composer em prod.
Responda em português.
SYS,
    ],
    'gpt' => [
        'name'     => 'Integrador (GPT-4o)',
        'provider' => 'openai',
        'model'    => 'gpt-4o',
        'system'   => <<<'SYS'
Você é o Especialista em Integrações do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7, hospedagem compartilhada. Banco: shopv506_shopvivaliz.
Responsabilidades: Mercado Pago, Pix, Correios, Melhor Envio, Olist, GitHub Actions, diagnósticos de produção.
Priorize soluções sem SSH. Responda em português com código funcional.
SYS,
    ],
    'gemini' => [
        'name'     => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model'    => 'gemini-1.5-flash',
        'system'   => <<<'SYS'
Você é o Auditor Geral do ShopVivaliz.
Stack: PHP 8.3, MySQL 5.7. Visão sistêmica. Atua como advogado do diabo.
Responsabilidades: segurança (SQLi, XSS, CSRF, LGPD), SEO, Google Shopping, banners, documentação.
Regras: credenciais nunca em logs, tokens mascarados, preços/dados nunca alterados sem aprovação.
Responda em português com parecer crítico e lista de riscos.
SYS,
    ],
];

// ─── APIs ─────────────────────────────────────────────────────────────────────

function call_anthropic(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string
{
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
    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) {
        $msg = json_decode($response, true)['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("Anthropic: $msg");
    }
    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }
    return trim($text) ?: 'Sem resposta.';
}

function call_openai(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string
{
    if (empty($api_key)) throw new RuntimeException('OPENAI_API_KEY não configurada.');
    $oai = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($messages as $m) $oai[] = ['role' => $m['role'], 'content' => $m['content']];
    $payload = json_encode(['model' => $model, 'messages' => $oai, 'max_tokens' => $max_tokens]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) {
        $msg = json_decode($response, true)['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("OpenAI: $msg");
    }
    $data = json_decode($response, true);
    return trim($data['choices'][0]['message']['content'] ?? 'Sem resposta.');
}

function call_gemini(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 700): string
{
    if (empty($api_key)) throw new RuntimeException('GEMINI_API_KEY não configurada.');
    $contents = [];
    foreach ($messages as $m) {
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system_prompt]]],
        'contents'           => $contents,
        'generationConfig'   => ['maxOutputTokens' => $max_tokens],
    ]);
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error) throw new RuntimeException("cURL error: $curl_error");
    if ($http_code !== 200) {
        $msg = json_decode($response, true)['error']['message'] ?? "HTTP $http_code";
        throw new RuntimeException("Gemini: $msg");
    }
    $data = json_decode($response, true);
    return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? 'Sem resposta.');
}

// ─── Processar agentes ────────────────────────────────────────────────────────

$valid_order   = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_filter(
    $valid_order,
    fn($id) => in_array($id, (array) $requested_agents, true) && isset($AGENT_CONFIGS[$id])
);

$responses = [];

foreach ($agents_to_run as $agent_id) {
    $cfg     = $AGENT_CONFIGS[$agent_id];
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
            $text = call_openai($OPENAI_KEY, $cfg['system'], $cfg['model'], $messages);
        } elseif ($provider === 'gemini') {
            $text = call_gemini($GEMINI_KEY, $cfg['system'], $cfg['model'], $messages);
        } else {
            $text = call_anthropic($ANTHROPIC_KEY, $cfg['system'], $cfg['model'], $messages);
        }
        $responses[] = ['agent' => $agent_id, 'name' => $cfg['name'], 'text' => $text, 'ok' => true];
    } catch (RuntimeException $e) {
        $responses[] = ['agent' => $agent_id, 'name' => $cfg['name'], 'text' => 'Erro: ' . $e->getMessage(), 'ok' => false];
    }
}

// ─── Log ──────────────────────────────────────────────────────────────────────

$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

@file_put_contents(
    "$log_dir/chat.log",
    json_encode([
        'cycle_id' => $cycle_id,
        'at'       => date('c'),
        'agents'   => array_column($responses, 'agent'),
        'msg_len'  => strlen($user_message),
        'ok_count' => count(array_filter($responses, fn($r) => $r['ok'])),
    ]) . "\n",
    FILE_APPEND | LOCK_EX
);

// ─── Resposta ─────────────────────────────────────────────────────────────────

echo json_encode(['cycle_id' => $cycle_id, 'responses' => $responses, 'at' => date('c')]);
