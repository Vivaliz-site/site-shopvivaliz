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

    $bucket = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . substr(hash('sha256', $token), 0, 12));
    $file = $dir . '/' . $bucket . '.json';
    $now = time();
    $window = 60;
    $limit = (int) (getenv('SQUAD_RATE_LIMIT_PER_MINUTE') ?: 12);
    if ($limit < 1) {
        $limit = 12;
    }

    $state = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }

    if (($now - (int) $state['start']) >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }

    $state['count'] = (int) $state['count'] + 1;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['count'] > $limit) {
        squad_json(429, ['error' => 'Rate limit exceeded']);
    }
}

squad_env_load(dirname(__DIR__, 2) . '/.env');

$allowed_origins = array_filter(array_map('trim', explode(',', getenv('SQUAD_ALLOWED_ORIGINS') ?: 'https://dev.shopvivaliz.com.br,https://shopvivaliz.com.br')));
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

$expected_token = getenv('SQUAD_TOKEN') ?: '';
$received_token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';

if ($expected_token === '') {
    squad_json(503, ['error' => 'SQUAD_TOKEN not configured']);
}
if ($received_token === '' || !hash_equals($expected_token, $received_token)) {
    squad_json(401, ['error' => 'Unauthorized']);
}

$anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
$openaiKey = getenv('OPENAI_API_KEY') ?: '';
$geminiKey = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: '');

$anthropicModel = getenv('SQUAD_ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6';
$openaiModel = getenv('SQUAD_OPENAI_MODEL') ?: 'gpt-4o';
$geminiModel = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-1.5-flash';
$maxTokens = (int) (getenv('SQUAD_MAX_TOKENS') ?: 900);
if ($maxTokens < 100 || $maxTokens > 4000) {
    $maxTokens = 900;
}

$AGENT_CONFIGS = [
    'director' => [
        'name' => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Diretor de Projetos do ShopVivaliz. Coordene o ciclo com foco em segurança, execução cumulativa e validação antes de qualquer mudança. Nunca exponha credenciais, nunca altere preços sem aprovação, nunca publique campanhas automaticamente e nunca apague dados sem aprovação. Responda em português com decisão objetiva e próximos passos.',
    ],
    'claude' => [
        'name' => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Arquiteto de Software do ShopVivaliz. Foque em PHP 8.3, organização, segurança, tratamento de erro, código cumulativo e compatibilidade com hospedagem compartilhada. Não invente estrutura sem evidência. Responda em português.',
    ],
    'gpt' => [
        'name' => 'Integrador (GPT)',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => 'Você é o Integrador do ShopVivaliz. Foque em APIs, deploy, GitHub Actions, testes, diagnóstico prático, Olist, checkout, frete, Pix e boleto. Proponha passos executáveis e seguros para dev antes de produção. Responda em português.',
    ],
    'gemini' => [
        'name' => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model' => $geminiModel,
        'system' => 'Você é o Auditor Geral do ShopVivaliz. Atue como advogado do diabo. Revise segurança, LGPD, XSS, CSRF, exposição de credenciais, custos de API, SEO, documentação e qualidade visual. Bloqueie soluções frágeis ou incompletas. Responda em português.',
    ],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (($_GET['health'] ?? '') !== '1') {
        squad_json(404, ['error' => 'Not found']);
    }
    squad_json(200, [
        'ok' => true,
        'endpoint' => 'squad-chat',
        'token_required' => true,
        'providers' => [
            'anthropic' => ['configured' => $anthropicKey !== '', 'model' => $anthropicModel],
            'openai' => ['configured' => $openaiKey !== '', 'model' => $openaiModel],
            'gemini' => ['configured' => $geminiKey !== '', 'model' => $geminiModel],
        ],
        'agents' => array_keys($AGENT_CONFIGS),
        'allowed_origins' => array_values($allowed_origins),
        'rate_limit_per_minute' => (int) (getenv('SQUAD_RATE_LIMIT_PER_MINUTE') ?: 12),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    squad_json(405, ['error' => 'Method not allowed']);
}

squad_rate_limit($expected_token);

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

$requestedAgents = $body['agents'] ?? ['director', 'claude', 'gpt', 'gemini'];
if (!is_array($requestedAgents)) {
    $requestedAgents = ['director', 'claude', 'gpt', 'gemini'];
}

$history = $body['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}
$history = array_slice(array_filter($history, static function ($item): bool {
    if (!is_array($item)) {
        return false;
    }
    $role = $item['role'] ?? '';
    $content = (string) ($item['content'] ?? '');
    return in_array($role, ['user', 'assistant'], true) && $content !== '' && squad_len($content) <= 8000;
}), -8);

function squad_required_key(string $provider, string $anthropic, string $openai, string $gemini): string
{
    return match ($provider) {
        'openai' => $openai,
        'gemini' => $gemini,
        default => $anthropic,
    };
}

function squad_curl_json(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('cURL error.');
    }
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException($msg);
    }
    return $decoded;
}

function call_anthropic(string $apiKey, string $systemPrompt, string $model, array $messages, int $maxTokens): string
{
    if ($apiKey === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
    }
    $data = squad_curl_json('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ], [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
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

function call_openai(string $apiKey, string $systemPrompt, string $model, array $messages, int $maxTokens): string
{
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY não configurada.');
    }
    $oai = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($messages as $message) {
        $oai[] = ['role' => $message['role'], 'content' => $message['content']];
    }
    $data = squad_curl_json('https://api.openai.com/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], [
        'model' => $model,
        'messages' => $oai,
        'max_tokens' => $maxTokens,
        'temperature' => 0.2,
    ]);
    return trim((string) ($data['choices'][0]['message']['content'] ?? '')) ?: 'Sem resposta.';
}

function call_gemini(string $apiKey, string $systemPrompt, string $model, array $messages, int $maxTokens): string
{
    if ($apiKey === '') {
        throw new RuntimeException('GEMINI_API_KEY/GOOGLE_API_KEY não configurada.');
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
        'x-goog-api-key: ' . $apiKey,
    ], [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.2],
    ]);
    return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')) ?: 'Sem resposta.';
}

