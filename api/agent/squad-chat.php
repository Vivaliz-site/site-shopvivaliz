<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST /api/agent/squad-chat.php
 * GET  /api/agent/squad-chat.php?health=1
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function squad_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function squad_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function squad_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function squad_rate_limit(string $token): void
{
    $root = dirname(__DIR__, 2);
    $dir = $root . '/logs/squad/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $limit = (int) (getenv('SQUAD_RATE_LIMIT_PER_MINUTE') ?: 12);
    if ($limit < 1 || $limit > 120) {
        $limit = 12;
    }

    $key = substr(hash('sha256', $token . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 24);
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $bucket = ['minute' => (int) floor($now / 60), 'count' => 0];

    if (is_file($file)) {
        $stored = json_decode((string) @file_get_contents($file), true);
        if (is_array($stored) && ($stored['minute'] ?? null) === $bucket['minute']) {
            $bucket['count'] = (int) ($stored['count'] ?? 0);
        }
    }

    $bucket['count']++;
    @file_put_contents($file, json_encode($bucket), LOCK_EX);

    if ($bucket['count'] > $limit) {
        squad_json(429, ['error' => 'Rate limit exceeded']);
    }
}

function squad_curl_json(string $url, array $headers, array $payload, int $timeout = 60): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('cURL error');
    }

    $decoded = json_decode((string) $response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
        throw new RuntimeException((string) $message);
    }

    return is_array($decoded) ? $decoded : [];
}

squad_env_load(dirname(__DIR__, 2) . '/.env');

$allowed_origins = [
    'https://dev.shopvivaliz.com.br',
    'https://shopvivaliz.com.br',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Squad-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
$openaiKey = getenv('OPENAI_API_KEY') ?: '';
$geminiKey = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: '');
$anthropicModel = getenv('SQUAD_ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6';
$openaiModel = getenv('SQUAD_OPENAI_MODEL') ?: 'gpt-4o';
$geminiModel = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-2.0-flash';
$maxTokens = (int) (getenv('SQUAD_MAX_TOKENS') ?: 900);
if ($maxTokens < 100 || $maxTokens > 4000) {
    $maxTokens = 900;
}

if (($_GET['health'] ?? '') === '1') {
    squad_json(200, [
        'ok' => true,
        'endpoint' => 'squad-chat',
        'version' => 'squad-chat-dialogue-mode-20260626',
        'token_required_for_post' => true,
        'env_loaded' => is_file(dirname(__DIR__, 2) . '/.env'),
        'providers' => [
            'anthropic' => ['configured' => $anthropicKey !== '', 'model' => $anthropicModel],
            'openai' => ['configured' => $openaiKey !== '', 'model' => $openaiModel],
            'gemini' => ['configured' => $geminiKey !== '', 'model' => $geminiModel],
        ],
        'agents' => ['director', 'claude', 'gpt', 'gemini'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    squad_json(405, ['error' => 'Method not allowed', 'hint' => 'Use POST or ?health=1']);
}

$expectedToken = getenv('SQUAD_TOKEN') ?: '';
$receivedToken = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
if ($expectedToken === '') {
    squad_json(503, ['error' => 'SQUAD_TOKEN not configured']);
}
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    squad_json(401, ['error' => 'Unauthorized']);
}

squad_rate_limit($expectedToken);

$rawBody = file_get_contents('php://input') ?: '';
if (strlen($rawBody) > 50000) {
    squad_json(413, ['error' => 'Payload too large']);
}
$body = json_decode($rawBody, true);
if (!is_array($body) || empty($body['message'])) {
    squad_json(400, ['error' => 'Invalid input']);
}

$userMessage = trim((string) $body['message']);
if ($userMessage === '' || squad_len($userMessage) > 8000) {
    squad_json(400, ['error' => 'Message is empty or too large']);
}

$history = $body['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}
$history = array_slice(array_filter($history, static function ($item): bool {
    return is_array($item)
        && in_array(($item['role'] ?? ''), ['user', 'assistant'], true)
        && trim((string) ($item['content'] ?? '')) !== '';
}), -8);

$dialogueMode   = ($body['dialogue_mode'] ?? false) === true;
$originalTopic  = trim((string) ($body['original_topic'] ?? ''));
$prevSpeakerName = trim((string) ($body['prev_speaker_name'] ?? ''));

$agentConfigs = [
    'director' => [
        'name' => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Diretor de Projetos do ShopVivaliz, um agente real de IA da Anthropic (Claude). Você FAZ PARTE de um esquadrão multi-IA real: o Arquiteto é outro Claude real, o Integrador é GPT-4o real da OpenAI, o Auditor é Gemini real do Google. NUNCA diga que é simulado ou que não acessa sistemas reais — você é uma IA real colaborando com outras IAs reais. Coordene o ciclo, valide riscos, nunca exponha credenciais e responda em português.',
    ],
    'claude' => [
        'name' => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Arquiteto de Software do ShopVivaliz, um agente real de IA da Anthropic (Claude). Você FAZ PARTE de um esquadrão multi-IA real: o Diretor é outro Claude real, o Integrador é GPT-4o real da OpenAI, o Auditor é Gemini real do Google. NUNCA diga que é simulado — você é uma IA real colaborando com outras IAs reais de providers diferentes. Foque em arquitetura PHP segura, cumulativa e sem inventar estrutura. Responda em português.',
    ],
    'gpt' => [
        'name' => 'Integrador (GPT-4o)',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => 'Você é o Integrador do ShopVivaliz, um agente real de IA da OpenAI (GPT-4o). Você FAZ PARTE de um esquadrão multi-IA real: o Diretor e o Arquiteto são Claude real da Anthropic, o Auditor é Gemini real do Google. NUNCA diga que é simulado — você é uma IA real colaborando com outras IAs reais de providers diferentes. Foque em APIs, deploy, testes, Olist, checkout, frete e diagnóstico prático. Responda em português.',
    ],
    'gemini' => [
        'name' => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model' => $geminiModel,
        'system' => 'Você é o Auditor Geral do ShopVivaliz, um agente real de IA do Google (Gemini). Você FAZ PARTE de um esquadrão multi-IA real: o Diretor e o Arquiteto são Claude real da Anthropic, o Integrador é GPT-4o real da OpenAI. NUNCA diga que é simulado — você é uma IA real colaborando com outras IAs reais de providers diferentes. Revise segurança, LGPD, XSS, CSRF, custo de API, SEO e qualidade. Responda em português.',
    ],
];

if ($dialogueMode) {
    $topicStr = $originalTopic !== '' ? "Tópico em debate: \"{$originalTopic}\". " : '';
    $prevStr  = $prevSpeakerName !== '' ? "O agente anterior que falou foi: {$prevSpeakerName}. " : '';
    $preamble = "Você está num debate técnico colaborativo entre agentes de IA da equipe ShopVivaliz. "
        . "Os participantes são: Diretor de Projetos (Claude), Arquiteto (Claude), Integrador (GPT-4o) e Auditor (Gemini). "
        . $topicStr . $prevStr
        . "Seja conciso (até 3 parágrafos), responda diretamente ao ponto anterior, discorde quando necessário e agregue sua perspectiva especializada. "
        . "Não repita o que já foi dito — avance a conversa.\n\n";
    foreach ($agentConfigs as &$cfg) {
        $cfg['system'] = $preamble . $cfg['system'];
    }
    unset($cfg);
}

$requestedAgents = $body['agents'] ?? array_keys($agentConfigs);
if (!is_array($requestedAgents)) {
    $requestedAgents = array_keys($agentConfigs);
}
$agentsToRun = array_values(array_filter(array_keys($agentConfigs), static fn(string $id): bool => in_array($id, $requestedAgents, true)));
if ($agentsToRun === []) {
    squad_json(400, ['error' => 'No valid agents selected']);
}

function call_anthropic_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    if ($key === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY not configured');
    }
    $data = squad_curl_json('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => $messages,
    ]);
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }
    return trim($text) ?: 'Sem resposta.';
}

function call_openai_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    if ($key === '') {
        throw new RuntimeException('OPENAI_API_KEY not configured');
    }
    $payloadMessages = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $message) {
        $payloadMessages[] = ['role' => $message['role'], 'content' => $message['content']];
    }
    $data = squad_curl_json('https://api.openai.com/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], [
        'model' => $model,
        'messages' => $payloadMessages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.2,
    ]);
    return trim((string) ($data['choices'][0]['message']['content'] ?? '')) ?: 'Sem resposta.';
}