$validOrder = ['director', 'claude', 'gpt', 'gemini'];
$agentsToRun = array_values(array_filter($validOrder, static fn(string $id): bool => in_array($id, $requestedAgents, true) && isset($AGENT_CONFIGS[$id])));
if ($agentsToRun === []) {
    squad_json(400, ['error' => 'No valid agents selected']);
}

$cycleId = 'cycle_' . date('YmdHis') . '_' . substr(hash('sha256', uniqid('', true)), 0, 8);
$responses = [];

foreach ($agentsToRun as $agentId) {
    $cfg = $AGENT_CONFIGS[$agentId];
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
        $provider = (string) $cfg['provider'];
        $apiKey = squad_required_key($provider, $anthropicKey, $openaiKey, $geminiKey);
        if ($provider === 'openai') {
            $text = call_openai($apiKey, $cfg['system'], $cfg['model'], $messages, $maxTokens);
        } elseif ($provider === 'gemini') {
            $text = call_gemini($apiKey, $cfg['system'], $cfg['model'], $messages, $maxTokens);
        } else {
            $text = call_anthropic($apiKey, $cfg['system'], $cfg['model'], $messages, $maxTokens);
        }
        $responses[] = ['agent' => $agentId, 'name' => $cfg['name'], 'provider' => $provider, 'model' => $cfg['model'], 'text' => $text, 'ok' => true];
    } catch (RuntimeException $e) {
        $responses[] = ['agent' => $agentId, 'name' => $cfg['name'], 'provider' => $cfg['provider'], 'model' => $cfg['model'], 'text' => 'Erro: ' . $e->getMessage(), 'ok' => false];
    }
}

$logDir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/chat.log', json_encode([
    'cycle_id' => $cycleId,
    'at' => date('c'),
    'agents' => array_column($responses, 'agent'),
    'providers' => array_column($responses, 'provider'),
    'msg_len' => squad_len($userMessage),
    'ok_count' => count(array_filter($responses, static fn(array $r): bool => $r['ok'] === true)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

squad_json(200, ['cycle_id' => $cycleId, 'responses' => $responses, 'at' => date('c')]);