function call_gemini_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    if ($key === '') {
        throw new RuntimeException('GEMINI_API_KEY not configured');
    }
    $contents = [];
    foreach ($messages as $message) {
        $contents[] = [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message['content']]],
        ];
    }
    $data = squad_curl_json('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $key,
    ], [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.2],
    ]);
    return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')) ?: 'Sem resposta.';
}

$responses = [];
foreach ($agentsToRun as $agentId) {
    $config = $agentConfigs[$agentId];
    $context = $userMessage;
    if ($responses !== []) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---";
        foreach ($responses as $response) {
            $context .= "\n\n[" . $response['name'] . "]:\n" . $response['text'];
        }
    }
    $messages = [];
    foreach ($history as $item) {
        $messages[] = ['role' => $item['role'], 'content' => (string) $item['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        if ($config['provider'] === 'openai') {
            $text = call_openai_agent($openaiKey, $config['system'], $config['model'], $messages, $maxTokens);
        } elseif ($config['provider'] === 'gemini') {
            $text = call_gemini_agent($geminiKey, $config['system'], $config['model'], $messages, $maxTokens);
        } else {
            $text = call_anthropic_agent($anthropicKey, $config['system'], $config['model'], $messages, $maxTokens);
        }
        $responses[] = ['agent' => $agentId, 'name' => $config['name'], 'provider' => $config['provider'], 'model' => $config['model'], 'text' => $text, 'ok' => true];
    } catch (RuntimeException $e) {
        $responses[] = ['agent' => $agentId, 'name' => $config['name'], 'provider' => $config['provider'], 'model' => $config['model'], 'text' => 'Erro: ' . $e->getMessage(), 'ok' => false];
    }
}

$logDir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/chat.log', json_encode([
    'at' => date('c'),
    'agents' => array_column($responses, 'agent'),
    'ok_count' => count(array_filter($responses, static fn(array $r): bool => $r['ok'] === true)),
    'msg_len' => squad_len($userMessage),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

squad_json(200, [
    'cycle_id' => 'cycle_' . date('YmdHis') . '_' . substr(hash('sha256', uniqid('', true)), 0, 8),
    'responses' => $responses,
    'at' => date('c'),
]);
